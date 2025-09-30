# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PostSecret is a WordPress-based archive, search, and moderation system for the PostSecret collection (~1M postcards). It consolidates public discovery, moderation, and data management into a single WordPress stack.

**Core Components:**
- **Custom WordPress Theme** (`wp-content/themes/postsecret/`) - Public-facing archive, search, and detail pages
- **Admin Plugin** (`wp-content/plugins/postsecret-admin/`) - Moderation queues, taxonomy governance, audit logging, backfill jobs, and settings
- **MySQL Database** - Canonical secret records with full-text and tag indexes

**Product Principles:**
- Product-first, single-stack, accessible by default, privacy-preserving
- Future-ready: schema and UI accept similarity search module (Phase 2) without re-platforming
- Safety and policy at the center: public-safe defaults, PII avoidance, server-side enforcement

## Development Setup

**Start the environment:**
```bash
docker-compose up -d
```
WordPress runs at `http://localhost:8080`. The `wp-content` directory is mounted for live development.

**Install PHP dependencies:**
```bash
composer install
```

**Run tests:**
```bash
composer test
# or directly:
vendor/bin/phpunit
```

**Run code style checks:**
```bash
vendor/bin/phpcs
```

**CI runs automatically** on push/PR and executes both PHPCS and PHPUnit tests.

## Code Architecture

### Plugin Architecture (`postsecret-admin`)

