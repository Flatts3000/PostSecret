/**
 * Secret Detail Page Interactions
 *
 * Handles lightbox/zoom, copy link, flip card (back), and Similar Secrets lazy-load.
 * Keyboard-accessible, respects reduced-motion, focus management.
 *
 * @package PostSecret
 */

(function () {
    'use strict';

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        initLightbox();
        initCopyLink();
        initFlipCard();
        initSimilarSecrets();
    }

    // ========================================
    // Lightbox / Zoom
    // ========================================
    function initLightbox() {
        const trigger = document.querySelector('.ps-secret__zoom-trigger');
        const lightbox = document.getElementById('ps-lightbox');

        if (!trigger || !lightbox) return;

        const closeBtn = lightbox.querySelector('.ps-lightbox__close');
        const backdrop = lightbox.querySelector('.ps-lightbox__backdrop');
        const img = lightbox.querySelector('.ps-lightbox__img');
        let previousFocus = null;

        // Open lightbox
        trigger.addEventListener('click', openLightbox);
        trigger.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openLightbox();
            }
        });

        function openLightbox() {
            const imgSrc = trigger.dataset.imageSrc;
            const imgAlt = trigger.dataset.imageAlt;

            if (!imgSrc) return;

            previousFocus = document.activeElement;
            img.src = imgSrc;
            img.alt = imgAlt || '';
            lightbox.removeAttribute('hidden');
            lightbox.setAttribute('aria-hidden', 'false');

            // Focus close button
            setTimeout(() => closeBtn.focus(), 50);

            // Close handlers
            closeBtn.addEventListener('click', closeLightbox);
            backdrop.addEventListener('click', closeLightbox);
            document.addEventListener('keydown', handleKeydown);
        }

        function closeLightbox() {
            lightbox.setAttribute('hidden', '');
            lightbox.setAttribute('aria-hidden', 'true');
            img.src = '';
            img.alt = '';

            // Return focus
            if (previousFocus) {
                previousFocus.focus();
            }

            // Remove handlers
            closeBtn.removeEventListener('click', closeLightbox);
            backdrop.removeEventListener('click', closeLightbox);
            document.removeEventListener('keydown', handleKeydown);
        }

        function handleKeydown(e) {
            if (e.key === 'Escape') {
                closeLightbox();
            }
        }
    }

    // ========================================
    // Copy Link to Clipboard
    // ========================================
    function initCopyLink() {
        const btn = document.querySelector('.ps-copy-link');
        if (!btn) return;

        const url = btn.dataset.url;
        const feedback = document.querySelector('.ps-copy-feedback');

        btn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(url);
                showFeedback('Link copied!', 'success');
            } catch (err) {
                // Fallback for older browsers
                fallbackCopy(url);
            }
        });

        function fallbackCopy(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showFeedback('Link copied!', 'success');
            } catch (err) {
                showFeedback('Copy failed', 'error');
            }
            document.body.removeChild(textarea);
        }

        function showFeedback(message, type) {
            if (!feedback) return;
            feedback.textContent = message;
            feedback.className = 'ps-copy-feedback ps-copy-feedback--' + type;
            feedback.style.display = 'inline';

            setTimeout(() => {
                feedback.style.display = 'none';
                feedback.textContent = '';
                feedback.className = 'ps-copy-feedback';
            }, 3000);
        }
    }

    // ========================================
    // Flip Card (Show Back)
    // ========================================
    function initFlipCard() {
        const flipBtn = document.querySelector('.ps-secret__flip-btn');
        if (!flipBtn) return;

        const figure = document.querySelector('.ps-secret__figure');
        const mainImg = figure.querySelector('.ps-secret__img');
        const backSrc = flipBtn.dataset.backSrc;
        const backAlt = flipBtn.dataset.backAlt;
        let isFlipped = false;

        flipBtn.addEventListener('click', toggleFlip);

        function toggleFlip() {
            isFlipped = !isFlipped;

            if (isFlipped) {
                // Show back
                const originalSrc = mainImg.src;
                const originalAlt = mainImg.alt;
                mainImg.dataset.originalSrc = originalSrc;
                mainImg.dataset.originalAlt = originalAlt;
                mainImg.src = backSrc;
                mainImg.alt = backAlt;
                flipBtn.setAttribute('aria-label', 'Show front of postcard');
                flipBtn.querySelector('i').className = 'fa-solid fa-rotate-left';
            } else {
                // Show front
                mainImg.src = mainImg.dataset.originalSrc;
                mainImg.alt = mainImg.dataset.originalAlt;
                flipBtn.setAttribute('aria-label', 'Show back of postcard');
                flipBtn.querySelector('i').className = 'fa-solid fa-rotate';
            }

            // Respect reduced motion
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                figure.style.transition = 'transform 0.6s ease';
                figure.style.transform = isFlipped ? 'rotateY(180deg)' : 'rotateY(0)';
            }
        }
    }

    // ========================================
    // Similar Secrets (Lazy-loaded)
    // ========================================
    function initSimilarSecrets() {
        const container = document.querySelector('.ps-similar-secrets');
        if (!container) return;

        const secretId = container.dataset.secretId;
        const resultsContainer = container.querySelector('.ps-similar-secrets__container');

        // Lazy-load when user scrolls near the section
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        loadSimilarSecrets(secretId, resultsContainer);
                        observer.disconnect();
                    }
                });
            },
            { rootMargin: '200px' }
        );

        observer.observe(container);
    }

    async function loadSimilarSecrets(secretId, container) {
        try {
            const response = await fetch(
                `/wp-json/psai/v1/similar-secrets/${secretId}?limit=6`,
                {
                    headers: {
                        'Content-Type': 'application/json',
                    },
                }
            );

            if (!response.ok) {
                throw new Error('Failed to load similar secrets');
            }

            const data = await response.json();
            renderSimilarSecrets(data.items || [], container);
        } catch (error) {
            console.error('Similar secrets error:', error);
            container.innerHTML = '<p class="ps-error">Unable to load similar secrets.</p>';
        }
    }

    function renderSimilarSecrets(items, container) {
        if (items.length === 0) {
            container.innerHTML = '<p class="ps-empty">No similar secrets found.</p>';
            return;
        }

        // Use the same Mustache template as front page/search if available
        const template = document.getElementById('psai-card-tpl');
        if (template && window.Mustache) {
            const grid = document.createElement('div');
            grid.className = 'ps-stream ps-latest';

            items.forEach((item) => {
                const cardData = prepareCardData(item);
                const html = window.Mustache.render(template.innerHTML, cardData);
                grid.insertAdjacentHTML('beforeend', html);
            });

            container.innerHTML = '';
            container.appendChild(grid);
        } else {
            // Fallback: simple card rendering
            const grid = document.createElement('div');
            grid.className = 'ps-similar-grid';

            items.forEach((item) => {
                const card = document.createElement('a');
                card.href = item.link;
                card.className = 'ps-similar-card';
                card.innerHTML = `
                    <img src="${escapeHtml(item.src)}" alt="${escapeHtml(item.alt)}" loading="lazy" decoding="async" />
                    <span class="ps-similar-similarity">${Math.round(item.similarity * 100)}% match</span>
                `;
                grid.appendChild(card);
            });

            container.innerHTML = '';
            container.appendChild(grid);
        }

        container.removeAttribute('data-loading');
    }

    function prepareCardData(item) {
        const date = item.date ? new Date(item.date) : null;
        const dateFmt = date
            ? date.toLocaleDateString(undefined, {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
              })
            : '';

        return {
            id: item.id,
            src: item.src,
            width: item.width,
            height: item.height,
            alt: item.alt || 'Secret postcard',
            altFallback: item.alt || 'Secret postcard',
            dateFmt: dateFmt,
            link: item.link,
            similarity: item.similarity,
            similarityPercent: Math.round(item.similarity * 100),
            primary: item.primary,
            orientation: item.orientation,
        };
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
