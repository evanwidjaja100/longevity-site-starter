# Production Readiness Remediation Plan

**Prepared:** 2026-07-22
**Repository:** Longevity Evidence Lab
**Baseline branch:** `improvement/production-readiness-v2`
**Baseline commit:** `11fbe32`
**Status:** Proposed for human technical and governance review
**Launch posture:** **NO-GO until every P0 gate in this plan has passed on the same immutable commit**

## 1. Purpose

This document converts the 2026-07-22 repository audit into an ordered implementation program. It is intended to be used as the execution source of truth for the next production-readiness effort.

The earlier `production-readiness-v2-opencode-plan.md` remains useful design history. This plan adds the defects discovered after running the reconstructed PRv2 implementation in the authoritative Git checkout, including:

- the same-request publication approval race;
- claim-verification and affiliate-control bypasses;
- incomplete corrections and protocol approval lifecycles;
- a clean-checkout CI path that cannot currently pass;
- false-positive content, analytics, REST, accessibility, and release-evidence checks;
- a confirmed category redirect/ranking-sort defect;
- incomplete operational controls and human launch evidence.

This plan does not authorize publication, medical review, evidence grading, legal approval, credential verification, product-testing approval, history rewriting, production deployment, or destructive data migration. Those actions require the named human owners identified below.

## 2. Outcomes

The program is complete only when the following outcomes are true:

1. An approval applies only to the exact immutable revision and dependency state reviewed by the approver.
2. No generic metadata, REST, classic-editor, CLI, import, cron, or service path can forge governance state.
3. HTML, REST, schema, feeds, cards, and rankings expose the same fail-closed public projection.
4. A clean checkout installs exact dependencies and runs every required test without hidden prerequisites.
5. Release evidence cannot be green when a required job failed, skipped, was unavailable, or produced no artifact.
6. Staging proves migration, publication, correction, contact, privacy, backup, restore, rollback, accessibility, and operational behavior.
7. Named humans approve the legal, editorial, medical, testing, commercial, privacy, technical, and operational launch gates.

## 3. Non-negotiable constraints

- WordPress remains the canonical CMS.
- `longevity-core` remains the first-party editorial control plane.
- Existing public URLs and stored metadata require a documented compatibility or redirect path before change.
- Publication and public trust signals fail closed when state is missing, stale, ambiguous, or unverifiable.
- AI must not invent or approve medical claims, citations, testing observations, reviewer credentials, evidence grades, disclosures, or legal wording.
- Every material claim must be checked against the source registry by a named human.
- No test may be weakened, conditionally skipped, or made less specific merely to obtain a green result.
- Database changes are additive, idempotent, restart-safe, bounded, and rollback-aware.
- Approval and audit records are preserved during rollback.
- Synthetic fixtures never run in production and must be visibly distinguishable from real content.
- No production secret or private health/contact data may enter Git, CI artifacts, analytics, or AI systems.

## 4. Roles and decision ownership

| Role | Accountable decisions |
|---|---|
| Technical owner | Architecture, merge approval, migrations, release gate, rollback decision |
| Security reviewer | Authorization, threat model, secrets, audit integrity, dependency exceptions |
| QA owner | Test design, baseline approval, visual changes, release evidence completeness |
| DevOps owner | Hosting model, staging, deploy artifact, TLS, WAF, cron, backups, restore, monitoring |
| Editorial governance owner | Field ownership, separation of duties, stale-content policy, override policy |
| Fact-check owner | Claim/source verification and source-registry reconciliation |
| Medical reviewer | Medical claims, safety language, medical corrections, reviewer attestations |
| Testing lead | Protocol versions, test-record validity, product-testing evidence |
| Commercial owner | Affiliate registry, relationship/disclosure approval |
| Privacy/legal owner | Privacy, terms, retention, consent, contact handling, jurisdictional review |
| Release manager | Final evidence index, go/no-go record, post-release observation |

One person may hold multiple operational roles only where the approved separation-of-duties policy explicitly permits it. Required actor inequality must be enforced in services, not inferred from role names.

## 5. Status and priority vocabulary

Use only these implementation statuses:

- `not_started`
- `in_progress`
- `implemented_unverified`
- `verified_local`
- `verified_ci`
- `verified_staging`
- `human_approved`
- `blocked`

Priorities:

- **P0:** release blocker; no public production launch.
- **P1:** required before launch unless a named owner records a time-bounded exception that does not weaken health, privacy, or security controls.
- **P2:** post-P0 hardening that should normally ship before launch.
- **Human gate:** cannot be completed by code or AI alone.

`implemented` is never equivalent to `verified`, and `unavailable` is never equivalent to `passed`.

## 6. Current audited baseline

The following baseline must be re-recorded when implementation begins:

- Current branch: `improvement/production-readiness-v2` at `11fbe32`.
- Working tree at audit time: 83 modified/staged entries and 54 untracked entries.
- The current branch was not present on the GitHub remote and had no authoritative remote CI run.
- The release manifest did not match the intended tracked tree.
- All tracked shell scripts were mode `100644`, while CI invoked several directly.
- A local database dump existed in earlier local history and included a credential prefix.
- Docker bootstrap completed in an isolated environment, but MySQL exceeded the initial health budget and MU-plugin migrations queried tables before WordPress installation completed.
- Configured lint and content scripts passed, but several passed because their scope or assertions were incomplete.
- Targeted Playwright execution passed 34 of 39 tests; the ranking-sort defect and bootstrap/fixture mismatch were confirmed.
- Accessibility execution passed 64 of 65 checks; the remaining CSS-zoom test is not a valid 400-percent browser-zoom model.
- Composer-managed PHP quality suites and dependency vulnerability audits still require a clean supported environment.

## 7. Program sequence and dependencies

| Phase | Priority | Primary outcome | Depends on |
|---|---:|---|---|
| R0 | P0 | Immutable source-of-truth release baseline | None |
| R1 | P0 | Reproducible, fail-closed CI and evidence | R0 |
| R2 | P0 | Revision-bound publication integrity | R0; test harness from R1 may develop in parallel |
| R3 | P0/P1 | Governed record integrity and separation of duties | R2 |
| R4 | P1 | Correct public routes, rankings, SEO, and performance | R2 public projection |
| R5 | P1 | Contact privacy, analytics integrity, and content governance | R1, R2 |
| R6 | P0 verification | Complete adversarial and cross-channel verification | R1-R5 |
| R7 | P0 human/external | Production-like staging and operational controls | R6 |
| R8 | P0 human gate | Content review, launch decision, and observation | R7 |

