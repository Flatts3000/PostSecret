/* psai-stream.js
 * Infinite postcard stream for the home page.
 * - Pulls vetted “front” attachments via REST (pretty or legacy ?rest_route)
 * - Newest-first, paged
 * - Lazy images + IO prefetch
 * - Light windowing (prunes far-off cards)
 * - Renders cards via Mustache templates (#psai-card-tpl), falls back to DOM if missing
 */

(() => {
    // ----- Config -----
    const CFG = window.PSAI_STREAM || {};
    const API_PRIMARY = CFG.endpoint || '';                  // e.g. /wp-json/psai/v1/secrets
    const API_FALLBACK = CFG.endpointLegacy || '';            // e.g. /?rest_route=/psai/v1/secrets
    const PER = Number(CFG.perPage || 24);
    const MOUNT_ID = CFG.mountId || 'psai-stream';

    // Soft windowing limits
    const MAX_DOM_CARDS = 240;   // ~10 pages @ 24 each
    const PRUNE_BATCH = 60;

    // ----- Mount -----
    // Only run if the mount element exists in the DOM (don't create it)
    let root = document.getElementById(MOUNT_ID);
    if (!root) {
        // Mount element doesn't exist - this script shouldn't run on this page
        return;
    }

    // If no endpoint info at all, bail loudly
    if (!API_PRIMARY && !API_FALLBACK) {
        console.warn('PSAI: missing REST endpoints (endpoint / endpointLegacy).');
        return;
    }

    // ----- UI shell -----
    root.innerHTML = '';
    const list = document.createElement('div');
    list.className = 'ps-stream';
    root.appendChild(list);

    const status = document.createElement('div');
    status.className = 'ps-status';
    status.style.textAlign = 'center';
    status.style.margin = '24px 0';
    status.style.color = 'var(--wp--preset--color--muted, #6b7280)';
    root.appendChild(status);

    const sentinel = document.createElement('div');
    sentinel.style.height = '1px';
    root.appendChild(sentinel);

    // ----- Templates -----
    const TPL = {
        card: document.getElementById('psai-card-tpl')?.innerHTML || '',
        skeleton: document.getElementById('psai-skeleton-tpl')?.innerHTML || '',
        error: document.getElementById('psai-error-tpl')?.innerHTML || ''
    };

    const hasMustache = typeof window.Mustache !== 'undefined' && !!TPL.card;

    // ----- State -----
    let page = 1;
    let totalPages = Infinity;
    let loading = false;
    const failedPages = new Set();

    const setStatus = (t) => {
        status.textContent = t || '';
    };

    const fmtDate = (iso) => {
        if (!iso) return '';
        try {
            return new Date(iso).toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: 'numeric'});
        } catch {
            return '';
        }
    };

    // Data -> view-model transform for templates
    const asViewModel = (item) => {
        const tags = Array.isArray(item.tags) ? item.tags : [];
        // Replace underscores with spaces in tag names
        const displayTags = tags.slice(0, 3).map(tag => tag.replace(/_/g, ' '));
        const overflowCount = Math.max(0, tags.length - displayTags.length);
        const alt = (item.alt || '').trim();
        const excerpt = (item.excerpt || '').trim();
        const hasBack = !!(item.back_id && item.back_src);

        if (hasBack) {
            console.log('Card with back detected:', item.id, 'back_id:', item.back_id, 'back_src:', item.back_src);
        }

        return {
            ...item,
            alt,
            altFallback: alt || 'View secret',
            dateFmt: fmtDate(item.date),
            displayTags,
            overflowCount: overflowCount || null,
            advisory: false, // TODO: wire to API if/when content advisories are available
            excerpt,
            hasBack,
            back_src: item.back_src || '',
            back_alt: item.back_alt || ''
        };
    };

    // DOM fallback (if Mustache/template missing)
    const createCardDOM = (item) => {
        const card = document.createElement('article');
        card.className = 'ps-card';
        card.dataset.id = item.id;
        card.dataset.orientation = item.orientation || 'unknown';

        const a = document.createElement('a');
        a.href = item.link || '#';
        a.className = 'ps-card__link';
        a.setAttribute('aria-label', (item.alt || 'View secret'));

        const fig = document.createElement('figure');
        fig.className = 'ps-card__media';

        const img = document.createElement('img');
        img.loading = 'lazy';
        img.decoding = 'async';
        img.src = item.src;
        img.alt = item.alt || '';
        img.className = 'ps-card__img';
        fig.appendChild(img);
        a.appendChild(fig);

        const meta = document.createElement('div');
        meta.className = 'ps-card__meta';

        const date = document.createElement('div');
        date.className = 'ps-card__date';
        date.style.fontSize = '.85rem';
        date.style.color = 'var(--wp--preset--color--muted, #6b7280)';
        date.textContent = fmtDate(item.date);
        meta.appendChild(date);

        if (item.excerpt) {
            const p = document.createElement('p');
            p.className = 'ps-card__excerpt';
            p.textContent = item.excerpt;
            meta.appendChild(p);
        }

        if (Array.isArray(item.tags) && item.tags.length) {
            const cap = document.createElement('div');
            cap.className = 'ps-card__tags';
            item.tags.slice(0, 3).forEach(t => {
                const chip = document.createElement('span');
                chip.className = 'ps-chip';
                // Replace underscores with spaces
                chip.textContent = t.replace(/_/g, ' ');
                cap.appendChild(chip);
            });
            const overflow = item.tags.length - 3;
            if (overflow > 0) {
                const chip = document.createElement('span');
                chip.className = 'ps-chip ps-chip--more';
                chip.textContent = `+${overflow}`;
                cap.appendChild(chip);
            }
            meta.appendChild(cap);
        }

        card.appendChild(a);
        card.appendChild(meta);

        return card;
    };

    // Track min long side dimension from API data
    let minLongSide = Infinity;

    // Calculate size styles based on API dimensions
    // Only constrain the long side, let the short side scale naturally
    const calculateCardStyles = (item) => {
        const width = item.width || 0;
        const height = item.height || 0;
        const orientation = item.orientation || 'unknown';
        const longSide = Math.max(width, height);

        // Update global min
        if (longSide > 0 && longSide < minLongSide) {
            minLongSide = longSide;
        }

        // Use min long side as target (never scale up)
        const targetLongSide = minLongSide === Infinity ? longSide : minLongSide;

        let styles = {};
        if (orientation === 'landscape' && width && height) {
            // For landscape: width is the long side, only constrain width
            styles.maxWidth = `${targetLongSide}px`;
        } else if (orientation === 'portrait' && width && height) {
            // For portrait: height is the long side, only constrain height
            styles.maxHeight = `${targetLongSide}px`;
        }

        return styles;
    };

    // Apply calculated styles to cards
    const normalizeSizes = () => {
        const cards = list.querySelectorAll('.ps-card');
        cards.forEach(card => {
            const media = card.querySelector('.ps-card__media');
            if (!media) return;

            const width = parseInt(card.dataset.width) || 0;
            const height = parseInt(card.dataset.height) || 0;
            const orientation = card.dataset.orientation || 'unknown';

            if (!width || !height) return;

            const item = { width, height, orientation };
            const styles = calculateCardStyles(item);

            Object.entries(styles).forEach(([prop, value]) => {
                media.style[prop] = value;
            });
        });
    };

    // Render helpers
    const renderCards = (items) => {
        // Update min long side from API data
        items.forEach(item => {
            const longSide = Math.max(item.width || 0, item.height || 0);
            if (longSide > 0 && longSide < minLongSide) {
                minLongSide = longSide;
            }
        });

        const frag = document.createDocumentFragment();
        if (hasMustache) {
            for (const raw of items) {
                const html = window.Mustache.render(TPL.card, asViewModel(raw));
                const wrap = document.createElement('div');
                wrap.innerHTML = html;
                const el = wrap.firstElementChild;
                if (el) frag.appendChild(el);
            }
        } else {
            for (const raw of items) frag.appendChild(createCardDOM(raw));
        }
        list.appendChild(frag);

        // Normalize sizes based on API dimensions
        normalizeSizes();

        // Attach flip handlers to newly rendered cards
        attachFlipHandlers();
    };

    // Flip card handler
    const attachFlipHandlers = () => {
        const flipBtns = list.querySelectorAll('.ps-card__flip-btn');
        flipBtns.forEach(btn => {
            if (btn.dataset.attached) return; // Already attached
            btn.dataset.attached = 'true';

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const card = btn.closest('.ps-card');
                if (card) {
                    card.classList.toggle('ps-card--flipped');
                }
            });
        });
    };

    const showSkeletons = () => {
        if (!TPL.skeleton) return [];
        const shells = [];
        const count = Math.min(PER, 8);
        for (let i = 0; i < count; i++) {
            const wrap = document.createElement('div');
            wrap.innerHTML = TPL.skeleton;
            const el = wrap.firstElementChild;
            if (el) {
                list.appendChild(el);
                shells.push(el);
            }
        }
        return shells;
    };

    const showError = () => {
        if (TPL.error) {
            status.innerHTML = TPL.error;
        } else {
            setStatus('Could not load more secrets right now.');
        }
    };

    const pruneDomIfNeeded = () => {
        const count = list.children.length;
        if (count <= MAX_DOM_CARDS) return;
        const first = list.firstElementChild;
        if (!first) return;
        const rect = first.getBoundingClientRect();
        const buffer = -window.innerHeight * 2;
        if (rect.bottom < buffer) {
            let removed = 0;
            while (removed < PRUNE_BATCH && list.firstElementChild) {
                list.removeChild(list.firstElementChild);
                removed++;
            }
        }
    };

    // ----- REST with fallback (/wp-json … then ?rest_route=) -----
    const buildUrl = (base, p, per) => {
        const u = new URL(base, window.location.href);
        u.searchParams.set('page', String(p));
        u.searchParams.set('per_page', String(per));
        return u.toString();
    };

    const fetchPage = async (p, per) => {
        // Try pretty
        if (API_PRIMARY) {
            try {
                const r = await fetch(buildUrl(API_PRIMARY, p, per), {credentials: 'same-origin'});
                if (r.ok) return r.json();
            } catch {
            }
        }
        // Fallback to legacy
        if (API_FALLBACK) {
            const r2 = await fetch(buildUrl(API_FALLBACK, p, per), {credentials: 'same-origin'});
            if (r2.ok) return r2.json();
        }
        throw new Error('REST unavailable (pretty & legacy failed)');
    };

    // ----- Loader -----
    async function load() {
        if (loading || page > totalPages) return;
        loading = true;
        setStatus('Loading…');

        const shells = showSkeletons();

        try {
            const data = await fetchPage(page, PER);
            totalPages = Number.isFinite(data.total_pages) ? data.total_pages : 1;
            const items = Array.isArray(data.items) ? data.items : [];

            renderCards(items);

            page += 1;
            setStatus('');
            pruneDomIfNeeded();
        } catch (err) {
            console.error('PSAI stream error:', err);
            if (!failedPages.has(page)) {
                failedPages.add(page);
                setTimeout(() => {
                    loading = false;
                    load();
                }, 1200);
                return;
            } else {
                showError();
            }
        } finally {
            shells.forEach(el => el.remove());
            loading = false;
        }
    }

    // ----- Infinite scroll -----
    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) load();
        });
    }, {rootMargin: '1200px 0px'});
    io.observe(sentinel);

    // Kickoff
    console.log('PSAI stream boot', {API_PRIMARY, API_FALLBACK, PER, MOUNT_ID, hasMustache});
    console.log('Mustache available:', typeof window.Mustache, 'Template:', !!TPL.card);
    if (TPL.card) {
        console.log('Template content (first 200 chars):', TPL.card.substring(0, 200));
    }
    load();

    // Manual fallback
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Load more';
    btn.style.display = 'inline-block';
    btn.style.margin = '8px auto';
    btn.style.padding = '8px 16px';
    btn.style.borderRadius = '999px';
    btn.style.border = '1px solid #d1d5db';
    btn.style.background = '#fff';
    btn.style.cursor = 'pointer';
    btn.setAttribute('aria-label', 'Load more secrets');
    btn.addEventListener('click', () => load());
    status.appendChild(btn);
})();