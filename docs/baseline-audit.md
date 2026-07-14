# Baseline Audit

Audit date: 2026-07-14

## Current architecture

The baseline is a local-first WordPress package with MySQL, WordPress, and WP-CLI services in Docker Compose. A custom block theme provides the presentation layer, while a single MU-plugin file registers a public `review` post type, six post-meta fields, three shortcodes, basic JSON-LD, security headers, and a public health endpoint.

## Existing features

- Docker Compose development stack using WordPress/PHP and MySQL images.
- Idempotent WP-CLI bootstrap for the site, categories, policy pages, and homepage.
- Custom block theme with front page, archive, index, page, and single templates.
- Review post type and shortcodes for affiliate links, medical disclaimer, and review summary.
- Sixty-item editorial calendar, eight briefs, policy drafts, and operating checklists.
- Basic static validation for PHP, JSON, shell syntax, and calendar ordering.

## Technical weaknesses

- The MU plugin is monolithic and has no service boundaries or test seams.
- Metadata lacks explicit defaults, field descriptions, conditional requirements, and post-specific authorization.
- No CI, Composer quality tooling, PHPUnit configuration, front-end linting, or end-to-end tests.
- The baseline manifest is static and is not validated against the working tree.
- Docker configuration does not expose all WordPress environment controls documented in the implementation plan.

## Editorial-system weaknesses

- No publication-readiness engine, workflow audit trail, role-specific capabilities, or authenticated medical-review attestation.
- Claim and source records exist only as an empty CSV header.
- Hands-on test records, protocol versions, scoring rationale, corrections, and affiliate registry are absent.
- Existing internal-link guidance includes self-links and generic repeated recommendations.

## Security risks

- Placeholder credentials can be copied without validation.
- Generic `edit_posts` authorization is used for every metadata field.
- No automated dependency, secret, or tracked-environment-file checks.
- The health endpoint has no configurable detail level or cache control.

## Accessibility risks

- No skip link or explicit focus treatment.
- Minimal navigation and footer structure.
- No automated WCAG checks, accessible table styles, reduced-motion treatment, or tested mobile navigation behavior.

## SEO and schema risks

- Schema is emitted as a single object rather than a graph and uses only constant checks for two SEO plugins.
- Review-specific schema, breadcrumbs, reviewer scope, citations, and entity consistency are absent.
- Theme archives and homepage queries omit review content in several paths.

## Testing gaps

- No unit, integration, accessibility, browser, or smoke tests.
- No validation for YAML, duplicate slugs, dates, internal links, content briefs, policy placeholders, or credentials.

## Deployment limitations

- Local validation cannot prove production behavior.
- Backups, restore drills, SMTP, WAF/CDN, object caching, cron, TLS, and observability require hosting-layer integration.
- No migration or rollback runbook exists in the baseline.

## Assumptions

- WordPress remains the canonical editorial system.
- First-party code must remain portable to managed WordPress hosting.
- Health, credential, testing, pricing, and citation data must never be invented.
- External services are represented by documented adapters or configuration placeholders only.

## Baseline commands executed

| Command | Result |
|---|---|
| `sh scripts/validate.sh` | Passed. PHP syntax, theme JSON, calendar row count/order, and shell syntax were valid. |
| `php -v` | Available: PHP 8.4.16 CLI. |
| `git status` | Not available before initialization because the reconstructed package was not a Git repository. |

## Commands not executed

| Command | Reason |
|---|---|
| `docker --version` / `docker compose config` | Docker is not installed in the execution environment. |
| `composer install` / Composer scripts | Composer is not installed in the execution environment. |
| Browser and WordPress integration tests | A running WordPress stack and installed npm dependencies are unavailable locally. |

These unavailable checks are configured in CI and documented as production prerequisites; they are not reported as locally passing.
