# PostSecret — WordPress-Native Archive, Search & Moderation

Fast, accessible browsing of the entire PostSecret collection, built on WordPress and MySQL. Public users get full-text search and tag filters; moderators get review queues, publishing controls, taxonomy tools, and an audit trail—privacy and safety first.

---

## Why this exists

The current site is mostly chronological; it limits discovery and burdens editorial work. Consolidating public discovery, moderation, and data into a single WordPress stack improves UX, speeds operations, and reduces risk and ongoing complexity.

---

## What’s in the MVP

**Public archive (frontend)**

* Mobile-first browse & result views; stateful, shareable URLs
* Full-text search over approved text + multi-select tag facets
* Accessible detail pages (image, descriptors, extracted text, metadata)
* Deterministic pagination / “load more”
* WCAG 2.2 AA and i18n-ready templates

**Admin (editorial & moderation)**

* Queues: Needs Review, Low Confidence, Flagged, Published
* Item panel with signals (confidence, labels), approve/publish/unpublish
* Basic bulk actions with guardrails, taxonomy merge/alias, audit logging
* Policy thresholds and safe defaults for sensitive content

**Performance & quality targets**

* p95 search ≤ 600 ms; mobile time-to-first-useful-result ≤ 2.5 s
* CWV “Good” on archive & detail pages
* Public search uptime ≥ 99.9%; ≤ 0.25% 5xx errors

> Phase 2 adds Similarity Search (“find similar”) without re-platforming.

---

## Project principles

Product-first, single-stack, accessible by default, privacy-preserving, and future-ready (schema and UI accept a similarity module later without upheaval).

---

## Architecture (high level)

* **Single WordPress instance** serves public and admin.
* **Custom theme & blocks** power archive, search, detail.
* **Custom plugin** provides queues, taxonomy governance, audit logs, settings, and backfill/ingest jobs.
* **MySQL** holds the canonical Secret record (image, approved text, tags, descriptors, moderation state, confidences, provenance) with proper text+tag indexes.

Key flows: public search (text+tags), admin review & publish, and historical backfill (resumable, checkpointed).

---

## Repository layout

```
/theme/                # WordPress theme for public archive/search/detail
/plugins/              # Admin plugin (queues, taxonomy, audit, settings, backfill)
/docs/                 # Architecture notes, PRD, runbooks
/tools/                # WP-CLI scripts, backfill helpers (optional)
/ci/                   # Lint, tests, accessibility/i18n hooks
```

*Exact names may vary—see ./docs for the authoritative map.*

---

## Getting started (local)

**Prereqs**

* WordPress 6.6+
* PHP 8.2+ and MySQL 8.x (or MariaDB with comparable features)
* WP-CLI recommended

**Install**

1. Create a local WordPress site (any preferred method or container).
2. Copy `/theme/` into `wp-content/themes/postsecret` and activate **PostSecret**.
3. Copy the relevant plugin directory from `/plugins/` into `wp-content/plugins/` (see `./docs` for the correct directory name), and activate the plugin in WordPress.
4. Run plugin/theme **migrations** (via WP-CLI command exposed by the plugin, if provided) to create custom tables and indexes.
5. In **Settings → PostSecret**, confirm policy thresholds and defaults (NSFW/self-harm gates, confidence levels).

**Seed/backfill (pilot)**

* Use WP-CLI/Admin Backfill UI to import a small batch; jobs are resumable, checkpointed, and idempotent with progress reporting and quarantine for hard failures. Start small, watch p95 search latency, then scale throughput.

---

## How search works (MVP)

* Tokenized/stemmed full-text over approved fields + inclusive multi-select tags
* Sort by relevance (default) or recency; deterministic paging
* “Zero results” and partial-failure states are user-friendly; server logs capture details
* Soft rate limiting and server-side policy gates enforce public-safe results

---

## Accessibility & i18n

All public and admin UIs target **WCAG 2.2 AA** with keyboard-complete flows, visible focus, and correct roles/labels. All strings are translatable; RTL layouts are respected. Accessibility audits are part of CI.

---

## Safety & privacy

* Public surfaces show only content that meets policy; sensitive items are gated or excluded
* No PII displayed publicly; server-side enforcement and conservative defaults
* All privileged actions require capability checks and nonces; every action is logged (actor, target, timestamp, outcome)

---

## Roadmap (summary)

* **M1** Foundations (repo, envs, CI, scaffolds)
* **M2** Data model & migrations (canonical record, audit log, indices)
* **M3** Public search MVP (archive, search, results/detail, a11y)
* **M4** Admin review & moderation (queues, actions, taxonomy, logging)
* **M5** Backfill pilot (10k) with SLO guardrails
* **M6** Full historical backfill (~1M)
* **M7** Launch prep & cutover
* **GA** Public launch
* **M8** Post-launch & similarity groundwork

---

## Performance & reliability

* Query hygiene, right indexes, short-TTL result caching
* Backfill throttles automatically if public p95 search breaches the budget
* Uptime/error-rate monitors and dashboards with meaningful alerts

---

## Contributing

We welcome issues and pull requests that improve accessibility, performance, search quality, moderation UX, and documentation.

1. Fork the repo; create a feature branch.
2. Follow coding standards (PHPCS/WPCS), add tests, and update docs.
3. Ensure CI is green (lint, tests, accessibility/i18n checks).
4. Open a PR with a clear description, screenshots (when UI), and risk notes.

Please also read:

* **Moderation & taxonomy guidelines** (./docs/guides)
* **Backfill runbook** (./docs/runbooks)

---

## Code of Conduct

Be kind, be curious, be privacy-minded. We follow a standard community Code of Conduct; see **CODE_OF_CONDUCT.md**.

---

## Security & responsible disclosure

Report vulnerabilities privately via **SECURITY.md**. Do not open public issues for security problems. Access controls, nonces, sanitization, and server-side policy gates are core to this project; thank you for helping us keep them strong.

---

## License

Open source license in **LICENSE** (per repo). Contributions are accepted under the same license.

---

## Acknowledgements

PostSecret community and contributors; all volunteers helping make the archive more discoverable, safer, and more humane.
