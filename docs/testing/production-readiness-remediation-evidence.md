# Production Readiness Remediation — Evidence Record and Go/No-Go Preparation

Status date: 2026-07-29
Branch: `improvement/production-readiness-v2`
Plan: `longevity-production-readiness-remediation-plan.md` (tickets PR-00…PR-13, OPS-01/OPS-02)
Decision: **NO-GO** for public production launch. This record prepares evidence
and templates only. Human and external gates (PR-13, OPS-01 sign-off, OPS-02)
must be completed and signed by named humans; they must never be marked
complete by AI or automation.

## 1. Implementation state by ticket

| Ticket | Scope | State | Commit |
|---|---|---|---|
| PR-00 | Authoritative manifest reconciliation | Done | `1b27b70` |
| OPS-01 | Historical credential-exposure incident record + operator checklist | Prepared (operator execution gate OPEN) | `d9523a0` |
| PR-01 | Supported WordPress/PHP security baseline | Done | `0b998bf` |
| PR-02 | Approval activation gated on durable mandatory-audit linkage | Done | `f14ad04` |
| PR-03 | Reviewer credential lifecycle with full-field cascade and scheduled expiration | Done | `0972ebd` |
| PR-04 | Fail-closed ranking cache with live eligibility revalidation | Done | `a676c64` |
| PR-05 | Rankings population completeness, filter/sort before limit, fail-closed ceiling | Done | `a8dfb29` |
| PR-06 | Operational meta-count queries fail closed on DB errors | Done | `d23b5e3` |
| PR-07 | Authoritative per-record contact retention deadlines | Done | `61c54ee` |
| PR-08 | Atomic unique-key contact idempotency reservations | Done | `70e7a77` |
| PR-09 | Complete fail-closed affiliate destination validation | Done | `7d97450` |
| PR-10 | Exact affiliate dependency edges | Done | `1a168bb` |
| PR-11 | Explicit fail-closed CSP mode contract with production launch gate | Done | `921f755` |
| PR-12 | DB root credential removed from managed-production contract | Done | `c32de3a` |
| PR-13 | Final release reconciliation and go/no-go evidence | **OPEN — human-gated** | — |
| OPS-02 | External and human launch gates | **OPEN — human/external** | — |

## 2. Evidence verified in this environment (2026-07-29, PR-12 HEAD `c32de3a`)

- PHPUnit: 402 tests, 1505 assertions, 0 failures (1 pre-existing PHPUnit
  deprecation notice; no coverage driver locally — coverage is measured in CI).
- Dependency-free fallback runner: 32 assertions + architecture constraints, green.
- PHPStan (level 5, 1G memory): no errors.
- `tests/integration/environment-validation.sh`: green, including the CSP mode
  contract (retired key rejected, enum enforced, explicit mode required in
  production) and the DB root-credential contract (production without root
  passes; production with root fails; local without root fails).
- Content validation, internal-link validation, freshness validation: passed.
- `MANIFEST.sha256`: regenerated (606 entries) and verified against HEAD.

## 3. Evidence NOT obtainable in this environment (requires CI/staging/humans)

- Docker-based integration contracts, Playwright (functional, accessibility,
  cross-browser, visual), Lighthouse: must pass in GitHub Actions on the
  candidate SHA (Docker is unavailable on this workstation).
- PHPCS full run: local PHP 8.5 crashes the PHPCompatibility sniff
  (environmental); CI pins PHP 8.3.
- Real browser zoom, forced colors, NVDA/VoiceOver/TalkBack: staging + human testers.
- Everything in Section 4 and Section 5 below.

## 4. OPS-02 gate record template (human/external — all OPEN)

Acceptance rule: no gate may have an owner listed only as "TBD". Every gate
requires a named owner, date, evidence location, result, candidate
SHA/checksum, and expiry/review date where applicable. Anything less is NO-GO.

