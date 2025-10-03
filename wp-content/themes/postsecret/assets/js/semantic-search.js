/**
 * Semantic Search Handler
 * Handles search form submission and result display with infinite scroll and caching
 */
(function () {
    'use strict';

    const MIN_QUERY_LENGTH = 3;
    const SEARCH_ENDPOINT = window.location.origin + '/index.php?rest_route=/psai/v1/semantic-search';
    const ITEMS_PER_PAGE = 12;
    const CACHE_EXPIRY_MS = 5 * 60 * 1000; // 5 minutes

    // Search state
    let currentQuery = '';
    let allResults = [];
    let displayedCount = 0;
    let isLoading = false;
    let observerSentinel = null;

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        const form = document.getElementById('ps-semantic-search-form');
        const input = document.getElementById('ps-search-input');
        const errorEl = document.querySelector('.ps-search-error');

        if (form && input) {
            // Validate on submit
            form.addEventListener('submit', function (e) {
                const query = input.value.trim();

                // Validate minimum length
                if (query.length < MIN_QUERY_LENGTH) {
                    e.preventDefault();
                    showError(errorEl, `Please enter at least ${MIN_QUERY_LENGTH} characters`);
                    return;
                }

                // Clear cache for this query to get fresh results
                clearCacheForQuery(query);

                // Clear any previous errors and let form submit naturally
                clearError(errorEl);
            });

            // Clear error on input
            input.addEventListener('input', function () {
                clearError(errorEl);
            });
        }

        // Handle search results display if on results page
        displayResults();
    }

    function showError(errorEl, message) {
        if (!errorEl) return;
        errorEl.textContent = message;
        errorEl.style.display = 'block';
    }

    function clearError(errorEl) {
        if (!errorEl) return;
        errorEl.textContent = '';
        errorEl.style.display = 'none';
    }

    // Cache management
    function getCacheKey(query) {
        return `ps_search_${query.toLowerCase().trim()}`;
    }

    function getCachedResults(query) {
        try {
            const key = getCacheKey(query);
            const cached = sessionStorage.getItem(key);
            if (!cached) return null;

            const data = JSON.parse(cached);
            const age = Date.now() - data.timestamp;

            if (age > CACHE_EXPIRY_MS) {
                sessionStorage.removeItem(key);
                return null;
            }

            return data.results;
        } catch (e) {
            console.error('Cache read error:', e);
            return null;
        }
    }

    function setCachedResults(query, results) {
        try {
            const key = getCacheKey(query);
            const data = {
                results: results,
                timestamp: Date.now()
            };
            sessionStorage.setItem(key, JSON.stringify(data));
        } catch (e) {
            console.error('Cache write error:', e);
        }
    }

    function clearCacheForQuery(query) {
        try {
            const key = getCacheKey(query);
            sessionStorage.removeItem(key);
        } catch (e) {
            console.error('Cache clear error:', e);
        }
    }

    async function displayResults() {
        // Check if we're on a search page with semantic search flag
        const urlParams = new URLSearchParams(window.location.search);
        const query = urlParams.get('s');
        const isSemantic = urlParams.get('semantic');

        // Only run for semantic searches (has ?s= and &semantic=1 parameters)
        if (!query || !isSemantic) {
            return;
        }

        currentQuery = query;

        // Find the main content area
        const main = document.querySelector('main') || document.getElementById('primary');
        if (!main) return;

        // Check cache first
        const cached = getCachedResults(query);
        if (cached) {
            console.log('Using cached results for:', query);
            allResults = cached.items || [];
            renderSearchResults(main, query, cached);
            return;
        }

        // Show loading state
        main.innerHTML = `
            <div class="ps-search-header">
                <h1>Searching for: "${escapeHtml(query)}"</h1>
                <p class="ps-search-loading">
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                    Finding similar secrets...
                </p>
            </div>
        `;

        try {
            // Fetch search results from API
            const response = await fetch(SEARCH_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    query: query,
                    limit: 60,  // Fetch more for infinite scroll
                    min_score: 0.15,
                }),
            });

            // Check content type
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.error('Received non-JSON response:', contentType);
                throw new Error('Server returned invalid response format');
            }

            if (!response.ok) {
                const error = await response.json();
                console.error('API error:', error);
                throw new Error(error.message || 'Search failed');
            }

            const data = await response.json();
            console.log('Search results:', data);

            // Cache the results
            setCachedResults(query, data);

            // Store results for infinite scroll
            allResults = data.items || [];

            // Render results
            renderSearchResults(main, query, data);

        } catch (error) {
            console.error('Search error:', error);
            main.innerHTML = `
                <div class="ps-search-header">
                    <h1>Search Error</h1>
                    <p class="ps-search-error-msg">Failed to load search results. Please try again.</p>
                    <a href="/" class="ps-button">Back to Home</a>
                </div>
            `;
        }
    }

    function renderSearchResults(container, query, data) {
        // Reset state
        displayedCount = 0;

        // Create results section with ps-latest wrapper to match front page styling
        const section = document.createElement('div');
        section.id = 'ps-search-results';
        section.className = 'ps-latest';
        section.innerHTML = `
            <div class="ps-search-header">
                <h1>Search Results for: "${escapeHtml(query)}"</h1>
                <p class="ps-search-count">${data.total} secret${data.total !== 1 ? 's' : ''} found</p>
            </div>
            <div id="ps-search-grid" class="ps-stream"></div>
        `;

        container.innerHTML = '';
        container.appendChild(section);

        const grid = document.getElementById('ps-search-grid');
        if (!grid) return;

        // Handle empty results
        if (data.total === 0) {
            grid.innerHTML = `
                <div class="ps-no-results">
                    <p>No secrets found matching "${escapeHtml(query)}"</p>
                    <p class="ps-search-hint">Try different words or feelings</p>
                </div>
            `;
            return;
        }

        // Render initial batch
        renderNextBatch(grid);

        // Set up infinite scroll if there are more results
        if (allResults.length > ITEMS_PER_PAGE) {
            setupInfiniteScroll(grid);
        }
    }

    function renderNextBatch(grid) {
        const cardTemplate = document.getElementById('psai-card-tpl');

        // Mustache and template are required - fail explicitly if missing
        if (!cardTemplate) {
            console.error('Card template (#psai-card-tpl) not found');
            throw new Error('Card template not found');
        }
        if (!window.Mustache) {
            console.error('Mustache library not loaded');
            throw new Error('Mustache library not loaded');
        }

        const template = cardTemplate.innerHTML;
        const start = displayedCount;
        const end = Math.min(start + ITEMS_PER_PAGE, allResults.length);

        for (let i = start; i < end; i++) {
            const item = allResults[i];
            const cardData = prepareCardData(item);
            const html = window.Mustache.render(template, cardData);
            grid.insertAdjacentHTML('beforeend', html);
        }

        displayedCount = end;

        // Update sentinel if exists
        if (observerSentinel && displayedCount >= allResults.length) {
            observerSentinel.remove();
            observerSentinel = null;
        }
    }

    function setupInfiniteScroll(grid) {
        // Create sentinel element for intersection observer
        const sentinel = document.createElement('div');
        sentinel.className = 'ps-search-sentinel';
        sentinel.style.height = '1px';
        grid.parentElement.appendChild(sentinel);
        observerSentinel = sentinel;

        // Create intersection observer
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !isLoading && displayedCount < allResults.length) {
                    isLoading = true;

                    // Add loading indicator
                    let loadingEl = document.querySelector('.ps-search-loading-more');
                    if (!loadingEl) {
                        loadingEl = document.createElement('div');
                        loadingEl.className = 'ps-search-loading-more';
                        loadingEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading more...';
                        grid.parentElement.appendChild(loadingEl);
                    }

                    // Small delay for UX
                    setTimeout(() => {
                        renderNextBatch(grid);
                        isLoading = false;

                        if (loadingEl) {
                            loadingEl.remove();
                        }
                    }, 300);
                }
            });
        }, {
            rootMargin: '400px'  // Start loading before user reaches bottom
        });

        observer.observe(sentinel);
    }

    function prepareCardData(item) {
        const hasBack = !!item.back_src;
        const displayTags = (item.tags || []).slice(0, 3);
        const overflowCount = Math.max(0, (item.tags || []).length - 3);
        const date = item.date ? new Date(item.date) : null;
        const similarityPercent = item.similarity ? Math.round(item.similarity * 100) : 0;

        // Format date to match front page (e.g., "Oct 3, 2025")
        const dateFmt = date ? date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        }) : '';

        return {
            id: item.id,
            src: item.src,
            width: item.width,
            height: item.height,
            alt: item.alt || 'Secret postcard',
            altFallback: item.alt || 'Secret postcard',
            caption: item.caption,
            dateFmt: dateFmt,
            displayTags: displayTags,
            overflowCount: overflowCount > 0 ? overflowCount : null,
            advisory: false, // Set based on content flags if available
            primary: item.primary,
            orientation: item.orientation,
            hasBack: hasBack,
            back_src: item.back_src,
            back_alt: item.back_alt || 'Secret postcard (back)',
            link: item.link,
            similarity: item.similarity,
            similarityPercent: similarityPercent > 0 ? similarityPercent : null,
        };
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})();
