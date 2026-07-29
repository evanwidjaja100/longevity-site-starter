# Longevity Evidence Lab — Agent Harness

WordPress-based evidence-led consumer health publication. Custom MU plugin (`longevity-core`) for editorial governance; block theme (`longevity-starter`) for presentation. Production is managed-host only (ADR 0015); Docker Compose is for local/CI verification only.

## Quick start

```bash
cp .env.example .env
# Replace every placeholder with a unique local value.
./scripts/validate-env.sh .env
docker compose config --quiet
make up
make bootstrap
make smoke
```

Site is at `http://localhost:8080` (or `WP_SITE_URL` from `.env`).

## Key directories

| Path | Purpose |
|---|---|
| `wp-content/mu-plugins/longevity-core/` | MU plugin — editorial governance, evidence traceability, product-testing controls, analytics, schema. Entry: `wp-content/mu-plugins/longevity-core.php` → `bootstrap.php` → `Bootstrap::init()` |
| `wp-content/themes/longevity-starter/` | Block theme — templates, patterns, `functions.php`, `style.css`, `theme.json` |
| `scripts/` | Validation, bootstrap, CI, release, manifest scripts |
| `tests/php/` | PHPUnit tests + dependency-free fallback (`run-unit-tests.php`) |
| `tests/e2e/` | Playwright specs + visual snapshots |
| `tests/integration/` | Shell-based integration contracts |
| `content/` | Editorial templates, backlog, source content |
| `docs/` | Architecture, editorial, operations, testing docs |
| `config/` | Schema, scoring, environment config |

## Commands

### All-in-one (via Make)

```bash
make validate       # syntax, content, links, freshness, manifest, env
make lint           # validation + Composer/PHPCS/PHPStan + npm lint
make test           # PHPUnit + content validation
make test-e2e       # Playwright (requires running site)
make test-a11y      # axe-core accessibility
make test-all       # everything
make deploy-check   # validate + docker-config + security tests
make manifest       # regenerate MANIFEST.sha256
make release-artifact SHA=<full-commit-sha>
make verify-release-artifact SHA=<full-commit-sha>
```

### Direct tool commands

```bash
# PHP quality
composer phpcs        # WordPress Coding Standards
composer phpstan      # level 5
composer test         # discovery + PHPUnit + routes test
composer test:fallback  # dependency-free PHP unit fallback

# Frontend quality
npm ci
npm run lint          # stylelint + eslint

# Content validation (Python, no Docker needed)
python3 scripts/validate-content.py
python3 scripts/validate-internal-links.py
python3 scripts/validate-freshness.py

# WP-CLI (always via docker compose, never docker exec)
docker compose run --rm wpcli wp longevity bootstrap all
docker compose run --rm wpcli wp longevity claims validate path/to/claims.csv
docker compose run --rm wpcli wp longevity readiness 123
docker compose run --rm wpcli wp --help
```

### WP-CLI command namespaces

- `wp longevity claims` — validate/import/export CSV claims
- `wp longevity sources` — validate/export CSV sources
- `wp longevity readiness` — check publication gates for a post
- `wp longevity freshness` — run freshness audit
- `wp longevity bootstrap` — pages, categories, content (idempotent)
- `wp longevity migrate` — schema migrations
- `wp longevity evidence` — export evidence
- `wp longevity metrics` — metrics dump
- `wp longevity preflight` — pre-deployment checks
- `wp longevity legal-hold` — legal hold management
- `wp longevity acceptance` — acceptance testing

## Architecture

### PHP namespace

All MU plugin code lives in `Longevity\Core` namespace. Use `use` imports in new files.

### Routes class (`class-routes.php`)

Central registry for canonical page routes and category terms. All hardcoded route URLs in PHP pattern files must use `Routes::public_page_url()`. Page definitions include: `home`, `start_here`, `guides`, `topics`, `reviews`, `evidence_methodology`, `testing_methodology`, `editorial_policy`, `corrections`, `affiliate_disclosure`, `medical_disclaimer`, `about`, `contact`, `privacy`, `terms`, `ai_assist_disclosure`, `source_registry`.

