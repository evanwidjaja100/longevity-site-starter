# Production Readiness Implementation Plan

This plan implements the remediation work identified by the repository-wide assessment defined in `bababooey.md`. It is based on the current working tree, including staged, unstaged, and untracked files, rather than relying only on historical status documents.

**Assessment date:** 2026-07-27  
**Target branch:** `improvement/production-readiness-v2`  
**Current release posture:** **NO-GO**  
**Implementation status:** Not started  

## 1. Decisions And Scope

The following decisions are fixed for this implementation:

| Area | Decision |
|---|---|
| Production hosting | Managed WordPress |
| Development and CI | Retain `compose.yaml` for local development and disposable CI |
| Custom VPS production stack | Retire rather than repair |
| Stale public content | Keep accessible, display a warning, suppress stale trust data, and noindex until reapproved |
| Redis | Remove from the initial production architecture |
| Source registry | Keep records private and expose approved article-level bibliographies |
| Availability | Single-node launch with planned maintenance and tested recovery |
| Legal market | Undecided; legal pages and public launch remain blocked pending counsel |

The implementation must not:

- Publish content or trust pages automatically.
- Verify medical claims or assign evidence grades.
- Invent citations, test records, reviewer credentials, or safety language.
- Treat repository configuration as evidence that an external control is active.
- Maintain both managed-host and custom VPS production implementations.
- Add Redis, high availability, or custom queue infrastructure without measured need.

## 2. Current Architecture

```text
Readers / Editors / Reviewers / WP-CLI / Cron
                       |
                       v
                 WordPress Core
                       |
          +------------+-------------+
          |                          |
          v                          v
  longevity-core MU plugin     longevity-starter
  governance control plane     block theme
          |
          +-- Roles and metadata authorization
          +-- Claims and sources
          +-- Reviewer credentials
          +-- Protocols and test records
          +-- Approval snapshots
          +-- Publication gates
          +-- Corrections and affiliates
          +-- Rankings and public projections
          +-- REST, SEO, schema, analytics
          +-- Freshness, audit, readiness
          |
          v
       MySQL
          |
          +-- WordPress posts, postmeta, users, options
          +-- lel_approval_snapshots
          +-- lel_audit_events
          +-- lel_audit_sequence
          +-- lel_dependencies
          +-- lel_invalidation_queue
          +-- lel_rate_limits
```

The intended governed flow is:

```text
Editor saves content or metadata
  -> persistence-boundary authorization and sanitization
  -> post/postmeta writes
  -> prior approvals become stale
  -> dependency changes enqueue bounded invalidation work
  -> named human performs a scoped approval
  -> fingerprint binds reviewed content, metadata, and dependencies
  -> snapshot and mandatory audit event persist atomically
  -> publication gate evaluates complete prospective state
  -> publish, reject, or authorized emergency override
```

## 3. Production Readiness Baseline

| Area | Score | Current reason |
|---|---:|---|
| Architecture | 6/10 | Strong WordPress/plugin/theme boundaries; inconsistent governance transition and dependency boundaries remain. |
| Code quality | 5/10 | Focused services exist, but large classes, duplicate inventories, dead paths, and incomplete type surfaces remain. |
| Security | 3/10 | Metadata authorization, workflow bypasses, historical credentials, rate limiting, and external controls block release. |
| Testing | 4/10 | Broad scaffolding exists, but key gates are broken or uninvoked and critical paths lack real-database coverage. |
| Performance | 4/10 | Minimal frontend runtime is positive; rankings and approval evaluation contain caps and N+1 work. |
| Reliability | 2/10 | Migration, invalidation, audit, backup, rollback, cron, and contact delivery are not dependable. |
| Observability | 3/10 | Readiness and logging exist, but validation, collection, and alerting are incomplete. |
| Database design | 4/10 | Custom tables exist; locking, constraints, transactions, and queue claiming are incomplete. |
| Deployment | 2/10 | Current production image and rollback path are unsafe; no managed-host delivery process exists. |
| Documentation | 4/10 | Extensive but contradictory, especially around trust pages, setup, CI, and operations. |
| Maintainability | 4/10 | The design is understandable, but the dirty tree and duplicated infrastructure prevent reproducibility. |
| Overall | 3/10 | Production remains NO-GO. |

## 4. Confirmed Root Findings

### F01: Governed metadata authorization is bypassable

**Severity:** Critical  
**Category:** Authorization and data integrity  
**Evidence:** `class-meta-authorization.php:23-65`, `class-publication-gates.php:507-521`

`Meta_Authorization::can_write()` is called by selected REST and admin paths, but no shared `add_post_metadata`, `update_post_metadata`, or `delete_post_metadata` guard enforces it at storage time. Registered-meta `auth_callback` does not protect every direct `update_post_meta()` or `meta_input` write.

**Required result:** Every governed metadata add, update, and delete must pass one deny-by-default policy. Only explicitly scoped first-party workflow and migration operations may bypass actor checks.

### F02: Audit sequencing and trusted transitions are not fail-closed

**Severity:** Critical  
**Category:** Audit and governance integrity  
**Evidence:** `class-audit-log.php:230-275`, `class-claims.php:109-120`, `class-review-methodology.php:164-199`, `class-corrections.php:132-139`

The sequence allocator reads `$wpdb->insert_id` after an ordinary update. Several trusted transitions keep their new state even if their audit event fails.

**Required result:** Trusted state and its audit event commit together or neither commits. Concurrent writers must produce one contiguous chain.

### F03: Migration execution and locking are unsafe

**Severity:** Critical  
**Category:** Database migration  
**Evidence:** `class-migrations.php:39-70`, `class-migrations.php:113-141`, `scripts/ci-setup.sh:43-44`, `scripts/deploy.sh:64-68`

Clean CI does not migrate before fixtures, deployment does not select an authorized WordPress actor, and lock acquisition is a non-atomic option read/write.

**Required result:** Fresh and upgraded databases must migrate under one atomic owner lock, validate schema postconditions, and rerun idempotently.

### F04: Old invalidation jobs can invalidate new approvals

**Severity:** Critical  
**Category:** Concurrency and publication integrity  
**Evidence:** `class-approval-service.php:289-307`, `class-approval-service.php:423-425`, `class-invalidation-queue.php:156-223`

