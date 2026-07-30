# Longevity Evidence Lab — Production Readiness V3 Implementation Plan

**Plan version:** 1.0
**Prepared:** 2026-07-30
**Audited baseline:** `8d7e602f7b4c7b3c83b794d7e8e3e6646ba7ffc1`
**Target branch:** `improvement/production-readiness-v2`
**Current launch decision:** **NO-GO**
**Document status:** Implementation plan; it is not launch approval or completion evidence

## 1. Purpose

This plan converts the repository audit and existing readiness evidence into an
ordered, testable program for reaching a defensible production launch decision.
It covers repository work, CI, managed-host staging, security, reliability,
privacy, accessibility, editorial governance, release evidence, and launch
operations.

Production readiness is not defined as "the application runs locally." It is
the combination of:

1. A secure and reproducible release candidate.
2. Green required checks on the exact candidate commit.
3. Successful deployment of the exact candidate artifact to a private,
   production-like managed WordPress staging environment.
4. Verified operational controls, failure recovery, monitoring, and rollback.
5. Named human approval for medical, editorial, legal, privacy, commercial,
   testing, security, and accessibility gates.
6. A signed go/no-go record tied to one immutable commit and artifact checksum.

This plan preserves the current **NO-GO** state until every mandatory gate has
acceptable evidence. A partially completed phase must not be interpreted as
permission to launch.

## 2. Governing constraints

The following constraints apply to every ticket and take precedence over
delivery convenience.

### 2.1 Medical and editorial governance

- AI may not independently verify medical claims, approve safety language,
  invent citations, assign evidence grades, publish content, approve disclosure
  status, or interpret private health information.
- A named human must check every AI-assisted material claim against the private
  source registry.
- Claims and sources remain private WordPress records. Raw source articles must
  not be exposed by public pages, REST responses, exports, logs, test fixtures,
  or release artifacts.
- Synthetic CI content must be unmistakably synthetic, non-medical, isolated
  from production, and unable to satisfy human publication approvals.
- Publication gates and metadata authorization may be strengthened, but never
  bypassed to make tests pass.

### 2.2 Production topology

- Production is managed WordPress hosting under
  [ADR 0015](../adr/0015-production-topology.md).
- Docker Compose is restricted to local and CI verification. It is not a
  production or disaster-recovery topology.
- Production and production-like staging require HTTPS, external secrets,
  least-privilege accounts, MFA, off-site encrypted backups, authenticated
  email, a WAF/rate-limiting layer, current dependencies, real cron, and
  monitoring.

### 2.3 Repository safety

- Never commit `.env`, credentials, database dumps, archives, private source
  content, or generated reports.
- Do not add third-party plugins or themes without an approved architecture and
  security decision; the allowlist remains enforced.
- Run WP-CLI through `docker compose run --rm wpcli wp ...`, never
  `docker exec`.
- Use `Routes::public_page_url()` for PHP-generated public routes.
- Preserve the explicit `Meta_Authorization` write boundary.
- Regenerate `MANIFEST.sha256` after every intentional tracked-file change.
- Do not weaken a check, lower a threshold, add a broad exclusion, or convert a
  failure to `continue-on-error` merely to obtain a green build.

### 2.4 Evidence integrity

- Every completion claim must link to durable evidence.
- Evidence must record the commit SHA, artifact checksum, environment,
  execution time, actor, command or procedure, result, and retained artifact
  location.
- "Unknown," "not tested," "not applicable without rationale," and
  "external gate pending" are not passes.
- An AI agent may prepare evidence templates and report observed results, but
  may not sign human approvals or change a human/external gate to passed.

## 3. Scope and non-scope

### 3.1 In scope

- Close the open credential-exposure incident and remove reachable historical
  secret material.
- Make CI accurately exercise the supported runtime and clean bootstrap path.
- Resolve security-significant PHPCS findings and establish an enforceable
  quality baseline.
- Align unit, integration, browser, accessibility, Lighthouse, and load tests
  with canonical route and content-state contracts.
- Establish a managed-host staging environment and validate an exact release
  artifact.
- Complete operational controls for backups, restore, cron, workers, email,
  WAF, CSP, logs, metrics, alerting, privacy, and incident response.
- Complete human review gates and produce an immutable release evidence bundle.
- Define promotion, rollback, hypercare, and post-launch verification.

### 3.2 Out of scope

- Replacing WordPress or adopting a self-managed production topology.
- Publishing new medical claims or assigning evidence grades.
- Redesigning the site unless a readiness defect requires a focused UI change.
- Adding unrelated product features.
- Treating local Docker success as managed-host acceptance.
- Automatically approving or executing destructive Git history rewriting
  without a separately approved incident procedure.

## 4. Audited baseline

This is a point-in-time planning baseline, not fresh completion evidence. Re-run
all checks on the eventual release candidate.

| Area | Baseline observation | Readiness effect |
|---|---|---|
| Launch record | Existing remediation evidence correctly records **NO-GO** and open human/external gates. | Blocking |
| Credential incident | The 2026-07-18 database credential incident is open; the incident lead is unassigned and historical secret-bearing blobs remain reachable. | Critical blocker |
| Branch state | The audited local branch is 17 commits ahead of its remote; the remote pull request does not represent the audited HEAD. | Blocking |
| Branch protection | The repository default branch was reported as unprotected by the GitHub API at audit time. | Critical governance blocker |
| Remote CI | The latest observed pull-request run failed on a stale commit; manifest, CodeQL initialization, and dependency review require reconciliation. | Blocking |
| PHP unit suite | 402 tests and 1,505 assertions completed without assertion failures; the direct local configured run exits non-zero because coverage is unavailable while `failOnWarning` is enabled. | Must verify under CI PCOV |
| Static analysis | PHPStan level 5 and Psalm taint analysis passed locally. | Positive, re-run on candidate |
| Frontend lint | ESLint and Stylelint passed locally. | Positive, re-run on candidate |
| Dependency security | Composer audit and npm audit reported no known vulnerabilities locally. | Positive, time-sensitive |
| Content checks | Content, internal-link, and freshness validators passed locally. | Positive, re-run on candidate |
| Documentation consistency | `scripts/verify-docs-consistency.sh` currently fails because `docs/operations/performance-baselines.md` has neither required Owner metadata nor a valid Last reviewed date. | Blocking until the baseline is owned and reviewed |
| Manifest and supply chain | Manifest, dependency state, SBOM, and license-policy checks passed locally. | Positive, re-run on candidate |
| PHPCS | 1,630 errors and 917 warnings were observed in 67 files; 1,049 findings were auto-fixable. Security-relevant categories include SQL preparation, escaping, nonces, sanitization, and capabilities. | Blocking until triaged and enforced |
| Runtime migrations | Database migrations 1–19 completed successfully in local Docker. | Positive, insufficient for staging |
| Smoke test | Local smoke testing passed. | Positive, insufficient for staging |
| Release artifact | A local artifact was reproducible at the audited SHA with checksum `87670ec74ea33969536f27e1915d33bf6784ef42ffa1de4bbf7e224dc43d16ca`. | Baseline only; rebuild candidate |
| Platform preflight | The application requires WordPress 7.0.2 or newer, while development/CI images are pinned to WordPress 7.0.1. | Blocking |
| System acceptance | Local acceptance remains blocked by candidate identity, platform mismatch, environment mismatch, cron/worker/freshness state, invalidation/backfill state, and external mail/backup/restore evidence. | Blocking |
| Bootstrap contract | Shell bootstrap, canonical WP-CLI bootstrap, CI fixtures, and browser expectations disagree about which routes exist and which are public. | Blocking |
| Performance tests | k6 uses stale routes, nonexistent REST endpoints/assets, a floating image, and ambiguous "non-blocking" workflow behavior. | Blocking |
| Monitoring | Documentation and emitted metric names disagree; lifetime counters can create permanent alerts; runbook links and coverage need repair. | Blocking |
| Privacy operations | Contact retention and legal-hold controls exist, but WordPress privacy export/erasure integration or a complete manual data-subject workflow was not found. | Human/legal and implementation blocker |
| External operations | Managed-host compatibility, backups and restore, mail/DNS, WAF, MFA, real cron, monitoring, CSP enforcement, and production-like staging remain externally unverified. | Blocking |
| Human assurance | Screen-reader, editorial, medical, legal/privacy, product-testing, commercial, and final release approvals remain open. | Blocking |

The source readiness records are:

- [Production readiness remediation evidence](../testing/production-readiness-remediation-evidence.md)
- [Production readiness V2 evidence reconciliation](../testing/production-readiness-v2-evidence-reconciliation.md)
- [Production readiness V2 baseline](../testing/production-readiness-v2-baseline.md)
- [2026-07-18 credential incident](../../operations/incident-response/2026-07-18-database-credential-exposure.md)

If those records and this plan disagree, use newly collected, candidate-specific
evidence and update both the evidence record and this plan through review. Do
not silently resolve the disagreement.

## 5. Definition of production ready

A candidate is production ready only when all of the following statements are
true for one immutable source SHA and one artifact checksum:

| Gate | Required result |
|---|---|
| Incident | Credential incident closed by the authorized incident lead, with rotation, access review, history purge, cache/mirror cleanup, re-clone instructions, full-history rescan, and privacy/legal disposition evidenced. |
| Source control | Candidate is pushed, reviewed, mergeable, protected by required reviews/checks, and represented by the release evidence. |
| CI | Every job in `config/release-required-jobs.json` passes on the candidate; no required result comes from an older SHA. |
| Quality | PHPCS security findings are resolved; any remaining debt has narrow, owned, expiring exceptions. PHPUnit, PHPStan, Psalm, JS, CSS, shell, content, link, freshness, architecture, manifest, SBOM, license, and dependency checks pass. |
| Runtime | Candidate satisfies PHP, WordPress, database, extension, HTTPS, and configuration preflight on the managed host. |
| Artifact | Artifact is generated from Git, deterministic, verified, checksummed, scanned, and free of prohibited/private/generated material. |
| Staging | The exact artifact is deployed to private production-like staging; migrations, bootstrap, smoke, integration, E2E, accessibility automation, performance, security, and acceptance tests pass. |
| Operations | Backups, restore, RPO/RTO, real cron, workers, mail, WAF, rate limits, secrets, MFA, logging, metrics, alert routing, escalation, and rollback are verified. |
| Privacy and legal | Data inventory, retention, contact handling, data-subject request procedure, consent integration, proxy handling, policies, affiliate disclosures, and incident disposition are approved by named humans. |
| Editorial and medical | Named editorial, medical, source-registry, testing, and commercial reviewers approve the launch corpus and workflow controls. |
| Accessibility | Automated WCAG checks and manual keyboard, zoom, forced-colors, and representative screen-reader/mobile assistive-technology checks pass or have approved launch-blocking dispositions. |
| CSP | Report-only observation has completed, violations are triaged, enforcement and rollback are approved, and enforcement is validated in staging. |
| Release decision | A named release manager convenes a go/no-go review and records signed approvals, open risks, rollback owner, communication owner, and launch window. |

No aggregate percentage may override a failed mandatory gate.

## 6. Priority, risk, status, and ownership vocabulary

### 6.1 Priority

- **P0:** Launch cannot proceed; security, data, governance, or release integrity
  is at immediate risk.
- **P1:** Required for a production candidate or production-like staging.
- **P2:** Required before public launch but may follow core candidate
  stabilization.
- **P3:** Post-launch hardening only if explicitly accepted and documented.

### 6.2 Risk

- **Critical:** Credential exposure, private-data exposure, governance bypass,
  irreversible corruption, or untrustworthy release evidence.
- **High:** Security control failure, unreliable deployment/rollback, incorrect
  public medical/editorial behavior, or unmonitored critical failure.
- **Medium:** Quality, maintainability, or operational weakness with bounded
  immediate impact.
- **Low:** Documentation or ergonomics improvement with no direct launch impact.

### 6.3 Ticket status

Use only: `Not started`, `In progress`, `Blocked`, `In review`, `Evidence
pending`, `Complete`, or `Accepted exception`.

`Complete` requires acceptance criteria and evidence. Code merged without
runtime or human evidence is normally `Evidence pending`, not `Complete`.

### 6.4 Owner roles

Every ticket must receive a named owner before work begins. Required roles are:

- Incident lead
- Repository administrator
- Application engineering lead
- Security reviewer
- Managed-host/platform owner
- Site reliability/operations owner
- Privacy/legal approver
- Editorial lead
- Medical reviewer
- Source-registry reviewer
- Product-testing lead
- Commercial/disclosure reviewer
- Accessibility reviewer
- Release manager

One person may hold multiple roles only where least privilege, separation of
duties, and reviewer independence remain credible.

## 7. Execution rules

### 7.1 Change isolation

- Use a dedicated branch and pull request for each ticket or tightly coupled
  ticket pair.
- Reference the ticket ID in commits and pull-request descriptions.
- Rebase or merge the current remote branch before treating remote CI as
  candidate evidence.
- Preserve unrelated user work and never include unreviewed local files.
- Include changed files, risk, tests, evidence, rollback, and documentation in
  each pull request.

### 7.2 Baseline before modification

For every code ticket:

1. Record the failing command and concise output.
2. Add or identify a test that detects the defect.
3. Implement the narrowest coherent fix.
4. Run targeted checks.
5. Run the full applicable validation set.
6. Regenerate and verify `MANIFEST.sha256`.
7. Confirm the release artifact contains only permitted files when packaging is
   affected.

### 7.3 Exceptions

An exception is valid only when it records:

- Exact rule, check, route, or risk being excepted.
- Why remediation cannot be completed before launch.
- User and system impact.
- Compensating control.
- Named business and security approvers.
- Owner, issue link, and expiry date.
- How CI will prevent the exception from broadening.

Broad directory exclusions, permanent warning suppression, wildcard capability
allowlists, and unspecified "known issues" are not acceptable.

### 7.4 Evidence storage

Repository evidence should be concise and contain no secrets or personal data.
Large logs, scans, videos, screenshots, backup reports, and provider exports
should be retained in access-controlled immutable storage, with checksums and
links recorded in the release evidence. Redact tokens, addresses, contact
messages, database rows, and private-source material.

## 8. Dependency map and phase gates

```text
Incident containment and repository governance
        |
        +--> trusted remote branch and CI baseline
        |         |
        |         +--> supported platform pins
        |         +--> canonical bootstrap/fixture contract
        |         +--> quality and test remediation
        |                       |
        +-----------------------+--> immutable candidate artifact
                                      |
Managed-host qualification ----------+--> production-like staging
                                              |
Operational/security/privacy setup ----------+--> staging acceptance
                                                      |
Human editorial/legal/accessibility reviews ----------+
                                                      |
                                              frozen candidate
                                                      |
                                              signed go/no-go
                                                      |
                                          production promotion/rollback
```

| Phase | Purpose | Exit gate |
|---|---|---|
| 0 | Contain the incident and establish trustworthy repository controls. | Incident actions have authorized ownership; branch protection and remote candidate integrity are enforced. |
| 1 | Make CI and platform assumptions truthful. | Candidate is on the remote; required jobs run on supported pinned versions and report usable results. |
| 2 | Establish one bootstrap, content-state, route, and test-fixture contract. | Clean environments are deterministic; production safeguards and CI-only publication are demonstrably separated. |
| 3 | Resolve quality/security debt and make all automated suites meaningful. | Required repository checks are green without broad suppression. |
| 4 | Repair operational, monitoring, privacy, and recovery controls. | Controls exist, are documented, and are ready for production-like exercise. |
| 5 | Qualify the managed host and deploy an immutable candidate to staging. | Exact artifact runs on a private, production-like supported environment. |
| 6 | Execute staging security, reliability, performance, recovery, and CSP acceptance. | No unresolved critical/high issue lacks an approved exception; operational drills pass. |
| 7 | Complete human content, legal, testing, commercial, and accessibility gates. | All mandatory human gates are signed by named authorized reviewers. |
| 8 | Freeze, attest, decide, promote, and monitor the release. | Signed go decision, controlled deployment, verification, and hypercare complete. |

Phases may overlap where dependencies allow, but no later-phase evidence may
mask a failed earlier-phase gate.

## 9. Phase 0 — Incident containment and repository governance

### PRV3-IR-01 — Assign and contain the credential incident

**Priority/Risk:** P0 / Critical
**Owner:** Named incident lead
**Dependencies:** None
**Systems:** Database provider, secret store, logs, Git hosting, mirrors, CI

**Implementation**

1. Assign an incident lead, security reviewer, database operator, and
   privacy/legal reviewer in the incident record.
2. Revoke and rotate every credential derived from or equivalent to the exposed
   `longevity_app` credential. Use the external secret store; never record new
   secret values in Git, tickets, chat, or logs.
3. Verify the old credential is invalid from an independent client.
4. Review authentication, query, network, and provider access logs for the
   exposure window and document the time range, data sources, gaps, and
   conclusion.
5. Inventory affected clones, forks, pull requests, workflow artifacts,
   caches, mirrors, backups, paste systems, and local workstations.
6. Determine whether personal, confidential, or regulated data was accessible
   and route notification decisions to authorized privacy/legal counsel.

**Acceptance criteria**

- Named incident roles and timestamps are in the incident record.
- Rotation is proven without revealing either old or new values.
- Independent invalid-credential verification is retained.
- Access-log review and privacy/legal disposition are signed.
- The incident remains open until PRV3-IR-02 and PRV3-IR-03 pass.

**Evidence**

- Secret-manager rotation event IDs.
- Redacted database authentication result.
- Access-review report and log-source inventory.
- Privacy/legal decision record.

**Rollback/stop conditions**

- If rotation breaks service, use the secret manager's controlled prior-version
  procedure only if the prior version is not the exposed value; otherwise issue
  a new credential.
- Stop and escalate on evidence of unauthorized access, incomplete provider
  logs, or inability to revoke all derived credentials.

### PRV3-IR-02 — Purge exposed material from all reachable Git history

**Priority/Risk:** P0 / Critical
**Owner:** Incident lead and repository administrator
**Dependencies:** PRV3-IR-01 rotation complete
**Systems:** GitHub repository, forks, mirrors, caches, artifacts, local clones

Historical objects identified at audit time include:

- Database blob `e1c05162f364`
- Archive blob `a02ab5d42507`
- README transcript blob `1d834fd1de3b`