Categories: `evidence`, `sleep`, `movement`, `nutrition`, `wearables`, `supplements`, `consumer_lab` (with legacy slug fallbacks).

### Public_Components facade

The old `Public_Components` god class was split into domain classes. New code should use:
- `Longevity\Core\Public_Nav`
- `Longevity\Core\Public_Contact`
- `Longevity\Core\Public_Content`
- `Longevity\Core\Public_Trust`
- `Longevity\Core\Public_Rankings`

### Bootstrap

`Bootstrap::init()` in `bootstrap.php` registers all services. Key hooks: `wp_robots` (noindex drafts/placeholders), `send_headers` (security headers + CSP), `render_block_core/navigation-link` (suppress links to non-public routes).

### Content states

Public content follows states: idea → research → drafting → editorial review → fact-check → medical review → testing → commercial review → publication → update → correction → archive. The readiness engine (`Publication_Gates`) returns blocking failures, warnings, passed, and N/A checks.

### Metadata authorization

`Meta_Authorization` guards `add_post_metadata`, `update_post_metadata`, `delete_post_metadata` filters. Metadata writes require explicit authorization.

## Code standards

- **PHP**: WordPress Coding Standards (`phpcs.xml.dist`), PHPStan level 5 (`phpstan.neon.dist`). PHP 8.3+ (matching `Platform_Requirements::MIN_PHP` and CI).
- **JS**: ESLint (`eslint.config.js`). ES modules (`"type": "module"` in `package.json`).
- **CSS**: Stylelint (`.stylelintrc.json`).
- **Shell**: ShellCheck. Scripts use `#!/bin/sh` or `#!/bin/bash`.
- **Accessibility**: WCAG 2.1 AA (enforced via axe-core in CI).
- **PHPStan** includes WordPress stubs via `szepeviktor/phpstan-wordpress`. WordPress function LSP errors are expected on host (stubs unavailable).

## Critical constraints

### AI governance

- AI may **not** independently verify medical claims, approve safety language, or invent citations.
- AI may **not** assign evidence grades without human review.
- AI may **not** publish content or approve disclosure status.
- AI may **not** interpret private health information.
- Any AI-assisted output affecting a material claim must be checked against the source registry by a named human.
- Claims and sources are **private** WordPress records. Never publish raw source articles.

### Security

- `.env` is never committed. `.gitignore` excludes `.env`, `vendor/`, `node_modules/`, `reports/`, `build/`, `*.sql`, `*.tgz`, `*.tar.gz`.
- No tracked database dumps or archives.
- No tracked secrets — `security.yml` scans for private keys, GitHub tokens, AWS keys.
- No unsafe executable permissions (no files with world/group write bits).
- Plugin/theme allowlist enforced in `validate.sh` — only `index.php` in plugins, only `index.php` and `longevity-starter` in themes.
- Placeholder credentials (`change-me-use-`, `example.com`, etc.) are rejected in tracked runtime files.

### Production

- Managed WordPress hosting only (ADR 0015). Docker Compose is not a production topology.
- Production requires HTTPS, external secrets, least-privilege accounts, MFA, off-site encrypted backups, SMTP/DNS auth, WAF/rate limiting, current dependencies, monitoring, real cron.
- CSP is report-only by default; flip to enforce via `LEL_CSP_MODE=enforce`.

## Testing

### PHP (unit)

```bash
composer test           # full suite with PHPUnit
composer test:fallback  # dependency-free PHP fallback
php tests/php/RoutesTest.php  # standalone routes test
```

Test files in `tests/php/` use PHPUnit 10. Bootstrap at `tests/php/bootstrap.php`. Stubs in `tests/php/stubs/`.

### Content validation (Python, no Docker)

