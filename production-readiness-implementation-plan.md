# Production Readiness Implementation Plan
### Longevity Evidence Lab — for execution by an AI coding agent

**Prepared:** 2026-07-21
**Audience:** an AI coding agent with shell + git access to this repository (Claude Code, opencode, Cursor, etc.), working under human review.
**Companion doc:** `production-readiness-recommendations.md` (the analysis this plan implements — read it first for *why*; this doc is the *how*).

---

## 0. How to use this plan

- Work through phases **in order**: 0 → 1 → 2 → 3 → 4. Phase 5 is human-only and not yours to execute.
- Each phase ends with a **Definition of Done** checklist and a **pause point** — stop, summarize what changed, and let a human review/approve before starting the next phase. This matches how the prior remediation session on this repo worked, and it worked well.
- Create one branch for this whole effort: `improvement/production-readiness-v1` (or ask the human what branch name they prefer if this repo has a naming convention you can detect from recent branch history).
- Commit atomically, one logical change per commit, using the conventional-commit style already used in this repo's history (`fix(core): ...`, `feat(ci): ...`, `refactor(...): ...`, `chore(...): ...`, `docs(...): ...`). Reference the phase/task number in the commit body, e.g. `Phase 1.2 of production-readiness-implementation-plan.md`.
- After every code change, run the relevant local verification command *before* committing. Don't commit on faith.
- If a task requires access you don't have (production credentials, hosting panel, DNS, GitHub repo settings) — **do not attempt it**. Stop, write it into a `HUMAN ACTION REQUIRED` note in your summary, and move to the next task that doesn't block on it.
- Respect `AGENTS.md`'s AI-assisted work policy: you may not invent citations, assign evidence grades, approve publication, or interpret private health information. Nothing in this plan asks you to — it's all infrastructure/tooling/code-quality work — but if any task drifts toward touching `content/`, `policies/`, or editorial metadata, stop and flag it instead of proceeding.
- **Out of scope for this entire plan:** anything under `content/`, `policies/`, editorial workflow logic in `class-review-workflow.php` / `class-publication-gates.php` / `class-claims.php` behavior (you may touch these files only for the specific, narrow fixes called out explicitly below — not general refactoring), and any real product/reviewer data. This plan is exclusively infrastructure, CI/CD, security hygiene, and code-quality.

---

## Phase 0 — Stop the bleeding: leaked credential + committed backups

**Why this is first:** a live-looking database password is sitting in git history right now. Everything else in this plan can wait a day; this can't.

### 0.1 — Confirm the scope of the leak

```bash
git log --all --oneline -- docs/testing/artifacts/pre-v2-backup/database-2026-07-18.sql
git log --all --oneline -- docs/testing/artifacts/pre-v2-backup/wp-content-2026-07-18.tgz
head -20 docs/testing/artifacts/pre-v2-backup/database-2026-07-18.sql
```
Confirm the first lines contain a captured `mysqldump -u longevity_app -p...` command with a visible password fragment. Note the exact commit(s) that introduced these files — you'll need them for the history purge in 0.4.

### 0.2 — Credential rotation — **HUMAN ACTION REQUIRED**

Do not attempt this yourself. Write this into your summary verbatim for the human:

> **Rotate immediately, regardless of whether this repo has ever been public:**
> - The `longevity_app` MySQL user password (the one partially visible in the dump header, prefix `Delta_10`)
> - The MySQL root password used in the same environment
> - Any `WP_ADMIN_PASSWORD` generated in the same session/environment, if it follows a similar pattern
> - Any other secret present in the local `.env` that was active when this backup was taken (2026-07-18)
>
> Once rotated, confirm with the agent so it can proceed to remove the files and update references.

### 0.3 — Remove the files from the working tree

```bash
git rm docs/testing/artifacts/pre-v2-backup/database-2026-07-18.sql
git rm docs/testing/artifacts/pre-v2-backup/wp-content-2026-07-18.tgz
```

Update `docs/testing/artifacts/pre-v2-backup/README.md` to describe where backups actually live going forward (e.g., "Backups are stored in [hosting provider]'s automated backup system / an off-site encrypted bucket — see `docs/operations/backup-and-restore.md`. They are never committed to this repository.") instead of documenting a now-deleted local file pair.

