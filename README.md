# Longevity Evidence Lab

A production-oriented WordPress foundation for an evidence-led consumer health and product-testing publication.

> Longevity Evidence Lab helps adults evaluate health practices, consumer devices, supplements, and wellness claims using transparent evidence reviews, reproducible testing methods, and clearly stated uncertainty.

This repository preserves WordPress as the CMS, a custom block theme as the presentation layer, and first-party MU-plugin services as the editorial control plane. It does not generate health claims, approve medical review, or invent product testing.

## What is included

- Modular `longevity-core` MU plugin with explicit editorial metadata, roles and capabilities, publication gates, audit events, private claim/source/protocol/test/correction/affiliate registries, medical-review attestation, versioned scoring, conservative schema, privacy-aware analytics, REST health/readiness endpoints, and WP-CLI import/export commands.
- Accessible `longevity-starter` block theme with post and review discovery, trust summaries, reviewer scope, limitations, disclosures, testing methods, correction history, responsive tables, keyboard focus, reduced-motion behavior, and print styles.
- Trust-first launch calendar, internal-link map, editorial templates, evidence and AI governance, and category-specific testing protocols.
- Docker development stack, environment validation, bootstrap, smoke, backup/restore examples, and portability guidance for staging, managed WordPress, and VPS hosting.
- CI, security and scheduled freshness workflows; PHP, front-end, content, integration and browser-test scaffolding; manifest integrity and static validation.

## Requirements

For the complete local workflow:

- Docker Engine with the Docker Compose plugin
- GNU Make
- PHP 8.1 or later
- Python 3.10 or later
- Composer 2
- Node.js 20 or later and npm

Static validation can run without Docker. A dependency-free PHP unit fallback is included for environments without Composer.

## Local installation

```bash
cp .env.example .env
# Replace every placeholder with a unique local value.
./scripts/validate-env.sh .env
docker compose config --quiet
make up
make bootstrap
make smoke
```

Open the URL configured by `WP_SITE_URL`. Bootstrap is designed for a new local or staging site. It creates policy and methodology pages as drafts, keeps search visibility disabled, and does not manufacture reviewer accounts, credentials, claims, products, measurements, or citations.

## Quality commands

```bash
make validate       # syntax, content, links, freshness, placeholders, manifest
make lint           # validation plus Composer/npm lint when installed
make test           # PHP fallback/PHPUnit plus content tests
make test-e2e       # Playwright against a running site
make docker-config  # Docker Compose interpolation and schema check
make manifest       # regenerate MANIFEST.sha256 after intentional changes
```

Install development dependencies with:

```bash
composer install
npm ci
```

CI runs Composer validation, WordPress Coding Standards, PHPStan, PHPUnit, ShellCheck, content validators, npm lint, Docker configuration, WordPress bootstrap, and smoke checks. Browser tests require a running test site and installed Playwright browser binaries.

## Editorial workflow

Public content uses explicit states from idea through research, drafting, editorial review, fact-check, scoped medical review, testing, commercial review, publication, update, correction, and archive. The readiness engine returns blocking failures, warnings, passed checks, and non-applicable checks.

Critical failures prevent publication while preserving the draft. Examples include incomplete required medical review, unsupported hands-on claims, missing commercial disclosure, affiliate links without an approved relationship, review scores without a methodology version, stale price claims, placeholders, or absent limitations. Emergency overrides require a dedicated capability and written reason and are audited.

See:

- `docs/editorial/editorial-workflow.md`
- `docs/editorial/medical-review.md`
- `docs/editorial/claim-management.md`
- `docs/editorial/product-testing.md`
- `docs/editorial/corrections.md`

## Claims and sources

Claims and sources are private WordPress records. The CLI supports validated, stable-ID CSV exchange:

```bash
wp longevity claims validate path/to/claims.csv
wp longevity claims import path/to/claims.csv --dry-run
wp longevity claims export path/to/claims.csv
wp longevity sources validate path/to/sources.csv
wp longevity sources export path/to/sources.csv
wp longevity readiness 123
```

Imports report row-level errors, reject duplicate IDs and unsafe data, and do not silently overwrite. Exports mitigate spreadsheet formula injection. Store metadata and editorial notes, not unauthorized copies of source articles.

## Safe shortcodes

Legacy shortcodes remain supported:

```text
[medical_disclaimer]
[review_box score="4.2" best_for="Sleep trend tracking" tested="21 days"]
```

Affiliate links must reference a domain approved in the affiliate registry and an article with completed disclosure state:

```text
[affiliate_link url="https://approved-merchant.invalid/product" label="Check current price"]
```

The renderer adds sponsored, nofollow and noopener relationship attributes. It does not approve a merchant or disclosure automatically.

## Production deployment

Docker Compose is intended for development, staging, and as a transparent VPS reference—not as a turnkey production platform. Production requires HTTPS, external secrets, least-privilege accounts, MFA where available, off-site encrypted backups, tested restoration, SMTP/DNS authentication, WAF/rate limiting, current dependencies, central logs, uptime/error monitoring, and a real cron strategy.

Managed hosts must support MU plugins and the required custom capabilities and private post types. Critical editorial data remains independent of the theme. Review the deployment and security documents under `docs/operations/` before launch.

## Verification status

The generated repository records exact local results and unavailable checks in:

- `docs/baseline-audit.md`
- `docs/implementation-status.md`
- `docs/final-implementation-report.md`

Docker, full WordPress integration, Composer-based PHPCS/PHPStan/PHPUnit, and browser accessibility checks must pass in a Docker- and network-enabled staging or CI environment before production. They are not represented as locally successful when the required infrastructure is unavailable.

## Safety and scope

This platform publishes general educational information. It must not present individualized medical advice, diagnosis, guaranteed longevity outcomes, disease treatment or prevention, unsupported dosage recommendations, fabricated testing, or popular “biohacks” as proven. Human review, transparent uncertainty, claim-level traceability, commercial independence, and visible corrections are release requirements—not optional decoration.