R0 must complete first. R1 and R2 can then proceed as separate pull requests, but no later phase may rely on a green build until R1 is verified. R8 is never automatic.

## 8. Pull-request and change discipline

- Use one focused pull request per phase, with smaller pull requests for migration-heavy or security-critical work.
- Begin every pull request from a clean checkout of an immutable parent commit.
- Do not mix CI repair, workflow security, reader-facing redesign, and infrastructure changes in one pull request.
- Add the failing regression or adversarial test before or with the implementation fix.
- Each pull request must document behavior before and after, files changed, migrations, backfill, tests, evidence, rollback, and human decisions.
- Use explicit release artifact versioning and retain the previous known-good artifact until the observation window closes.
- Regenerate `MANIFEST.sha256` only after the intended file set is staged and stable.

---

# Phase R0 - Establish the authoritative release baseline

## Objective

Turn the current dirty working tree into a reviewable, immutable source state without losing unrelated human work.

## R0.1 - Inventory and classify the working tree

**Priority:** P0
**Owner:** Technical owner
**Primary areas:** Git index, untracked files, `production-readiness-v2.patch`, `ss`, generated reports

### Implementation

1. Capture branch, commit, status, staged diff, unstaged diff, untracked inventory, file modes, and ignored-file inventory.
2. Classify every changed path as:
   - intended PRv2 implementation;
   - generated evidence;
   - local scratch artifact;
   - dependency/vendor artifact;
   - unrelated human work;
   - secret or sensitive artifact.
3. Remove or relocate scratch artifacts only after the technical owner confirms their classification.
4. Split unrelated work into separate branches or commits without rewriting it.
5. Confirm that `composer.lock`, `package-lock.json`, CI workflows, migrations, tests, and all new runtime classes are intentionally tracked.

### Acceptance and evidence

- Every current status entry has a recorded disposition.
- The intended release tree is represented by Git, not by an external patch file.
- No generated browser report, database dump, backup archive, secret, or terminal capture is staged.
- Evidence: `reports/remediation/r0-working-tree-inventory.txt` and a human-approved disposition table.

### Rollback

This step is organizational only. Preserve patches or worktree copies before moving human work. Do not use `git reset --hard` or `git clean -fd`.

## R0.2 - Sanitize sensitive local history

**Priority:** P0
**Owners:** Technical owner and security reviewer
**Human gate:** Required before rewriting or force-pushing history

### Implementation

1. Identify every commit containing database dumps, archives, passwords, tokens, salts, private contact data, or production configuration.
2. Determine whether any affected commit or object reached a remote, fork, artifact store, backup, or collaborator clone.
3. Rotate any real or reused credential before treating history cleanup as sufficient.
4. Prepare a documented history-rewrite plan if required.
5. Enable remote secret scanning and push protection where available.

### Acceptance and evidence

- Credential rotation has a named owner, date, and redacted evidence.
- A secret scan of reachable history reports no unresolved real secret.
- If history was rewritten, all collaborators receive recovery instructions and remote refs are verified.

### Rollback

Credential rotation is not rolled back. History rewriting requires a separate approved recovery plan and a protected backup of the pre-rewrite repository.

## R0.3 - Create the clean baseline

**Priority:** P0
**Owner:** QA owner

### Implementation

1. Create a fresh clone or isolated Git worktree from the candidate commit.
2. Record OS, PHP, Composer, Node, npm, Python, Docker, Compose, WordPress, MySQL, and browser versions.
3. Verify both lockfiles from Git.
4. Run exact dependency installation without updating dependencies.
5. Run every currently configured suite and record `PASS`, `FAIL`, or `UNAVAILABLE` with exit code and test count.
6. Record the manifest path-set and hash comparison separately from application tests.

### Acceptance and evidence

- The baseline starts with `git status --porcelain` empty.
- Each configured test is represented honestly; no missing tool becomes a pass.
- The report includes exact commit SHA, lockfile hashes, image identifiers, command lines, exit codes, and failed test names.
- Evidence: update `docs/testing/production-readiness-v2-baseline.md` or create a dated successor linked from this plan.

## R0 exit gate

- [ ] Intended source is committed and reviewable.
- [ ] Scratch and sensitive artifacts are excluded.
- [ ] Credential/history decision is recorded.
- [ ] Clean-checkout baseline is accepted by the technical and QA owners.
- [ ] Launch posture remains NO-GO.

---

# Phase R1 - Make CI and release evidence trustworthy

## Objective

Make a clean Linux checkout capable of running the complete release pipeline with no hidden local state, false-positive aggregate, or missing artifact.

## R1.1 - Fix shell execution and local/CI parity

**Priority:** P0
**Primary files:** `.github/workflows/ci.yml`, `Makefile`, tracked `*.sh`

### Implementation

1. Choose one policy:
   - commit executable Git modes for executable scripts; or
   - invoke scripts explicitly with `bash` or `sh` everywhere.
2. Use the same repository commands locally and in CI.
3. Correct the PHPUnit suite name mismatch between `Makefile` and `phpunit.xml.dist`.
4. Add strict shell options appropriate to each script and validate with ShellCheck.
5. Add job-level `timeout-minutes` and guaranteed teardown for Docker jobs.

### Acceptance

- A clean Ubuntu checkout executes every script without permission errors.
- `make validate`, `make test`, and `make deploy-check` call the same underlying commands as CI.
- No job relies on a shell profile, global package, or pre-existing directory.

## R1.2 - Repair dependency and supply-chain integrity

**Priority:** P0/P1
**Primary files:** `composer.lock`, `package-lock.json`, dependency verification scripts, CI security jobs

### Implementation

1. Verify exact locked Composer and npm installs from a clean checkout.
2. Make `npm ls --all` succeed with no invalid or extraneous installed-tree entries.
3. Run Composer and npm vulnerability audits without silently accepting unavailable registries.
4. Scan the resolved WordPress, MySQL, PHP/Composer, WP-CLI, and Playwright images rather than only the Compose file.
5. Pin production and deterministic CI images by digest; document the update process.
6. Generate application and container SBOMs tied to the commit and image digests.