An edit queues invalidation before a later same-request approval. Queue processing then invalidates all active approvals without checking whether the new approval matches current state.

**Required result:** Direct parent edits invalidate synchronously. Dependency jobs invalidate only snapshots that no longer match current state.

### F05: Product testing is not bound to an exact methodology

**Severity:** Critical for public rankings  
**Category:** Testing methodology  
**Evidence:** `class-review-methodology.php:225-258`, `class-review-methodology.php:358-389`, `class-publication-gates.php:232-240`

Test records use textual protocol ID/version lookup and bind only a boolean protocol result. Review score versions need only be nonempty.

**Required result:** Test records must reference an exact protocol post and approval hash. Review dimensions and score versions must match the approved protocol and installed model.

### F06: Claim verification has source and independence gaps

**Severity:** High  
**Category:** Claim governance  
**Evidence:** `class-claims.php:83-120`, `class-claims.php:187-276`

Verification does not reject the latest material editor and accepts any nonempty source ID without proving that a valid source exists.

**Required result:** The verifier must differ from the preparer and latest material editor. Source records must exist, be the correct type, and satisfy approved bibliographic requirements.

### F07: Correction controls are bypassable and disconnected

**Severity:** High  
**Category:** Corrections  
**Evidence:** `class-corrections.php:23-41`, `class-corrections.php:88-182`

Only update operations are partially filtered. Add/delete paths, actor capability, parent propagation, ranking invalidation, and mandatory audit are absent.

**Required result:** One protected transition service must own correction state. Open corrections must affect the corrected parent and rankings immediately.

### F08: Public outputs do not share one approval boundary

**Severity:** High  
**Category:** REST, HTML, schema, and privacy  
**Evidence:** `class-rest-api.php:99-136`, `class-public-trust.php:65-102`, `class-schema.php:137-154`, `class-public-rankings.php:159-187`

REST, HTML, JSON-LD, and ranking renderers apply different approval rules. Public test output reads fields described as private operational data.

**Required result:** REST, HTML, schema, feeds, excerpts, cards, citations, and rankings must consume one public-state policy and one allowlisted projection.

### F09: Ranking caches can expose stale or incomplete results

**Severity:** High  
**Category:** Ranking integrity  
**Evidence:** `class-rankings.php:22-67`, `class-rankings.php:206-250`, `class-rankings.php:326-352`

Eligibility cache invalidation omits several dependencies, eligibility is capped at 500 reviews, and results are sliced before requested sorting.

**Required result:** Every eligibility-affecting transition invalidates rankings. Complete result sets must be filtered and sorted before limiting.

### F10: Contact limiting and feedback are broken

**Severity:** High  
**Category:** Abuse protection and privacy  
**Evidence:** `class-public-contact.php:163-226`, `wp-content/object-cache.php:204-226`, `wp-content/object-cache.php:597-599`

Default WordPress object cache is request-local. The custom Redis implementation overwrites expiring add keys and loses cache groups during increments. Error redirects use invalid non-3xx status codes and feedback is not rendered.

**Required result:** Contact limiting must use the database table directly and remain correct with no persistent object cache.

### F11: The current source tree is not reproducible

**Severity:** Critical  
**Category:** Release integrity  

Required runtime classes are untracked while loaded unconditionally. The manifest and Git index do not describe the same filesystem used locally.

**Required result:** One clean candidate commit must contain every runtime, test, and release file, with a manifest generated from that exact state.

### F12: CI does not currently prove release quality

**Severity:** High  
**Category:** Testing and supply chain  
**Evidence:** `.github/workflows/ci.yml:50-60`, `package.json`, `Makefile:55`

Coverage reads a nonexistent Clover percentage attribute, accessibility is omitted from the functional lane and `test-all`, Linux visual CI lacks Linux baselines, and frontend lint surfaces are incomplete.

**Required result:** Every named release gate must execute its actual suite and fail on a meaningful regression.

### F13: The custom production stack is the wrong target

**Severity:** High  
**Category:** Deployment architecture  
**Evidence:** `docker/production/Dockerfile:32-39`, `docker/production/Dockerfile:75-76`, `scripts/deploy.sh:56-58`

The production image copies a nonexistent build directory and deployment builds from an operator working tree. Managed WordPress is now the selected production topology.

**Required result:** Retire the unsupported production Compose/image/deployment path and create one managed-host artifact and promotion process.

### F14: Backup and rollback procedures can lose data

**Severity:** Critical if used  
**Category:** Disaster recovery  
**Evidence:** `compose.production.yaml:91-92`, `scripts/backup.sh:99-110`, `scripts/rollback.sh:32-52`

The current backup misses the production uploads volume. Routine rollback automatically imports a pre-deploy database backup after restarting the old application.

**Required result:** Use provider DB-plus-media backups. Routine rollback must be code-only; destructive restore must remain a separately authorized incident procedure.

### F15: Historical credential exposure remains unresolved

**Severity:** Critical until rotation is evidenced  
**Category:** Secret management  

Git history contains a previous database dump and documented credential marker. Current ignore rules do not invalidate an exposed credential.

**Required result:** Rotate first, investigate reuse, scan history and artifacts, then coordinate any history rewrite separately.

### F16: Accessibility and CSP remain launch blockers

**Severity:** High  
**Category:** Frontend and browser security  
**Evidence:** `consumer-lab.css:631-638`, `bootstrap.php:237-275`, `docs/testing/production-readiness-audit-2026-07-20.md`

Mobile CSS hides the only textual Search label. Production CSP does not permit WordPress inline style attributes. Existing evidence records serious axe and zoom failures.

**Required result:** WCAG 2.1 AA, responsive accessible names, reflow, and a tested WordPress-compatible CSP must be release gates.

### F17: Trust and privacy content is not reliably publishable

**Severity:** High  
**Category:** Compliance, privacy, and documentation  

Trust templates contain placeholders and internal notes. Contact/privacy wording does not fully match collection and retention. Multiple scripts can overwrite the same pages, and trust pages are not governed by publication snapshots.

**Required result:** Git templates become one-time seeds. Named humans approve exact trust-page snapshots before publication.

### F18: Roles, freshness, and readiness are not authoritative