Treat these identifiers as discovery inputs, not an exhaustive list.

**Implementation**

1. Freeze merges and coordinate a history-rewrite window.
2. Create an access-controlled backup of current repository references for
   forensic retention according to counsel and incident policy.
3. Perform a dry-run rewrite in a disposable mirror clone.
4. Remove the complete secret-bearing files/content and scan all refs, tags,
   notes, pull-request refs where accessible, and large-file/object stores.
5. Run full-history secret and prohibited-artifact scans on the rewritten
   mirror before any force update.
6. Obtain written approval from the incident lead and repository administrator.
7. Replace authorized remote refs during the announced window.
8. Request provider cache and pull-request object purge where required.
9. Delete or replace contaminated CI artifacts, release archives, mirrors, and
   caches.
10. Require fresh clones; publish instructions that explicitly prohibit merging
    old clone history back into the sanitized repository.
11. Re-run full-history scans against the canonical remote after provider
    garbage collection/purge processing.

**Acceptance criteria**

- Named objects and all additional findings are unreachable from canonical
  remote refs.
- Full-history secret and archive scans pass on the canonical remote.
- Provider purge case is resolved or has a documented residual-risk decision.
- Protected branches, tags, open pull requests, and release references point to
  intended sanitized commits.
- Team re-clone acknowledgement is recorded.

**Evidence**

- Approved rewrite procedure and ref inventory.
- Before/after object reachability report with no secret values.
- Scanner versions, rulesets, timestamps, and clean reports.
- Provider purge ticket and team communication.

**Rollback/stop conditions**

- This is destructive and must not be executed by an autonomous agent.
- Stop if the ref inventory is incomplete, the forensic-retention decision is
  unresolved, or an authorized approver is unavailable.
- Retain the controlled forensic backup; do not re-push it as a rollback.

### PRV3-IR-03 — Close incident privacy, legal, and prevention actions

**Priority/Risk:** P0 / Critical
**Owner:** Incident lead with privacy/legal and security approval
**Dependencies:** PRV3-IR-01, PRV3-IR-02

**Implementation**

- Record root cause, exposure window, impact, detection path, and control gaps.
- Decide notification obligations and retain the legal rationale.
- Add prevention actions: pre-commit secret detection, full-history scheduled
  scanning, artifact/dump rejection, credential rotation runbook, and
  incident-tabletop coverage.
- Verify `.gitignore`, CI secret rules, release packaging, and backup procedures
  prevent the same artifact classes from entering Git.
- Conduct a short retrospective with owners and due dates.

**Acceptance criteria**

- Incident status is closed only by the named incident lead.
- Privacy/legal and security reviewers sign the disposition.
- Preventive controls have tests that fail on safe synthetic canaries.
- No real credential is used to test detection.

### PRV3-GOV-01 — Enforce repository branch and release governance

**Priority/Risk:** P0 / Critical
**Owner:** Repository administrator
**Dependencies:** Coordinate with PRV3-IR-02 rewrite window

**Implementation**

- Protect the default and release branches.
- Require pull requests, at least one independent review, dismissal of stale
  approvals, resolution of review conversations, linear or otherwise documented
  merge policy, and signed commits/tags if organizational policy supports them.
- Require the exact jobs listed in `config/release-required-jobs.json`.
- Restrict force pushes and deletions to the controlled incident procedure.
- Restrict workflow changes and production environments with CODEOWNERS or
  equivalent review and environment approvals.
- Enable dependency, secret, and code scanning supported by the repository
  plan; document any unavailable feature and compensating CI control.
- Require MFA and least-privilege repository roles.

**Acceptance criteria**

- API/exported settings evidence shows protection and required jobs.
- A synthetic unreviewed or failing pull request cannot merge.
- Production environment deployment requires a named human approval.
- Administrator bypass is restricted, logged, and included in periodic review.

## 10. Phase 1 — Truthful CI and supported platform baseline

### PRV3-CI-01 — Publish and reconcile the audited branch

**Priority/Risk:** P0 / High
**Owner:** Application engineering lead
**Dependencies:** PRV3-GOV-01 sequencing; account for PRV3-IR-02 rewrite

**Implementation**

- Reconcile the 17 local commits with the remote branch through a reviewed,
  non-destructive Git workflow.
- Exclude untracked and unrelated local files.
- Confirm the pull request head SHA exactly matches the reviewed local SHA after
  reconciliation.
- Re-run all workflows and distinguish code failures from permissions,
  entitlement, configuration, runner, or stale-SHA failures.
- Replace outdated readiness links and status statements with the current run
  IDs and SHAs.

**Acceptance criteria**

- The remote pull request represents every intended tracked change and no
  unintended file.
- Required CI reports against the current head.
- Manifest validation runs after checkout with no workspace mutation.
- No result from the previously observed stale `d8ac` head is used as evidence.

### PRV3-CI-02 — Align development and CI with the minimum WordPress runtime

**Priority/Risk:** P0 / High
**Owner:** Application engineering lead
**Dependencies:** PRV3-CI-01
**Files:** `class-platform-requirements.php`, Dockerfiles under
`docker/development/`, Compose/workflow references, runtime documentation

**Implementation**

1. Confirm the intended minimum WordPress version with the managed-host
   compatibility target.
2. Pin WordPress and WP-CLI images to compatible immutable versions; use image
   digests in release-critical CI where practical.
3. Keep PHP at the documented 8.3 minimum and test the managed host's actual
   supported PHP patch.
4. Update the compatibility matrix and all setup documentation.
5. Add a CI assertion comparing the application minimum to the resolved runtime
   version so image drift fails with a clear message.
6. Rebuild clean volumes and run migrations, bootstrap, preflight, smoke, unit,
   integration, and browser tests.

**Acceptance criteria**

- `wp longevity preflight` passes in clean local/CI runtime.
- The Dockerfiles no longer contain a known-below-minimum pin.
- CI reports exact WordPress, PHP, MySQL, browser, image tag, and digest data.
- A synthetic below-minimum version test fails closed.

**Rollback**

- Revert image pins only together with a reviewed application compatibility
  decision; never lower the application minimum merely to make the old image
  pass.

### PRV3-CI-03 — Make required security jobs reliable and actionable

**Priority/Risk:** P0 / High
**Owner:** Security reviewer and repository administrator
**Dependencies:** PRV3-CI-01, PRV3-GOV-01

**Implementation**

- Diagnose CodeQL initialization and dependency-review failures on the current
  head.
- Verify workflow permissions, repository feature availability, event type,
  fork behavior, language/build configuration, and organization policy.
- If a hosted feature is unavailable, implement an approved, required,
  least-privilege CI alternative rather than silently skipping the control.
- Ensure pull-request workflows do not expose secrets to untrusted fork code.
- Add a small workflow-health runbook distinguishing scanner findings from
  scanner infrastructure failure.

**Acceptance criteria**

- Code scanning and dependency review either pass or fail with actionable
  findings on the candidate.
- Scanner initialization failure is a required-check failure.
- Approved compensating controls, if any, are documented with owner and expiry.

### PRV3-CI-04 — Pin and govern build dependencies

**Priority/Risk:** P1 / Medium
**Owner:** Application engineering lead and security reviewer
**Dependencies:** PRV3-CI-01

**Implementation**

- Keep action references pinned to full SHAs and document the corresponding
  release/tag.
- Pin the k6 container by version and digest; remove `latest`.
- Review patch/minor updates in isolated pull requests, including PHPStan and
  Playwright updates observed during the audit.
- Treat Stylelint 17 and PHPUnit major-version changes as migrations with
  compatibility review, not routine patch updates.
- Update WordPress stubs when a compatible version matching the WordPress 7.0.2+
  contract exists; until then document and test the mismatch.
- Re-run SBOM, license, Composer audit, npm audit, and reproducibility checks.

**Acceptance criteria**

- Release-critical tooling versions are deterministic.
- No blind major upgrade is included in the readiness stabilization branch.
- Lockfiles and generated dependency evidence are reviewed and committed
  together.

## 11. Phase 2 — Canonical bootstrap, route, and fixture contracts

### PRV3-BOOT-01 — Define one route and content-state contract

**Priority/Risk:** P0 / High
**Owner:** Application engineering lead with editorial lead review
**Dependencies:** PRV3-CI-02
**Files:** `class-routes.php`, bootstrap commands/scripts, fixture scripts,
Playwright route inventories, Lighthouse and k6 configuration

**Implementation**

- Treat `Routes` as the canonical registry for page routes and category terms.
- Define, for every canonical route:
  - required existence after base bootstrap;
  - default content state and WordPress status;
  - whether it is public in local, CI-fixture, staging, and production modes;
  - responsible human role for production publication;
  - E2E/Lighthouse/load-test expectation by environment mode.
- Expose a dependency-free or WP-CLI-readable route-state projection so tests
  consume the registry rather than duplicate slug lists.
- Add contract tests that fail when route definitions and test inventories
  diverge.
- Keep legacy category fallbacks explicit and tested.

**Acceptance criteria**

- One version-controlled route-state matrix drives all test expectations.
- Trust, legal, methodology, hub, and contact routes have unambiguous default
  states.