### Acceptance

- Exact installs succeed twice from separate clean directories.
- No unwaived high or critical dependency/image vulnerability remains.
- Every exception has an owner, rationale, compensating control, and expiry date.

## R1.3 - Make the manifest deterministic

**Priority:** P0
**Primary files:** `MANIFEST.sha256`, `scripts/release-file-list.sh`, `scripts/regenerate-manifest.sh`, `.gitattributes`

### Implementation

1. Define whether the manifest covers the full source repository or a minimal deployable artifact. Prefer a separate runtime artifact manifest.
2. Generate the path set only from the intended staged/tracked release state.
3. Normalize line endings or hash Git/index bytes so Windows and Linux produce the same result.
4. Include lockfiles and all release-critical runtime/configuration inputs.
5. Exclude reports, local environments, tests only when the deployment artifact contract explicitly excludes them.

### Acceptance

- Path-set equality and hash verification pass on clean Windows and Linux checkouts.
- Re-running generation without source changes produces no diff.
- A missing, extra, or modified runtime file fails verification.

## R1.4 - Create one canonical CI WordPress setup

**Priority:** P0
**Primary files:** `scripts/bootstrap.sh`, `scripts/create-test-fixtures.sh`, `.github/workflows/ci.yml`

### Implementation

1. Add a single CI preparation command that:
   - confirms an explicit non-production environment;
   - starts isolated MySQL and WordPress;
   - waits using bounded readiness checks;
   - runs installation/bootstrap;
   - loads synthetic fixtures;
   - writes a fixture-version marker;
   - verifies expected routes and record counts.
2. Use this command in Chromium, accessibility, cross-browser, visual, and Lighthouse jobs.
3. Make setup idempotent and teardown unconditional.
4. Ensure bootstrap and fixture scripts do not silently publish real or placeholder content outside explicit test environments.

### Acceptance

- Required synthetic guide/review routes return 200.
- Ineligible fixtures remain private/404.
- Ranking fixture count and trust-page status match centralized route expectations.
- Fixture loading refuses `production` and refuses an unset environment.
- Re-running setup produces no duplicates.

## R1.5 - Repair integration and artifact paths

**Priority:** P0

### Implementation

1. Create `reports/` inside every script before writing to it.
2. Align Playwright, Lighthouse, PHPUnit, PHPStan, PHPCS, lint, integration, Docker-log, and SBOM output paths with CI uploads.
3. Use `if-no-files-found: error` for mandatory evidence.
4. Upload Docker logs and environment diagnostics on failure.
5. Exclude unasserted `debug.spec.js` from release tests or remove it after human confirmation.

### Acceptance

- Every required job uploads a nonempty artifact on success and useful diagnostics on failure.
- Artifact names and paths are defined once and reused.

## R1.6 - Repair test discovery and suite boundaries

**Priority:** P0

### Implementation

1. Replace source-regex discovery with `phpunit --list-tests` or PHPUnit XML/JUnit discovery.
2. Convert procedural route assertions into a normal PHPUnit test class.
3. Require exact test IDs for authorization, publication snapshots, claims, credentials, REST boundaries, protocols, readiness, freshness, audit, affiliate, corrections, and contact privacy.
4. Separate functional Chromium, accessibility, visual, cross-browser, and Lighthouse suites.
5. Enable `forbidOnly` in CI and fail release evidence on unexpected skip, incomplete, risky, no-assertion, or retry-only pass.
6. Generate and human-approve Linux visual baselines using the pinned runtime image.

### Acceptance

- Functional Chromium does not execute visual or debug suites.
- Every declared critical test demonstrably executes.
- No missing UI prerequisite is treated as a pass.
- Visual tests run exactly once with Linux-owned baselines.

## R1.7 - Build a fail-closed release evidence aggregate

**Priority:** P0
**Primary files:** `.github/workflows/ci.yml`, `scripts/generate-release-evidence.sh`

### Implementation

1. Add every mandatory job, including dependency review, to the aggregate dependency list.
2. Download all upstream artifacts into the aggregate job.
3. Bind the evidence index to commit SHA, tree hash, lockfile hashes, manifest hash, workflow run ID, and image digests.
4. Fail when any mandatory result is failed, cancelled, skipped, unavailable, or missing.
5. Keep external/human checks as explicit `unknown_external`; they must prevent launch but should not be misrepresented as CI failures.
6. Checksum the final evidence pack and define retention.

### Acceptance

- Intentionally failing one upstream job makes the aggregate job fail.
- Removing one mandatory artifact makes the aggregate fail.
- Evidence from one commit cannot be reused for another.
- Branch protection requires the aggregate or all mandatory underlying checks.

## R1 exit gate

- [ ] Clean Linux and Windows integrity lanes pass.
- [ ] Both lockfiles and manifest are exact.
- [ ] Canonical WordPress setup is idempotent and fixture-safe.
- [ ] Every critical suite executes without unexpected skip/flakiness.
- [ ] Aggregate evidence fails closed.
- [ ] Repository administrator has configured required branch protection.

---

# Phase R2 - Bind publication to the exact reviewed revision

## Objective

Eliminate the highest-risk defect: publishing new material against approvals for an older post state.

## R2.1 - Add exploit-first regression tests

**Priority:** P0
**Primary files:** publication-gate unit/integration tests and REST/classic workflow tests

### Required scenarios

For an approved published post or review, attempt each change while requesting `publish`, `future`, or `private` in the same request:

- title;
- excerpt;
- content;
- author;
- featured image;
- evidence grade and rationale;
- summary, scope, limitations, region, and original contribution;
- medical/fact/testing/commercial fields;
- claims, sources, affiliate destinations, protocol, and test record;
- mixed allowed and denied metadata;
- REST, classic editor, WP-CLI, import, and direct service paths.

Each test must prove that the previously approved public revision remains unchanged and the proposed revision is not made public.

## R2.2 - Define the revision and fingerprint model

**Priority:** P0
**Owners:** Technical and editorial governance owners

### Recommended design

1. Treat the last approved public revision as immutable.
2. Save subsequent edits as a pending revision/draft without replacing the public revision.
3. Build one canonical prospective fingerprint from:
   - title, excerpt, content, author, featured image;
   - every governed metadata value;
   - normalized claim/source snapshots;
   - medical reviewer snapshot and scope;
   - protocol and test-record approval hashes;
   - commercial relationship and normalized destinations;
   - scoring model/configuration version.