**Severity:** High  
**Category:** Authorization and observability  
**Evidence:** `class-roles.php:66-100`, `class-freshness.php:149-183`, `class-system-readiness.php:205-226`

Role reconciliation does not remove omitted managed capabilities. Freshness SQL mishandles `any` and `NOT EXISTS`. A missing invalidation table can appear healthy.

**Required result:** Effective managed capabilities must exactly match policy, and missing internal dependencies must block readiness.

## 5. Production Blockers

### Security Blockers

- Rotate historically exposed credentials.
- Enforce metadata authorization on add, update, and delete operations.
- Make every material trusted transition audit-mandatory.
- Reconcile role capabilities exactly.
- Resolve high-severity dependency findings.
- Verify MFA, HTTPS, HSTS, secure cookies, WAF/login limits, and privileged account inventory on the managed host.
- Complete a full-history secret scan.

### Data Integrity Blockers

- Repair migration authorization, locking, ordering, and schema postconditions.
- Repair audit sequence allocation.
- Make invalidation generation-safe.
- Bind test records to exact protocols and scoring models.
- Validate claim sources and correction transitions.
- Establish DB-plus-media restore evidence.
- Define retention for contact, approval, audit, correction, and backup data.

### Reliability Blockers

- Configure one real cron mechanism.
- Make contact limiting independent of object cache.
- Gate release on protected readiness.
- Correct freshness and queue health checks.
- Verify contact delivery or monitored queue handling.
- Complete code rollback and separate disaster-recovery procedures.

### Deployment Blockers

- Produce a clean, tracked candidate.
- Select a managed WordPress provider.
- Retire the custom VPS production path.
- Build a checksum-verifiable first-party release artifact.
- Deploy the same artifact to staging and production.
- Run authorized migrations before promotion.
- Establish branch protection and environment approvals.

### Compliance And Privacy Blockers

- Keep legal pages draft while jurisdiction is undecided.
- Record named legal/privacy approval before trust-page publication.
- Align privacy language with actual processors, retention, logs, mail, analytics, and backups.
- Complete launch-article editorial and medical review.
- Do not publish product-testing claims or rankings without real testing.
- Resolve WCAG 2.1 AA findings with automated and named human verification.

## 6. Phase 0: Immediate Critical Fixes

### 0.1 Freeze Governed Publication

**Goal:** Prevent new invalid governed states while shared controls are repaired.

**Actions:**

- Suspend new publication, reapproval, numbered rankings, and production deployment.
- Preserve existing invalidation jobs and audit records.
- Avoid running migration or cleanup scripts against production data.

**Verification:** No governed public state changes during remediation without an explicitly recorded exception.

### 0.2 Rotate Historical Credentials

**Goal:** Remove the immediate impact of the historical dump.

**Actions:**

- Identify every credential represented in the historical artifact.
- Rotate database and any reused credentials.
- Invalidate old access.
- Search CI artifacts, caches, forks, mirrors, and backups.
- Decide on a coordinated history rewrite only after rotation.

**Verification:** Old credentials fail authentication; rotation evidence records owner and date without storing the new secret.

### 0.3 Reconcile The Working Tree

**Goal:** Establish one authoritative source state.

**Actions:**

- Review staged, unstaged, and untracked files individually.
- Track required runtime files or remove their loader references.
- Remove accidental index-only and deleted artifacts.
- Do not revert unrelated user changes.
- Regenerate `MANIFEST.sha256` only after all intended files are finalized.

**Verification:**

```bash
git status --porcelain=v1 --untracked-files=all
bash scripts/verify-manifest.sh
```

Expected result: empty Git status in the release checkout and a passing manifest check.

### 0.4 Select The Managed WordPress Provider

The provider must support:

- MU plugins and custom database tables.
- Required PHP and WordPress versions.
- WP-CLI.
- Isolated staging.
- Real cron.
- Database and uploads backups with restoration.
- HTTPS, HSTS, MFA, runtime logs, and WAF/login controls.
- Release deployment with checksum or immutable artifact evidence.
- Operation without the repository's custom object-cache drop-in.

**Verification:** A signed provider capability checklist identifies the accountable owner for every external control.

### 0.5 Retire Unsupported Production Infrastructure

**Candidate removals or archival changes:**

- `compose.production.yaml`
- `docker/production/`
- `scripts/deploy.sh`
- `scripts/rollback.sh`
- Production use of `scripts/backup.sh`
- Production use of `scripts/restore-drill.sh`
- `wp-content/object-cache.php`
- Redis services and `WP_REDIS_*` configuration
- VPS-specific production claims and runbooks

Keep `compose.yaml` and development Dockerfiles for local development and disposable CI.

**Verification:** Development bootstrap, integration tests, and all static checks pass without Redis or production Compose.

### 0.6 Record Database Baseline

Against a cloned database, record:

- `SHOW CREATE TABLE` for all custom tables.
- Table engines, indexes, and row counts.
- `lel_data_version` and schema options.
- Audit sequence allocator and `MAX(sequence)`.
- Audit-chain verification result.
- Pending, completed, and failed invalidation jobs.
- Current approvals by type and status.
- Effective role capability sets.

**Verification:** The baseline is read-only, non-secret, tied to an environment and timestamp, and retained with release evidence.

### Phase 0 Exit Criteria

- Credentials rotated.
- Managed provider selected.
- One clean candidate source state exists.
- Unsupported production infrastructure is retired or clearly non-production.
- Current database/schema/audit baseline is recorded.

## 7. Phase 1: Production Blockers

### 1.1 Enforce Persistence-Boundary Metadata Authorization

**Primary files:**

- `class-meta-authorization.php`
- `class-meta-registry.php`
- `bootstrap.php`
- `class-claims.php`
- `class-review-methodology.php`
- `class-corrections.php`

**Implementation:**

- Register add, update, and delete postmeta filters.
- Enforce policies only for declared governed keys and governed post types.
- Deny unknown governed fields.
- Add a depth-counted trusted scope using `try/finally` for workflow and migration services.
- Protect service-only claim verification, protocol/test approval, correction snapshot, and approval projection fields.
- Audit denied writes without exposing sensitive attempted values.
- Ensure direct SQL maintenance is outside the supported application contract and covered by audit/readiness checks.

**Required tests:**