- No browser or load test expects a production-draft route to return public
  `200` unless an explicit CI fixture mode published a synthetic projection.

### PRV3-BOOT-02 — Consolidate bootstrap into one idempotent entry point

**Priority/Risk:** P0 / High
**Owner:** Application engineering lead
**Dependencies:** PRV3-BOOT-01
**Files:** `Makefile`, `scripts/bootstrap.sh`, `scripts/ci-setup.sh`,
`class-cli.php`, bootstrap implementation and tests

**Implementation**

1. Select `wp longevity bootstrap all` as the canonical application bootstrap,
   or formally replace it with an equivalent single entry point.
2. Make `make bootstrap`, local setup, CI setup, and documented commands invoke
   that same contract.
3. Limit the shell wrapper to environment orchestration; remove duplicated page
   and category definitions.
4. Preserve safe initial states: records requiring human review remain drafts
   or otherwise non-public.
5. Ensure idempotency across two consecutive runs without duplicate posts,
   terms, IDs, or changed approval data.
6. Add clean-database and second-run tests.
7. Ensure bootstrap never creates approvals, evidence grades, verified
   reviewer credentials, or disclosure sign-off.

**Acceptance criteria**

- A clean bootstrap produces the documented 16 canonical pages, categories, and
  intended draft launch records exactly once.
- A second bootstrap produces no semantic changes.
- `make bootstrap`, CI setup, and the quick-start documentation agree.
- Production-safe status is the default.

### PRV3-BOOT-03 — Create an explicit CI-only public fixture projection

**Priority/Risk:** P0 / High
**Owner:** Application engineering lead with editorial-governance review
**Dependencies:** PRV3-BOOT-01, PRV3-BOOT-02
**Files:** `scripts/create-test-fixtures.php`, fixture wrapper, CI workflow,
E2E setup and documentation

**Implementation**

- Introduce an explicit environment guard such as a test-only constant plus
  `WP_ENVIRONMENT_TYPE` validation.
- Publish only synthetic, non-medical fixtures needed for route, navigation,
  accessibility, visual, and performance testing.
- Watermark fixture titles/content and use reserved identifiers so fixtures can
  be detected.
- Refuse to run on staging or production and test the refusal.
- Do not create human approval metadata or mutate production editorial records.
- Add teardown or disposable-environment expectations; fixtures must never be
  packaged into the release artifact.

**Acceptance criteria**

- CI browser routes are deterministic on a clean database.
- Running the fixture command in a production-like environment fails before any
  write.
- A release-artifact inspection finds no fixtures or fixture-generated data.
- Publication governance remains enforced outside the isolated test projection.

### PRV3-TEST-01 — Align browser, accessibility, and visual tests

**Priority/Risk:** P1 / High
**Owner:** Application engineering lead and accessibility reviewer
**Dependencies:** PRV3-BOOT-03
**Files:** `tests/e2e/`, route inventories, Playwright configuration

**Implementation**

- Generate route cases from the route-state contract.
- Separate anonymous-public, authenticated-editorial, and CI-fixture suites.
- Assert correct non-public behavior for drafts, not just absence from
  navigation.
- Cover canonical and legacy category routes, security headers, contact
  controls, public REST redaction, navigation suppression, 404 behavior, and
  private record types.
- Regenerate visual snapshots only after a human reviews intentional diffs;
  retain platform/browser metadata.
- Run axe-core on the supported public route set, including error and empty
  states.

**Acceptance criteria**

- The complete suite passes from a clean CI setup.
- Tests do not rely on developer-local database state.
- Snapshot updates include a reviewed before/after artifact and rationale.
- Private claims/sources are tested against accidental public exposure.

### PRV3-PERF-01 — Replace the stale k6 scenario with canonical journeys

**Priority/Risk:** P1 / High
**Owner:** Application engineering lead and operations owner
**Dependencies:** PRV3-BOOT-01, PRV3-BOOT-03, PRV3-CI-04

**Implementation**

- Replace `/evidence/` and other stale paths with registry-derived canonical
  routes.
- Assign a named owner to the performance baseline and record a real human
  review date using the repository's documentation metadata convention.
- Remove nonexistent REST endpoints and asset paths; add only real public
  endpoints/assets.
- Split smoke and load profiles.
- Model representative anonymous journeys: home, topic/category, guide/article,
  review/ranking where fixtures exist, trust pages, search, and contact form
  page load. Do not load-test contact submission without explicit anti-abuse
  controls and isolated sinks.
- Define thresholds for HTTP failures, latency percentiles, and check rate based
  on [performance baselines](../operations/performance-baselines.md).
- Make workflow semantics explicit: candidate smoke/load gates are blocking;
  exploratory scheduled tests may be informational only if separately named.
- Pin the k6 container version and digest.

**Acceptance criteria**

- Every requested route/asset exists in the declared fixture mode.
- Threshold failure causes the candidate performance job to fail.
- Results record candidate SHA, artifact checksum, host, dataset, k6 version,
  virtual-user profile, and timestamps.
- No private endpoint or personal data is included in output.
- `scripts/verify-docs-consistency.sh` passes with the reviewed performance
  baseline.

### PRV3-PERF-02 — Align Lighthouse with public fixture state

**Priority/Risk:** P1 / Medium
**Owner:** Application engineering lead
**Dependencies:** PRV3-BOOT-03

**Implementation**

- Derive Lighthouse URLs from routes declared public in CI fixture mode.
- Separate performance budgets from accessibility automation while keeping both
  blocking where defined.
- Test mobile and desktop profiles for the critical route set.
- Store concise reports as CI artifacts with retention and candidate identity.
- Fail clearly on 404, login redirects, draft routes, or missing fixtures before
  scoring.

**Acceptance criteria**

- Lighthouse never scores an error page as a target route.
- Budgets and allowed variance are documented and reviewed.
- Candidate reports are traceable and reproducible enough for regression use.

## 12. Phase 3 — Code quality, security triage, and test integrity

### PRV3-QA-01 — Triage and resolve security-significant PHPCS findings

**Priority/Risk:** P0 / Critical
**Owner:** Application engineering lead with security review
**Dependencies:** PRV3-CI-01

At audit time the security-relevant PHPCS groups included:

- 51 interpolated-not-prepared SQL findings
- 26 not-prepared SQL findings
- 2 unfinished-prepare findings
- 48 exception-not-escaped findings
- 16 output-not-escaped findings
- 15 nonce-recommended and 7 nonce-missing findings
- 13 unsanitized-input findings
- 1 unvalidated-input and 1 missing-unslash finding
- 50 unknown-capability findings

Counts are triage inputs and must be re-measured on the ticket branch.

**Implementation**

1. Export machine-readable PHPCS results and group by security category and
   reachable trust boundary.
2. Review database queries for placeholders, identifier allowlists, `LIKE`
   escaping, dynamic table names, and false positives. Do not mechanically
   prepare identifiers.
3. Review every HTML, attribute, URL, JSON, header, and exception output for
   context-appropriate escaping.
4. Add nonce verification and capability authorization to state-changing admin,
   AJAX, REST, CLI, and form paths as appropriate.
5. Apply `wp_unslash()` followed by correct validation/sanitization at the input
   boundary; preserve raw values only where explicitly justified and contained.
6. Map custom capabilities in PHPCS configuration narrowly after confirming
   registration and enforcement.
7. Add exploit-oriented regression tests for each resolved class.
8. Permit inline suppressions only with issue, reason, owner, and expiry.

**Acceptance criteria**

- Zero unresolved security-significant findings in reachable first-party code,
  unless individually accepted under the exception policy.
- Security regression tests fail before and pass after fixes.
- Manual security reviewer signs the SQL, nonce/capability, input, and output
  triage record.
- No broad standard exclusion hides future findings.

### PRV3-QA-02 — Remediate mechanical PHPCS debt and enforce the baseline

**Priority/Risk:** P1 / Medium
**Owner:** Application engineering lead
**Dependencies:** PRV3-QA-01

Dominant observed mechanical groups included missing parameter documentation,
alignment, and array-format findings.

**Implementation**

- Run auto-fixing only on reviewed, scoped batches.
- Separate mechanical-only commits from behavioral security changes.
- Add missing docblocks where they improve type/contract clarity; do not insert
  misleading boilerplate.
- Resolve formatting, naming, spacing, and file-structure findings.
- Keep generated/vendor/test-fixture exclusions minimal and documented.
- Make `composer phpcs` a required CI job once the approved baseline is clean.
- Correct readiness documentation that claims PHPCS crashes on PHP 8.5 if that
  statement is no longer reproducible.

**Acceptance criteria**

- `composer phpcs` exits zero on first-party scope.
- Any remaining warnings are individually reviewed and governed.
- Auto-fix diffs pass unit, static, content, and integration tests.

### PRV3-QA-03 — Make PHPUnit coverage behavior unambiguous

**Priority/Risk:** P1 / Medium
**Owner:** Application engineering lead
**Dependencies:** PRV3-CI-02
**Files:** `phpunit.xml.dist`, Composer scripts, PHP CI jobs, test docs

**Implementation**

- Update the deprecated PHPUnit XML schema using the version-appropriate
  migration.
- Define separate local assertion and CI coverage commands if local coverage
  drivers are optional.