4. Require each applicable approval to reference the same revision/fingerprint.
5. Permit promotion only through a transaction-like publication service after rechecking the complete fingerprint.

### Human policy decision

Choose the behavior for already-public content that becomes stale:

- preferred: keep the last approved revision public while the edited revision remains pending;
- safety exception: immediately noindex/unpublish the public item when a critical correction or medical-safety invalidation requires removal.

## R2.3 - Implement atomic publication promotion

**Priority:** P0
**Primary files:** `class-publication-gates.php`, `class-approval-service.php`, `class-approval-fingerprint.php`, approval repository/migrations

### Implementation

1. Create a publication orchestration service; do not distribute the final state transition across independent hooks.
2. Compute and verify the prospective fingerprint after WordPress has prepared the complete authorized request state but before public promotion.
3. Lock or otherwise protect the post/revision during final verification and promotion.
4. Re-read approvals and dependencies immediately before transition.
5. Reject first publication until a saved revision exists and all required approvals bind to it.
6. Ensure post-save invalidation cannot leave newly changed content public.
7. Record success and rejection in the durable audit log without sensitive payloads.

### Acceptance

- All R2.1 exploit tests fail before the fix and pass after it.
- Concurrent edit/approve/publish tests cannot expose an unapproved revision.
- A failed promotion leaves the prior approved public state intact.

## R2.4 - Add transitive invalidation

**Priority:** P0/P1

### Implementation

1. Create a dependency index from public content to claims, sources, reviewer snapshots, protocols, test records, affiliate records, and scoring configuration.
2. Invalidate the directly affected approval when a dependency changes.
3. Cascade invalidation to editorial approval and public eligibility.
4. Recompute hashes at read/publish time so missed hooks still fail closed.
5. Queue bounded background reconciliation for large dependency sets.

### Acceptance

- Every dependency edge has a mutation regression test.
- A disabled/missed hook cannot make a stale snapshot current.
- Reconciliation is idempotent and reports remaining work.

## R2.5 - Centralize the public projection

**Priority:** P0/P1

### Implementation

1. Create one public projection/eligibility service for HTML, REST, schema, feeds, cards, rankings, and search.
2. Suppress or explicitly label stale evidence grades, claims, citations, reviewer/testing badges, scores, and commercial assertions.
3. Return only allowlisted fields and stable public semantics.
4. Compare responses across output channels using contract tests.

### Human gate

Editorial and privacy owners approve reader-facing `unavailable`, `pending review`, and correction language.

## R2.6 - Constrain emergency overrides

**Priority:** P0

### Implementation

1. Define allowlisted overrideable warning codes.
2. Make medical-safety, unsupported material claims, stale evidence, invalid credentials, missing test evidence, privacy exposure, and forged state non-overrideable.
3. Require a dedicated capability, incident/ticket ID, reason, expiry, second-party approval where policy requires, and alerting.
4. Prevent transient replay and bind an override to the exact revision/fingerprint.

## Migration and rollback

1. Add schema changes without deleting legacy metadata.
2. Generate a dry-run report classifying existing approvals as current, stale, ambiguous, or legacy-unbound.
3. Do not invent approval records during backfill.
4. Keep old readers only behind a time-bounded compatibility flag; old status strings never count as approval.
5. Rollback retains new tables and snapshots and restores only the previous application reader.

## R2 exit gate

- [ ] Same-request publication bypass is closed in classic and REST paths.
- [ ] Approvals bind to one exact revision and dependency fingerprint.
- [ ] Concurrent promotion is safe.
- [ ] Stale dependencies fail closed in every public channel.
- [ ] Override policy is approved and safety gates are non-overrideable.
- [ ] Existing-content stale report is reviewed by named humans.

---

# Phase R3 - Secure governed records and separation of duties

## Objective

Make claims, sources, testing, commercial relationships, corrections, roles, and audit records trustworthy inputs to R2.

## R3.1 - Secure claim verification

**Priority:** P0

### Implementation

1. Make `verification_status`, verifier identity/date, and snapshot hash system-only.
2. Remove generic Custom Fields and raw REST/CLI write access to final verification fields.
3. Route verification through one nonce- and capability-protected command that validates claim ID/text and at least one valid source reference.
4. Hash the complete normalized source identity, including URL and persistent identifier.
5. Revalidate the hash whenever a claim is projected or used for approval.
6. Strip verifier-controlled columns from imports and initialize imported claims as unverified.

### Tests

- Forged classic custom field, REST meta, CLI meta, import, and direct metadata updates.
- Missing claim/source fields.
- Post-verification claim/source edits.
- Same-person editor/verifier prohibition.

### Migration and human gate

Quarantine existing unverifiable hashes as stale and retain the prior value for audit. A named fact checker must re-verify material claims.

## R3.2 - Use one affiliate parser and lifecycle gate

**Priority:** P0/P1

### Implementation

1. Parse anchors and shortcodes once with a structured parser.
2. Tokenize `rel` case-insensitively and recognize `sponsored` in any valid order.
3. Use the same parsed destinations for presence, registry lookup, approval fingerprint, rendering, and auditing.
4. Enforce active dates, verification freshness, exact/subdomain policy, scheme, port, credential, IDN, `www`, case, and trailing-dot normalization.
5. Re-scan all content and report newly detected affiliate links before changing publication state.

### Tests

Include reordered tokens, mixed quotes/case, duplicate attributes, malformed anchors, shortcodes, inactive/expired merchants, subdomains, ports, credentials, and Unicode domains.

### Human gate

Commercial owner reconciles the scan with the registry and approves disclosures.

## R3.3 - Enforce the corrections state machine

**Priority:** P0/P1

### Implementation

1. Deny raw writes to final correction status and completion fields.
2. Implement explicit transitions: `reported -> investigating -> in_progress -> complete` and a separately authorized rejection path.
3. Require corrected post, issue description, severity, resolution, corrected date, and a nonempty public note for completion.
4. Require an independent medical reviewer and bound re-review snapshot for medical-safety or critical corrections.
5. Make conclusion-change and affected-claim data part of the immutable completion snapshot.
6. Project only valid completed corrections publicly.

### Migration