- Classic editor permitted write.
- Classic editor denied write.
- REST permitted write.
- REST service-only write denied.
- Forged `meta_input` denied.
- Direct `update_post_meta()` denied.
- Direct add and delete denied.
- Workflow trusted scope succeeds.
- Migration trusted scope succeeds.
- Unknown governed field denied.

**Exit criterion:** Every actor/field/channel combination matches the approved policy matrix in real WordPress integration tests.

### 1.2 Repair Audit And Transaction Integrity

**Primary files:**

- `class-audit-log.php`
- `class-approval-service.php`
- `class-claims.php`
- `class-review-methodology.php`
- `class-corrections.php`
- `class-publication-gates.php`

**Implementation:**

- Retrieve the allocated sequence with `SELECT LAST_INSERT_ID()`.
- Synchronize the singleton allocator to the stored audit high-water mark.
- Verify that sequence and predecessor indexes are unique and cover expected columns.
- Confirm InnoDB before enabling trusted transitions.
- Make claim verification, protocol approval, test-record approval, correction completion, publication override, and approval completion mandatory audit events.
- Commit state and audit in one transaction.
- Avoid nested `START TRANSACTION` behavior.
- Roll back only the transition being attempted, never all previous approvals.
- Store enough non-sensitive evidence to verify the transition without duplicating private notes.

**Required tests:**

- Fifty concurrent writers produce exactly fifty contiguous events.
- Sequence allocator equals the maximum stored sequence.
- Tampered payload fails verification.
- Sequence gap fails verification.
- Forked predecessor fails verification.
- Audit-table outage leaves no trusted state.
- Commit failure leaves no partial metadata or snapshot.
- Approval audit failure does not invalidate a previous valid approval.

**Exit criterion:** Every trusted state is inseparable from its durable audit event.

### 1.3 Repair Migrations And Roles

**Primary files:**

- `class-migrations.php`
- `class-cli.php`
- `class-roles.php`
- `scripts/ci-setup.sh`
- integration tests

**Implementation:**

- Require an explicit authorized WordPress operations user.
- Run migrations before bootstrap and fixtures in CI.
- Replace the option lock with an advisory lock and owner token.
- Reject release or extension from a non-owner.
- Do not record audit events before audit installation.
- Verify every table, engine, column, and critical index before advancing `lel_data_version`.
- Synchronize audit sequence during migration/backfill.
- Backfill dependency edges where required.
- Reconcile against the complete managed capability universe.
- Remove managed capabilities not explicitly granted.
- Preserve unrelated WordPress/plugin capabilities.

**Required tests:**

- Fresh version 0 to current.
- Previous supported version to current.
- Idempotent rerun.
- Two concurrent migration attempts with one winner.
- Interrupted batch resumes safely.
- Failed postcondition does not advance the version.
- Exact role capability matrix after deliberate drift.

**Exit criterion:** A clean database reaches the current version once, reruns without changes, and reports all internal schema checks as healthy.

### 1.4 Make Invalidation Generation-Safe

**Primary files:**

- `class-approval-service.php`
- `class-invalidation-queue.php`
- `class-publication-gates.php`
- `class-rankings.php`

**Implementation:**

- Invalidate bounded direct parent changes synchronously.
- Reserve the queue for dependency fan-out.
- Recompute the current snapshot before invalidating a queued parent.
- Skip a newer snapshot that matches current state.
- Atomically claim pending jobs.
- Add `processing`, retry availability, and owner/lease fields as needed.
- Make deduplication atomic.
- Delay retries across cron runs instead of exhausting retries in one loop.
- Schedule completed-job cleanup.
- Trigger ranking invalidation after every eligibility-affecting transition.

**Required tests:**

- Edit, approve, process old job: new approval remains current.
- Edit after approval: approval becomes stale.
- Two workers cannot claim the same job.
- Transient lock failure retries later.
- Permanent failure becomes observable.
- Ranking output drops a stale review on the next request.

**Exit criterion:** No old job can invalidate a newer matching snapshot, and no invalidation is silently lost.

### 1.5 Implement The Stale Public Content Policy

**Primary files:**

- `class-approval-service.php`
- `class-publication-gates.php`
- `class-public-trust.php`
- `class-public-rankings.php`
- `class-rest-api.php`
- `class-schema.php`
- `class-seo.php`

**Implementation:**

- Add a governance-specific noindex state instead of reusing a manual noindex flag.
- Display a prominent update/review warning on stale public content.
- Keep the article body accessible.
- Suppress stale evidence grade, citations, reviewer identity, testing claims, score, ranking, and commercial approval representations.
- Apply identical state rules to REST, HTML, JSON-LD, cards, feeds, excerpts, and search projections.
- Clear the governance noindex state only after all required approvals are current.
- Re-evaluate scheduled posts immediately before they transition to publish.
- Treat natural review-date expiry as stale even without an edit event.

**Required tests:**

- Source change produces warning and noindex.
- Stale content body remains accessible.
- Stale trust fields disappear from every anonymous channel.
- Reapproval clears warning/noindex when all required approvals are current.
- Scheduled stale content cannot auto-publish.

**Exit criterion:** Stale content behavior is consistent, visible, fail-closed, and reversible through named human reapproval.

### 1.6 Repair Claims And Article Bibliographies

**Primary files:**

- `class-claims.php`
- `class-approval-fingerprint.php`
- public source renderer
- schema renderer

**Implementation:**

- Reject verification by the preparer or latest material editor.
- Validate that `source_id` resolves to a non-trashed `lel_source`.
- Validate approved minimum bibliographic fields.
- Decide, through human editorial review, which claim fields are mandatory.
- Bind the verification hash to every material claim/source field required by policy.
- Store a durable audit/snapshot reference.
- Recompute hash validity before public projection.
- Keep source records private.
- Expose only approved article-level bibliographic fields.
- Remove or rewrite promises of a global searchable public registry.
- Never assign or change evidence grades automatically.

**Required tests:**

- Nonexistent source rejected.
- Wrong post type rejected.
- Trashed source rejected.
- Latest editor cannot verify.
- Independent verifier can verify complete state.
- Source modification stales the claim and parent approvals.
- Private notes never appear publicly.

**Exit criterion:** Every public bibliography entry originates from a current, independently verified claim/source snapshot.