Commit:
```
chore(security): remove committed database dump and file backup

The pre-v2 backup files contained a captured terminal transcript that
leaked the longevity_app MySQL password in plaintext. Credential has
been rotated (see PR description). Backups do not belong in version
control regardless of content — see updated README in the same
directory for where they live now.
```

### 0.4 — Purge from git history — **confirm with human before running**

This rewrites commit SHAs and requires a force-push and re-clone by anyone else with a copy of the repo. Get explicit sign-off before running it; state clearly in your summary that you're pausing here for that confirmation.

Once confirmed:
```bash
# Backup first, always
git clone --mirror <repo-url> ../repo-backup-before-history-purge.git

pip install git-filter-repo   # or: brew install git-filter-repo

git filter-repo \
  --path docs/testing/artifacts/pre-v2-backup/database-2026-07-18.sql \
  --path docs/testing/artifacts/pre-v2-backup/wp-content-2026-07-18.tgz \
  --invert-paths

git push origin --force --all
git push origin --force --tags
```
Note in your summary: **history purge does not un-leak an already-rotated credential's old value** — it only prevents *future* clones/forks from finding it. Rotation in 0.2 is the actual fix; this step is defense-in-depth and hygiene.

### 0.5 — Prevent recurrence

Add to `.gitignore` (near the existing generated-artifact section):
```gitignore
# Database dumps and archives must never be committed — see docs/operations/backup-and-restore.md
*.sql
*.sql.gz
*.tgz
*.tar.gz
```
If any *legitimate* `.sql`/`.tgz` fixtures exist elsewhere in the repo and need to stay tracked (check `tests/fixtures/` and `scripts/` first), add narrow `!path/to/file` exceptions rather than removing the blanket rule.

### 0.6 — Add a CI guard so this can't happen silently again

In `.github/workflows/security.yml`, add a step alongside the existing "Reject tracked secrets and environment files" step:
```yaml
      - name: Reject tracked database dumps and archives
        run: |
          bad=$(git ls-files | grep -E '\.(sql|sql\.gz|tgz|tar\.gz)$' || true)
          test -z "$bad" || { echo "Tracked backup/dump files found:"; echo "$bad"; exit 1; }
```

### 0.7 — Verify

```bash
git log --all --full-history -- docs/testing/artifacts/pre-v2-backup/database-2026-07-18.sql   # must return nothing
git ls-files | grep -E '\.(sql|sql\.gz|tgz|tar\.gz)$'                                            # must return nothing (or only intended fixtures)
```

**Definition of Done — Phase 0:**
- [ ] Credential rotation confirmed by human
- [ ] Files removed from working tree, README updated
- [ ] History purge run (if approved) and force-pushed
- [ ] `.gitignore` updated
- [ ] CI guard added to `security.yml`
- [ ] Verification commands above return clean

**⏸ Pause here for human review before starting Phase 1.**

---

## Phase 1 — Wire CI to actually run the test suite

**Why this is next:** every fix in Phases 2–4 is only as trustworthy as the CI that verifies it. Do this before relying on CI for anything else in this plan.

### 1.1 — Fix `phpstan.neon.dist` (missing WordPress stubs)

Current file:
```neon
parameters:
  level: 5
  paths:
    - wp-content/mu-plugins/longevity-core
    - wp-content/themes/longevity-starter/functions.php
    - scripts
  excludePaths:
    - vendor
  scanFiles:
    - wp-content/mu-plugins/longevity-core.php
```