Inventory existing corrections. Invalid `complete` records become a reviewed migration queue; do not silently rewrite history.

## R3.4 - Bind test records to exact protocol approvals

**Priority:** P0/P1

### Implementation

1. Store protocol post ID and exact approved protocol hash in each test-record snapshot.
2. Enforce unique immutable protocol ID/version combinations.
3. Use deterministic lookup ordering only for legacy reconciliation, never for approval validity.
4. Invalidate dependent test records and reviews when a protocol changes or retires.
5. Require tester/submitter/approver separation.

### Migration

Dry-run match existing test records. Mark ambiguous or orphaned records stale for testing-lead resolution.

## R3.5 - Version role and capability reconciliation

**Priority:** P1

### Implementation

1. Move role reconciliation off every `init` request into a versioned migration/CLI command.
2. Diff desired and actual capabilities, add required capabilities, and explicitly remove obsolete ones.
3. Provide dry-run output and snapshot current role definitions before migration.
4. Enforce actor inequality in services for author, editor, fact checker, medical reviewer, tester, commercial approver, and publisher.

### Acceptance

- Frontend requests do not update role options.
- Fresh, upgraded, and intentionally drifted role fixtures converge to the approved matrix.
- Administrators are not silently exempt from workflow separation.

## R3.6 - Make the audit chain concurrency-safe

**Priority:** P1

### Implementation

1. Use a transaction plus row/advisory lock, or a sequence-bound design, for predecessor selection and insert.
2. Add unique sequence/index constraints.
3. Eliminate mutable post-meta fallback for governance-critical events; protected readiness fails if durable storage is unavailable.
4. Add a verifier command detecting mutation, deletion, gap, fork, and invalid predecessor.
5. Bound and allowlist payload fields; never store message bodies, IP addresses, secrets, or private medical information.

### Migration and rollback

Seal legacy events into a documented genesis import. Never delete an audit chain during rollback.

## R3 exit gate

- [ ] Final claim status is service-only and exact-hash verified.
- [ ] Affiliate detection and enforcement use one canonical parse.
- [ ] Correction completion is a validated immutable transition.
- [ ] Test records bind to unique protocol approval snapshots.
- [ ] Approved role matrix and actor inequality are enforced.
- [ ] Audit verifier passes concurrency and tamper tests.

---

# Phase R4 - Fix public correctness, routes, SEO, and scale

## Objective

Correct confirmed reader-facing defects and remove route/query behavior that will fail at production scale.

## R4.1 - Fix category canonical redirects and ranking controls

**Priority:** P1
**Primary files:** `class-routes.php`, `class-rankings.php`, `class-public-rankings.php`

### Implementation

1. Redirect only actual legacy category slugs, never canonical slugs.
2. Preserve allowlisted ranking controls such as `ranking_sort` and approved filters.
3. Do not append default query variables such as `paged=0` or `order=DESC`.
4. Prevent redirect loops and define canonical query behavior.
5. Make ranking controls use GET and emit analytics on `change`, not incidental click.

### Acceptance

- `?ranking_sort=title` remains in the final URL and selects Product name.
- Alpha/Valid/Zeta fixtures sort correctly.
- Canonical, legacy, filtered, paginated, and malformed query tests pass.

## R4.2 - Introduce typed routes

**Priority:** P1

### Implementation

1. Model Page, post-type archive, taxonomy archive, search, and external routes explicitly.
2. Treat `reviews` as the review archive rather than both a Page and archive.
3. Update bootstrap, navigation, canonical generation, sitemap expectations, and tests to use route kinds.
4. Inventory any existing `reviews` Page; do not delete or redirect it automatically.
5. Test both root and subdirectory WordPress installations.

### Human gate

Editorial/SEO owner approves the canonical Consumer Lab URL and redirect policy.

## R4.3 - Materialize ranking eligibility and paginate correctly

**Priority:** P1/P2

### Implementation

1. Create an indexed eligibility projection updated on governed transitions.
2. Include all eligible reviews, not only the newest 100.
3. Perform sorting/filtering/pagination in the database projection rather than after loading candidates.
4. Batch prefetch public projection data.
5. Use a persistent cache or transients with targeted invalidation; do not assume request-local object cache persists.
6. Define query-count, memory, TTFB, and cache-hit budgets.

### Migration and rollback

Backfill idempotently, dual-read old and new results, and compare before cutover. Retain the old reader for one rollback window.

## R4.4 - Consolidate SEO and schema ownership

**Priority:** P1

### Implementation

1. Detect one active SEO provider consistently.
2. Keep WordPress core canonical behavior unless the custom service owns the request.
3. Emit exactly one description and canonical.
4. Preserve correct pagination and query semantics.
5. Use the R2 public projection for citations, reviewers, evidence, and commercial schema.

### Tests

Cover singular posts/reviews/pages, category/tag/custom taxonomy, author/date/search archives, pagination, 404, canonical query controls, and supported SEO-provider combinations.

## R4.5 - Correct counts, TOC fragments, pagination, and portable links

**Priority:** P2

### Implementation

1. Separate guide counts from eligible review counts and use valid taxonomy query arguments.
2. Generate heading IDs and TOC links from the same normalized source, including duplicates and non-ASCII text.
3. Clamp `guide_page`, reject arrays/invalid values, handle out-of-range pages, and use bounded pagination windows.
4. Replace hardcoded root-relative links/forms with dynamic route output compatible with subdirectory installs.
5. Preserve approved legacy fragment anchors where necessary.

## R4.6 - Repair bootstrap and startup behavior

**Priority:** P1/P2

### Implementation

1. Gate migrations and role reconciliation behind WordPress-installed/table-existence checks.
2. Increase or restructure cold-start readiness so slow but healthy MySQL initialization does not abort prematurely.
3. Remove the corrupted `uncertainty???without` copy and add encoding validation.
4. Separate production bootstrap from demo/test content generation.
5. Production bootstrap should create obvious draft shells, not substantive unsourced health claims or evidence grades.

## R4 exit gate

- [ ] Ranking controls remain linkable and deterministic.
- [ ] Route registry has one owner and no Page/archive collision.
- [ ] Rankings include the complete eligible corpus within approved budgets.
- [ ] Canonical/description/schema output is singular and correct.
- [ ] Root and subdirectory installation tests pass.
- [ ] Fresh bootstrap has no pre-install database errors or corrupted copy.