### 1.7 Bind Product Testing And Scoring

**Primary files:**

- `class-review-methodology.php`
- `class-runtime-config.php`
- scoring JSON/schema
- approval fingerprints
- publication gates
- rankings

**Implementation:**

- Add an exact numeric protocol post reference to test records.
- Require a current protocol approval hash.
- Include protocol post ID and approval hash in the test-record fingerprint.
- Reject duplicate textual protocol ID/version pairs.
- Canonicalize tester user IDs with integer normalization.
- Recheck author, submitter, tester, and approver separation at read time.
- Enforce protocol minimum duration and required observations, comparisons, environment, and disclosures.
- Require `review_score_version` to resolve to the installed model version.
- Validate the complete scoring model against its schema.
- Require review dimensions to match approved protocol/model definitions.
- Define ranking comparability by exact methodology, not category alone.
- Require named human methodology approval before production use.

**Required tests:**

- Duplicate protocol substitution rejected.
- Changed protocol approval invalidates test records.
- Tester ID `12`, `012`, and `0012` normalize identically.
- Unknown scoring version rejected.
- Missing protocol requirement rejected.
- Mismatched dimensions rejected.
- Changed author invalidates separation of duty.

**Exit criterion:** Every score and ranking is reproducible against one exact approved protocol and scoring model.

### 1.8 Repair Corrections And Rankings

**Primary files:**

- `class-corrections.php`
- `class-rankings.php`
- correction admin UI/action
- public correction renderer

**Implementation:**

- Define an explicit initial `reported` state.
- Guard add, update, and delete paths for status and snapshot fields.
- Add one nonce- and capability-protected transition action.
- Acquire the corrected parent publication lock.
- Make completion snapshot and audit mandatory.
- Update the corrected parent's correction state.
- Invalidate parent approvals and ranking caches.
- Apply warning/noindex policy while material corrections remain open.
- Prevent hard deletion of completed correction evidence.
- Keep contact-to-correction promotion a human triage action, not automatic spam-driven creation.
- Revalidate cached ranking IDs before rendering until event coverage is proven.
- Sort and filter before applying limits.

**Required tests:**

- Complete legal transition sequence.
- Every illegal transition fails.
- Direct status/snapshot write fails.
- Material correction requires medical re-review where policy requires it.
- Parent warning/noindex appears.
- Review disappears from ranking immediately.
- Completed correction cannot be silently deleted.

**Exit criterion:** Corrections are durable, independently auditable, and immediately reflected in public trust state.

### 1.9 Enforce One Public Projection Boundary

**Primary files:**

- `class-rest-api.php`
- `class-public-trust.php`
- `class-public-rankings.php`
- `class-schema.php`
- `class-seo.php`

**Implementation:**

- Define one shared public-state decision for each domain.
- Require current editorial approval for all governed public metadata.
- Require current fact-check approval for evidence/claim/source output.
- Require current medical approval for reviewer output.
- Require current testing approval for test/score output.
- Require current commercial approval for non-none commercial output.
- Remove direct public reads of private test-record fields.
- Keep only `public_test_results` and separately approved public fields.
- Apply the same decisions to every anonymous representation.

**Required canary test:** Seed every private field with a unique marker and prove that no marker appears in HTML, REST, JSON-LD, feeds, excerpts, search, cards, or archives.

**Exit criterion:** One policy determines every public field, regardless of rendering channel.

### 1.10 Repair Contact And Privacy Controls

**Primary files:**

- `class-public-contact.php`
- contact form JS/CSS
- migrations
- contact tests

**Implementation:**

- Remove the custom Redis/object-cache dependency.
- Use the `lel_rate_limits` atomic database upsert for every request.
- Purge expired rate rows.
- Use valid 303 redirects after POST.
- Render allowlisted success and error feedback with `role="status"` and `role="alert"`.
- Add `autocomplete="name"` and `autocomplete="email"`.
- Register contact metadata as private with explicit sanitization/auth policy.
- Add disposition and legal-hold states before age-based deletion.
- Decide whether SMTP is required or the admin queue is authoritative.
- Alert on mail failure or unprocessed queue age.
- Complete privacy exporter/eraser integration if counsel requires it.

**Required tests:**

- Requests one through five accepted.
- Request six rejected without creating a post or mail.
- Twenty concurrent requests create no more than five records.
- Success feedback visible.
- Every validation failure visible and safe.
- SMTP failure preserves the private message and alerts an operator.
- Legal-held messages survive retention cleanup.

**Exit criterion:** Contact remains bounded, accessible, private, and operational without persistent cache.

### 1.11 Govern Trust Pages And Content Sources

**Primary files:**

- `content/templates/`
- `scripts/populate-trust-pages.php`
- trust date/update scripts
- bootstrap route/page definitions
- trust approval service/UI

**Implementation:**

- Document Git templates as one-time seeds and WordPress as operational content.
- Prevent scripts from overwriting an existing approved page without explicit force and reviewed diff.
- Remove `[date]`, implementation placeholders, internal comments, and contradictory copy.
- Consolidate Contact into one source.
- Add named reviewer, scope/jurisdiction, reviewed date, next review, and immutable content hash.
- Add a dedicated trust-page approval capability and service.
- Audit approval and invalidation.
- Block trust-page publication without a current named human approval.
- Keep legal pages draft while jurisdiction remains undecided.

**Required tests:**

- No public trust page contains placeholder markers.
- Re-running population does not overwrite approved content.
- Content change invalidates trust approval.
- Unauthorized user cannot approve or publish.
- Script execution alone cannot change the public last-reviewed date.

**Exit criterion:** Every public trust page represents one exact human-approved snapshot for a declared jurisdiction and review period.

### 1.12 Repair Accessibility, SEO, Analytics, And CSP

**Primary files:**

- `parts/header.html`
- theme CSS and `theme.json`
- contact renderer
- `class-seo.php`
- `class-schema.php`
- `assets/analytics.js`
- Playwright tests

**Implementation:**

