# System Overview

## Purpose
Longevity Evidence Lab is a WordPress-based evidence and consumer-testing publication. WordPress remains the editorial system of record; the block theme presents reader-facing trust information; and first-party MU-plugin services enforce governance independently of the active theme.

## Runtime components
- **WordPress:** posts, pages, users, revisions, media, REST API, and editorial permissions.
- **Longevity Core MU plugin:** content types, metadata, roles, publication gates, claim and source registries, medical review, test protocols, scoring, corrections, affiliate controls, schema, analytics, REST health checks, and WP-CLI utilities.
- **Longevity Starter block theme:** accessible templates and trust components. It contains no authoritative editorial state.
- **Structured repository content:** launch calendar, briefs, protocols, governance documents, and operational checklists.
- **Docker development stack:** MySQL, WordPress, and WP-CLI. Production deployment can use a managed host or a hardened VPS without changing the content model.

## Trust boundary
Only authenticated users with explicit capabilities may complete review states, change disclosures, approve tests, override gates, or publish. Public templates read approved state; they do not infer completion. No code fabricates reviewers, credentials, citations, measurements, or testing periods.

## Data flow
1. A writer drafts content and records scope, limitations, disclosures, and review dates.
2. Claims and sources are recorded in private registries.
3. Assigned specialists complete fact-check, scoped medical review, or test records.
4. The readiness service returns blocking failures, warnings, and passes.
5. Publication is allowed only when blocking requirements are met or a specially authorized, reasoned override is recorded.
6. Public templates and conservative JSON-LD expose only substantiated, non-empty data.

## Portability
Critical data is stored through WordPress APIs in posts, users, and post metadata. The theme can be replaced without losing governance records. The MU plugin can be copied to managed WordPress hosts that permit MU plugins. Host-specific caching, WAF, SMTP, backups, and observability remain external adapters.