---

# Phase R5 - Align privacy, analytics, and content governance

## Objective

Ensure operational behavior matches public policy and that green validation means governed content is actually ready.

## R5.1 - Harden contact handling

**Priority:** P1

### Implementation

1. Require `is_email()` after sanitization and prevent header injection.
2. Enforce server-side length/size limits for name, email, subject, and message.
3. Use an atomic limiter or external WAF/rate-limit service for concurrent requests.
4. Validate trusted-proxy configuration; fail safe when unset or invalid.
5. Drain retention backlogs in bounded repeated batches and monitor oldest retained age.
6. Record resolution state if the approved policy is resolution-based, or update legal wording to match time-based deletion.
7. Log delivery failure without message body, email, IP, or sensitive free text.
8. Test nonce, honeypot, throttle, malformed email, oversized request, mail failure, retention, and privacy-safe logs against real WordPress.

### Human gate

Privacy/legal owner chooses and approves the retention basis, vendors, consent, data-subject process, and sensitive-submission response.

## R5.2 - Repair analytics contracts

**Priority:** P1

### Implementation

1. Define one versioned schema for every supported event.
2. Read only the event-specific allowlisted dataset fields.
3. Use correct interactions: click, change, toggle-open, submit-success, and download.
4. Add or remove `methodology_open` consistently across markup, PHP allowlist, documentation, tests, and dashboards.
5. Never send free text, claim text, search content with health meaning, email, user ID, IP, or private workflow state.
6. Integrate consent behavior with the actual deployed analytics vendor.

### Acceptance

- Browser tests assert exact event name, exact payload, exactly once, and no prohibited network request.
- Zero emitted events fails the test.
- KPI definitions match collected dimensions.

## R5.3 - Make content validation fail closed

**Priority:** P0 for launch content

### Implementation

1. Require nonempty source, fact-check, and freshness records for every governed publication.
2. Validate unique IDs, semantic dates, named owners, allowed status transitions, and referential integrity.
3. Reconcile registries against live published WordPress inventory.
4. Make overdue non-retired content block the release gate.
5. Detect `To be assigned`, `To be set`, `To be populated`, bracketed dates, `???`, Unicode replacement characters, mojibake, obvious example data, and unapproved HUMAN notes.
6. Validate that privacy/contact/analytics documentation matches deployed behavior.
7. Keep substantive launch articles draft/noindex until named human approvals exist.

### Human-only work

- Populate real sources and claim relationships.
- Assign authors, fact checkers, and medical reviewers.
- Approve evidence grades, safety wording, disclosures, privacy, terms, and publication.
- Execute and approve real product testing.

## R5 exit gate

- [ ] Contact behavior and approved privacy language agree.
- [ ] Contact abuse and retention tests pass under concurrency/backlog conditions.
- [ ] Analytics emits exact privacy-safe events.
- [ ] Empty registries and unresolved placeholders fail release validation.
- [ ] No material content is published through AI-only approval.

---

# Phase R6 - Complete adversarial and release verification

## Objective

Prove the implementation across actors, channels, browsers, data states, and failures.

## R6.1 - Required actor/channel matrix

Test at least these actors:

- anonymous;
- subscriber;
- writer/editor;
- fact checker;
- assigned and unassigned medical reviewer;
- product tester;
- testing approver;
- affiliate manager;
- commercial approver;
- managing editor;
- administrator;
- system/migration/cron.

Test at least these channels:

- classic editor POST;
- block editor REST;
- generic metadata API;
- custom admin action;
- WP-CLI;
- CSV/import path;
- direct service call;
- cron/background reconciliation;
- anonymous REST;
- authenticated REST;
- public HTML;
- JSON-LD/schema;
- feeds/search/rankings.

Every security decision that differs by actor or channel requires an explicit allow and deny assertion.

## R6.2 - Required clean-checkout lanes

| Lane | Required execution |
|---|---|
| Integrity | Exact installs, lockfiles, manifest, test discovery, clean tree |
| PHP quality | Syntax, PHPCS, PHPStan level 5, PHPUnit, coverage |
| Frontend quality | ESLint for all first-party JS, Stylelint, dependency tree |
| Content/config | Strict content, registry, link, freshness, encoding, environment, Compose |
| WordPress integration | Install, migration, idempotency, fixtures, readiness, authorization, REST |
| Chromium functional | Routes, publication, rankings, SEO, schema, analytics, contact |
| Accessibility | Axe plus route/status prerequisites and interactive states |
| Firefox/WebKit | Critical navigation, forms, filters, dialogs, focus |
| Visual Linux | Approved 360, 768, and 1440 baselines in pinned image |
| Lighthouse | Mobile and desktop, three runs, 200 responses, approved budgets |
| Security | CodeQL, secret scan, dependency review, audits, image scans, SBOM |
| Windows parity | Manifest/EOL, validators, lint, test discovery |

## R6.3 - Replace false-assurance assertions

1. REST tests insert sentinel private values and fail if any appears in posts, reviews, schemas, indexes, corrections, messages, or private CPT routes.
2. Link tests fail on unexpected status `>=400`, malformed local URLs, failed source pages, or excessive redirects.
3. Axe, SEO, schema, and Lighthouse tests assert HTTP 200 and required page prerequisites first.
4. JSON-LD absence fails on routes where schema is required.
5. Cross-browser tests fail when required controls are missing.
6. Accessibility uses real reflow/browser zoom methodology; CSS `zoom` is not accepted as 400-percent evidence.
7. Contact tests submit the real form rather than only asserting a draft route is 404.
8. Release CI rejects `.only`, unexpected skips, no-assertion tests, and retry-only passes.

## R6.4 - Performance, resilience, and concurrency tests

1. Ranking corpus larger than 100 and approved production sizing assumptions.
2. Query-count and TTFB assertions with warm and cold cache.
3. Concurrent publish/approve/edit requests.
4. Concurrent audit writes and contact rate-limit attempts.
5. Freshness/retention backlogs larger than one batch.
6. Migration interruption followed by safe rerun.
7. Database/cache/mail unavailability and recovery.

## R6 exit gate