- Preserve the Search button accessible name at mobile widths.
- Remove root-level overflow clipping after fixing actual overflow causes.
- Repair color contrast and reflow defects.
- Test real browser zoom rather than CSS `zoom` only.
- Add `aria-current` and missing table semantics.
- Fix archive canonical coverage and make `og:url` equal canonical URL.
- Replace root-relative route assumptions where subdirectory hosting is supported.
- Make analytics collect only schema-allowlisted required dataset fields.
- Strengthen analytics tests to require exact queue growth and payload.
- Keep CSP report-only until managed staging is clean.
- Use a WordPress-compatible style policy and nonce scripts/schema output.
- Quarantine or disable inserter patterns containing plausible fabricated data.

**Required tests:**

- Mobile Search resolves by accessible name.
- Zero applicable axe A/AA violations.
- Keyboard and focus restoration across menus/dialogs.
- 200% and 400% browser zoom.
- Forced colors and reduced motion.
- VoiceOver, NVDA, and TalkBack sign-off.
- Exactly one correct canonical/social set per route.
- Zero unexpected CSP violations under enforcement.

**Exit criterion:** WCAG 2.1 AA and production CSP are verified in managed staging.

### 1.13 Repair CI And Managed-Host Delivery

**Primary files:**

- `.github/workflows/ci.yml`
- `.github/workflows/security.yml`
- `Makefile`
- `package.json`
- lint/test configs
- release artifact script
- provider deployment runbook

**Implementation:**

- Calculate coverage from covered and total statements.
- Fail if coverage data is missing or empty.
- Add a required accessibility job.
- Generate and human-review Linux visual baselines.
- Include all runtime JS/CSS/PHP/shell surfaces in lint/static analysis.
- Run migrations before fixtures.
- Make test discovery cover every critical suite.
- Remove weak no-op browser assertions.
- Resolve high dependency advisories with the smallest compatible lockfile update.
- Remove production-image CI jobs after retiring the VPS target.
- Package only first-party runtime files:
  - `wp-content/mu-plugins/longevity-core.php`
  - `wp-content/mu-plugins/longevity-core/**`
  - `wp-content/themes/longevity-starter/**`
- Record artifact checksum, manifest, source SHA, dependency audits, and test outcomes.
- Deploy that exact artifact to managed staging.
- Promote the same checksum to production after manual approval.

**Exit criterion:** One clean SHA produces one verified artifact, and staging/production use the same bytes.

### Phase 1 Exit Criteria

- All critical/high confirmed code findings resolved.
- All real-database governance tests pass.
- Managed staging uses the exact release artifact.
- Accessibility, CSP, browser, dependency, and readiness gates pass.
- Remaining launch blockers are explicitly human/external and assigned to named owners.

## 8. Phase 2: Reliability And Maintainability

| Order | Task | Main benefit | Verification |
|---:|---|---|---|
| 2.1 | Complete queue delayed retries, leasing, deduplication, and purge | Prevent duplicate workers and retry storms | Concurrent-worker integration test |
| 2.2 | Replace malformed freshness SQL with tested `WP_Query` counts | Accurate lifecycle reporting | Fixture counts exactly match expected values |
| 2.3 | Handle natural approval/date expiry | Stale content changes without edit events | Time-bound tests cross exact expiry dates |
| 2.4 | Validate operator evidence strictly | Blank evidence cannot report ready | Missing actor/artifact/time/release/environment fails |
| 2.5 | Schedule audit-chain verification and retain external head anchor | Detect mutation and truncation | Modified/truncated chain blocks readiness |
| 2.6 | Configure provider cron and heartbeat alerts | Reliable queues, freshness, and retention | Missed cron generates an operator alert |
| 2.7 | Use provider logs/error monitoring | Avoid unused custom observability surfaces | Test exception reaches named operator |
| 2.8 | Remove custom metrics endpoint if no real consumer exists | Reduce unused attack surface | Readiness remains available through protected UI/CLI |
| 2.9 | Reconcile architecture, API, schema, permissions, setup, and operations docs | Reliable onboarding and incidents | Every command and route in active docs is tested |
| 2.10 | Remove dead methods and duplicate configuration inventories | Reduce drift | Caller search and complete suite stay green |

### Phase 2 Exit Criteria

- Queue, cron, lifecycle, audit monitoring, contact operations, and runbooks are exercised in staging.
- Protected readiness reports no blocked or degraded internal checks.
- Documentation matches actual commands, routes, roles, schemas, and provider operations.

## 9. Phase 3: Performance And Scalability

Correctness must land before these optimizations.

| Order | Task | Trigger | Verification |
|---:|---|---|---|
| 3.1 | Keyset-page ranking eligibility beyond 500 reviews | Before ranking inventory approaches the cap | 501-review fixture is complete |
| 3.2 | Sort/filter complete sets before limiting | Before numbered rankings launch | High-ranked older records are retained |
| 3.3 | Request-cache claim/source/dependency/fingerprint payloads | Profiling exceeds query budget | SQL count and latency decrease materially |
| 3.4 | Move ranking rebuilds out of reader requests | Cold request exceeds target | Reader request never scans full inventory |
| 3.5 | Run realistic managed-staging load and soak tests | Before public promotion | p95/p99, memory, DB, queue, and errors meet budgets |
| 3.6 | Use provider-native CDN/media optimization | Real media and traffic justify it | Responsive image and Core Web Vitals gates pass |

Do not add custom Redis, multiple web nodes, or a bespoke cache layer until profiling proves the managed platform insufficient.

## 10. Phase 4: Long-Term Architecture

| Improvement | Add only when |
|---|---|
| Managed database/read replicas | Database is a measured bottleneck |
| Object/shared media storage | Multiple web nodes or media volume requires it |
| Multiple stateless web replicas | Availability SLO requires high availability |
| Public global source registry | Product scope changes from article bibliographies |
| Dedicated background queue | Cron workload exceeds managed-host limits |
| PSR-4/container refactor | Manual loading/static coupling causes measured maintenance failures |
| Multi-region deployment | Business requirements specify corresponding SLO/RTO |

These improvements are excluded from launch scope.

## 11. Pull Request Sequence

Implement in this order so each change has one coherent purpose and deterministic verification:

| PR | Scope | Depends on |
|---:|---|---|
| 00 | Source reconciliation, current plan/report, manifest baseline | None |
| 01 | Retire custom production/Redis path and document managed target | Provider decision |
| 02 | Metadata persistence authorization and actor/channel tests | PR 00 |
| 03 | Audit allocator, transactions, mandatory transitions | PR 02 |
| 04 | Migration locking, postconditions, CI ordering, role reconciliation | PR 03 |
| 05 | Invalidation safety, queue claiming, stale/noindex policy, scheduled publication | PR 04 |
| 06 | Claim source validation and article bibliography boundary | PR 05 |
| 07 | Exact protocol/scoring binding and methodology tests | PR 03-05 |
| 08 | Correction lifecycle, parent propagation, ranking invalidation | PR 05 and PR 07 |
| 09 | Shared public projection for REST/HTML/schema/feed/rankings | PR 05-08 |
| 10 | DB-only contact limiter, feedback, retention, privacy integration | PR 04 |
| 11 | Trust-page source-of-truth and approval snapshots | PR 03-05 |
| 12 | Accessibility, SEO, analytics, CSP, visual fixes | PR 09-11 |
| 13 | CI truth, Linux visual baselines, release artifact | PR 00-12 |
| 14 | Managed staging deployment, restore drill, monitoring, runbooks | PR 13 |
| 15 | Final evidence reconciliation and launch go/no-go | PR 14 and all human gates |

## 12. Testing Strategy

### First Tests To Add

1. Metadata persistence authorization matrix.
2. Audit sequence, concurrency, tamper, and failure rollback.
3. Fresh/upgrade migration, contention, interruption, and schema postconditions.
4. Edit then approve then old-job invalidation ordering.
5. Scheduled publication after dependency staleness.
6. Claim latest-editor and source validation.
7. Exact protocol and scoring-model binding.
8. Correction lifecycle and parent/ranking propagation.
9. Concurrent DB contact rate limiting and retention.
10. Private-field canary across every anonymous output.
11. Trust-page approval and overwrite protection.
12. Mobile accessibility and production CSP.

### Critical End-To-End Workflows

- Writer -> fact checker -> medical reviewer -> editor -> publication.
- Product tester -> independent approver -> review -> ranking.
- Source change -> stale approval -> warning/noindex -> reapproval.
- Correction report -> human triage -> correction -> public notice.
- Contact success, validation failure, rate limit, mail failure, retention, and privacy request.
- Clean install -> migrations -> bootstrap -> protected readiness.
- Managed staging deployment -> smoke -> code rollback.
- Provider DB-plus-media backup -> isolated restore drill.

### Security Tests

- Role/action/channel authorization matrix.
- Direct postmeta add/update/delete attempts.
- Forged classic `meta_input` and REST metadata.
- Nonce, CSRF, IDOR, and capability failures.
- Claim/test/correction self-approval variants.
- CSP report size and rate limits.
- CORS and authenticated REST preflights.
- Managed-host upload MIME and execution controls.
- Full-history secret scanning.
- Dependency and release-artifact scanning.

### Performance Tests

- Rankings with 100, 500, and 501 reviews.
- Articles with more than 200 claims.
- Cold and warm public requests.
- Ranking cache invalidation bursts.
- Concurrent contact submissions.
- Concurrent audit writers.
- Queue workers under transient lock failures.
- Managed-staging soak with realistic content and media.

## 13. Required CI Gates

The final commands may be grouped into jobs, but all must execute against the same candidate SHA.

```bash
bash scripts/verify-dependency-state.sh
bash scripts/verify-manifest.sh
bash scripts/validate.sh

composer validate --strict
composer phpcs
composer phpstan
composer test
composer audit --locked

npm ci
npm run lint
npm audit --audit-level=high

python3 scripts/validate-content.py
python3 scripts/validate-internal-links.py
python3 scripts/validate-freshness.py

docker compose config --quiet
bash scripts/ci-setup.sh
bash tests/integration/environment-validation.sh
bash tests/integration/system-readiness.sh
bash tests/integration/rest-public-boundary.sh
bash tests/integration/pr2-security-contracts.sh
bash tests/integration/audit-chain.sh

npm run test:e2e:functional -- --project=chromium
npm run test:a11y -- --project=chromium
npx playwright test tests/e2e/critical-cross-browser.spec.js --project=firefox-critical --project=webkit-critical
npm run test:visual
npm run test:lighthouse
npm run test:lighthouse:desktop
```

### CI Acceptance Criteria

- Candidate checkout is clean.
- Every intended runtime and test file is tracked.
- Lockfiles install exactly.
- Manifest matches candidate bytes.
- Coverage uses covered/total statement counts and fails on missing data.
- No PHPUnit failure, warning, risky, skipped, incomplete, or assertionless test.
- No high/critical unresolved dependency finding without signed exception.
- No unexpected browser-test skip.
- Zero applicable axe WCAG A/AA violations.
- Visual tests use reviewed Linux baselines and never update snapshots in a gate.
- Failure artifacts include traces, screenshots, video, logs, and setup evidence.
- Release artifact checksum is tied to the source SHA.

## 14. Managed-Host Release Process

### Pull Request Pipeline

- Run all static, unit, content, integration, browser, accessibility, visual, and security gates.
- Use no production secrets.
- Do not deploy untrusted pull requests.

### Trusted Release Build

- Package only the first-party MU plugin and theme runtime files.
- Record source SHA, artifact checksum, manifest, dependency audits, and test outcomes.
- Store the artifact with explicit retention.
- Never rebuild separately for staging and production.

### Staging Promotion

- Deploy the exact release artifact.
- Run authorized additive migrations.
- Verify protected readiness.
- Run smoke, critical E2E, accessibility, CSP, SEO, and contact checks.
- Exercise code rollback.
- Confirm backup and restore evidence is current.

### Production Promotion

- Require manual approval by the technical release owner.
- Require applicable editorial, medical, privacy/legal, and testing approvals.
- Promote the exact staging artifact checksum.
- Take a provider DB-plus-media backup.
- Run migrations once.
- Verify protected readiness and representative routes.
- Observe error, cron, queue, and contact-delivery signals.

### Rollback

- Redeploy the previous verified code artifact.
- Keep additive database migrations in place.
- Verify readiness and critical routes.
- Restore the database only for a separately declared data incident under maintenance mode and human approval.

## 15. Deployment And Operations Checklist

### Environment