Change to:
```neon
includes:
  - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
  level: 5
  paths:
    - wp-content/mu-plugins/longevity-core
    - wp-content/themes/longevity-starter/functions.php
    - scripts
  excludePaths:
    - vendor
  scanFiles:
    - wp-content/mu-plugins/longevity-core.php
  bootstrapFiles:
    - tests/php/bootstrap.php
```
(`bootstrapFiles` is optional — include it only if PHPStan still can't resolve first-party constants like `LONGEVITY_CORE_PATH` after adding the WordPress stubs. Try without it first.)

Verify:
```bash
composer install --no-interaction --prefer-dist
composer phpstan
```
Expect a **manageable, real** list of findings now (not 1,000+ "unknown function" noise). Do not mass-suppress errors to get to zero — fix genuine ones where trivial (unused variables, missing return types), and for anything non-trivial or judgment-heavy, leave it and list it in your phase summary for human triage rather than silently ignoring or `@phpstan-ignore`-ing it.

### 1.2 — Fix the stale `RoutesTest.php` assertion

The file asserts `15 === count( $defs['pages'] )`, but `class-routes.php` currently defines **17** pages — which matches the count documented as correct in `docs/testing/v3-preimplementation-baseline.md` ("Pages (17 defined in Routes)"). The test is stale, not the code.

In `tests/php/RoutesTest.php`, change:
```php
$assert( 15 === count( $defs['pages'] ), 'Should have 15 page definitions, got ' . count( $defs['pages'] ) );
```
to:
```php
$assert( 17 === count( $defs['pages'] ), 'Should have 17 page definitions, got ' . count( $defs['pages'] ) );
```
Also double-check the adjacent category assertion (`7 === count( $defs['categories'] )`) against the current `$category_definitions` array in `class-routes.php` the same way — confirm it's still accurate before leaving it alone.

**Better long-term fix, do this too if time allows:** replace the hardcoded magic numbers with a comparison against a documented manifest, so this can't silently drift again:
```php
// instead of a bare integer, assert against the route keys you expect to exist
$expected_page_keys = array( 'home', 'start_here', 'guides', 'topics', 'reviews', 'evidence_methodology', /* ...all 17, taken from class-routes.php */ );
$assert( $expected_page_keys === array_keys( $defs['pages'] ), 'Page definition keys changed unexpectedly — update this test deliberately if intentional.' );
```
Pull the full key list directly from `class-routes.php`'s `$page_definitions` array rather than guessing.

### 1.3 — Confirm the newly-written PHPUnit tests actually pass

Five test files were added in a prior session but never confirmed to execute successfully: `PublicComponentsTest.php`, `AffiliateRegistryTest.php`, `ContentDiscoveryTest.php`, `AdminUITest.php`, `RestApiTest.php`.

```bash
composer install --no-interaction --prefer-dist
composer phpunit -- --filter PublicComponentsTest
composer phpunit -- --filter AffiliateRegistryTest
composer phpunit -- --filter ContentDiscoveryTest
composer phpunit -- --filter AdminUITest
composer phpunit -- --filter RestApiTest
composer phpunit    # full suite
```
If any fail due to missing stubs in `tests/php/bootstrap.php` (missing WP function/class stubs — this bootstrap file was already extended with ~40 stubs but may be incomplete), add the missing stub following the existing pattern in that file rather than weakening the test's assertions. If a test is asserting genuinely incorrect behavior (not a missing stub, but a real mismatch with the current implementation), fix the implementation if the test's expectation is correct per the surrounding code's documented intent — but if you're not confident which side is wrong, flag it in your summary instead of guessing.

### 1.4 — Rewrite `.github/workflows/ci.yml`

Current file only runs: PHP syntax-check (`php -l`), ShellCheck, `composer audit`, CodeQL, TruffleHog, dependency-review, Trivy, and SBOM generation. It does **not** run PHPCS, PHPStan, PHPUnit, ESLint, Stylelint, or Playwright, despite all of them being fully configured (`phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `eslint.config.js`, `.stylelintrc.json`, `playwright.config.js`) and despite `README.md` claiming CI already does all of this.

Keep every existing job in `ci.yml` unchanged. Add three new jobs:

```yaml
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-
      - run: composer install --no-interaction --prefer-dist
      - name: PHPCS (WordPress Coding Standards)
        run: composer phpcs
      - name: PHPStan (static analysis)
        run: composer phpstan
      - name: PHPUnit
        run: composer phpunit

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
      - run: npm ci
      - run: npm run lint

  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Configure environment
        run: cp .env.ci .env
      - name: Start stack
        run: docker compose up -d db wordpress
      - name: Wait for WordPress to be healthy
        run: |
          for i in $(seq 1 30); do
            status=$(docker compose ps --format json wordpress | grep -o '"Health":"[a-z]*"' || echo "")
            echo "$status" | grep -q healthy && break
            sleep 5
          done
      - name: Bootstrap site
        run: docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh
      - name: Smoke test
        run: ./scripts/smoke-test.sh
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
      - run: npm ci
      - run: npx playwright install --with-deps chromium
      - name: Run Playwright core suite
        env:
          WP_SITE_URL: http://localhost:8080
        run: npm run test:e2e
      - name: Upload Playwright report
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: reports/playwright/
```

Notes:
- The `e2e` job mirrors `make up`, `make bootstrap`, `make smoke`, `make test-e2e` from the `Makefile` — if any step above fails in a way the Makefile targets wouldn't, that's itself a bug worth fixing in the Makefile too (CI and local dev should exercise the same path).
- The prior session hit an unresolved issue getting output out of the `test-runner` Docker Compose service (`docker compose run --rm --entrypoint sh wpcli ...` produced no visible output when piped through PowerShell). The job above avoids that specific pattern by running PHPUnit via `composer phpunit` on the GitHub Actions runner directly (job `quality`, no Docker needed for PHP unit tests since `tests/php/bootstrap.php` uses dependency-free stubs, not a live WordPress install) and only uses the `wpcli`/`wordpress` containers for the true end-to-end Playwright run. Confirm this split works before assuming the old blocker is resolved.

### 1.5 — Verify locally before pushing

Run every new CI step locally first, in order, and fix anything that fails:
```bash
composer install --no-interaction --prefer-dist
composer phpcs
composer phpstan
composer phpunit

npm ci
npm run lint

cp .env.ci .env
docker compose up -d db wordpress
docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh
./scripts/smoke-test.sh
npx playwright install --with-deps chromium
npm run test:e2e
```
Only commit the `ci.yml` changes once all of the above pass locally (or once any failures are triaged and either fixed or explicitly documented as known/expected in your summary).

### 1.6 — Recommend branch protection (human action, not yours to configure)

Note in your summary: once this CI is merged and green on `main` for a few runs, the human should enable required status checks (`quality`, `frontend`, `e2e`, `lint`, `security`) under GitHub repo Settings → Branches, so future PRs can't merge with a red pipeline. You cannot configure this yourself without repo admin API access — flag it, don't attempt it.

**Definition of Done — Phase 1:**
- [ ] `phpstan.neon.dist` includes WordPress stubs; `composer phpstan` produces real, actionable output (not stub-noise)
- [ ] `RoutesTest.php` assertion corrected to 17 (and category count double-checked)
- [ ] All 5 previously-untested PHPUnit files pass, or failures are documented and understood
- [ ] `ci.yml` runs PHPCS, PHPStan, PHPUnit, ESLint, Stylelint, and at least a Chromium Playwright pass on every PR
- [ ] Everything above verified locally before pushing
- [ ] Branch-protection recommendation written into summary for human follow-up

**⏸ Pause here for human review before starting Phase 2.**

---

## Phase 2 — Supply chain & dependency hygiene

### 2.1 — SHA-pin GitHub Actions

All three workflow files (`ci.yml`, `security.yml`, `scheduled-content-validation.yml`) currently reference actions by floating tag (`@v4`, `@v2`, `@0.28.0`, etc.). This is already flagged as an open item in the repo's own `docs/operations/security-checklist.md`.

**Do not fabricate or guess commit SHAs.** For each action reference, resolve the real SHA for the currently-pinned tag using one of:
```bash
# Option A: gh CLI
gh api repos/actions/checkout/commits/v4 --jq .sha

# Option B: git ls-remote
git ls-remote https://github.com/actions/checkout refs/tags/v4
```
Then pin with a trailing comment noting the human-readable version (this is the standard pattern and keeps Dependabot able to open PRs against it):
```yaml
- uses: actions/checkout@<full-40-char-sha>  # v4.x.x
```
Repeat for every third-party action across all three workflow files: `actions/checkout`, `actions/cache`, `actions/setup-node`, `actions/setup-python`, `actions/github-script`, `shivammathur/setup-php`, `github/codeql-action/init`, `github/codeql-action/analyze`, `trufflesecurity/trufflehog`, `actions/dependency-review-action`, `aquasecurity/trivy-action`, `anchore/sbom-action`, `actions/upload-artifact` (the one you're adding in Phase 1).

Verify Dependabot picks these up correctly after merge: `.github/dependabot.yml` already has a `github-actions` ecosystem entry, so no config change needed there — just confirm (in your summary, not by waiting for it) that pinned-SHA-with-version-comment is the format Dependabot expects for `github-actions` PRs to keep working.

### 2.2 — Decide the fate of vendored third-party code — **decision point, ask before deleting anything**

Two things are currently committed as first-party repo content instead of being dependency-managed:
- `wp-content/plugins/akismet/` — full plugin source (~40 files)
- `wp-content/themes/twentytwentyfive/`, `twentytwentyfour/`, `twentytwentythree/` — three complete default WordPress themes (fonts, images, patterns, templates)

Before changing anything, check whether Akismet is actually active:
```bash
grep -rn "akismet" scripts/bootstrap.sh scripts/*.php config/ 2>/dev/null
```
And check whether any of the three default themes are referenced anywhere outside themselves (as a fallback theme, in `wp-config`, in bootstrap scripts):
```bash
grep -rn "twentytwentyfive\|twentytwentyfour\|twentytwentythree" --include="*.php" --include="*.sh" --exclude-dir=twentytwentyfive --exclude-dir=twentytwentyfour --exclude-dir=twentytwentythree .
```

Then present the human with the finding and these options, and **wait for a decision before deleting anything**:
- **If Akismet is active:** migrate it to Composer-managed via WPackagist rather than deleting it:
  ```json
  "repositories": [{ "type": "composer", "url": "https://wpackagist.org" }],
  "require": {
    "composer/installers": "^2.0",
    "wpackagist-plugin/akismet": "^5.0"
  },
  "extra": {
    "installer-paths": {
      "wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
    }
  }
  ```
  Then `git rm -r wp-content/plugins/akismet` and add `wp-content/plugins/*` (excluding `index.php`) to `.gitignore`, so future installs pull the current, patched version via `composer install` instead of a frozen manual copy.
- **If Akismet is not active:** just remove it (`git rm -r wp-content/plugins/akismet`), it's dead weight.
- **For the three default themes:** if none of them is the active theme and nothing references them as a fallback, remove them (`git rm -r wp-content/themes/twentytwentyfive wp-content/themes/twentytwentyfour wp-content/themes/twentytwentythree`) — WordPress core ships these itself on any real install; they don't need to live in application source control. If any *is* used as a documented fallback/parent theme, leave it, but say so explicitly in your summary.

Commit each removal/migration separately with a clear message, e.g.:
```
chore(deps): manage Akismet via Composer/WPackagist instead of vendoring

Akismet was committed as first-party plugin source, meaning it would
never receive a security update without someone manually re-vendoring
a new copy. Now resolved via composer.json + WPackagist so `composer
install` always pulls a current version, and Dependabot's composer
ecosystem entry can flag CVEs in it going forward.
```

### 2.3 — Remove dead code in the contact form renderer

In `wp-content/mu-plugins/longevity-core/class-public-components.php`, `render_contact_form()` currently has:
```php
$nonce = wp_create_nonce( 'longevity_contact' );
$api_url = rest_url( 'longevity/v1/contact' );
```
`$api_url` is never referenced again in the method (the form posts to `admin_url( 'admin-post.php' )`, not a REST endpoint — and no `/contact` REST route exists in `class-rest-api.php`, only `/health` and `/readiness/{id}`). Remove the unused line:
```php
$nonce = wp_create_nonce( 'longevity_contact' );
```
Verify no other reference to that variable exists in the method (`grep -n "api_url" wp-content/mu-plugins/longevity-core/class-public-components.php`) before removing, then run `composer phpcs` to confirm nothing else flags on the change.

### 2.4 — Contact-form email notification — **decision point**

Currently `handle_contact_submission()` stores the message as a private `longevity_message` post with no email/notification sent anywhere. Ask the human whether this is deliberate (avoiding an SMTP dependency that isn't configured yet) or an oversight. If they want a notification added, gate it behind SMTP actually being configured rather than assuming `wp_mail()` will silently succeed:
```php
if ( defined( 'SMTP_HOST' ) || has_action( 'phpmailer_init' ) ) {
    wp_mail(
        get_option( 'admin_email' ),
        sprintf( '[Contact] %s from %s', $subject, $name ),
        $message . "\n\nReply-to: {$email}",
        array( "Reply-To: {$name} <{$email}>" )
    );
}
```
Do not add this speculatively without the human's confirmation — it's a behavior change, not a pure hygiene fix.

**Definition of Done — Phase 2:**
- [ ] All GitHub Actions across all 3 workflows pinned to real, verified commit SHAs with version comments
- [ ] Akismet and default-theme decision made *with* the human and executed accordingly
- [ ] Dead `$api_url` variable removed
- [ ] Contact-form notification decision made with the human (implemented or explicitly deferred)

**⏸ Pause here for human review before starting Phase 3.**

---

## Phase 3 — Pre-launch quality gates

These were already identified as open blockers in the repo's own `docs/testing/production-readiness-audit-2026-07-20.md`. Phase 1's CI wiring means you can now verify these locally the same way CI will.

### 3.1 — Fix axe-core accessibility violations

```bash
cp .env.ci .env
docker compose up -d db wordpress
docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh
npm ci && npx playwright install --with-deps chromium
npm run test:a11y
```
Read the failure output route-by-route. The audit doc calls out **color contrast** and **missing form labels** as the known categories. Likely fix locations, in order of likelihood:
- Color contrast: check the color palette in `wp-content/themes/longevity-starter/theme.json` — look for text/background pairs that don't meet WCAG AA (4.5:1 for normal text, 3:1 for large text). Use a contrast checker on each declared color pair rather than guessing.
- Missing form labels: check `render_contact_form()` in `class-public-components.php` (already has explicit `<label for>` — verify it wasn't the source) and the search dialog markup referenced in `assets/site-ui.js` / theme templates — any `<input>` without an associated `<label>` or `aria-label`.
- Landmark roles: the audit doc also notes `getByRole('navigation')` failing on some routes — check `parts/header.html` and `parts/footer.html` in the theme for a `<nav>` element that's missing or that the block editor isn't rendering with the expected ARIA role.

Fix root causes (theme CSS custom properties, missing attributes) rather than only patching the specific test assertions. Re-run `npm run test:a11y` after each fix batch until clean, then run the full suite once more to confirm no regressions.

### 3.2 — Fix 400% zoom horizontal overflow

```bash
npm run test:a11y -- --grep "400% zoom"
```
This is almost always a fixed-width element (an image, a table without `overflow-x`, or a flex/grid container without `min-width: 0` on its children) that doesn't shrink below its content size. Check the homepage template (`front-page.html` and the patterns it composes, especially any hero/grid patterns under `wp-content/themes/longevity-starter/patterns/`) for elements with fixed `px` widths or missing `max-width: 100%`. Re-run the specific test until it passes, then the full a11y suite.

### 3.3 — Run Lighthouse CI and record real baselines

`lighthouserc.cjs` is fully configured (mobile + desktop presets, real performance/accessibility/SEO score thresholds, resource budgets) but has apparently never actually been executed against this site.

```bash
npm run test:lighthouse
```
If scores fail the configured thresholds (`performance ≥ 0.9`, `accessibility ≥ 0.95`, `best-practices ≥ 0.95`, `seo ≥ 0.95`, LCP < 2.5s, CLS < 0.1, TBT < 200ms), triage genuine performance issues (unoptimized images, render-blocking assets) versus threshold values that may need human recalibration if they were aspirational placeholders rather than measured targets. Don't silently loosen the thresholds yourself — if something can't reasonably pass without a larger change, document it for the human rather than editing `lighthouserc.cjs` to make it pass.

Once you have a first clean (or human-approved) run, note in your summary that this should be added as a scheduled/PR-triggered CI job going forward — a full Lighthouse run on every PR may be too slow/flaky for a required check, so recommend either a nightly scheduled workflow or a manual `workflow_dispatch` trigger rather than blocking every PR on it, and let the human decide.

### 3.4 — Cross-browser CI coverage

`playwright.config.js` already defines `webkit-critical` and `firefox-critical` projects targeting `critical-cross-browser.spec.js`, but the Phase 1 `e2e` CI job only installs and runs Chromium. Add a second, lighter job (or extend the existing one) that also runs the critical-path suite on Firefox/WebKit:
```yaml
      - run: npx playwright install --with-deps firefox webkit
      - name: Run critical cross-browser suite
        env:
          WP_SITE_URL: http://localhost:8080
        run: npx playwright test tests/e2e/critical-cross-browser.spec.js --project=webkit-critical --project=firefox-critical
```
Given install time for three browser engines, propose to the human whether this should run on every PR or only on `main`/nightly — don't unilaterally decide if it meaningfully slows down the PR feedback loop; note both options in your summary.

### 3.5 — Clean up known divergences already logged in `docs/testing/v3-preimplementation-baseline.md`

These were self-documented by a prior session and left unresolved. Each is small; do them as separate, easily-reviewable commits:

- **`scripts/bootstrap.sh` independently creates categories** via shell slug generation, duplicating what `Routes::init()` already owns. Locate the category-creation block (documented around lines 58–69 of that script) and remove it, delegating entirely to the `wp longevity bootstrap categories` CLI command that already exists in `class-cli.php`. Verify `make bootstrap` still produces the same 7 categories afterward.
- **Hardcoded route literals in theme templates** — several `.html` template files contain literal strings like `/start-here/`, `/corrections/`, `/evidence-methodology/` instead of resolving them via `Routes::public_page_url()`. Grep for these:
  ```bash
  grep -rn "/start-here/\|/corrections/\|/evidence-methodology/\|/testing-methodology/" wp-content/themes/longevity-starter/templates/ wp-content/themes/longevity-starter/parts/ wp-content/themes/longevity-starter/patterns/
  ```
  For each hit inside a `.php` pattern file (not a static `.html` template — those can't call PHP), replace the literal with `<?php echo esc_url( \Longevity\Core\Routes::public_page_url( 'start_here' ) ); ?>` (adjust the route key per match). Static `.html` block templates that can't execute PHP are a known WordPress block-theme limitation — leave those as-is but note them in your summary rather than attempting an unsafe workaround.
- **`routes.spec.js` compares absolute vs. relative URLs** — `page.url()` returns a full URL; if the spec compares it directly against a relative path string, update the assertions to either use `new URL(page.url()).pathname` or compare against a fully-qualified expected URL built from `process.env.WP_SITE_URL`. Run `npm run test:routes` after the fix to confirm no false negatives were previously masking real issues (or false positives being introduced now).

### 3.6 — Start the CSP enforcement observation window

`docs/operations/csp-enforcement-plan.md` already documents a 30-day observation plan and the prep work (moving analytics config out of an inline script into a JSON data block) is already done — the header is just sitting in `Content-Security-Policy-Report-Only` mode indefinitely. This isn't a code task so much as a process one:
- Confirm today's date against the plan's timeline; if the 30-day window hasn't formally started, note in your summary that it should, and that someone needs to actually monitor CSP violation reports during that window (this requires a report-collection endpoint or browser reporting API target — check whether `bootstrap.php`'s `content_security_policy()` method includes a `report-to`/`report-uri` directive; if not, that's a prerequisite gap worth flagging before the window can meaningfully start).

**Definition of Done — Phase 3:**
- [ ] `npm run test:a11y` passes with zero critical/serious violations across all routes
- [ ] 400% zoom overflow test passes
- [ ] Lighthouse CI run completed at least once, with results either passing or explicitly triaged for the human
- [ ] Cross-browser critical suite runs somewhere in CI (PR or scheduled — human decided which)
- [ ] `bootstrap.sh` category duplication removed
- [ ] Hardcoded route literals in PHP pattern files resolved via `Routes::public_page_url()`
- [ ] `routes.spec.js` URL comparison fixed
- [ ] CSP observation-window status and prerequisites (reporting endpoint) documented for the human

**⏸ Pause here for human review before starting Phase 4.**

---

## Phase 4 — God-class cleanup (lower priority, do only with explicit go-ahead)

This phase is riskier and lower-urgency than 0–3. It was deliberately deferred in the prior remediation session for good reason: `Public_Components` (~1,225 lines) and `Admin_UI` (~497 lines) are called from many theme template/pattern files via static method calls, so a naive extraction risks breaking call sites across the codebase. **Get explicit human go-ahead before starting this phase at all** — it's the kind of change best done when there's time for careful review, not squeezed in.

### 4.1 — Inventory before touching anything

```bash
grep -n "public static function\|private static function" wp-content/mu-plugins/longevity-core/class-public-components.php
grep -rn "Public_Components::" wp-content/themes/longevity-starter/ wp-content/mu-plugins/longevity-core/ | grep -v "class-public-components.php"
```
The second command gives you every call site you'll need to consider. Do the same for `Admin_UI`.

### 4.2 — Propose a grouping, get sign-off, *then* execute

Based on the methods already confirmed to exist (`render_breadcrumbs`/`breadcrumb_items`, `get_client_ip`/`render_contact_form`/`handle_contact_submission`, `render_policy_links`/`render_footer_nav`/`render_footer_meta`, `extract_headings`/`add_heading_ids`, `scope_label`, plus whatever else the inventory in 4.1 surfaces), a reasonable split is:
- `Public_Nav` — breadcrumbs, footer nav, policy links
- `Public_Contact` — client IP resolution, contact form render + handler
- `Public_Content` — heading extraction/IDs, shared render helpers (`options()`, `checked_context()`, `unique_id()`)
- `Public_Trust` — medical-review scope labels and any other trust/review-facing string helpers the inventory turns up

Present this grouping (or your revised version based on the full 4.1 inventory) to the human as a plan before writing any code. Once approved:

- **Use a backward-compatible facade, don't big-bang it.** Move method bodies into the new classes, then make `Public_Components` a thin delegator (`public static function render_breadcrumbs( int $post_id = 0 ): string { return Public_Nav::render_breadcrumbs( $post_id ); }`) so every existing call site across theme templates keeps working unchanged. This turns a repo-wide risky refactor into a safe, incremental one — call-site migration can happen in a later, separate PR once the split is proven stable.
- Mark the facade methods `@deprecated` in their docblocks pointing at the new home, so future code naturally migrates.
- Run the full test suite (`composer phpunit`, `npm run test:e2e`, `npm run test:a11y`) after the split, before and after — behavior must be byte-for-byte identical, this is a pure refactor.

Do the same facade-first approach for `Admin_UI` if you get to it (likely split: meta-box rendering vs. save handlers vs. user-profile fields, based on what `AdminUITest.php` already exercises).

**Definition of Done — Phase 4 (only if undertaken):**
- [ ] Full method inventory taken and grouping proposed to human before any code was written
- [ ] New domain classes created; old class(es) reduced to thin, deprecated-but-working facades
- [ ] Every existing call site still compiles/runs unchanged
- [ ] Full test suite passes before and after with no behavioral diff

---

## Phase 5 — Not yours to execute (human/infra only)

Do not attempt any of this. It requires production credentials, hosting-panel access, or organizational decisions outside a code repository. List it in your final summary as the remaining human work:

- Named, least-privilege production accounts; no shared/default admin
- MFA on all privileged accounts
- HTTPS/HSTS at the infrastructure layer
- SMTP configured with SPF/DKIM/DMARC
- Off-site, encrypted backups **with at least one completed restore drill** (this is exactly the kind of backup that should exist *instead of* what Phase 0 removed from git)
- Uptime and error monitoring wired to a real alert channel
- CSP violation-report collection endpoint, then the 30-day observation window from Phase 3.6, then flipping `Content-Security-Policy-Report-Only` to `Content-Security-Policy` (enforce)
- Repo branch-protection rules requiring the CI checks from Phase 1 to pass before merge

---

## Final master checklist

Use this as your top-level progress tracker across the whole plan:

- [ ] **Phase 0** — Credential rotated, files purged from working tree and history, `.gitignore` + CI guard added
- [ ] **Phase 1** — PHPStan fixed, stale test fixed, all 5 new PHPUnit files verified passing, CI runs the full quality/frontend/e2e pipeline
- [ ] **Phase 2** — Actions SHA-pinned, Akismet/theme vendoring decided and executed, dead code removed, contact-form notification decided
- [ ] **Phase 3** — a11y violations fixed, zoom overflow fixed, Lighthouse baselined, cross-browser CI added, known divergences from baseline doc cleaned up, CSP window status documented
- [ ] **Phase 4** — (optional, human-approved only) god classes split via backward-compatible facade
- [ ] **Phase 5** — handed off to human as a checklist, not executed

At the end of each phase, write a short summary covering: what changed, what you verified and how, anything you deliberately didn't do and why, and anything flagged for human decision or action. That summary is what the human reviews before you're cleared to start the next phase.
