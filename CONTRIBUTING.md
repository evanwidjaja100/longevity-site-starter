# Contributing to Longevity Evidence Lab

Thank you for your interest in contributing. This document covers the development workflow.

## Prerequisites

- Docker Engine with Docker Compose plugin
- GNU Make
- PHP 8.3 or later
- Python 3.10 or later
- Composer 2
- Node.js 22 or later and npm

## Local setup

```bash
cp .env.example .env
# Replace every placeholder with a unique local value.
bash scripts/validate-env.sh .env
docker compose config --quiet
make up
make bootstrap
make smoke
```

## Development workflow

```bash
make validate       # syntax, content, links, freshness, placeholders, manifest
make quality        # Composer/npm lint, PHPCS, PHPStan, ESLint, Stylelint
make test           # PHP unit tests + content tests
make test:e2e       # Playwright browser tests against a running site
make test:a11y      # axe-core accessibility tests
make test:all       # all test suites
```

## Code standards

- **PHP**: WordPress Coding Standards (`composer phpcs`), PHPStan level 5 (`composer phpstan`)
- **JS**: ESLint (`npm run lint:js`)
- **CSS**: Stylelint (`npm run lint:css`)
- **Shell**: ShellCheck (`shellcheck scripts/*.sh`)
- **Accessibility**: WCAG 2.1 AA (enforced via axe-core in CI)

## Architecture notes

- WordPress serves as the CMS; the `longevity-core` MU plugin provides the editorial control plane.
- The `longevity-starter` block theme provides presentation.
- All editorial metadata, approval snapshots, and audit events are private.
- The REST API exposes only a minimal public projection of published content.

## Pull requests

1. Ensure all tests pass: `make test-all`
2. Ensure CI is green
3. Include a clear description of changes
4. For editorial or medical content changes, note whether human review is required

## Reporting issues

Use the issue tracker for bugs, feature requests, and editorial process questions. For medical safety issues, follow the incident response process in `docs/operations/incident-response.md`.