- [ ] Managed provider selected and documented.
- [ ] PHP, WordPress, database, MU-plugin, custom-table, and WP-CLI compatibility confirmed.
- [ ] Staging isolated from production.
- [ ] Production URL uses HTTPS.
- [ ] Debug display and file editing disabled.
- [ ] Provider object cache does not conflict with application behavior.

### Secrets And Accounts

- [ ] Historical credential rotated.
- [ ] No secrets in Git, artifacts, or logs.
- [ ] Unique privileged accounts.
- [ ] MFA enabled.
- [ ] Least-privilege deployment and WordPress operations identities.
- [ ] Application passwords inventoried and restricted.

### Database And Migrations

- [ ] Provider-level backup taken before migration.
- [ ] Authorized operations identity selected.
- [ ] Advisory locking supported.
- [ ] Migration run exactly once.
- [ ] Idempotent rerun succeeds.
- [ ] Custom table schema postconditions pass.
- [ ] Audit chain and allocator high-water mark agree.

### Backups And Recovery

- [ ] Database and uploads both backed up.
- [ ] Backup destination is independent of the production host.
- [ ] Encryption and retention approved.
- [ ] Isolated restore drill completed.
- [ ] Media sentinel restored successfully.
- [ ] RPO and RTO recorded.
- [ ] Routine rollback does not restore the database.

### Logging And Monitoring

- [ ] PHP/runtime errors reach provider logging.
- [ ] Uptime checks cover health and representative pages.
- [ ] Protected readiness checked by an authenticated operator.
- [ ] Cron heartbeat alert configured.
- [ ] Contact queue/mail failure alert configured.
- [ ] Backup age and restore evidence monitored.
- [ ] Disk/database/provider capacity alerts configured.
- [ ] Named incident contacts and escalation path recorded.

### HTTP Security

- [ ] HTTPS-only redirect.
- [ ] HSTS after HTTPS verification.
- [ ] Secure, HttpOnly, and appropriate SameSite cookies.
- [ ] CSP verified report-only before enforcement.
- [ ] Security headers verified through provider/CDN.
- [ ] WAF/login throttling active.
- [ ] Trusted proxy configuration verified.
- [ ] CORS behavior tested.
- [ ] Upload execution blocked.

### Contact And Privacy

- [ ] DB limiter passes concurrency tests.
- [ ] Contact route public only after legal/privacy review.
- [ ] SMTP or monitored message queue works.
- [ ] Retention and legal-hold behavior approved.
- [ ] Processor and retention language matches deployment.
- [ ] Sensitive-health-information warning reviewed by a human.
- [ ] Data access/deletion workflow documented where applicable.

### CI/CD

- [ ] Required branch protection enabled.
- [ ] All required jobs pass on the candidate SHA.
- [ ] Release artifact contains only required first-party runtime files.
- [ ] Artifact checksum and manifest recorded.
- [ ] Same artifact deployed to staging and production.
- [ ] Production promotion requires manual approval.
- [ ] Release evidence retained.

### Post-Deployment

- [ ] Home, search, topic, article, review, correction, and contact routes verified.
- [ ] No PHP warnings or unexpected CSP violations.
- [ ] Canonical, robots, social metadata, and JSON-LD verified.
- [ ] Cron heartbeat observed.
- [ ] Contact test received and removed according to policy.
- [ ] Anonymous REST canary scan passes.
- [ ] Accessibility smoke passes.
- [ ] Monitoring test alert reaches the named operator.
- [ ] Final go/no-go record signed.

## 16. Human And External Gates

Engineering cannot complete these gates independently:

- Select and contract the managed host.
- Rotate real credentials.
- Approve jurisdictions and legal entity/controller details.
- Approve Privacy, Terms, Affiliate Disclosure, Medical Disclaimer, and contact retention.
- Verify reviewer credentials.
- Verify claims and sources.
- Assign evidence grades and approve methodology.
- Perform real product testing.
- Publish launch content and trust pages.
- Enable MFA, branch protection, WAF, SMTP/DNS, backups, and monitoring.
- Conduct screen-reader and operational sign-off.

Production remains blocked until each applicable gate has a named owner, dated evidence, and release-specific approval.

## 17. Remaining Clarifying Questions

1. Which managed WordPress provider will be used?
2. What launch traffic and editorial concurrency are expected?
3. What RPO and RTO are acceptable?
4. Which legal entity operates the site?
5. Which jurisdictions will be targeted?
6. What retention applies to contact, rate identifiers, audit events, approvals, corrections, and backups?
7. Which SMTP, analytics, consent, newsletter, CDN, WAF, and monitoring vendors will be used?
8. Who are the named technical, editorial, privacy/legal, medical, and incident owners?
9. Must every product in a ranking use the exact same protocol and scoring-model version?
10. Is planned maintenance acceptable for initial releases?
11. Is provider-native object caching enabled?
12. What evidence already exists for MFA, branch protection, backups, restore drills, SMTP, DNS authentication, and uptime monitoring?

## 18. Estimated Effort

| Phase | Engineering estimate | External/human estimate |
|---|---:|---|
| Phase 0 | 4-8 engineer-days | Provider and credential owner dependent |
| Phase 1 | 30-45 engineer-days | Legal, editorial, medical, methodology, and provider dependent |
| Phase 2 | 8-15 engineer-days | Operations and privacy owner dependent |
| Phase 3 | 5-10 engineer-days when triggered | Traffic/SLO dependent |
| Phase 4 | Not part of launch | Business-requirement dependent |

Total pre-launch engineering estimate: approximately **40-65 engineer-days**, excluding external approvals, real product testing, medical review, legal review, and provider lead times.

## 19. Final Exit Criteria

The repository may be considered technically ready for launch only when:

- Phase 0 and Phase 1 are complete.
- Every production blocker is resolved or has a signed, time-bounded exception approved by the accountable owner.
- The release checkout is clean and reproducible.
- The exact release artifact passed all required CI gates.
- Managed staging uses the same artifact planned for production.
- Migrations, audit, invalidation, claims, corrections, testing, contact, and stale-content workflows pass real integration tests.
- Accessibility and CSP pass automated and named human review.
- Database and media restoration has been demonstrated.
- Technical, editorial, medical, privacy/legal, and testing owners have signed the applicable go/no-go record.

Until then, the release posture remains **NO-GO**.