- Keep CI coverage blocking with PCOV and the repository minimum threshold
  (currently documented as 30%).
- Ensure absence of a coverage driver reports a targeted configuration message
  rather than making successful assertion output ambiguous.
- Verify all test discovery, standalone routes, fallback tests, and architecture
  checks run.
- Publish coverage metadata and report scope; do not count stubs/vendor files.

**Acceptance criteria**

- Local documented test commands have predictable exit semantics.
- CI executes at least the audited 402-test suite, subject to intentional test
  additions/removals explained in review.
- Coverage threshold failure fails the required job.
- Deprecated schema warnings are removed.

### PRV3-QA-04 — Complete the automated candidate validation matrix

**Priority/Risk:** P0 / High
**Owner:** Application engineering lead and security reviewer
**Dependencies:** PRV3-CI-02 through PRV3-QA-03, PRV3-PERF-02

The candidate matrix must include:

```bash
make validate
make lint
make test
composer test:fallback
composer phpstan
php vendor/bin/psalm --taint-analysis --no-progress --no-cache
npm run lint
make deploy-check
make test-e2e
make test-a11y
make test-integration
make release-artifact SHA=<full-candidate-sha>
make verify-release-artifact SHA=<full-candidate-sha>
```

Also run workflow-specific SBOM, license, CodeQL, dependency-review,
reproducibility, Lighthouse, and k6 jobs.

**Implementation**

- Reconcile command names with actual Composer/npm scripts before enforcement.
- Ensure each required job has a unique stable name matching
  `config/release-required-jobs.json`.
- Add timeouts and artifact retention without suppressing failures.
- Ensure clean-build jobs start from Git plus lockfiles, not cached generated
  state.
- Test the test harness with safe synthetic defects for SAST, manifest,
  dependency state, and prohibited files.

**Acceptance criteria**

- All required jobs pass on the exact candidate SHA.
- A controlled failure in each critical harness is detected.
- No skipped or cancelled job is represented as passed.
- Workflow artifacts contain no secrets, personal data, private sources, or
  untracked build residue.

## 13. Phase 4 — Operations, monitoring, recovery, and privacy

### PRV3-OBS-01 — Reconcile metrics, alerts, dashboards, and runbooks

**Priority/Risk:** P1 / High
**Owner:** Operations owner with application engineering
**Dependencies:** PRV3-QA-04 may proceed in parallel
**Files:** metrics implementation, monitoring docs, alert rules, runbooks

**Implementation**

1. Generate or validate a metric catalog from the code's emitted names, types,
   labels, units, cardinality, and collection interval.
2. Resolve the documentation/code mismatch between
   `lel_readiness_check{check,status}` and `lel_readiness_check_state`.
3. Replace lifetime-counter alerts such as
   `lel_audit_write_failures_total > 0` with reset-aware windowed logic such as
   `increase()` where appropriate.
4. Define distinct degraded and blocked severities and inhibition rules.
5. Link every page-worthy alert to a dedicated runbook with matching anchors
   and verified steps.
6. Add or verify coverage for:
   - audit-chain/write failures;
   - publication-lock exhaustion;
   - migration/preflight failure;
   - freshness and cron lateness;
   - worker heartbeats and queue lag;
   - invalidation backlog/dead letters;
   - notification outbox dead letters;
   - dependency backfill status;
   - contact delivery failures;
   - backup age and restore-evidence age;
   - uptime, latency, TLS expiry, HTTP failures, and disk/database capacity;
   - CSP violation trend and enforcement breakage.
7. Test alert routing, deduplication, acknowledgement, escalation, and recovery
   notifications.

**Acceptance criteria**

- Every documented production metric is emitted or explicitly marked external.
- Alerts fire on injected synthetic conditions and resolve after recovery.
- A single historical counter increment does not page forever.
- Dashboards and runbooks identify environment, service, severity, and owner.

### PRV3-OPS-01 — Define and test backup, restore, RPO, and RTO

**Priority/Risk:** P0 / Critical
**Owner:** Managed-host/platform owner and operations owner
**Dependencies:** Managed-host candidate may be selected in parallel
**Reference:** [Backup and restore](../operations/backup-and-restore.md)

**Implementation**

- Obtain provider backup architecture, encryption, isolation, retention,
  immutability, regional/off-site properties, and access-control evidence.
- Define explicit business-approved recovery point objective (RPO) and recovery
  time objective (RTO), not only schedule and retention.
- Cover database, uploads, configuration, release artifacts, secrets/config
  references, DNS/edge configuration, and required operational records.
- Perform a restore into an isolated non-production environment.
- Verify data integrity, WordPress login, migrations, media, governed metadata,
  audit-chain integrity, route behavior, and application smoke tests.
- Record actual achieved recovery point and elapsed recovery time.
- Test backup-failure and stale-backup alerts.

**Acceptance criteria**

- RPO/RTO are approved and achieved in a timed restore drill.
- Backups are encrypted, access-controlled, off-site, and restorable.
- Restore evidence is candidate/environment specific and contains no exposed
  data.
- Named operators can execute the runbook without undocumented knowledge.

### PRV3-OPS-02 — Validate cron, queues, workers, and failure recovery

**Priority/Risk:** P0 / High
**Owner:** Operations owner and application engineering lead
**Dependencies:** Managed staging available for final evidence

**Implementation**

- Configure real cron according to the managed host; do not rely on
  request-driven WP-Cron for production assurance.
- Validate freshness jobs, invalidation queue, dependency backfill,
  notification outbox, retry limits, idempotency, dead-letter behavior, and
  worker heartbeats.
- Exercise process interruption, duplicate delivery, database timeout, poison
  item, and backlog recovery.
- Document concurrency limits and advisory-lock behavior.
- Add dashboards and alerts for lateness, failure, backlog age, dead letters,
  and missing heartbeat.

**Acceptance criteria**

- System acceptance reports healthy cron, workers, invalidation, and dependency
  backfill on staging.
- Replayed jobs do not create duplicate state or unauthorized metadata.
- Failure injection produces an alert and successful runbook recovery.

### PRV3-OPS-03 — Complete mail, DNS, contact, and abuse controls

**Priority/Risk:** P1 / High
**Owner:** Operations owner with privacy/legal and security review
**Dependencies:** Managed staging, approved mail provider

**Implementation**

- Configure SMTP/API delivery through external secrets and least-privilege
  credentials.
- Configure and validate SPF, DKIM, and DMARC for the sending domain.
- Verify contact idempotency, rate limits, anti-automation controls, safe logs,
  retention, deletion, legal hold, and delivery-failure handling.
- Ensure contact email contents cannot produce header injection, unsafe HTML,
  or sensitive log payloads.
- Use controlled test recipients and remove test messages according to policy.
- Add delivery health, bounce/failure, and queue/dead-letter monitoring.

**Acceptance criteria**

- Authenticated mail passes alignment checks and controlled delivery tests.
- Contact submissions are neither silently lost nor duplicated under retry.
- Rate limits work through the real trusted-proxy chain.
- Privacy/legal reviewer approves retention and handling.

### PRV3-PRIV-01 — Complete data-subject and retention operations

**Priority/Risk:** P0 / High
**Owner:** Privacy/legal approver with application engineering
**Dependencies:** PRV3-OPS-03

**Implementation**

- Create a personal-data inventory covering WordPress users, contact records,
  HMAC network identifiers, analytics, logs, backups, mail providers, CI
  artifacts, and managed-host telemetry.
- Decide whether to implement WordPress personal-data exporter/eraser hooks or
  operate a documented manual data-subject request procedure. The decision must
  be approved by privacy/legal.
- If implementing hooks, enforce identity verification, authorization,
  auditable actions, retention constraints, and legal holds.
- Define how deletion propagates to backups and processors within approved
  policy.
- Test access, correction, deletion, restriction/hold, and rejected/invalid
  requests with synthetic identities.
- Align the privacy notice and internal procedure without overpromising
  technical behavior.

**Acceptance criteria**

- A trained human can locate and act on all in-scope data for a synthetic
  request.
- Legal holds prevent unauthorized deletion and are auditable.
- The public privacy statement matches actual systems and processors.
- No private health information is collected or interpreted by an AI workflow.

### PRV3-PRIV-02 — Validate trusted proxies, network identifiers, and consent

**Priority/Risk:** P0 / High
**Owner:** Security reviewer, privacy/legal approver, platform owner
**Dependencies:** Managed-host network design

**Implementation**

- Obtain the provider's authoritative proxy ranges and header behavior.
- Configure `LONGEVITY_TRUSTED_PROXIES` with exact least-trust ranges.
- Test direct requests, trusted forwarded chains, multiple hops, malformed
  headers, spoofed `X-Forwarded-For`, missing configuration, IPv4, and IPv6.
- Verify HMAC keys come from external secrets, can rotate, and are not reused
  for unrelated purposes.
- Select and configure the actual consent manager/adapter for analytics or keep
  non-essential analytics disabled.
- Test default-deny, grant, withdrawal, policy/version change, and
  no-JavaScript behavior.

**Acceptance criteria**

- Untrusted forwarded headers cannot bypass rate limits or alter the derived
  client identity.
- Missing/invalid proxy configuration fails safely and alerts.
- Non-essential analytics do not run before valid consent where required.
- Privacy/legal signs the consent and network-identifier design.