- [ ] Every lane passes on the same commit.
- [ ] Zero unexpected skips, flaky retry-passes, missing artifacts, or unavailable internal checks.
- [ ] Zero unresolved P0/P1 defect or unwaived high/critical vulnerability.
- [ ] Required performance and concurrency budgets pass.
- [ ] Manual accessibility evidence is signed by the QA/accessibility owner.

---

# Phase R7 - Production-like staging and operational controls

## Objective

Demonstrate that the exact release artifact operates safely in the chosen production architecture.

## R7.1 - Choose and document the production control plane

**Human/external gate**

1. Select managed WordPress or a concrete self-hosted architecture.
2. Define PHP/WordPress/MySQL versions, storage, cache, CDN/WAF, secret manager, cron, mail, logs, monitoring, backup provider, and deployment mechanism.
3. Build an immutable minimal runtime artifact and pin base images by digest where containers are used.
4. Make production environment validation require HTTPS, correct proxy forwarding, secure cookies, and explicit environment type.

## R7.2 - Fix backup and restore tooling

**Priority:** P0 human/external

### Implementation

1. Fix the reference backup flow so the database dump is written or streamed to host-controlled/persistent storage before the temporary container exits.
2. Make restore scripts fail closed unless the environment explicitly identifies an approved isolated restore target.
3. Verify target host, database, site URL, and a unique confirmation token before import.
4. Back up database, uploads, runtime artifact identity, approval tables, audit tables, configuration references, and checksums.
5. Encrypt off-site backups and monitor age, size, checksum, and completion.

### Acceptance

- Full restore to isolated staging succeeds.
- Approval/currentness results, audit chain, roles, private CPTs, media, permalinks, and representative pages match pre-backup state.
- RPO and RTO are measured and accepted.
- Rollback deploys the exact previous artifact/tag, not mutable `main`.

## R7.3 - Configure production security

**Human/external gate**

- Rotate all exposed/reused credentials and store production secrets outside Git.
- Named least-privilege accounts and MFA.
- TLS, HSTS, secure cookies, trusted proxy settings, WAF, login/form/API rate limits.
- SMTP with SPF, DKIM, DMARC, monitored bounce/failure handling.
- Centralized logs with access/retention policy and no sensitive message bodies.
- GitHub secret scanning, push protection, dependency ownership, protected branches/tags/environments.
- Image and dependency update process with vulnerability response SLA.

## R7.4 - Complete CSP progression

1. Configure a real report collector.
2. Remove `'unsafe-eval'` and reduce/remove inline script dependencies.
3. Observe approved staging/production-like traffic for the documented period.
4. Verify public and admin workflows.
5. Enforce CSP in production only after the human security owner accepts the report evidence.
6. Retain post-enforcement monitoring and rollback instructions.

## R7.5 - Configure cron, cache, monitoring, and incident response

1. Disable request-driven WP-Cron in production and configure a reliable external scheduler.
2. Monitor freshness heartbeat/cycle, retention backlog, migration version, approval/audit tables, mail, queue, cache, error rate, latency, uptime, certificate expiry, backup age, and restore age.
3. Assign on-call owners and escalation channels.
4. Add medical-safety, privacy/contact, affiliate, credential, availability, and deployment incident exercises.
5. Conduct and record a tabletop exercise.

## R7.6 - Exercise high-risk staging scenarios

Using synthetic data only, prove:

1. low-risk article approval and publication;
2. material claim blocking without fact-check evidence;
3. medical-review blocking without a valid independent snapshot;
4. edit creates a pending revision while the prior approved revision remains public;
5. protocol/test changes remove ranking eligibility;
6. affiliate destination/lifecycle changes stale commercial approval;
7. critical correction requires medical re-review;
8. unauthorized roles cannot mutate protected state;
9. anonymous REST/schema cannot expose sentinel private values;
10. contact validation, mail sink, rate limiting, retention, and logging;
11. backup restore and exact-artifact rollback;
12. CSP, TLS, WAF, cron, monitoring, and alert delivery.

## R7 exit gate

- [ ] Exact candidate artifact is deployed privately over HTTPS.
- [ ] Protected readiness has no unresolved internal blocker.
- [ ] Backup restore and artifact rollback drills succeed.
- [ ] External controls have redacted operator evidence.
- [ ] Security and incident-response owners accept staging results.

---

# Phase R8 - Human content gate, launch decision, and observation

## Objective

Complete the activities that cannot be automated or approved by AI and make an evidence-backed go/no-go decision.

## R8.1 - Human content and policy review

- Every material claim is reconciled with the source registry by a named fact checker.
- Required medical claims and safety language receive named medical review.
- Reviewer credentials are independently verified.
- Evidence grades and rationales receive human approval.
- Product tests have real acquisition, approved immutable protocol, documented execution, raw observations, and independent test approval.
- Affiliate/supplied-product disclosures and registry records are approved.
- Privacy, terms, contact, consent, ownership, corrections, medical disclaimer, affiliate disclosure, testing methodology, editorial policy, and AI-assisted-work disclosure receive appropriate legal/governance approval.
- Draft/noindex status remains until each applicable approval snapshot is current.

## R8.2 - Final release evidence pack

Store a non-sensitive, checksummed evidence index containing:

- commit SHA, tree hash, tag, artifact digest, manifest hash, and lockfile hashes;
- CI run URL and exact job results;
- dependency and image audit results plus SBOMs;
- migration and protected-readiness reports;
- authorization, publication, REST/privacy, browser, axe, visual, Lighthouse, performance, and concurrency evidence;
- staging environment inventory;
- backup restore and rollback drill records with RPO/RTO;
- monitoring and alert verification;
- named technical, security, QA, DevOps, editorial, medical, testing, commercial, privacy/legal, and release approvals.

Never include secrets, raw contact messages, IP addresses, private medical information, private reviewer records, or production database exports.

## R8.3 - Go/no-go meeting

Production is **GO** only when:

- every P0 item is `verified_staging` or `human_approved` as applicable;
- all mandatory CI jobs passed on the same commit;
- no unresolved P0/P1 security, privacy, medical-safety, publication-integrity, restore, or accessibility issue remains;
- no required evidence is missing or unavailable;
- production content contains no synthetic fixtures, placeholders, invented citations, or AI-only approvals;
- rollback owner, observation owner, and incident contacts are present;
- every required human owner signs the decision.

Any dissent or missing required approver results in NO-GO.

## R8.4 - Deployment and observation

