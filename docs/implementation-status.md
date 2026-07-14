# Implementation Status

Last updated: 2026-07-14

| Phase | Status | Evidence and limitations |
|---|---|---|
| 0 — Baseline, safety, ADRs | Complete | Baseline audit, eight ADRs, environment validation, safe examples, and manifest workflow added. |
| 1 — Engineering quality and CI | Implemented | Composer/npm configuration and three GitHub workflows added. npm lint/audit passed locally; Composer tools could not be installed because Composer and outbound DNS were unavailable. |
| 2 — Modular MU plugin | Complete | Loader plus focused first-party classes; existing post type and shortcodes preserved. PHP syntax passed. |
| 3 — Workflow and publication gates | Implemented | Readiness service, editor interface, enforcement, reasoned override, and audit trail added. Pure gate tests passed; full block-editor integration awaits Docker CI. |
| 4 — Claims and evidence | Implemented | Private claim/source records, evidence grading fields, counts, validation, and safe WP-CLI CSV exchange added. WordPress import integration awaits running CI. |
| 5 — Medical review | Implemented | Assigned user, public credentials, exact scope, dates, conflicts, attestation and public card added. Host-specific role/editor compatibility must be integration-tested. |
| 6 — Product testing | Implemented | Versioned protocols, test records, scoring engine, confidence labels, and three category protocols added. No physical test evidence was fabricated. |
| 7 — Theme and trust UX | Implemented | Required templates, review discovery, article trust components, accessible navigation/focus, responsive tables, reduced motion and print styles added. Automated browser checks await a running site. |
| 8 — Structured data | Complete | Conservative schema graph, entity links, review gating and SEO-plugin suppression added. Rich-results eligibility is not claimed. |
| 9 — Content architecture | Complete | Trust-first launch calendar, meaningful internal-link map, brief/test/correction templates and governance files added; original 60-item calendar retained as backlog. |
| 10 — Analytics and privacy | Implemented | Allowlisted event queue and non-sensitive payload rules added; no analytics vendor is silently installed. |
| 11 — Monetization controls | Implemented | Private affiliate registry, approved-domain requirement, disclosure gate and sponsored-link output added. |
| 12 — Docker and operations | Implemented, unexecuted locally | Compose health checks, environment controls, bootstrap, cron profile, smoke and backup/restore examples added. Docker is unavailable in this environment. |
| 13 — Performance | Implemented baseline | Lean block theme, minimal JavaScript, system fonts and no front-end build runtime. Lighthouse and production cache/CDN tests remain external. |
| 14 — Testing | Implemented | Static/content/unit fallback tests pass; PHPUnit, WordPress integration, Playwright and axe are configured but require unavailable dependencies/infrastructure. |
| 15 — Corrections and lifecycle | Complete | Private correction records, public notices, audit events, freshness register and scheduled validation workflow added. |
| 16 — Documentation | Complete | Architecture, editorial, operations, testing, runbook, README and final report documentation added. |
| 17 — Operational roadmap | Complete | Ninety-day roadmap revised around trust pages, evidence tools, research literacy and limited commercial publishing after real testing. |

## Explicit external dependencies

The repository does not simulate SMTP, CDN/WAF, object cache, managed-host controls, credential verification services, production analytics, physical product testing, or external backup storage. Local interfaces, safeguards, configuration examples and runbooks are included; real integration and approval remain deployment tasks.