### PRV3-SEC-01 — Validate edge, HTTPS, headers, accounts, and secrets

**Priority/Risk:** P0 / Critical
**Owner:** Security reviewer and platform owner
**Dependencies:** Managed staging

**Implementation**

- Enforce HTTPS and secure admin access; validate redirects, TLS versions,
  certificate chain, renewal, and HSTS rollout.
- Configure WAF/rate limits for login, XML-RPC if enabled, REST, search,
  contact, and known abusive patterns.
- Verify application security headers and managed-host header interaction.
- Enforce MFA, least-privilege WordPress roles, least-privilege database access,
  separate service credentials, and removal of dormant/default accounts.
- Keep file editing/modification disabled in production according to deployment
  design.
- Inventory secrets, owners, rotation periods, and emergency revocation.
- Test unauthorized admin, metadata, REST, file-modification, and database
  actions.

**Acceptance criteria**

- External scans show approved TLS/header configuration and no unintended
  administrative exposure.
- WAF controls block safe synthetic abuse without blocking critical reader
  journeys.
- Access review and database privilege evidence are signed.
- No production secret appears in code, logs, CI artifacts, screenshots, or
  release packages.

### PRV3-CSP-01 — Complete report-only observation and enforce CSP

**Priority/Risk:** P1 / High
**Owner:** Security reviewer and application engineering lead
**Dependencies:** Stable production-like staging integrations
**Reference:** [CSP enforcement plan](../operations/csp-enforcement-plan.md)

**Implementation**

- Deploy report-only policy to staging with an approved report collector and
  privacy-safe retention.
- Observe for the documented 30-day period, including a final clean seven-day
  window, unless a named security approver revises the criterion.
- Classify first-party defects, browser noise, extensions, attack probes, and
  third-party requirements.
- Remove unsafe sources before adding narrow directives; avoid wildcard hosts.
- Validate all route, admin, consent, analytics, mail/contact, and error flows.
- Obtain human approval for `LEL_CSP_MODE=enforce`.
- Test an immediate, documented rollback to report-only.

**Acceptance criteria**

- Required flows pass under enforcement in staging.
- No unresolved actionable violation remains.
- Enforcement and rollback decisions are signed and timestamped.
- CSP reporting does not collect prohibited personal or private-source data.

## 14. Phase 5 — Managed-host qualification and immutable staging

### PRV3-HOST-01 — Select and qualify the managed WordPress provider

**Priority/Risk:** P0 / Critical
**Owner:** Platform owner with security, legal, and operations approval
**Dependencies:** Business/vendor process
**References:** [Managed deployment](../operations/managed-wordpress-deployment.md),
[database privileges](../operations/database-privileges.md)

**Qualification matrix**

| Capability | Required evidence |
|---|---|
| Runtime | Supported PHP 8.3+, WordPress 7.0.2+, database/version/extensions, WP-CLI, cron, and MU-plugin compatibility. |
| Deployment | Immutable or controlled first-party artifact deployment, release identity, rollback, staging parity, and audit log. |
| Security | MFA/SSO options, least privilege, WAF/DDoS/rate limiting, secret/config management, vulnerability response, access logs. |
| Data | Region, processors, encryption, backup isolation, retention, export/deletion, breach terms, and legal agreement. |
| Operations | Uptime/SLA, support escalation, monitoring/export, maintenance windows, incident communication, resource limits. |
| Network | TLS/HSTS, trusted proxy behavior/ranges, DNS integration, outbound mail/API behavior, IP restrictions. |
| Recovery | Backup schedule/retention, point-in-time capability, restore process, achieved RPO/RTO evidence, disaster recovery. |

**Acceptance criteria**

- No mandatory capability is answered only by marketing material.
- Contract, data-processing, security, and support terms are approved.
- Provider limitations are translated into configuration, tests, monitoring, or
  accepted risks with owners and expiry.

### PRV3-STG-01 — Build private production-like staging

**Priority/Risk:** P0 / Critical
**Owner:** Platform owner
**Dependencies:** PRV3-HOST-01, PRV3-CI-02, PRV3-BOOT-02

**Implementation**

- Provision staging with production-equivalent PHP, WordPress, database,
  extensions, edge, TLS, cron, mail integration shape, secrets, filesystem
  policy, and plugin/theme allowlist.
- Require authentication or network restriction; add noindex and prevent
  outbound indexing.
- Use synthetic/sanitized data only.
- Disable real analytics, affiliate destinations, and customer mail unless
  explicitly routed to test sinks.
- Configure least-privilege accounts and audit access.
- Run clean bootstrap and migrations through supported managed-host mechanisms.

**Acceptance criteria**

- Environment comparison shows every material difference and risk disposition.
- Staging is private, noindex, and unable to contact unintended real users.
- Preflight, migrations, audit-chain checks, role matrix, and smoke tests pass.

### PRV3-STG-02 — Deploy and identify the exact candidate artifact

**Priority/Risk:** P0 / Critical
**Owner:** Release manager and platform owner
**Dependencies:** PRV3-STG-01, PRV3-QA-04

**Implementation**

1. Freeze a candidate commit.
2. Build the artifact from a clean clone using the full SHA.
3. Verify deterministic reproduction, manifest, file allowlist, SBOM, licenses,
   and security scans.
4. Record SHA-256 checksum and sign/attest according to organizational policy.
5. Deploy that exact artifact to staging without ad hoc server-side edits.
6. Expose a protected or non-sensitive release identity mechanism for operators.
7. Verify the deployed files/checksum using the provider-supported method.

**Acceptance criteria**

- Source SHA, artifact checksum, deployment ID, and staging release identity
  agree.
- No file is manually edited after deployment.
- Rebuild checksum matches.
- Rollback artifact is identified and retained before promotion.

## 15. Phase 6 — Staging acceptance and operational drills

### PRV3-ACC-01 — Run full staging application acceptance

**Priority/Risk:** P0 / High
**Owner:** Release manager
**Dependencies:** PRV3-STG-02, Phases 2–4 implementation complete

**Implementation**

- Run `wp longevity preflight` and `wp longevity acceptance` against the staging
  candidate.
- Execute migrations twice to demonstrate safe idempotency where supported.
- Run smoke, integration, E2E, accessibility automation, Lighthouse, and k6
  candidate profiles.
- Validate routes, navigation, search, contact, headers, schema, robots/noindex,
  canonical URLs, feeds, 404s, redirects, REST projections, private record
  isolation, and publication readiness behavior.
- Verify cron, freshness, queues, outbox, backfill, audit chain, legal hold,
  roles, and approval invalidation.

**Acceptance criteria**

- Acceptance output has no blocking, error, unknown-external, or
  environment-mismatch result.
- Warnings have an owner and approved disposition.
- Test evidence is tied to the deployed candidate identity.

### PRV3-ACC-02 — Execute failure, recovery, and rollback drills

**Priority/Risk:** P0 / Critical
**Owner:** Operations owner and release manager
**Dependencies:** PRV3-ACC-01, PRV3-OPS-01, PRV3-OBS-01

**Drills**

- Application deployment rollback.
- Database restore into isolation and application verification.
- Database unavailable/degraded.
- Cron stopped and worker heartbeat missing.
- Queue backlog, poison item, and dead letter.
- Mail provider failure.
- Audit-chain integrity failure.
- Publication-lock exhaustion.
- WAF false positive and emergency rule rollback.
- CSP enforcement regression and report-only rollback.
- Credential revocation/rotation.

**Acceptance criteria**

- Each injected condition is detected within the approved target.
- Alert reaches the correct owner; acknowledgement and escalation work.
- Runbook recovery meets approved RTO where applicable.
- Rollback does not bypass migrations or editorial governance.
- Evidence records actual rather than assumed outcomes.

### PRV3-ACC-03 — Perform security and privacy acceptance

**Priority/Risk:** P0 / Critical
**Owner:** Security reviewer and privacy/legal approver
**Dependencies:** PRV3-SEC-01, PRV3-PRIV-01, PRV3-PRIV-02

**Coverage**

- Authentication, authorization, custom capabilities, metadata writes.
- CSRF/nonces, injection, XSS/escaping, SSRF/file behavior where applicable.
- REST/public projections and private claims/sources.
- Headers, TLS, cookies, caching, robots, admin exposure.
- Contact abuse, proxy spoofing, retention, legal hold, export/erasure.
- Secret, dependency, artifact, SBOM, and license review.
- Managed-host and third-party data flows.

**Acceptance criteria**

- No unresolved critical or high finding.
- Medium findings have owners and launch disposition.
- Retest confirms remediation.
- Privacy/legal signs actual data flows and procedures.

### PRV3-ACC-04 — Validate performance and capacity

**Priority/Risk:** P1 / High
**Owner:** Operations owner and application engineering lead
**Dependencies:** PRV3-PERF-01, PRV3-STG-02

**Implementation**

- Establish cold/warm cache baselines and test representative reader journeys.
- Measure origin and edge behavior, database load, PHP workers, memory, CPU,
  error rate, queue/cron interference, and external service latency.
- Test within provider-approved limits; coordinate any material load.
- Establish launch capacity, scaling/support escalation, and abort thresholds.
- Compare results to documented budgets and prior baseline.