| # | Gate | Owner role | Owner name | Date | Evidence location | Result | Candidate SHA / checksum | Expiry/review |
|---:|---|---|---|---|---|---|---|---|
| 1 | Managed WordPress provider selected/contracted; MU plugins, custom capabilities, private CPTs, WP/PHP/MySQL baseline, advisory locks, real cron, protected metrics confirmed | Technical release owner | TBD | — | — | OPEN | — | — |
| 2 | Branch protection on production branch; every job in `config/release-required-jobs.json` required | Technical release owner | TBD | — | — | OPEN | — | — |
| 3 | Exact candidate artifact deployed to private production-like staging; migration, smoke, acceptance, rollback drills recorded | Technical release owner | TBD | — | — | OPEN | — | — |
| 4 | Historical `longevity_app` credential rotated; dump purged per OPS-01 (`operations/incident-response/2026-07-18-database-credential-exposure.md`) | Technical release owner | TBD | — | — | OPEN | — | — |
| 5 | Off-site encrypted DB+media backups configured; restoration drill recorded | Operations owner | TBD | — | — | OPEN | — | — |
| 6 | Real cron; monitoring for readiness, audit failures, invalidation/outbox backlog, contact delivery, freshness, backup age, uptime/error rate; named alert recipients and escalation | Operations owner | TBD | — | — | OPEN | — | — |
| 7 | SMTP with SPF/DKIM/DMARC, administrative delivery, failed-delivery alerting | Operations owner | TBD | — | — | OPEN | — | — |
| 8 | WAF and edge rate limits; HTTPS + HSTS; MFA for privileged accounts | Operations owner | TBD | — | — | OPEN | — | — |
| 9 | CSP report-only observation window completed; enforcement drill (`LEL_CSP_MODE=enforce`) evidenced per `docs/operations/csp-enforcement-plan.md` | Operations owner | TBD | — | — | OPEN | — | — |
| 10 | Least-privilege production DB user provisioned per `docs/operations/database-privileges.md`; no root credential in any production environment | Operations owner | TBD | — | — | OPEN | — | — |
| 11 | Privacy Policy, Terms, Affiliate Disclosure, Medical Disclaimer, contact notice, retention period, legal-hold procedure, data-controller identity, jurisdictions approved | Privacy/legal owner | TBD | — | — | OPEN | — | — |
| 12 | Historical credential/dump incident reviewed; health-information handling, deletion and incident-notification obligations confirmed | Privacy/legal owner | TBD | — | — | OPEN | — | — |
| 13 | Real reviewer credentials verified; evidence methodology, launch claims/sources, medical-review scope and attestations approved | Editorial + medical owners | TBD | — | — | OPEN | — | — |
| 14 | Trust pages and launch content approved; no synthetic fixtures or credentials in production | Editorial owner | TBD | — | — | OPEN | — | — |
| 15 | Real protocols, actual test evidence, firmware/model/date/acquisition details, scoring inputs, comparison inventory approved; nothing marked tested without a real approved record | Product-testing owner | TBD | — | — | OPEN | — | — |
| 16 | Manual accessibility: keyboard-only, zoom, forced colors, NVDA, VoiceOver, TalkBack (where applicable), mobile navigation, forms/errors, ranking tables, trust components | Accessibility reviewer | TBD | — | — | OPEN | — | — |
| 17 | Final release board: one signed go/no-go record tied to source SHA, artifact SHA-256, CI run, staging deployment, migration result, rollback result, backup/restore evidence, named approvals, unresolved exceptions | All owners | TBD | — | — | OPEN | — | — |

## 5. PR-13 reconciliation checklist template (execute on the frozen candidate SHA)

- [ ] Clean, protected candidate commit identified (record full SHA).
- [ ] Clean dependency installation from `composer.lock` and `package-lock.json`.
- [ ] Every required CI job in `config/release-required-jobs.json` passes for that SHA.
- [ ] Required reports and evidence metadata verified.
- [ ] `MANIFEST.sha256` regenerated and verified.
- [ ] Release artifact built from the exact candidate SHA (`scripts/build-release-artifact.sh`).
- [ ] Artifact verified: allowlist, permissions, internal manifest, checksum, source SHA, no secrets/dumps/logs/test credentials/uploads/dev files (`scripts/verify-release-artifact.sh`).
- [ ] Second independent build verifies reproducibility.
- [ ] Exact artifact deployed to private staging; migrations, schema postconditions, readiness, smoke, acceptance, browser, accessibility, cross-browser, Lighthouse, contact delivery, cron, backup/restore, rollback all pass.
- [ ] Staged artifact checksum matches the approved artifact.
- [ ] All human/external evidence from Section 4 attached.
- [ ] Every exception recorded; High/Critical exceptions carry explicit signed risk acceptance.
- [ ] Go/no-go document updated with evidence, not assertions, and signed by every accountable owner.

## 6. Standing rule

Production remains **NO-GO** until every Section 4 gate and the Section 5
checklist are evidenced and signed by named humans for one specific source SHA
and artifact checksum. AI/automation must never flip this record to GO.