**Namespace:** `PostSecret\Admin\`

**Bootstrap:** `postsecret-admin.php` initializes all route classes on `plugins_loaded` hook.

**Key Components:**
- **Routes/** - Request handlers for admin endpoints (Search, Review, Taxonomy, Backfill, Settings)
- **Services/** - Business logic layer:
  - `SearchService` - Full-text + tag search (tokenized, stemmed)
  - `ModerationService` - Queue management and approval workflows
  - `TaxonomyService` - Tag merge/alias operations
  - `LoggingService` - Audit trail (actor, action, timestamp, context)
  - `ConfigService` - Policy thresholds and settings
- **Model/** - DTOs like `Secret` (id, title, content, tags)
- **Util/** - Sanitization (`Sanitize`) and capability checks (`Caps`)
- **CLI/** - WP-CLI commands via `class-ps-cli.php`
  - `wp postsecret backfill --batch=<n> --rate=<n>`
- **migrations/** - Database schema files (e.g., `001_init.php`)

**Database Tables:**
- `ps_classification` - OCR text, descriptors, confidence scores, moderation state
- `ps_audit_log` - Complete audit trail of privileged actions (append-only, immutable)
- `ps_tag_alias` - Tag normalization (alias → canonical)
- `ps_backfill_job`, `ps_backfill_item` - Backfill progress tracking, checkpoints, error quarantine

**Canonical Secret Record Structure:**
Each Secret has: image pointers, extracted/approved text, tags, media descriptors (art/font/media), moderation state (pending/needs_review/approved/published/unpublished/flagged), confidence scores, provenance metadata.

### Theme Architecture (`postsecret`)

**Templates:**
- `front-page.php` - Homepage
- `archive-secrets.php` - Browse view
- `search.php` - Search results
- `single-secret.php` - Detail page
- `parts/card.php` - Reusable secret card component

**Includes (`inc/`):**
- `a11y.php` - WCAG 2.2 AA accessibility enhancements
- `seo.php` - Meta tags and structured data
- `routing.php` - Custom rewrite rules

**Assets:**
- `assets/css/style.css` - Compiled styles
- `assets/js/main.js` - Frontend interactions

### Data Flow

**Public Search:**
1. User submits query + optional tag filters
2. Theme calls `SearchService->search()`
3. Service executes tokenized/stemmed MySQL full-text query with tag JOIN
4. Results sorted by relevance (default) or recency
5. Paginated results rendered via theme templates

**Admin Moderation:**
1. Moderator accesses queue via `ReviewRoute`
2. `ModerationService` fetches items by state (needs_review, low_confidence, flagged, published)
3. Moderator reviews item with confidence indicators and policy signals
4. Actions: approve/publish/unpublish/re-review/edit tags (with capability + nonce checks)
5. `LoggingService` records action (actor, target, before/after, timestamp, outcome) to append-only audit log
6. State transition committed; caches invalidated

**Backfill (Historical Import ~1M Secrets):**
1. Initiated via WP-CLI (`wp postsecret backfill`) or admin UI (`BackfillRoute`)
2. Jobs are resumable (checkpoint per batch), idempotent (hash/signature), bounded retries
3. Schema validation; per-item error capture → quarantine table with reason
4. **Auto-throttling:** Pauses if p95 search > 1.2s for 15 minutes; resumes when ≤ 600ms for 30 min
5. Progress metrics: processed count, failed count, ETA, reconciliation against source
6. Quality gates: Policy-driven gating for NSFW/self-harm; audit trail per batch

## Standards & Requirements

**Code Style:** WordPress Coding Standards (WPCS) enforced via PHPCS. Configuration in `phpcs.xml`.

**Security & Privacy:**
- All admin actions require capability checks (via `Caps` utility) - `current_user_can()` on every route/action
- Nonce validation required for all state-changing operations (CSRF protection)
- Server-side policy gates enforce content safety (NSFW/self-harm thresholds)
- No PII in public-facing queries or responses; logs avoid raw PII/secrets
- Input sanitization + output escaping; HTML blocked in tags/notes
- Soft rate limiting on search and admin bulk endpoints
- API keys/config stored server-side only; never exposed client-side

**Accessibility:** All UIs target WCAG 2.2 AA with:
- Keyboard-complete navigation
- Visible focus indicators
- Correct ARIA roles and labels
- Screen reader tested flows

**i18n:** All strings wrapped in translation functions (`__()`, `_e()`, `esc_html__()`). Text domain: `postsecret` (theme), `postsecret-admin` (plugin).

**Performance Targets:**
- p95 search ≤ 600 ms (server processing for text+tag queries)
- Cold cache ≤ 1.2 s (triggers alert if sustained)
- Mobile time-to-first-useful-result ≤ 2.5 s (4G)
- Core Web Vitals: LCP/INP/CLS in "Good" ranges
- Uptime ≥ 99.9%; ≤ 0.25% 5xx error rate on public search endpoints
- Admin queue load p95 ≤ 400 ms; item open p95 ≤ 300 ms

**Caching Strategy:**
- Object cache for query fragments
- Short-TTL page/query caches (vary by q|tags|sort|page)
- HTTP caching headers on public routes
- Cache busting on publish/unpublish and tag merges
- Image lazy-load + responsive srcset

## Testing

**Unit Tests:** Located in `wp-content/plugins/postsecret-admin/tests/`

**Test Bootstrap:** `tests/bootstrap.php` loads WordPress test environment

**Run single test:**
```bash
vendor/bin/phpunit --filter=test_name
```

## Roles & Capabilities

**Admin Role (MVP):**
- Full editorial control: review queues, approve/publish/unpublish, re-review, edit tags
- Taxonomy governance: merge/alias/delete tags
- Settings: configure confidence thresholds, policy gates
- Audit logs: view/export all privileged actions

**Custom Capabilities (via `Caps` utility):**
- `ps.view_admin` - Access to PostSecret admin screens
- `ps.review.queue` - View triage queues & item details
- `ps.review.act` - Approve/reject/re-review items
- `ps.publish` - Publish/unpublish items
- `ps.tags.merge` - Merge/alias/delete tags
- `ps.logs.view` - View/export audit logs

**Access Control Principles:**
- Least privilege: assign minimal capabilities needed
- Separation of duties: publishing, taxonomy merges, settings are distinct powers
- Explicit gating: all admin actions require both capability checks AND nonces
- Auditability: every privileged action logged with actor, timestamp, target, outcome
- Deny by default: `current_user_can()` checks on every route/action

## Common Development Patterns

**Adding a new moderation queue:**
1. Add queue state constant in `ModerationService`
2. Create SQL query method in service
3. Add route handler in `ReviewRoute`
4. Update admin UI to link to new queue

**Adding a new WP-CLI command:**
1. Add method to `PS_CLI` class in `cli/class-ps-cli.php`
2. Register command in `register()` method
3. Document synopsis with `@synopsis` docblock

**Database migrations:**
1. Create new file in `migrations/` (e.g., `002_description.php`)
2. Implement `up()` function with SQL
3. Use `dbDelta()` for table creation/updates
4. Trigger via WP-CLI or admin migration UI

**Adding a new service:**
1. Create class in `src/Services/`
2. Use namespace `PostSecret\Admin\Services`
3. Inject via constructor or use singleton pattern
4. Call from route handlers

**Taxonomy operations (merge/alias):**
1. Use `TaxonomyService->merge()` or `->alias()` methods
2. Mark deprecated tag as alias pointing to canonical
3. Reindex affected Secrets asynchronously
4. Log operation to audit trail with actor and rationale
5. Target: ≤1% duplicate/orphan tag operations

**Handling bulk operations:**
1. Validate capability + nonce before processing
2. Preview affected item count before commit
3. For >100 items, require type-to-confirm from user
4. Process in batches; record progress
5. On partial failure: complete successful items, report failures with CSV export
6. Single audit entry referencing all target IDs

## Key Files Reference

- Plugin entry: `wp-content/plugins/postsecret-admin/postsecret-admin.php`
- Theme entry: `wp-content/themes/postsecret/functions.php`
- WP-CLI commands: `wp-content/plugins/postsecret-admin/cli/class-ps-cli.php`
- Database schema: `wp-content/plugins/postsecret-admin/migrations/001_init.php`
- Search logic: `wp-content/plugins/postsecret-admin/src/Services/SearchService.php`
- Moderation flow: `wp-content/plugins/postsecret-admin/src/Services/ModerationService.php`
- Audit logging: `wp-content/plugins/postsecret-admin/src/Services/LoggingService.php`

## Public UI Requirements

**Search Behavior:**
- Input: debounced 250-400ms; Enter submits immediately; Esc clears text focus
- Parsing: plain keywords, case-insensitive, ASCII folding (no boolean operators required at MVP)
- Scope: queries run against approved text fields (OCR/model text)
- Facets: multi-select tags with counts; selected tags shown as removable chips
- Sorting: relevance (default), recency
- Pagination: server-side, deterministic ordering; 24 items/page (desktop), 12 (mobile)
- URL state: all search states (query + facets + sort + page) encoded in URL and shareable

**Result Cards:**
- Image thumbnail (aspect-aware, lazy-loaded) with alt text from descriptors
- Key tags (up to 3 chips; overflow "+N")
- Text excerpt (first ~140 chars of approved text; ellipsis if truncated)
- Safe indicators (e.g., "Content advisory" icon if applicable)
- Click target: entire card opens detail view

**Detail View:**
- Large image with zoom/lightbox; alt text provided
- Tags, art/font/media descriptors, orientation
- Approved extracted text; language label if non-English
- Postmark/ingest date (if public-safe), canonical link
- Placeholder area for Phase 2 "Find similar" module

**Empty/Error States:**
- Zero results: guidance ("Try fewer tags", "Check spelling") + top tags
- Partial results: non-blocking alert if facet fails; retry affordance
- Errors: friendly message + retry; no stack traces; status logged server-side

## Admin UI Requirements

**Queues:**
- Views: Needs Review, Low Confidence, Flagged, Published (read-only)
- Display: paginated tables/grids with thumbnail, key tags, confidence badge, review status, last action/actor, updated time
- Filters: tags (multi-select), status, confidence range slider, date range, text contains
- Sorting: updated time (default), confidence, recency
- Batch size: 25 per page (configurable)

**Item Detail Panel:**
- Full image (zoom), approved text, language, descriptors (tags, art/font/media)
- Signals: confidence (overall + by-field), moderation labels, NSFW/self-harm flags, policy notes
- History: last 5 actions (actor, timestamp, summary); link to full audit log

**Editorial Actions:**
- Single-item: Approve, Publish, Unpublish, Send to Re-review, Edit/Add Tags, Edit Text (approved field), Add Note (internal)
- Guards: confirmation dialogs for Publish/Unpublish; policy interstitials for flagged content
- Undo: 30-second inline undo for Publish/Unpublish where feasible
- Provenance: all edits record actor, timestamp, rationale (optional note)

**Workflow States & Transitions:**
- States: `pending`, `needs_review`, `approved`, `published`, `unpublished`, `flagged`
- Invalid transitions blocked with guidance (e.g., `published` → `approved` requires `unpublish` first)
- Auto-routing: items with sensitive labels or low confidence land in Needs Review

**Settings:**
- Confidence threshold for Low Confidence queue
- Policy toggles for NSFW/self-harm gating
- Default public visibility rules
- Preview: shows projected queue deltas before save
- Rollback: one-click revert to previous settings version

## Documentation

- `docs/DEV_SETUP.md` - Detailed setup instructions
- `docs/MODERATION_GUIDE.md` - Queue review workflows
- `docs/TAG_GOVERNANCE.md` - Taxonomy management guidelines
- `README.md` - High-level project overview and architecture

## Future: Phase 2 Similarity Search

**Out of MVP scope** - designed for pluggable integration without re-platforming:

- Entry points: "Similar Secrets" module on detail page (lazy-loaded); "Find similar" button on result cards
- Signals: visual embedding similarity, text embedding similarity, tag overlap boost, freshness
- Ranking: cosine distance on embeddings; tag overlap and moderation safety boosts; near-duplicate suppression
- Storage: embeddings stored as artifacts linked to canonical Secret record (model name, dimension, timestamp)
- Target: p95 ≤ 900 ms for top-K similarity request; ≥15% CTR on detail pages
- Safety: only public-safe items are candidates; respects all policy gates