**Acceptance criteria**

- Candidate meets approved latency, availability, and error thresholds.
- No cache behavior exposes private or authenticated content.
- Capacity and support escalation are documented for the launch window.

## 16. Phase 7 — Human review gates

These tickets cannot be signed by an AI agent.

### PRV3-HUM-01 — Editorial, source, and medical governance review

**Priority/Risk:** P0 / Critical
**Owners:** Editorial lead, medical reviewer, source-registry reviewer

**Review**

- Launch corpus and route publication state.
- Material claims linked to the private source registry.
- Evidence grades assigned and approved only by authorized humans.
- Safety language, contraindications, limitations, dates, authors, reviewers,
  corrections, and freshness.
- AI assistance disclosure and named-human verification.
- Publication gates, approval fingerprints, and invalidation behavior.

**Acceptance criteria**

- Every public material claim and medical/safety statement has required named
  review.
- Placeholder, draft, stale, or unapproved content is not public.
- Human reviewers sign the candidate-specific corpus inventory.

### PRV3-HUM-02 — Product-testing, commercial, and disclosure review

**Priority/Risk:** P0 / High
**Owners:** Product-testing lead and commercial/disclosure reviewer

**Review**

- Testing methodology, sample identity, laboratory records, limitations, score
  model/version, ranking reproducibility, and change controls.
- Affiliate registry, disclosure placement, independence controls, destination
  links, incident procedure, and commercial-review separation.
- No unsupported ranking, badge, reviewer credential, or test result is public.

**Acceptance criteria**

- Public product/testing records have complete governed evidence.
- Commercial relationships do not alter evidence grades or medical review.
- Disclosures are visible, accessible, accurate, and human approved.

### PRV3-HUM-03 — Legal and privacy launch review

**Priority/Risk:** P0 / Critical
**Owners:** Privacy/legal approver

**Review**

- Privacy notice, terms, medical disclaimer, affiliate disclosure, corrections,
  AI assistance disclosure, consent, cookies/analytics, contact retention,
  processors, international transfers, data-subject requests, and legal holds.
- Credential incident legal disposition and any notification duties.
- Managed-host and vendor contractual/data-processing terms.

**Acceptance criteria**

- Approved policies match deployed behavior and effective dates.
- Incident obligations are completed.
- Named counsel/privacy approver records candidate-specific approval.

### PRV3-HUM-04 — Manual accessibility and usability review

**Priority/Risk:** P0 / High
**Owner:** Accessibility reviewer with representative users where available
**References:** [Accessibility checklist](../testing/accessibility-checklist.md),
[usability protocol](../operations/usability-review-protocol.md)

**Required coverage**

- Keyboard-only operation, visible focus, skip links, dialogs/forms, error
  recovery, navigation, search, and contact.
- 200% and 400% zoom/reflow, text spacing, high contrast/forced colors,
  reduced-motion behavior, and touch target use.
- Current representative NVDA/Windows, VoiceOver/macOS/iOS, and
  TalkBack/Android journeys, with browser/version recorded.
- Home, hub/category, article/guide, review/ranking, trust/legal, search,
  contact, 404, empty, and validation-error states.
- Tables, evidence/source relationships, badges, disclosures, headings,
  landmarks, accessible names, status messages, and link purpose.

**Acceptance criteria**

- No unresolved launch-blocking WCAG 2.1 AA defect.
- Findings include severity, route, technology, reproduction, owner, and retest.
- Named reviewer signs the final manual report.

## 17. Phase 8 — Candidate freeze, decision, launch, and hypercare

### PRV3-REL-01 — Freeze and attest the final candidate

**Priority/Risk:** P0 / Critical
**Owner:** Release manager
**Dependencies:** Phases 0–7 complete

**Implementation**

- Select one full commit SHA after all remediation merges.
- Re-run the complete required CI matrix.
- Build, reproduce, verify, checksum, scan, and attest the artifact.
- Deploy that exact artifact to staging and run final acceptance.
- Freeze code/content/config changes except approved release blockers.
- Any post-freeze change creates a new candidate and invalidates affected
  evidence.
- Assemble the release evidence defined in
  [release acceptance](../testing/release-acceptance.md).

**Acceptance criteria**

- All evidence references one source SHA and one checksum.
- Required jobs are green and current.
- Staging deployment identity matches the attested artifact.
- No critical/high risk remains without a valid signed exception; critical
  credential/private-data/governance risks are not exception-eligible.

### PRV3-REL-02 — Conduct the signed go/no-go review

**Priority/Risk:** P0 / Critical
**Owner:** Release manager
**Dependencies:** PRV3-REL-01

**Agenda**

1. Confirm candidate identity and evidence completeness.
2. Review incident closure and security/privacy status.
3. Review automated and staging acceptance.
4. Review human approvals.
5. Review known risks and exceptions.
6. Confirm backup/restore, rollback artifact, commands, triggers, and owners.
7. Confirm launch window, provider support, monitoring, communications, and
   decision authority.
8. Record `GO`, `NO-GO`, or `DEFER`, with signatures and timestamp.

**Acceptance criteria**

- All mandatory roles are represented by named authorized humans.
- Missing evidence produces `NO-GO` or `DEFER`.
- The decision record cannot be inferred from merged code or a green CI badge.

### PRV3-REL-03 — Promote, verify, and monitor production

**Priority/Risk:** P0 / Critical
**Owner:** Release manager and platform/operations owner
**Dependencies:** Signed `GO`

**Implementation**

- Confirm fresh backup and rollback readiness immediately before promotion.
- Enable the approved maintenance/change window.
- Promote the exact attested artifact using the managed-host procedure.
- Run migrations once through the approved mechanism.
- Verify release identity, HTTPS, headers, robots/canonical, key public routes,
  contact/mail, cron/workers, audit chain, queues, metrics, alerts, and absence
  of synthetic fixtures.
- Monitor launch thresholds and error/latency/security/contact/editorial signals
  through the defined hypercare period.
- Record any incident and execute rollback when a trigger is met.

**Mandatory rollback triggers**

- Release identity/checksum mismatch.
- Migration, audit-chain, authorization, or private-data control failure.
- Public exposure of draft, claim, source, fixture, credential, or personal data.
- Material medical/editorial integrity defect.
- Sustained availability/error/latency breach beyond approved threshold.
- Contact loss/duplication or critical worker/queue failure without timely
  recovery.
- Security-header, TLS, WAF, or CSP regression with unacceptable exposure.

**Acceptance criteria**

- Production verification passes on the deployed identity.
- Hypercare ends only after stable metrics and resolved launch incidents.
- Launch evidence, decisions, and lessons are archived without secrets or
  personal data.

## 18. Pull-request sequence

The following sequence limits cross-cutting risk. Independent documentation or
external-provider work may proceed concurrently, but dependency gates remain.

| Batch | Tickets | Merge condition |
|---|---|---|
| A — Trust foundation | PRV3-IR-01, IR-02, IR-03, GOV-01, CI-01 | Incident procedure authorized; remote history and branch governance trustworthy. |
| B — Runtime truth | PRV3-CI-02, CI-03, CI-04 | Supported pinned platform and actionable security jobs. |
| C — Environment contract | PRV3-BOOT-01, BOOT-02, BOOT-03 | Clean bootstrap and isolated CI fixtures pass twice. |
| D — Test alignment | PRV3-TEST-01, PERF-01, PERF-02 | Browser/accessibility/performance tests use canonical route state. |
| E — Code assurance | PRV3-QA-01, QA-02, QA-03, QA-04 | Full automated candidate matrix required and green. |
| F — Operational controls | PRV3-OBS-01, OPS-01 through OPS-03, PRIV-01, PRIV-02, SEC-01, CSP-01 | Controls ready for production-like exercise. |
| G — Managed staging | PRV3-HOST-01, STG-01, STG-02 | Exact candidate artifact running privately on supported host. |
| H — Acceptance | PRV3-ACC-01 through ACC-04 | Full application, failure, security/privacy, and performance evidence passes. |
| I — Human approval | PRV3-HUM-01 through HUM-04 | Named reviewers sign candidate-specific evidence. |
| J — Release | PRV3-REL-01 through REL-03 | Frozen candidate, signed GO, controlled promotion and hypercare. |

For PHPCS remediation, use smaller pull requests:

1. SQL preparation and query allowlists.
2. Nonces, capability checks, input validation, and unslashing.
3. Output and exception escaping.
4. Narrow custom-capability configuration.
5. Mechanical auto-fix batches by subsystem.
6. Documentation/baseline enforcement.

Do not combine history rewriting, runtime version changes, mass formatting, and
behavioral security fixes in one pull request.

## 19. Candidate evidence index

Create one release evidence index with the following minimum fields:

| Evidence item | Required identity |
|---|---|
| Source | Full commit SHA, branch/tag, pull request, review approvals |
| Artifact | Filename, SHA-256, build run, reproducibility result, attestation |
| Dependencies | Composer/npm lock checks, audits, SBOM, licenses, tool versions |
| CI | Required-job list, run URL/ID, head SHA, conclusion, artifact retention |
| Runtime | PHP, WordPress, database, image/provider versions, extensions |
| Staging | Environment ID, deployment ID, artifact checksum, parity report |
| Tests | Commands, versions, dataset/fixture mode, result, report checksum |
| Security | SAST, dependency, secret, artifact, configuration, edge/TLS results |
| Operations | Backup/restore, RPO/RTO, cron/workers, alerts, mail, rollback drills |
| Privacy/legal | Data inventory, DSR test, consent/proxy test, policy approvals |
| Editorial/medical | Corpus inventory, reviewer/source approvals, freshness |
| Testing/commercial | Methodology, ranking, affiliate/disclosure approvals |
| Accessibility | Automated results and signed manual assistive-technology report |
| Decision | Exceptions, named approvers, GO/NO-GO, timestamp, launch window |
| Production | Deployment identity, verification, monitoring, incidents, hypercare close |

Evidence links must remain valid for the retention period in the release policy.
CI artifacts with short retention must be copied to approved durable storage.

## 20. Risk register

| Risk | Initial level | Primary mitigation | Residual acceptance owner |
|---|---|---|---|
| Exposed database credential/history | Critical | Rotate, review logs, rewrite all refs, purge caches, rescan, legal disposition | Incident lead + security + legal |
| Unprotected or stale remote candidate | Critical | Branch protection, required reviews/jobs, candidate SHA reconciliation | Repository administrator |
| Unsupported WordPress test runtime | High | Pin supported versions/digests and enforce preflight | Engineering + platform |
| CI tests depend on local/public draft state | High | Canonical route-state contract and CI-only fixtures | Engineering + editorial |
| Large PHPCS security backlog | Critical | Security-first manual triage, regression tests, narrow exceptions | Security reviewer |
| False-positive green CI | Critical | Required job registry, harness canaries, no skipped-as-pass results | Release manager |
| Private claim/source exposure | Critical | REST/page/cache tests, data classification, human governance review | Security + editorial |
| Backup exists but is not restorable | Critical | Timed isolated restore against RPO/RTO | Operations owner |
| Missing cron/worker processing | High | Real cron, heartbeats, queue alerts, failure drills | Operations owner |
| Permanent/noisy monitoring alerts | High | Metric contract, windowed rules, inhibition, alert drills | Operations owner |
| Contact privacy/delivery failure | High | DSR/retention/hold process, authenticated mail, dead-letter monitoring | Privacy + operations |
| Proxy spoofing/rate-limit bypass | High | Provider ranges, chain parsing tests, fail-safe configuration | Security + platform |
| CSP enforcement outage | High | Observation, triage, staging enforcement, tested rollback | Security + engineering |
| Provider limitation discovered late | High | Qualification matrix and parity report before candidate freeze | Platform owner |
| Human approval inferred from automation | Critical | Named signed gates; AI prohibited from approval | Release manager |
| Post-freeze drift | Critical | New candidate on any change; immutable artifact identity | Release manager |

Update the risk register at each phase exit. Decreasing severity requires
evidence and a named owner, not optimism.

## 21. Documentation updates required during execution

Update documentation in the same pull request as the behavior it describes:

- Quick start and bootstrap commands.
- Supported WordPress/PHP/database and managed-host matrix.
- Test strategy and clean CI fixture behavior.
- Release-required jobs and release process.
- Monitoring metric catalog, alert rules, and runbooks.
- Backup/restore RPO/RTO and drill procedure.
- Mail, contact, privacy, data-subject, consent, and trusted-proxy operations.
- CSP status and enforcement decision.
- Staging deployment, artifact verification, rollback, and acceptance.
- Production readiness evidence and open-gate status.

Documentation must not state that a control is operational until environment
evidence exists. Use `prepared`, `configured`, `tested`, and `approved`
precisely.

## 22. Stop-the-line conditions

Stop candidate progression and set/retain **NO-GO** when any of these occurs:

- A credential, private source, personal record, database dump, or archive is
  found in current or reachable history/artifacts.
- The incident cannot establish credential revocation or access-review scope.
- Required CI is missing, stale, skipped, untrusted, or running on an
  unsupported platform.
- Tests require bypassing publication, metadata, medical, disclosure, privacy,
  or authorization gates.
- A critical/high security issue is unresolved.
- Artifact identity or reproducibility cannot be proven.
- Staging differs materially from production without tested mitigation.
- Backup restore or deployment rollback fails.
- Monitoring cannot detect and route a critical injected failure.
- Required human review is missing or performed by an unauthorized role.
- A post-freeze code, content, dependency, configuration, or artifact change
  invalidates existing evidence.

## 23. Definition of done for every implementation ticket

A ticket is complete only when:

- Scope, owner, dependencies, and risk are recorded.
- Acceptance criteria are objectively met.
- Relevant tests were added or updated and pass.
- Security/privacy/editorial impacts were reviewed.
- Documentation matches behavior.
- `MANIFEST.sha256` is regenerated and verified when tracked files change.
- No secret, private source, personal data, generated report, database dump, or
  unrelated file is included.
- Rollback is documented and, for high/critical changes, exercised where safe.
- Durable evidence is linked.
- Required human/external approval is signed; otherwise status is
  `Evidence pending`, not `Complete`.

## 24. Recommended first execution batch

Begin with the trust foundation. Do not start by mass-auto-fixing PHPCS or
deploying an unaudited branch.

1. Assign the incident lead and repository administrator.
2. Rotate/revoke the exposed credential and start the access-log review.
3. Approve the history-rewrite procedure and inventory all refs/mirrors.
4. Coordinate branch protection with the rewrite window.
5. Reconcile and publish the intended branch after history sanitation.
6. Run current remote CI and classify each failure.
7. In parallel, prepare two bounded code pull requests:
   - WordPress runtime pin/preflight alignment.
   - Canonical route-state/bootstrap contract tests.
8. Only after the remote baseline is trustworthy, begin PHPCS security triage.

The first batch exits when:

- the incident has named ownership and containment evidence;
- sanitized remote history is verified;
- branch protection is active;
- the intended head is present remotely;
- required CI runs on that exact head; and
- runtime and bootstrap remediation have failing regression tests ready.

## 25. Implementation-agent handoff prompt

Use the following prompt for each bounded implementation ticket:

> Implement ticket `<ID>` from
> `docs/implementation/production-readiness-v3-implementation-plan.md`.
> First read `AGENTS.md`, the ticket, its referenced source files, and current
> readiness evidence. Confirm the working tree and preserve unrelated changes.
> Record the baseline failure, implement only the authorized ticket scope, add
> regression tests, run targeted and full applicable checks, regenerate and
> verify `MANIFEST.sha256`, and report changed files, results, residual risks,
> evidence, and rollback. Do not mark a human/external gate complete, publish
> content, verify medical claims, assign evidence grades, expose private
> claims/sources, or weaken checks. Stop if the work requires new authority,
> destructive external action, a secret, or a material scope change.

### Required implementation response

```text
Ticket:
Outcome:
Baseline failure:
Files changed:
Tests added/changed:
Commands and results:
Security/privacy/editorial review:
Evidence:
Rollback:
Residual risks/blockers:
Human/external gates still open:
Manifest verification:
```

## 26. Final go/no-go checklist

The release manager must answer every item with evidence:

- [ ] Credential incident is closed by authorized human roles.
- [ ] Canonical remote history passes full-history secret/prohibited-file scans.
- [ ] Default/release branches enforce reviews and required checks.
- [ ] Candidate full SHA and artifact checksum are frozen and consistent.
- [ ] All release-required CI jobs pass on the candidate.
- [ ] Platform preflight passes on managed staging.
- [ ] Canonical bootstrap is idempotent and production-safe.
- [ ] CI fixtures are isolated and absent from artifacts/production.
- [ ] PHPCS security and mechanical gates pass or have valid narrow exceptions.
- [ ] PHPUnit/coverage, PHPStan, Psalm, JS, CSS, shell, content, links,
      freshness, architecture, manifest, SBOM, license, and dependency checks
      pass.
- [ ] Browser, accessibility automation, Lighthouse, integration, and k6
      candidate profiles pass from clean setup.
- [ ] Exact artifact is verified on private production-like staging.
- [ ] Migrations, audit chain, authorization, route, REST, and privacy controls
      pass.
- [ ] Backups and an isolated restore meet approved RPO/RTO.
- [ ] Deployment rollback passes within the approved target.
- [ ] Real cron, workers, queues, invalidation, backfill, outbox, and freshness
      are healthy.
- [ ] SMTP and SPF/DKIM/DMARC pass; contact retry/failure handling is verified.
- [ ] WAF, rate limits, TLS, headers, MFA, least privilege, and secrets are
      approved.
- [ ] Metrics, alerts, routing, escalation, and runbooks pass failure drills.
- [ ] Privacy data inventory, DSR, retention, legal hold, trusted proxy, and
      consent behavior are approved.
- [ ] CSP observation, enforcement, and rollback gates pass.
- [ ] Editorial, source-registry, medical, testing, commercial, disclosure,
      legal/privacy, and accessibility reviewers sign.
- [ ] No placeholder, draft, synthetic, stale, or unapproved material is public.
- [ ] Release, rollback, communication, provider support, and hypercare owners
      are named and available.
- [ ] Signed go/no-go decision references the exact candidate and launch window.

Until every mandatory item is checked with valid evidence, the authoritative
launch decision remains **NO-GO**.