1. Reconfirm backup and rollback artifact immediately before deployment.
2. Deploy the exact approved artifact; do not rebuild from a moving branch.
3. Run migrations, clear only documented caches, and execute post-deploy smoke/readiness checks.
4. Verify robots, sitemap, canonical URLs, CSP, TLS, mail, cron, analytics consent, and public trust projection.
5. Observe error rate, latency, availability, audit events, contact/affiliate anomalies, and freshness/cron for the approved window.
6. Roll back on any predetermined trigger and record the incident.
7. Close the release only after the observation owner signs the result.

---

# 9. Release-blocking acceptance matrix

| Control | Blocking acceptance condition | Required evidence |
|---|---|---|
| Source integrity | Clean immutable commit; intended files tracked; no secret/scratch artifact | Git inventory, secret scan, human disposition |
| Dependencies | Exact installs; no unwaived high/critical finding | Lock hashes, audit reports, SBOM |
| Manifest/artifact | Deterministic path set and hashes on Windows/Linux | Verification logs and artifact digest |
| Publication | Proposed edits cannot publish against old approvals | Classic/REST/concurrency regression reports |
| Claims | Final verification service-only and hash-current | Adversarial tests, human re-verification queue |
| Testing | Test record bound to unique approved protocol | Migration and workflow tests |
| Affiliate | Canonical parser and current commercial approval | Scan report and commercial sign-off |
| Corrections | Valid state transition and safety re-review | Workflow tests and medical sign-off where required |
| REST/public data | Exact allowlist; no sentinel private value | REST/schema/HTML contract report |
| CI evidence | Missing/failing/skipped evidence fails aggregate | Negative aggregate test and run URL |
| Accessibility | Automated gates plus manual keyboard/screen-reader/zoom evidence | Axe/browser reports and signed checklist |
| Performance | Ranking and critical routes meet approved budgets | Load/query/Lighthouse results |
| Privacy | Contact/analytics behavior matches approved policy | Integration tests and privacy/legal sign-off |
| Backup/rollback | Successful isolated restore and exact-artifact rollback | Drill report with RPO/RTO |
| Operations | TLS/WAF/CSP/cron/mail/monitoring/MFA verified | Redacted provider/operator attestations |
| Content | Named source, editorial, medical/testing/commercial approvals | Current immutable snapshots and sign-offs |

# 10. Mandatory evidence directory contract

Use a commit-addressed structure such as:

```text
reports/release/<commit-sha>/
  environment.json
  ci-job-results.json
  manifest/
  dependencies/
  php/
  frontend/
  content/
  integration/
  playwright/
  accessibility/
  visual/
  lighthouse/
  security/
  staging/
  backup-restore/
  approvals/
  SHA256SUMS
```

The evidence index must declare each expected artifact, its checksum, producer job, result, and whether it is automated, external, or human. Missing mandatory evidence is a failure.

# 11. Definition of Done for every implementation item

An item is complete only when:

1. the implementation and negative test are merged;
2. migration/backfill is idempotent and documented;
3. local targeted verification passes;
4. the complete required CI lanes pass on the merged commit;
5. documentation reflects actual behavior;
6. rollback is executable and does not delete approval/audit evidence;
7. relevant human decisions are named and recorded;
8. the release evidence index contains the required artifacts.

# 12. Master execution checklist

## P0 source and release integrity

- [ ] R0 working-tree disposition approved
- [ ] Sensitive history and credential rotation decision complete
- [ ] Clean baseline accepted
- [ ] Shell execution and local/CI parity fixed
- [ ] Exact dependency installs verified
- [ ] Deterministic manifest/artifact verified
- [ ] Canonical CI setup and fixtures verified
- [ ] Critical test discovery verified
- [ ] Fail-closed release evidence verified
- [ ] Branch protection configured

## P0 governance integrity

- [ ] Same-request publication race fixed
- [ ] Exact revision/dependency fingerprint enforced
- [ ] Concurrent publication promotion verified
- [ ] Public projection fails closed
- [ ] Claim verification service-only
- [ ] Affiliate parser/gate canonical
- [ ] Corrections state machine enforced
- [ ] Test records bound to approved protocols
- [ ] Actor inequality and override restrictions approved

## P1 reader, privacy, and reliability

- [ ] Ranking redirect/sort fixed
- [ ] Typed routes and canonical policy approved
- [ ] Complete ranking eligibility projection verified
- [ ] SEO/schema ownership consolidated
- [ ] Counts, TOC, pagination, and subdirectory links fixed
- [ ] Bootstrap/startup behavior clean
- [ ] Contact privacy/retention/abuse controls approved
- [ ] Analytics exact-event contracts verified
- [ ] Content registries and placeholder validation fail closed
- [ ] Audit chain concurrency and verifier pass

## Human and external launch gates

- [ ] Production hosting/control plane approved
- [ ] Secrets rotated and secret manager configured
- [ ] TLS/HSTS, WAF, secure cookies, MFA verified
- [ ] CSP enforcement approved after observation
- [ ] SMTP/SPF/DKIM/DMARC verified
- [ ] External cron, cache, logs, monitoring, and alerts verified
- [ ] Encrypted off-site backup and restore drill pass
- [ ] Exact-artifact rollback drill pass
- [ ] Manual accessibility pass
- [ ] Source/fact/medical/testing/commercial reviews complete
- [ ] Privacy/legal documents approved
- [ ] Final named go/no-go decision recorded

# 13. Recommended first implementation slice

Begin with two narrowly scoped pull requests after R0:

1. **PR A - CI truth foundation**
   - shell execution;
   - reports directory;
   - canonical fixture setup;
   - suite separation;
   - artifact paths;
   - executable PHPUnit discovery;
   - fail-closed release evidence.

2. **PR B - Publication revision integrity**
   - exploit-first classic and REST tests;
   - immutable pending revision model;
   - prospective full fingerprint;
   - atomic promotion service;
   - stale dependency handling;
   - constrained override policy.

PR A makes later results trustworthy. PR B closes the most serious application-level release blocker. Do not begin visual redesign, production publishing, or performance tuning until both are merged and verified.

# 14. Final instruction

Optimize for provable enforcement, not the appearance of completeness.

A green job without executed assertions, a current status without an exact snapshot, a verified claim writable through generic metadata, a correction completed without required review, or an external control without operator evidence is not production readiness.
