# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PostSecret is a WordPress-based archive, search, and moderation system for the PostSecret collection (~1M postcards). It consolidates public discovery, moderation, and data management into a single WordPress stack.

**Core Components:**
- **Custom WordPress Theme** (`wp-content/themes/postsecret/`) - Public-facing archive, search, and detail pages
- **Admin Plugin** (`wp-content/plugins/postsecret-admin/`) - Moderation queues, audit logging, backfill jobs, and settings
- **AI Plugin** (`wp-content/plugins/postsecret-ai/`) - Classification, faceted metadata extraction, and embeddings
- **MySQL Database** - Canonical secret records with full-text and facet indexes, plus embeddings table

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
- **Routes/** - Request handlers for admin endpoints (Search, Review, Backfill, Settings)
- **Services/** - Business logic layer:
  - `SearchService` - Full-text + facet search (supports topics, feelings, meanings)
  - `ModerationService` - Queue management, approval workflows, and facet editing
  - `LoggingService` - Audit trail (actor, action, timestamp, context)
  - `ConfigService` - Policy thresholds and settings
- **Model/** - DTOs like `Secret` (id, title, content, topics, feelings, meanings)
- **Util/** - Sanitization (`Sanitize`) and capability checks (`Caps`)
- **CLI/** - WP-CLI commands via `class-ps-cli.php`
  - `wp postsecret backfill --batch=<n> --rate=<n>`
- **migrations/** - Database schema files (e.g., `001_init.php`, `002_facets.php`, `003_embeddings.php`)

**Database Tables:**
- `ps_classification` - OCR text, descriptors, confidence scores, moderation state
- `ps_audit_log` - Complete audit trail of privileged actions (append-only, immutable)
- `ps_text_embeddings` - Semantic embeddings for similarity search (1536d vectors)
- `ps_backfill_job`, `ps_backfill_item` - Backfill progress tracking, checkpoints, error quarantine

**Canonical Secret Record Structure:**
Each Secret has: image pointers, extracted/approved text, **faceted classification (topics, feelings, meanings)**, media descriptors (art/font/media), moderation state (pending/needs_review/approved/published/unpublished/flagged), confidence scores, provenance metadata, semantic embedding.

**Faceted Classification:**
- **Topics** (2-4): What the secret is about (subjects, life domains, themes)
- **Feelings** (0-3): Emotional tone and stance expressed
- **Meanings** (0-2): Insights, lessons, or purposes conveyed
- Stored as `_ps_topics`, `_ps_feelings`, `_ps_meanings` post meta (arrays)
- Enables multi-dimensional search and semantic clustering

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
1. User submits query + optional facet filters (topics, feelings, meanings)
2. Theme calls `SearchService->search()`
3. Service executes tokenized/stemmed MySQL full-text query with facet meta_query filters
4. Results sorted by relevance (default) or recency
5. Paginated results rendered via theme templates

**Admin Moderation:**
1. Moderator accesses queue via `ReviewRoute`
2. `ModerationService` fetches items by state (needs_review, low_confidence, flagged, published)
3. Moderator reviews item with confidence indicators and policy signals
4. Actions: approve/publish/unpublish/re-review/edit facets (with capability + nonce checks)
5. `LoggingService` records action (actor, target, before/after, timestamp, outcome) to append-only audit log
6. State transition committed; caches invalidated

**AI Classification Flow:**
1. Image uploaded via single-postcard uploader or backfill
2. `Classifier` sends image to OpenAI vision model with structured prompt
3. `SchemaGuard` normalizes response (facets, text extraction, moderation signals)
4. `Ingress` stores classification data as post meta
5. `EmbeddingService` generates semantic embedding from facets + text
6. Embedding stored in `ps_text_embeddings` for similarity search
7. `AttachmentSync` updates attachment alt text/caption with facets

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
- p95 search ≤ 600 ms (server processing for text + facet queries)
- Cold cache ≤ 1.2 s (triggers alert if sustained)
- Mobile time-to-first-useful-result ≤ 2.5 s (4G)
- Core Web Vitals: LCP/INP/CLS in "Good" ranges
- Uptime ≥ 99.9%; ≤ 0.25% 5xx error rate on public search endpoints
- Admin queue load p95 ≤ 400 ms; item open p95 ≤ 300 ms
- Similarity search p95 ≤ 900 ms for top-10 results

**Caching Strategy:**
- Object cache for query fragments
- Short-TTL page/query caches (vary by q|facets|sort|page)
- HTTP caching headers on public routes
- Cache busting on publish/unpublish and facet edits
- Image lazy-load + responsive srcset
- Embedding generation cached (only regenerated on re-classification)

## Testing

**Unit Tests:** Located in `wp-content/plugins/postsecret-admin/tests/`

**Test Bootstrap:** `tests/bootstrap.php` loads WordPress test environment

**Run single test:**
```bash
vendor/bin/phpunit --filter=test_name
```

## Roles & Capabilities

**Admin Role (MVP):**
- Full editorial control: review queues, approve/publish/unpublish, re-review, edit facets
- Settings: configure confidence thresholds, policy gates
- Audit logs: view/export all privileged actions

**Custom Capabilities (via `Caps` utility):**
- `ps.view_admin` - Access to PostSecret admin screens
- `ps.review.queue` - View triage queues & item details
- `ps.review.act` - Approve/reject/re-review items
- `ps.publish` - Publish/unpublish items
- `ps.facets.edit` - Edit facets (topics, feelings, meanings)
- `ps.logs.view` - View/export audit logs

**Access Control Principles:**
- Least privilege: assign minimal capabilities needed
- Separation of duties: publishing, facet editing, settings are distinct powers
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
1. Create new file in `migrations/` (e.g., `004_description.php`)
2. Implement `up()` function with SQL in `PostSecret\Admin\Migrations` namespace
3. Use `dbDelta()` for table creation/updates
4. Run via `wp-content/plugins/postsecret-admin/run-migrations.php`

**Adding a new service:**
1. Create class in `src/Services/`
2. Use namespace `PostSecret\Admin\Services`
3. Inject via constructor or use singleton pattern
4. Call from route handlers

**Facet operations:**
1. Use `ModerationService->update_facets()` to update topics/feelings/meanings
2. Facets stored as arrays in `_ps_topics`, `_ps_feelings`, `_ps_meanings` post meta
3. SearchService supports filtering by any facet type
4. Embeddings automatically regenerate on re-classification

**Handling bulk operations:**
1. Validate capability + nonce before processing
2. Preview affected item count before commit
3. For >100 items, require type-to-confirm from user
4. Process in batches; record progress
5. On partial failure: complete successful items, report failures with CSV export
6. Single audit entry referencing all target IDs

## Key Files Reference

**Admin Plugin:**
- Plugin entry: `wp-content/plugins/postsecret-admin/postsecret-admin.php`
- Search logic: `wp-content/plugins/postsecret-admin/src/Services/SearchService.php`
- Moderation flow: `wp-content/plugins/postsecret-admin/src/Services/ModerationService.php`
- Audit logging: `wp-content/plugins/postsecret-admin/src/Services/LoggingService.php`
- Database migrations: `wp-content/plugins/postsecret-admin/migrations/`
- WP-CLI commands: `wp-content/plugins/postsecret-admin/cli/class-ps-cli.php`

**AI Plugin:**
- Plugin entry: `wp-content/plugins/postsecret-ai/postsecret-ai.php`
- Prompt (v4.1.0): `wp-content/plugins/postsecret-ai/src/Prompt.php`
- Classification: `wp-content/plugins/postsecret-ai/src/Classifier.php`
- Schema validation: `wp-content/plugins/postsecret-ai/src/SchemaGuard.php`
- Embeddings: `wp-content/plugins/postsecret-ai/src/EmbeddingService.php`
- Metadata (colors/orientation): `wp-content/plugins/postsecret-ai/src/Metadata.php`
- Storage: `wp-content/plugins/postsecret-ai/src/Ingress.php`

**Theme:**
- Theme entry: `wp-content/themes/postsecret/functions.php`
- Secret card: `wp-content/themes/postsecret/parts/card.php`
- Single view: `wp-content/themes/postsecret/single-secret.php`

## Public UI Requirements

**Search Behavior:**
- Input: debounced 250-400ms; Enter submits immediately; Esc clears text focus
- Parsing: plain keywords, case-insensitive, ASCII folding (no boolean operators required at MVP)
- Scope: queries run against approved text fields (OCR/model text)
- Facets: multi-select filters for topics, feelings, meanings with counts; selected facets shown as removable chips
- Sorting: relevance (default), recency
- Pagination: server-side, deterministic ordering; 24 items/page (desktop), 12 (mobile)
- URL state: all search states (query + facets + sort + page) encoded in URL and shareable

**Result Cards:**
- Image thumbnail (aspect-aware, lazy-loaded) with alt text from descriptors
- Key facets (up to 3 chips combined from topics/feelings/meanings; overflow "+N")
- Text excerpt (first ~140 chars of approved text; ellipsis if truncated)
- Safe indicators (e.g., "Content advisory" icon if applicable)
- Click target: entire card opens detail view

**Detail View:**
- Large image with zoom/lightbox; alt text provided
- Facets organized by type (Topics, Feelings, Meanings)
- Art/font/media descriptors, orientation
- Approved extracted text; language label if non-English
- "Teaches Wisdom" indicator if applicable
- Postmark/ingest date (if public-safe), canonical link
- "Find similar" button (uses embedding similarity)

**Empty/Error States:**
- Zero results: guidance ("Try fewer facets", "Check spelling") + popular facets
- Partial results: non-blocking alert if facet fails; retry affordance
- Errors: friendly message + retry; no stack traces; status logged server-side

## Admin UI Requirements

**Queues:**
- Views: Needs Review, Low Confidence, Flagged, Published (read-only)
- Display: paginated tables/grids with thumbnail, key facets, confidence badge, review status, last action/actor, updated time
- Filters: facets (multi-select by type), status, confidence range slider, date range, text contains
- Sorting: updated time (default), confidence, recency
- Batch size: 25 per page (configurable)

**Item Detail Panel (AdminMetaBox):**
- Full image (zoom), approved text, language
- Facets displayed by type: Topics (blue), Feelings (amber), Meanings (green)
- "Teaches Wisdom" indicator if present
- Art/font/media descriptors, orientation, color palette
- Embedding status (dimension, model, generation timestamp)
- Signals: confidence (overall + by-field including facets), moderation labels, NSFW/self-harm flags
- Process now / Re-classify buttons
- History: last 5 actions (actor, timestamp, summary); link to full audit log

**Editorial Actions:**
- Single-item: Approve, Publish, Unpublish, Send to Re-review, Edit Facets, Edit Text (approved field), Add Note (internal)
- Re-classify: Force new AI classification (regenerates facets + embeddings)
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
- `README.md` - High-level project overview and architecture

## Semantic Similarity Search

**Implementation Status:** Core infrastructure complete, UI integration pending

**Architecture:**
- Embeddings generated automatically on classification via `EmbeddingService`
- Input: "Secret: [desc]. Topics: [t1,t2]. Feelings: [f1]. Meanings: [m1]. Text: [extracted]"
- Model: `text-embedding-3-small` (1536 dimensions, OpenAI)
- Storage: `ps_text_embeddings` table (secret_id, model_version, embedding JSON, dimension, timestamps)
- Similarity: Cosine distance in LAB color space for perceptual accuracy

**Methods:**
- `EmbeddingService::generate_and_store()` - Generate and store embedding
- `EmbeddingService::find_similar()` - Top-K similarity search with configurable threshold
- `EmbeddingService::get_stats()` - Embedding coverage statistics

**Future Scaling:**
- Current: In-memory cosine similarity (works for <10K Secrets)
- Phase 2: Export to vector DB (Qdrant, Weaviate, pgvector) for production scale
- Target: p95 ≤ 900 ms for top-10 similarity; ≥15% CTR on "Find similar" button
- Safety: only public-safe items are candidates; respects all policy gates

**Color Palette:**
- Perceptual distance filtering (Delta-E ≥ 20) prevents similar colors
- RGB → LAB conversion for human-perceived color differences
- Ensures palette diversity (no more #58af67, #58af69, #5aae67)