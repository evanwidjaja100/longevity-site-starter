# Production Readiness v2 — Evidence Reconciliation and Go/No-Go Record

Status date: 2026-07-27
Branch: `improvement/production-readiness-v2`
Decision: **NO-GO** for public production launch until every human/external gate below has a named owner, dated evidence, and release-specific approval.

## 1. Implementation state by PR (babaooey_2.md plan)

| PR | Scope | State | Commit |
|---:|---|---|---|
| 00 | Plan baseline, source reconciliation, manifest | Done | 202f3ca |
| 01 | Metadata authorization at persistence layer (F01) | Done | 738f8a4 |
| 02 | Fail-closed mandatory audit events (F02) | Done | 8bc6908 |
| 03 | Atomic migrations, schema postconditions (F03, F18) | Done | 9951072 |
| 04 | Synchronous invalidation, protocol binding (F04, F05) | Done | debd627 |
| 05 | Score/model/protocol binding (F05) | Done | 9a03fd2 |
| 06 | Claim source validation, editor separation (F06) | Done | cc205bc |
| 07 | Protected correction transitions, propagation (F07) | Done | 9a42d91 |
| 08 | Single public-state projection (F08) | Done | e5d73a1 |
| 09 | Shared public projection surfaces | Done | e5d73a1 |
| 10 | DB-only contact limiter, feedback, retention (F10) | Done | 6442eda |
| 11 | Trust-page approval snapshots, seed protection (F17) | Done | 4928d69 |
| 12 | Accessibility, SEO, analytics, CSP fixes (F16) | Done (code) | fbf2b09 |
| 13 | CI truth, release artifact (F12, F13) | Done (code) | 38d726f |
| 14 | Managed staging deployment, restore drill, monitoring, runbooks | Runbooks done; execution is a human/external gate | this commit |
| 15 | Final evidence reconciliation and go/no-go | This document | this commit |

## 2. Evidence verified in this environment

- PHPUnit: 129 tests, 485 assertions, 0 failures (dependency-free fallback runner also green).
- Critical suite discovery: 14 required suites verified discoverable by `scripts/verify-test-discovery.php` (≥100 test floor).
- Lint: ESLint 10 across all runtime JS, test JS, and configs — clean. Stylelint on theme CSS — clean.
- npm audit: 0 vulnerabilities after eslint 10 upgrade and `chrome-launcher` override; `npx lhci --version` still functional.
- Manifest: `scripts/verify-manifest.sh` passes against HEAD (498 entries, hashes match index bytes).
- Release artifact: `scripts/build-release-artifact.sh` builds a first-party-only artifact (mu-plugin loader + `longevity-core/` + `longevity-starter/`, 123 files) with per-file manifest, artifact checksum, and source SHA.
- Redis and the VPS path are retired: no `redis` service, `WP_REDIS_*` defines, or `object-cache.php`; `docs/operations/vps-deployment.md` removed; managed host is the single production target.
- CSP ships report-only by default with an enforcement toggle; `style-src-attr 'unsafe-inline'` accommodates WordPress inline style attributes; schema JSON-LD is nonce-bound.

## 3. Evidence NOT obtainable in this environment (requires CI/staging)

- Docker-based integration contracts, Playwright (functional, accessibility, cross-browser, visual), Lighthouse: no Docker/browsers on this host. The CI lanes are defined and must pass on the candidate SHA in GitHub Actions.
- Linux visual baselines: must be generated in the pinned Playwright image and human-reviewed before the visual lane is a required gate.
- PHPCS full run: local PHP 8.5 crashes the PHPCompatibility sniff (environmental); CI pins PHP 8.3.
- Pre-existing PHPStan findings (~51 project-wide, none introduced by PR 10–15 changes) remain to be triaged; new code is PHPStan-clean.
- Real browser zoom, forced colors, screen reader (VoiceOver/NVDA/TalkBack) checks: staging + human testers.

## 4. Outstanding human and external gates (owners must be named before launch)

| Gate | Owner (role — name TBD) |
|---|---|
| Select/contract managed WordPress provider | Technical release owner |
| Rotate historical `longevity_app` MySQL credential; purge dump from Git history | Technical release owner |
| Branch protection on `main`; required CI checks | Technical release owner |
| Private staging deployment of the exact release artifact; smoke + rollback drill | Technical release owner |
| Provider DB+media backup and quarterly restore drill evidence | Operations owner |
| Monitoring, cron heartbeat, contact-delivery and error alerts to a named operator | Operations owner |
| SMTP/SPF/DKIM/DMARC, WAF, MFA, HTTPS/HSTS, CSP enforcement flip after clean report-only window | Operations owner |
| Privacy, Terms, Affiliate Disclosure, Medical Disclaimer, contact retention approval | Privacy/legal owner |
| Jurisdiction and legal entity/controller confirmation | Privacy/legal owner |
| Reviewer credential verification | Editorial owner |
| Claim/source verification, evidence grades, methodology approval | Editorial + medical owners |
| Real product testing under registered protocols | Testing owner |
| Trust pages and launch content publication (after approvals) | Editorial owner |
| Screen-reader and manual accessibility sign-off | Accessibility reviewer |
| Final signed go/no-go record for a specific artifact checksum | All owners above |

## 5. Go/No-Go rule

GO requires, for one specific source SHA and artifact checksum: all required CI jobs green, staging exercised with that exact artifact, all Section 4 gates evidenced and signed, and no unresolved high/critical finding without a signed exception. Anything less is NO-GO.