```bash
python3 scripts/validate-content.py
python3 scripts/validate-internal-links.py
python3 scripts/validate-freshness.py
```

### E2E (Playwright, requires running site)

```bash
make up && make bootstrap
npm ci
npm run test:e2e          # core functional suite
npm run test:e2e:all      # all specs including visual/cross-browser
npm run test:a11y         # accessibility
```

E2E tests use `WP_SITE_URL` env var (defaults to `http://localhost:8080`). Visual snapshots are in `tests/e2e/snapshots/` and are platform-specific.

### Integration (shell, requires running site)

```bash
make test-integration
```

Scripts in `tests/integration/*.sh`.

### Coverage

CI enforces a minimum coverage threshold (default 30%). Coverage is measured via PCOV in CI.

## Manifest

`MANIFEST.sha256` tracks SHA-256 hashes of all tracked files. After intentional changes, regenerate with `make manifest` or `bash scripts/regenerate-manifest.sh`. CI verifies the manifest matches tracked files.

## Environment variables

`.env.example` documents all local variables. CI uses `.env.ci.template`. Production uses `config/environments/production.example.php`.

Key variables: `WORDPRESS_PORT`, `WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER`, `WORDPRESS_DB_PASSWORD`, `WORDPRESS_DB_ROOT_PASSWORD` (local/CI Docker only — forbidden in managed staging/production; see `docs/operations/database-privileges.md`), `WP_SITE_URL`, `WP_SITE_TITLE`, `WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`, `WP_ADMIN_EMAIL`, `WP_ENVIRONMENT_TYPE`, `WP_DEBUG`, `FORCE_SSL_ADMIN`, `DISALLOW_FILE_MODS`, `INSTALL_OPTIONAL_PLUGINS`.

## Docker

`compose.yaml` defines: `db` (MySQL 8.0), `wordpress` (Apache), `wpcli`, `wpcron` (profile `cron`), `test-runner` (profile `test`). Services use `no-new-privileges:true`. Volumes: `db_data`, `wordpress_data`.

- `make up` / `make down` — start/stop
- `make bootstrap` — runs `docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh`
- `make smoke` — runs `scripts/smoke-test.sh`
- `make ci-setup` — runs `scripts/ci-setup.sh`

## Release artifact

```bash
make release-artifact SHA=<full-commit-sha>
make verify-release-artifact SHA=<full-commit-sha>
```

Builds a deterministic first-party package from Git. CI also builds and verifies reproducibility.

## Key documentation

- Editorial workflow: `docs/editorial/editorial-workflow.md`
- Medical review: `docs/editorial/medical-review.md`
- Claim management: `docs/editorial/claim-management.md`
- Product testing: `docs/editorial/product-testing.md`
- Corrections: `docs/editorial/corrections.md`
- Content model: `docs/architecture/content-model.md`
- System overview: `docs/architecture/system-overview.md`
- Permissions: `docs/architecture/permissions.md`
- Test strategy: `docs/testing/test-strategy.md`
- ADRs: `docs/adr/`
- Operations: `docs/operations/`
- Editorial policy: `content/editorial-policy.md`
- AI governance: `content/governance/ai-assisted-work-policy.md`

## Production readiness status

Production launch remains **NO-GO** until human/external gates pass: staging, backups, approvals, credential rotation, branch protection, screen-reader sign-off. See `docs/testing/production-readiness-v2-baseline.md` and `docs/testing/production-readiness-v2-implementation-report.md`.

## Common mistakes to avoid

- Do not run WP-CLI via `docker exec` — use `docker compose run --rm wpcli wp ...`
- Do not commit `.env` or database dumps
- Do not add third-party plugins or themes (allowlist enforced)
- Do not hardcode route URLs — use `Routes::public_page_url()`
- Do not assign evidence grades or verify medical claims
- Do not publish content or approve disclosure status
- Regenerate `MANIFEST.sha256` after intentional file changes
- WordPress function LSP errors on host are expected (stubs unavailable)
