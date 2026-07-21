# Longevity Evidence Lab — AI Implementation Plan

**Version:** 1.0  
**Prepared:** 20 July 2026  
**Target branch:** `improvement/reader-experience-v3`  
**Primary objective:** Turn the existing governed WordPress publishing platform into a coherent, useful, trustworthy, accessible, and launch-ready reader product without weakening editorial or medical-safety controls.

---

## 1. Purpose of this document

This document is an execution specification for an AI coding agent working on the Longevity Evidence Lab repository. It converts the current audit recommendations into a sequenced implementation program with:

- explicit architectural boundaries;
- exact work packages and repository targets;
- dependencies and priorities;
- acceptance criteria;
- test requirements;
- rollback rules;
- human-approval checkpoints;
- release evidence requirements; and
- a reusable operating prompt for future AI sessions.

The AI must treat this plan as an implementation contract, not as a loose list of ideas. It may propose a safer or simpler implementation when repository evidence justifies it, but it must document the deviation before coding and preserve all non-negotiable constraints in this plan.

---

## 2. Product outcome

At the end of this program, the website should deliver the following experience.

### 2.1 Reader experience

A new visitor should be able to understand within approximately ten seconds:

1. what the publication does;
2. what it does not claim;
3. where to begin;
4. how evidence is assessed;
5. how product testing differs from scientific evidence; and
6. whether a page is a guide, product report, methodology page, policy, or correction.

The primary reader journey should be:

> **Start Here → choose a question or topic → read a foundational guide → inspect evidence and limitations → continue to an intentional next step.**

Consumer Lab must not dominate the website before there is enough real, protocol-complete product inventory to support useful comparisons.

### 2.2 Editorial outcome

The platform must have one authoritative launch calendar and one launch brief set. Every published material claim must remain traceable to the claim and source registries. AI may assist with structure and implementation, but it must never independently approve medical claims, evidence grades, reviewer qualifications, product-test findings, disclosures, or publication readiness.

### 2.3 Engineering outcome

The repository should have:

- coherent route behavior;
- no public navigation links to anonymous 404 destinations;
- one shared test expectation model;
- passing static, unit, integration, browser, accessibility, visual, and performance checks;
- reproducible release artifacts;
- mobile-first quality gates;
- explicit manual QA records; and
- no unresolved critical security, accessibility, or editorial-safety defects.

---

## 3. Non-negotiable constraints

The AI must follow these constraints in every phase.

### 3.1 Medical and editorial safety

The AI must not:

- invent, infer, or independently approve medical claims;
- assign or change evidence grades without named human review;
- invent citations, source details, reviewer credentials, affiliations, conflicts, product measurements, test dates, firmware versions, prices, warranties, or privacy facts;
- fabricate a test record or mark a product as tested without a real approved record;
- publish or schedule health content autonomously;
- bypass, weaken, or silently remove publication gates;
- change a disclosure status to make content publishable;
- convert draft policy pages to published status without accountable human approval;
- use private health information in search, analytics, fixtures, or examples; or
- treat a code test passing as medical, legal, security, or accessibility certification.

### 3.2 Architecture

Preserve the current architectural split:

- WordPress remains the primary CMS.
- `wp-content/mu-plugins/longevity-core/` owns governance, editorial records, dynamic trust components, routes, and publication controls.
- `wp-content/themes/longevity-starter/` owns presentation and block templates.
- Private editorial records remain independent of the active theme.
- Public output remains server rendered by default.
- Do not introduce a front-end framework, headless CMS, GraphQL layer, page-builder dependency, or remote font service unless a separate architecture decision record is approved.

### 3.3 Data integrity

- Never delete human-owned pages, posts, terms, menus, or records automatically.
- Bootstrap-created records may be changed only when `_longevity_bootstrap = 1` and the change is explicitly scoped.
- Every migration must be idempotent.
- Every destructive migration requires a dry-run mode and backup evidence.
- Preserve stable IDs for claims, sources, protocols, test records, corrections, and affiliate records.

### 3.4 Accessibility and privacy

- Target WCAG 2.1 AA at minimum.
- Preserve keyboard access, visible focus, reduced-motion behavior, semantic landmarks, and responsive table behavior.
- Do not send raw health-related search queries, article body text, email addresses, or free-form medical text to analytics.
- Do not add a newsletter form until consent copy, privacy review, accessible status feedback, and a functioning handler exist.

### 3.5 AI change discipline

- One coherent ticket per pull request unless two changes are inseparable.
- No broad refactor mixed with copy changes, route changes, or content migrations.
- No unexplained generated code.
- No “temporary” bypasses that are not tracked and time-bounded.
- Every PR must state files changed, risk level, tests run, test results, and manual checks still required.

---

## 4. Repository source-of-truth map

Before changing code, the AI must read these files.

### 4.1 Required first read

- `AGENTS.md`
- `README.md`
- `content/governance/ai-assisted-work-policy.md`
- `content/editorial-policy.md`
- `docs/architecture/system-overview.md`
- `docs/architecture/content-model.md`
- `docs/architecture/permissions.md`
- `docs/testing/test-strategy.md`
- `docs/testing/release-acceptance.md`
- `docs/final-implementation-report.md`
- `docs/implementation-status.md`
- `operations/90-day-roadmap.md`

### 4.2 Runtime sources of truth

| Concern | Primary source |
|---|---|
| Canonical page and category definitions | `wp-content/mu-plugins/longevity-core/class-routes.php` |
| Bootstrap page/category state | `wp-content/mu-plugins/longevity-core/class-cli.php` |
| Publication readiness | `class-publication-gates.php` and `class-gate-result.php` |
| Public trust output | `class-public-components.php` |
| Product eligibility and ranking | `class-rankings.php` |
| Claims and sources | `class-claims.php` |
| Medical review and test validity | `class-review-methodology.php` |
| SEO output | `class-seo.php`, `class-schema.php`, and `bootstrap.php` |
| Reader discovery | `class-content-discovery.php` |
| Theme structure | `wp-content/themes/longevity-starter/` |
| Current launch sequence | `content/calendar/launch-calendar.csv` |
| Tests | `tests/`, `scripts/`, `lighthouserc.cjs`, and `.github/workflows/` |

### 4.3 Canonical editorial source after Phase 2

The intended canonical files will be:

- `content/calendar/launch-calendar.csv`
- `content/launch-briefs/`
- `content/calendar/internal-link-map.csv`
- `content/evidence/freshness-register.csv`

The old broad commercial calendar and first-eight brief set must be retained only as clearly labelled backlog/history.

---

## 5. Priority and risk model

### 5.1 Priority

- **P0:** launch blocker or safety/integrity contradiction.
- **P1:** high-impact reader experience, content, or quality improvement.
- **P2:** important refinement that can follow initial launch coherence.
- **P3:** optimization or scale work after real usage data exists.

### 5.2 Risk

- **Low:** copy, isolated pattern, or non-behavioral style change.
- **Medium:** route, query, template, metadata, or accessibility behavior.
- **High:** publication gates, roles, medical review, test eligibility, scoring, migrations, redirects, or production infrastructure.

High-risk changes require a dedicated PR and review by a human familiar with the affected domain.

---

## 6. Standard AI execution workflow

The AI must use this process for every implementation ticket.

### Step 1 — Establish baseline

1. Confirm the working tree is clean.
2. Create or switch to the target feature branch.
3. Record the current commit SHA.
4. Run the relevant baseline tests before editing.
5. Save the baseline result in the PR description or an artifact file.
6. If the relevant baseline already fails, document the failure and determine whether the ticket is responsible for fixing it. Do not silently normalize existing failures.

### Step 2 — Inspect before editing

1. Read all affected production files.
2. Read their related tests.
3. Search for duplicate selectors, route strings, meta keys, renderers, and documentation.
4. Identify data migrations and backwards-compatibility requirements.
5. State the intended implementation in a brief change note before coding.

### Step 3 — Implement narrowly

1. Make the smallest coherent change.
2. Reuse existing services and renderers.
3. Escape all public output and sanitize all input.
4. Preserve translation functions for public strings.
5. Use deterministic ordering and stable IDs.
6. Avoid hidden side effects.

### Step 4 — Test in layers

Run, as applicable:

```bash
make validate
composer validate --strict
composer lint
composer test
npm run lint
npm run test:e2e
npm run test:a11y
npm run test:lighthouse
```

For WordPress runtime work:

```bash
cp .env.ci .env
docker compose up -d db wordpress
docker compose run --rm --entrypoint sh wpcli /scripts/bootstrap.sh
docker compose run --rm --entrypoint sh wpcli /scripts/create-test-fixtures.sh
./scripts/smoke-test.sh
```

Always shut down isolated test environments after use:

```bash
docker compose down -v
```

### Step 5 — Review output, not only tests

The AI must manually inspect:

- desktop, tablet, and mobile rendering;
- keyboard flow;
- focus states;
- empty states;
- no-results behavior;
- long titles and long metadata;
- missing optional data;
- draft and unpublished destinations;
- print output for articles and reviews; and
- HTML source for canonical, robots, Open Graph, and schema output.

### Step 6 — Produce evidence

Every completed ticket must include:

- changed files;
- implementation summary;
- acceptance criteria result;
- commands run;
- test output summary;
- screenshots or reports when visual behavior changed;
- known limitations;
- human approvals still required; and
- rollback instructions.

---

## 7. Program sequence

| Phase | Priority | Outcome | Must precede |
|---|---:|---|---|
| 0. Baseline and decision lock | P0 | Reproducible starting point | All phases |
| 1. Route and release coherence | P0 | No contradictory public/draft behavior | UI navigation and hubs |
| 2. Editorial source consolidation | P0 | One launch plan and brief system | Content creation |
| 3. Homepage and global navigation | P1 | Clear reader-first positioning | Broad visual refinement |
| 4. Topics, Guides, Start Here, and Search | P1 | Useful discovery journeys | Content scale |
| 5. Evidence and article UX | P1 | Inspectable claim-level trust | Publishing launch articles |
| 6. Consumer Lab and ranking integrity | P1/P2 | No premature or falsely precise rankings | Commercial scale |
| 7. Design-system consolidation | P1/P2 | Distinctive, maintainable visual system | Final visual regression lock |
| 8. QA, performance, accessibility, security | P0/P1 | Defensible release evidence | Production release |
| 9. Trust-first content launch | P1 | Useful original launch library | Marketing/outreach |
| 10. Measurement and iteration | P2 | Privacy-safe product learning | Scale decisions |

Do not start Phase 9 publication work until Phases 1 and 2 are complete. Do not promote Consumer Lab rankings until Phase 6 exit criteria are met.

---

# Phase 0 — Baseline and decision lock

## RX-000 — Create the implementation branch and audit folder

**Priority:** P0  
**Risk:** Low

### Files to add

- `docs/implementation/reader-experience-v3-plan.md`
- `docs/testing/artifacts/reader-experience-v3/README.md`
- `docs/testing/artifacts/reader-experience-v3/baseline-test-results.md`
- `docs/testing/artifacts/reader-experience-v3/route-inventory.csv`

### Implementation

1. Create `improvement/reader-experience-v3` from the latest approved baseline.
2. Copy this plan into `docs/implementation/reader-experience-v3-plan.md`.
3. Record commit SHA, date, environment, WordPress version, PHP version, Node version, browser version, and Docker version.
4. Run all currently available tests.
5. Record pass, fail, skipped, and unavailable status separately. Do not describe an unavailable test as passed.

### Acceptance criteria

- Baseline commit is recorded.
- Existing failures are listed with exact commands and output summaries.
- No production file is changed in the baseline commit.
- A human can reproduce the baseline from the documented commands.

---

## RX-001 — Capture route and navigation baseline

**Priority:** P0  
**Risk:** Low

### Inventory fields

The route inventory must include:

```csv
route_key,path,resource_type,bootstrap_status,anonymous_status,indexation,linked_from_header,linked_from_footer,linked_from_home,expected_launch_state,notes
```

### Required routes

Audit at minimum:

- `/`
- `/start-here/`
- `/guides/`
- `/topics/`
- `/reviews/`
- `/evidence-methodology/`
- `/testing-methodology/`
- `/editorial-policy/`
- `/corrections/`
- `/affiliate-disclosure/`
- `/medical-disclaimer/`
- `/about/`
- `/contact/`
- `/privacy/`
- `/terms/`
- `/ai-assisted-work-disclosure/`
- `/source-registry/`
- all canonical category routes;
- all legacy category routes;
- search results;
- an unknown route; and
- representative article, review, author, and archive routes.

### Acceptance criteria

- Every header, footer, homepage, and methodology link has a recorded anonymous response.
- Redirect chains are recorded.
- Draft routes are distinguished from published placeholders.
- Screenshots exist at 360, 768, and 1440 pixel widths for key routes.

---

## RX-002 — Lock product decisions

**Priority:** P0  
**Risk:** Low

Create a decision record in `docs/adr/` or `docs/implementation/reader-experience-v3-decisions.md` covering:

1. **Primary launch product:** evidence-led reader education, not product rankings.
2. **Primary homepage CTA:** Start Here.
3. **Top navigation at launch:** Start Here, Topics, How We Work, About, Search.
4. **Reviews visibility:** public archive may exist, but top-navigation prominence is enabled only when ranking inventory meets the threshold in Phase 6.
5. **Canonical editorial calendar:** `content/calendar/launch-calendar.csv`.
6. **Draft behavior:** anonymous 404 and no public navigation link.
7. **CI behavior:** synthetic fixtures may represent publishable states, but production bootstrap defaults remain draft for pages requiring human approval.
8. **Newsletter behavior:** omitted until functional and approved; never show a public “not configured” component.

### Acceptance criteria

- Decisions are approved before implementation begins.
- Later PRs reference the decision record instead of re-litigating basic product direction.

---

# Phase 1 — Route and release coherence

## RX-101 — Centralize E2E route expectations

**Priority:** P0  
**Risk:** Medium

### Problem

Route expectations are duplicated across `routes.spec.js`, `page-readiness.spec.js`, and `seo.spec.js`. Some tests expect draft pages to return 200 while production bootstrap creates those pages as drafts, which should return 404 to anonymous visitors.

### Files

- Add `tests/e2e/support/route-expectations.js`
- Update:
  - `tests/e2e/routes.spec.js`
  - `tests/e2e/page-readiness.spec.js`
  - `tests/e2e/seo.spec.js`
  - `tests/e2e/accessibility.spec.js` where relevant

### Expected model

Define routes by state, not as one undifferentiated array:

```js
export const PUBLIC_ROUTES = [
  { key: 'home', path: '/', indexable: true },
  { key: 'start_here', path: '/start-here/', indexable: true },
  // Fixture-published policy and hub routes are added by the test fixture.
];

export const PRIVATE_DRAFT_ROUTES = [
  { key: 'ai_assist_disclosure', path: '/ai-assisted-work-disclosure/' },
  { key: 'source_registry', path: '/source-registry/' },
];

export const LEGACY_REDIRECTS = [
  // legacy path and canonical path
];
```

The fixture generator may publish synthetic, non-medical page content for browser testing. It must never run in production and must not change production bootstrap defaults.

### Required tests

- Public fixture routes return 200.
- Private draft routes return 404 anonymously.
- Search returns 200 and `noindex,follow`.
- Unknown route returns 404.
- No duplicate route page exists.
- The test expectation keys exist in `Routes::definitions()`.

### Acceptance criteria

- No E2E test expects an anonymous WordPress draft page to return 200.
- One support module owns route expectations for all E2E suites.
- Tests clearly distinguish production bootstrap behavior from CI fixture behavior.

---

## RX-102 — Correct redirect and canonical testing

**Priority:** P0  
**Risk:** Medium

### Files

- `wp-content/mu-plugins/longevity-core/bootstrap.php`
- `wp-content/mu-plugins/longevity-core/class-routes.php`
- `tests/php/RoutesTest.php`
- `tests/php/ArchitectureTest.php`
- `tests/e2e/routes.spec.js`
- `tests/e2e/seo.spec.js`

### Implementation

1. In canonical output, use `Routes::canonical_url_for_slug()` rather than independently reading an inconsistent `legacy_slug` key.
2. Ensure no duplicate canonical tags are emitted when another SEO plugin is active.
3. Test legacy redirects with redirects disabled so the actual 301 status is asserted. A final 200 after automatic following is not proof of a 301.
4. Preserve safe query parameters only when explicitly allowlisted. Do not propagate tracking or arbitrary parameters through redirects by default.
5. Confirm redirect-loop prevention.

### Acceptance criteria

- Legacy category path returns exactly one 301 to the canonical short slug.
- Canonical category path returns 200 and does not redirect.
- Exactly one canonical link is rendered.
- No canonical URL points to a draft, legacy, or query-parameter variant.
- Unit and browser tests cover canonical and legacy slugs.

---

## RX-103 — Add route-publication helpers

**Priority:** P0  
**Risk:** Medium

### Files

- `class-routes.php`
- `tests/php/RoutesTest.php`

### Add methods

Implement methods with request caching:

```php
Routes::page_status(string $key): ?string
Routes::is_public_page(string $key): bool
Routes::public_page_url(string $key): ?string
Routes::is_public_category(string $key): bool
Routes::route_key_for_path(string $path): ?string
```

`is_public_page()` should require:

- an existing page;
- `post_status === 'publish'`;
- no `_longevity_noindex` placeholder flag; and
- a valid permalink.

Do not equate “page exists” with “page is safe to link publicly.”

### Acceptance criteria

- Draft pages never produce a public URL through the new helper.
- Existing `page_url()` remains available for administrative/internal uses.
- Tests cover missing, draft, noindex, published, and front-page routes.

---

## RX-104 — Suppress unavailable navigation destinations

**Priority:** P0  
**Risk:** Medium

### Files

- `wp-content/mu-plugins/longevity-core/bootstrap.php`
- `wp-content/themes/longevity-starter/parts/header.html`
- `wp-content/themes/longevity-starter/parts/footer.html`
- `tests/e2e/navigation.spec.js` — new

### Implementation decision

1. Remove the raw HTML quick-link strip from the header.
2. Keep the core WordPress Navigation block for its accessibility behavior.
3. Add a `render_block_core/navigation-link` filter that:
   - identifies links corresponding to registered routes;
   - returns an empty string when the page is not public;
   - replaces the destination with the canonical public URL when it is public;
   - leaves unrelated external or editorial links unchanged.
4. Convert footer route lists from one raw HTML block into core navigation links or a route-aware dynamic component so unavailable pages can be omitted.
5. Do not generate fallback links to non-existent pages.

### Launch header

- Start Here
- Topics
- How We Work — label may point to `/evidence-methodology/`
- About
- Search

Reviews may be added later by Phase 6 inventory logic.

### Acceptance criteria

- No header or footer anchor resolves to anonymous 404.
- Draft pages are absent from rendered navigation.
- Header remains usable with keyboard, mobile overlay, Escape, and focus return.
- External links are unaffected.
- Navigation tests run at desktop and mobile widths.

---

## RX-105 — Add runtime internal-link crawling

**Priority:** P0  
**Risk:** Medium

### Files

- Add `tests/e2e/internal-links.spec.js`
- Enhance `scripts/validate-internal-links.py`
- Update `.github/workflows/ci.yml`

### Behavior

Static content validation is not enough because block templates, shortcodes, and dynamic components create links at runtime. Add a browser crawler that:

- starts from public launch routes;
- collects same-origin anchors;
- normalizes fragments and trailing slashes;
- ignores mail, telephone, admin, logout, preview, feed, and explicitly excluded routes;
- checks final response status;
- detects redirect chains longer than one hop;
- reports source page, link text, target, and status;
- fails on public 404/410/500 targets; and
- warns on links to noindex destinations.

### Acceptance criteria

- CI fails when a public template links to a draft page.
- The report is uploaded as an artifact on failure.
- The crawler has a route and page limit to prevent infinite traversal.

---

## Phase 1 exit criteria

- Production drafts remain anonymous 404s.
- CI fixture pages can be tested as 200 without changing production bootstrap defaults.
- Navigation contains no unavailable route.
- Redirects and canonicals are correct.
- Runtime internal-link crawl passes.
- All route-related tests use a coherent expectation model.

---

# Phase 2 — Editorial source consolidation

## RX-201 — Declare the canonical editorial calendar

**Priority:** P0  
**Risk:** Low

### Files

- Add `content/calendar/README.md`
- Keep `content/calendar/launch-calendar.csv`
- Move `content/editorial-calendar.csv` to `content/backlog/editorial-calendar-legacy.csv`
- Update references in documentation and validation scripts

### Requirements

`content/calendar/README.md` must state:

- `launch-calendar.csv` is the only active launch sequence.
- Files under `content/backlog/` are ideas, not approved publication commitments.
- A backlog date is not a publication date.
- “Testing required” content cannot advance without approved real-world records.
- Medical-review status is assigned and approved by humans.

### Acceptance criteria

- No script treats the legacy calendar as current.
- No current documentation calls the legacy first-eight items the launch set.
- Git history preserves the old calendar.

---

## RX-202 — Separate legacy briefs from launch briefs

**Priority:** P0  
**Risk:** Low

### Files

- Move `content/first-8-briefs/` to `content/backlog/legacy-first-8-briefs/`
- Add `content/launch-briefs/`
- Use `content/templates/content-brief-v2.md` as the base

### Initial launch briefs

Create brief files for at least LEL-001 through LEL-008. The AI may populate structure, reader intent, required modules, prohibited claims, workflow fields, and placeholders. It must not fabricate source lists, evidence grades, reviewer identities, or approved medical language.

Each brief must include:

- content ID and owner;
- target reader and decision;
- scope and exclusions;
- primary question;
- reader risks;
- allowed and prohibited claim categories;
- required claim registry records;
- required source categories;
- evidence cutoff placeholder;
- medical-review requirement and scope placeholder;
- original contribution;
- required diagram, matrix, worksheet, or table;
- accessibility requirements;
- internal-link journey;
- metadata and search intent;
- update triggers;
- analytics events;
- publication gate checklist; and
- named human approvals required.

### Acceptance criteria

- Every active launch-calendar item has a brief or an explicit “not yet briefed” state.
- Legacy briefs are not mistaken for approved launch work.
- Validators distinguish placeholders from verified facts.

---

## RX-203 — Update content validation for source-of-truth rules

**Priority:** P0  
**Risk:** Medium

### Files

- `scripts/validate-content.py`
- `scripts/validate-freshness.py`
- `scripts/validate-internal-links.py`
- tests for those scripts

### Validation rules

- Active calendar IDs are unique.
- Order and publish week are valid.
- Blocked testing items cannot have a “ready” status.
- Every active brief ID exists exactly once.
- Briefs requiring medical review include human-review placeholders and cannot claim completion.
- Backlog files do not satisfy launch readiness.
- Original-contribution fields are not empty.
- Internal-link targets refer to active or explicitly planned content IDs.
- No draft brief contains invented citation identifiers in placeholder sections.

### Acceptance criteria

- `make validate` fails on duplicated IDs, missing active briefs, or testing-required items incorrectly marked ready.
- Backlog content is validated for file integrity but excluded from launch readiness.

---

## RX-204 — Create an editorial status vocabulary

**Priority:** P1  
**Risk:** Medium

Use a fixed allowlist, for example:

- `Planned`
- `Briefing`
- `Researching`
- `Drafting`
- `Human fact-check`
- `Medical review`
- `Editorial approval`
- `Ready for scheduling`
- `Published`
- `Blocked — testing required`
- `Blocked — reviewer required`
- `Paused`
- `Retired`

Do not use vague values such as “almost ready.”

### Acceptance criteria

- Validators reject unknown states.
- Automated systems cannot move an item into approval or publication states.
- State transitions requiring humans are documented.

---

## Phase 2 exit criteria

- One active calendar exists.
- Launch briefs use the richer template.
- Legacy commercial plans are clearly backlog.
- Validation prevents blocked testing content from appearing launch-ready.
- AI and human responsibilities are explicit.

---

# Phase 3 — Homepage and global navigation

## RX-301 — Redesign the homepage hero

**Priority:** P1  
**Risk:** Low

### File

- `wp-content/themes/longevity-starter/patterns/homepage-hero.php`

### Target copy structure

**Eyebrow**  
Independent health evidence and consumer testing

**H1**  
Understand what works, what is uncertain, and what is worth considering.

**Supporting copy**  
Explain that the publication examines claims and products with documented evidence, visible limitations, transparent methods, corrections, and editorial independence. Keep it to approximately two short sentences.

**Primary CTA**  
Start Here → `/start-here/`

**Secondary CTA**  
Browse Topics → `/topics/`

**Compact trust signals**

- Evidence graded
- Testing documented
- Conflicts disclosed

### Requirements

- Do not lead with Consumer Lab.
- Do not place the full medical disclaimer in the hero.
- Do not make outcome promises.
- Maintain one H1.
- Buttons must remain visible and usable at 320 pixels.

### Acceptance criteria

- Value proposition is understandable without scrolling.
- Primary action is Start Here.
- Hero has no link to a draft route.
- No layout shift occurs from hero content.

---

## RX-302 — Replace quick links with a focused header

**Priority:** P1  
**Risk:** Medium

### File

- `parts/header.html`
- related CSS and JS

### Requirements

- Remove the quick-link strip.
- Keep brand, concise tagline, primary navigation, and search.
- Use the route-publication filter from RX-104.
- Search dialog must preserve:
  - labelled dialog;
  - initial focus;
  - focus containment;
  - Escape close;
  - click-close where appropriate;
  - focus restoration; and
  - non-JavaScript search fallback.

### Acceptance criteria

- Header is visually quieter.
- Navigation does not wrap awkwardly at common laptop widths.
- Mobile menu and search do not conflict.
- Keyboard and screen-reader checks pass.

---

## RX-303 — Rebuild homepage information architecture

**Priority:** P1  
**Risk:** Medium

### Files

- `templates/front-page.html`
- modify or add patterns under `patterns/`
- dynamic blocks in `class-blocks.php` and `class-public-components.php` where necessary

### Required order

1. Homepage hero
2. Choose your path
3. Featured foundational resources
4. Topic directory preview
5. Why trust this publication
6. Testing program / Consumer Lab state
7. Methodology links
8. Newsletter only when configured and approved

### New pattern: Choose your path

Add `patterns/choose-your-path.php` with three cards:

1. **Understand evidence** — evaluate claims and study quality.
2. **Improve the foundations** — sleep, movement, and nutrition.
3. **Evaluate a product** — measurements, devices, protocols, and buyer facts.

Each card must lead to a public destination and answer a reader question.

### Featured resources

Implement a server-rendered block using WordPress sticky posts as the editorial control:

- one primary sticky evidence guide;
- up to three supporting sticky guides;
- fallback to latest published guides only when no sticky guide exists;
- never surface a draft, noindex placeholder, or ineligible review;
- display content type, decision-oriented excerpt, updated date, and reading time;
- do not display a page-wide evidence letter as though it grades the entire article.

### Acceptance criteria

- Homepage remains useful when there are zero eligible product rankings.
- Homepage contains no public configuration placeholder.
- Every section has a distinct reader purpose.
- The same destination is not promoted repeatedly without a clear reason.

---

## RX-304 — Create Consumer Lab prelaunch and live states

**Priority:** P1  
**Risk:** Medium

### Files

- `class-rankings.php`
- `class-public-components.php`
- `patterns/consumer-lab-feature.php`
- `patterns/how-testing-works.php`
- `patterns/final-consumer-lab-cta.php`

### Prelaunch state

When no category meets the ranking threshold:

- section label: “Our testing program”;
- explain that protocols define how future testing is conducted;
- state that a protocol does not mean a product has been tested;
- link to testing methodology;
- optionally link to published measurement-literacy guides;
- do not use “Explore rankings” as the primary call to action.

### Live state

When at least one category meets the minimum eligible comparison threshold:

- show ranking categories;
- allow a secondary “Explore Consumer Lab” action;
- keep limitations and eligibility rules visible.

### Acceptance criteria

- Empty ranking inventory does not look like a failed product page.
- The live state activates automatically from eligible data, not a hardcoded date.
- Tests cover both states with fixtures.

---

## RX-305 — Remove the public newsletter placeholder

**Priority:** P1  
**Risk:** Low

### Files

- `templates/front-page.html`
- `patterns/newsletter-cta.php`

### Implementation

- Remove the placeholder pattern from the public homepage.
- Retain a documented pattern or admin note for future configuration, but do not render “Newsletter not configured” publicly.
- Future newsletter activation requires:
  - functioning handler;
  - consent copy;
  - privacy approval;
  - double opt-in decision;
  - accessible success and error messages;
  - abuse controls; and
  - analytics payload review.

### Acceptance criteria

- No public page shows an internal configuration status.

---

## RX-306 — Simplify the footer

**Priority:** P1  
**Risk:** Medium

### Recommended groups

- Explore
- How We Work
- About
- Policies

Consumer Lab should not require a dedicated footer column before it has real inventory.

### Requirements

- Show only public pages.
- Include medical disclaimer, affiliate disclosure, corrections, privacy, and terms when published.
- Add AI-assisted work disclosure only after human approval and publication.
- Preserve policy review date and ownership meta.
- Avoid fallback links to missing pages.

### Acceptance criteria

- No broken footer links.
- Footer is not visually denser than the main content.
- Policy links are easy to locate.

---

## Phase 3 exit criteria

- Homepage leads with evidence and reader goals.
- Header and footer are simplified and route-safe.
- Consumer Lab has honest prelaunch behavior.
- Newsletter placeholder is gone.
- Mobile, keyboard, and visual checks pass.

---

# Phase 4 — Topics, Guides, Start Here, and Search

## RX-401 — Build a genuine Topics hub

**Priority:** P1  
**Risk:** Medium

### Files

- Add `templates/page-topics.html`
- Add a `topic-directory` dynamic block:
  - block metadata under `blocks/topic-directory/`
  - registration in `class-blocks.php`
  - renderer in `class-public-components.php`
- Add styles and tests

### Topic cards

Each topic card should include:

- short reader-facing label;
- canonical category URL;
- one-sentence purpose;
- one example question;
- count of published guides;
- count of eligible product reports only when greater than zero;
- optional featured guide; and
- no empty marketing promise.

### Reader-facing labels

Use concise display labels while retaining full taxonomy names in descriptions:

- Evidence Literacy
- Sleep
- Movement
- Nutrition
- Wearables
- Supplements

Consumer Lab is a content type/program, not a health topic, and should be presented separately.

### Empty topic behavior

If a topic has no substantive published content:

- either omit it from the public hub; or
- show it only when a human-approved introduction and useful destination exist.

Do not publish six empty category cards simply to fill a grid.

### Acceptance criteria

- `/topics/` is a useful hub, not a placeholder.
- Cards answer reader questions.
- Category counts exclude drafts and ineligible reviews.
- One H1 and logical heading hierarchy.

---

## RX-402 — Build a Guides archive

**Priority:** P1  
**Risk:** Medium

### Files

- Add `templates/page-guides.html`
- add `guide-directory` dynamic block or a dedicated query renderer
- update discovery logic and tests

### Features

- topic filter;
- sort by relevance/default editorial order, recently updated, and newest;
- content cards with decision-oriented excerpts;
- active filter summary;
- clear-filters action;
- pagination;
- useful no-results suggestions;
- no review posts in the guide-only archive.

### Acceptance criteria

- `/guides/` is reachable only after it contains a functional archive and human-approved introduction.
- Query parameters are allowlisted and sanitized.
- Filtered variants are canonicalized appropriately and usually noindexed unless an SEO decision approves indexation.

---

## RX-403 — Rebuild Start Here as guided onboarding

**Priority:** P1  
**Risk:** Medium

### Files

- Add `templates/page-start-here.html`
- add patterns for reader onboarding
- human-owned page content remains in WordPress

### Required sections

1. What the publication does.
2. What it does not do.
3. Difference between evidence grading and product testing.
4. How to read uncertainty and limitations.
5. Choose a goal.
6. Recommended first resources.
7. How medical review works.
8. How commercial relationships are handled.
9. How to request a correction.

### Requirements

- Do not turn the page into a legal-document wall.
- Keep key explanations in plain language.
- Link to full policies rather than duplicating them.
- Human review is required for health-safety wording.

### Acceptance criteria

- A usability reviewer can identify the next action without assistance.
- All policy references resolve publicly.
- No unsupported health recommendation appears.

---

## RX-404 — Improve search and filters

**Priority:** P1  
**Risk:** Medium

### Files

- `class-content-discovery.php`
- `class-public-components.php`
- `templates/search.html`
- add tests in `tests/e2e/search.spec.js`

### Additions

- topic/category filter using canonical route keys;
- visible active-filter chips or summary;
- clear-all link;
- content type and sort remain allowlisted;
- query-aware heading;
- suggested topic and Start Here links on zero results;
- preserve no-JavaScript operation;
- no raw health query in analytics payloads.

### Query rules

- Validate category against `Routes::definitions()['categories']`.
- Use `tax_query` only with valid term IDs.
- Preserve relevance ordering when no explicit sort is selected.
- Do not expose private post types.
- Search remains `noindex,follow`.

### Acceptance criteria

- Invalid filters safely fall back.
- Empty results provide useful next steps.
- Filter state is visible and keyboard accessible.
- URLs are shareable and deterministic.

---

## RX-405 — Add question-led discovery copy

**Priority:** P1  
**Risk:** Low

Update homepage, topic, Start Here, archive, and no-results copy to use real reader questions, for example:

- How can I improve sleep before buying a device?
- What can a sleep tracker measure reliably?
- How do I know whether a health claim is supported?
- What buyer facts matter beyond a product score?

The AI may draft wording, but medical claims and action guidance require human review.

### Acceptance criteria

- Navigation labels remain concise.
- Supporting copy is decision-oriented rather than taxonomy jargon.

---

## Phase 4 exit criteria

- Topics and Guides are real functional destinations.
- Start Here provides guided onboarding.
- Search has topic filters and strong empty states.
- Discovery paths are question-led.

---

# Phase 5 — Evidence and article UX

## RX-501 — Clarify overall conclusion confidence

**Priority:** P1  
**Risk:** Medium

### Files

- `class-public-components.php`
- styles
- tests for trust summary and content-card metadata

### Change

The current single evidence grade can look like a grade for the whole article. Change the public label to something like:

> **Confidence in the main conclusion: Moderate**

The exact conclusion scope must be visible beside it. Preserve the stored evidence-grade field for backwards compatibility, but avoid displaying a bare “Evidence B” badge on general content cards.

### Card behavior

Replace broad letter display with one or more of:

- Evidence guide
- Updated date
- Medical review recorded
- Claim-level evidence available

Only use a grade on a card when the card explicitly states the scoped conclusion being graded.

### Acceptance criteria

- Public wording does not imply that one grade applies to every claim in an article.
- Existing metadata remains intact.
- Unit tests cover each grade and missing rationale.

---

## RX-502 — Add a public claim evidence matrix

**Priority:** P1  
**Risk:** High

### Files

- `class-claims.php`
- `class-public-components.php`
- `class-blocks.php`
- add block metadata under `blocks/claim-evidence-matrix/`
- tests in `tests/php/` and E2E

### Public fields

For verified material claims only, render:

- claim text or approved public paraphrase;
- evidence grade;
- population;
- outcome;
- evidence design;
- concise evidence rationale;
- verification date; and
- source link/count.

Do not expose private notes, conflict notes intended for editors, reviewer email addresses, full source text, or unverified claims.

### Safety rules

- Only `verification_status = verified` claims may appear.
- Claims with a superseding record must show the current record only or a visible updated status.
- The matrix must be omitted when no verified material claims exist.
- The AI must not create claim records merely to populate the component.

### Acceptance criteria

- Public matrix is generated from authoritative records.
- No private metadata is exposed.
- Tests cover verified, unverified, superseded, missing, and duplicate claims.

---

## RX-503 — Improve source presentation

**Priority:** P1  
**Risk:** Medium

### Files

- `class-claims.php`
- `class-public-components.php`
- styles and tests

### Add where data exists

- source type;
- claim or section supported;
- publication date;
- accessed date;
- identifier;
- archive link;
- applicable jurisdiction;
- visible funding/conflict note only when approved for public display;
- label such as guideline, systematic review, trial, observational study, official documentation, or manufacturer documentation.

### Requirements

- No long quotations.
- No full copyrighted source body.
- Deduplicate sources while preserving claim associations.
- External links use safe `rel` attributes.

### Acceptance criteria

- A reader can understand why a source is included.
- Source details remain readable on mobile.
- No private editorial notes appear.

---

## RX-504 — Add manual editorial next-step relationships

**Priority:** P1  
**Risk:** Medium

### Files

- `class-meta-registry.php`
- `class-public-components.php`
- admin UI as needed
- tests

### Data model

Add a protected metadata field such as:

```text
_longevity_related_post_ids
```

Store an ordered, deduplicated list of public post IDs. Validate that:

- IDs refer to published `post` or eligible `review` objects;
- the current post is excluded;
- maximum count is bounded;
- unauthorized users cannot edit it.

### Rendering priority

1. Manual next-step IDs.
2. Editorial internal-link map where integrated.
3. Shared category.
4. Shared tags.
5. Same content type fallback.

### Acceptance criteria

- Manual order is preserved.
- Draft and ineligible content is excluded.
- Automatic fallback remains available.
- Related content is not simply “newest in category” when a manual journey exists.

---

## RX-505 — Improve article metadata hierarchy

**Priority:** P1  
**Risk:** Low

### Requirements

Group metadata visually instead of presenting one long dotted line. Recommended hierarchy:

- author and reviewer identity;
- published and updated dates;
- evidence cutoff;
- reading time;
- correction/update status.

Medical-review labels must continue to require verified credentials, completed status, and attestation.

### Acceptance criteria

- Metadata wraps cleanly on mobile.
- Screen readers encounter meaningful phrases without decorative separators.

---

## RX-506 — Add original visual modules

**Priority:** P1  
**Risk:** Medium

Define reusable block patterns for:

- evidence-risk matrix;
- decision tree;
- protocol timeline;
- study-appraisal worksheet;
- measurement-boundary diagram;
- buyer-facts table; and
- correction/update timeline.

### Requirements

- Information must remain available in text or table form.
- Images require meaningful alt text or empty alt when decorative.
- Diagrams should be original and editable.
- Do not rely on generic stock wellness imagery as the main explanatory asset.

### Acceptance criteria

- Every launch article brief specifies at least one original contribution.
- Visual modules remain understandable in print and at 200% zoom.

---

## Phase 5 exit criteria

- Evidence presentation is claim-aware.
- Overall confidence is scoped.
- Sources explain their role.
- Related content follows intentional journeys.
- Launch articles have reusable original visual modules.

---

# Phase 6 — Consumer Lab and ranking integrity

## RX-601 — Require meaningful comparison inventory

**Priority:** P1  
**Risk:** High

### Files

- `class-rankings.php`
- `class-public-components.php`
- tests
- scoring configuration documentation

### Rule

A category must have at least **three eligible, genuinely comparable product reports** before it is presented as a numbered public ranking.

Individual eligible reports may be published before the threshold, but they must appear as reports, not as a ranking.

### Additions

- `Rankings::minimum_ranking_size()` with a filterable default of 3.
- `Rankings::has_public_ranking_inventory()`.
- Directory groups exclude categories below threshold.
- Review archive empty/prelaunch state explains the distinction.

### Acceptance criteria

- One or two reports cannot produce “#1” ranking language.
- Tests cover 0, 1, 2, 3, and more eligible reports.
- Comparability remains an editorial requirement, not just a count.

---

## RX-602 — Reduce false precision and add ranking bands

**Priority:** P2  
**Risk:** High

### Files

- `config/scoring/default-review-model.json`
- scoring validation code
- `class-rankings.php`
- ranking renderer and tests

### Model change

Add a validated value such as:

```json
"minimum_meaningful_difference": 0.2
```

Products whose scores differ by less than the threshold and have comparable confidence may share a ranking band or be labelled “not meaningfully different under this scoring model.”

### Requirements

- Threshold is versioned with the scoring model.
- Changing the threshold creates a new model version.
- Confidence may prevent a lower-confidence product from sharing a definitive top band.
- Manual override requires a reason and audit event.
- Commercial relationships never affect bands or order.

### Acceptance criteria

- Public ranking explains ties/bands.
- Historical scores remain reproducible under their original model.
- Tests cover boundary values and model versions.

---

## RX-603 — Expand buyer-facts presentation

**Priority:** P1  
**Risk:** Medium

### Files

- metadata registry if fields are missing
- `render_product_report_summary()`
- review templates and tests

### High-visibility facts

Display only verified values:

- price and date checked;
- subscription amount and billing interval;
- market/region;
- exact model, size, hardware, firmware, and app version;
- test dates;
- acquisition method in human-readable language;
- warranty and date checked;
- return policy and date checked;
- account requirement;
- data-export availability;
- privacy-policy check date;
- comparison set; and
- material limitations.

### Requirements

- Map internal enum values to reader-facing labels. Never expose raw values such as `product_supplied`.
- Stale commercial facts must trigger recheck warnings or publication blocks according to policy.

### Acceptance criteria

- Buyer facts are visible before the long review body.
- Missing facts are omitted or clearly marked, never invented.
- Dates and regions are explicit.

---

## RX-604 — Clarify test confidence and scope

**Priority:** P1  
**Risk:** Medium

### Requirements

- Distinguish product-unit observations from clinical validation.
- Show test record status, protocol version, deviations, failures, and sample limitations.
- “Tested” appears only when the approved, protocol-matched record is valid.
- Preliminary confidence may allow a report but must not imply definitive ranking.

### Acceptance criteria

- Every tested statement is traceable to a valid record.
- Invalid or version-mismatched records suppress tested presentation.

---

## RX-605 — Add scoring sensitivity disclosure

**Priority:** P2  
**Risk:** High

For categories where weighting choices materially change order, provide a concise sensitivity note or downloadable method output showing:

- scoring dimensions;
- weights;
- model version;
- missing-data behavior;
- meaningful-difference threshold; and
- whether reasonable alternate weights change the top band.

Do not present sensitivity output as statistical certainty unless the method supports that interpretation.

---

## Phase 6 exit criteria

- No ranking is produced from fewer than three comparable eligible reports.
- Scores do not imply unsupported precision.
- Buyer facts are prominent and dated.
- Test confidence and limitations are visible.
- Consumer Lab top-navigation promotion is enabled only after inventory criteria pass.

---

# Phase 7 — Design-system consolidation

## RX-701 — Introduce CSS cascade layers

**Priority:** P1  
**Risk:** Medium

### Files

- `wp-content/themes/longevity-starter/style.css`
- `assets/css/consumer-lab.css`
- editor style loading as needed

### Target structure

```css
@layer tokens, reset, base, layout, components, utilities, overrides;
```

### Rules

- Tokens are declared once.
- A component has one authoritative definition.
- Consumer Lab styles do not silently override global components by load order.
- Temporary compatibility rules live in `overrides` with comments and removal criteria.
- Editor and front end remain visually aligned.

### Acceptance criteria

- Duplicate selectors are inventoried and resolved.
- Existing component snapshots are reviewed.
- CSS lint passes.
- No specificity escalation using unnecessary IDs or `!important`.

---

## RX-702 — Standardize component states

**Priority:** P1  
**Risk:** Medium

Create consistent visual rules for:

- guide cards;
- report cards;
- methodology panels;
- policy pages;
- empty states;
- warnings and limitations;
- confidence labels;
- medical-review cards;
- correction notices;
- forms;
- tables; and
- focus/hover/active/disabled states.

### Acceptance criteria

- Color is never the only status indicator.
- Text contrast passes automated and manual checks.
- Long labels and translations do not break layout.

---

## RX-703 — Establish a distinctive editorial visual language

**Priority:** P2  
**Risk:** Low

### Direction

Use restrained editorial design based on:

- inspectable data;
- diagrams and matrices;
- clear evidence and status hierarchy;
- real test photography when available;
- consistent annotation styles; and
- subtle differentiation between content types.

Avoid:

- generic wellness stock-photo dominance;
- decorative scientific imagery that implies evidence;
- glowing supplement bottles;
- fake dashboards;
- excessive gradients, glass effects, animation, or gamified scores.

### Typography

- Keep body typography highly readable.
- A bundled local editorial serif may be tested for article titles or pull quotes.
- Do not add remote font requests.
- Measure performance and readability before adoption.

### Acceptance criteria

- Content types are recognizable without relying only on color.
- Visual identity is distinctive but sober.
- No visual treatment implies clinical certainty.

---

## RX-704 — Create responsive and print design tokens

**Priority:** P2  
**Risk:** Low

Document tokens for:

- content width;
- wide width;
- spacing scale;
- type scale;
- border radius;
- border strength;
- status colors;
- focus ring;
- print colors; and
- table behavior.

### Acceptance criteria

- Tokens are documented in `docs/architecture/` or theme documentation.
- Components do not introduce arbitrary one-off values without justification.

---

## Phase 7 exit criteria

- CSS ownership is clear.
- Core components have consistent states.
- Visual language supports evidence and decisions.
- Front-end and editor views remain aligned.

---

# Phase 8 — QA, performance, accessibility, security, and release evidence

## RX-801 — Convert screenshots into true visual regression tests

**Priority:** P0  
**Risk:** Medium

### Files

- `tests/e2e/visual.spec.js`
- Playwright snapshot configuration
- approved baseline artifacts

### Change

Replace screenshot-only evidence with `expect(page).toHaveScreenshot()` or equivalent stable assertions.

### Requirements

- disable animations;
- use deterministic fixtures;
- mask volatile dates or content only when necessary;
- test 360, 768, and 1440 widths;
- cover home, Start Here, Topics, Guides, article, review, search, archive, empty state, and 404;
- human review approves baseline changes.

### Acceptance criteria

- CI fails on unapproved visual changes.
- Updated snapshots are never accepted automatically by the AI.

---

## RX-802 — Add cross-browser critical-path coverage

**Priority:** P1  
**Risk:** Medium

Run the full suite in Chromium and a smaller critical suite in WebKit and Firefox covering:

- header navigation;
- mobile overlay;
- search dialog;
- search filters;
- ranking filters;
- details/summary components;
- forms;
- skip link; and
- focus restoration.

### Acceptance criteria

- Critical paths pass in all configured engines.
- Safari-specific issues are documented rather than dismissed as Chromium differences.

---

## RX-803 — Make Lighthouse mobile-first and repeatable

**Priority:** P1  
**Risk:** Medium

### File

- `lighthouserc.cjs`

### Requirements

- three mobile runs;
- three desktop runs;
- use median values;
- mobile is the primary gate;
- preserve minimum category scores;
- add budgets for total transfer, CSS, JavaScript, images, and third-party requests;
- test representative public pages and empty states;
- upload reports on failure.

### Initial performance gates

- LCP ≤ 2.5 seconds in CI environment;
- CLS ≤ 0.1;
- TBT ≤ 200 ms;
- no unexpected third-party requests;
- performance score ≥ 0.90;
- accessibility, best practices, and SEO ≥ 0.95.

Treat CI Lighthouse as regression detection, not a guarantee of real-user performance.

---

## RX-804 — Add a dedicated repository test container

**Priority:** P1  
**Risk:** Medium

### Problem

The WP-CLI container mounts WordPress runtime paths but not the repository `tests/` directory, causing confusion when attempting to run host-level PHP tests inside `/var/www/html`.

### Implementation

Add a dedicated development/CI service or profile such as `test-runner` that:

- mounts the repository read-only at `/workspace` except report directories;
- uses PHP 8.3 and required extensions;
- can run Composer, PHPUnit, PHP lint, and repository scripts;
- does not alter the production WordPress image or expose a port;
- is excluded from production profiles.

Document exact commands.

### Acceptance criteria

- `tests/php/run-unit-tests.php` can run from a predictable container path.
- No production service receives an unnecessary repository mount.

---

## RX-805 — Strengthen accessibility release checks

**Priority:** P0  
**Risk:** Medium

### Automated

- axe on all primary templates and both Consumer Lab states;
- heading count and order checks;
- landmark checks;
- accessible name checks;
- form error/status checks;
- focus visibility assertions;
- no horizontal overflow at 320 pixels and 400% zoom simulation where feasible.

### Manual sign-off

Record checks for:

- keyboard-only navigation;
- VoiceOver with Safari;
- NVDA with Firefox or Chrome;
- 200% and 400% zoom;
- Windows forced-colors mode;
- reduced-motion mode;
- responsive tables;
- search dialog;
- mobile menu;
- article table of contents;
- correction notices; and
- print/PDF output.

### Acceptance criteria

- No unresolved critical or serious axe issue.
- Manual results are signed and dated.
- Limitations are recorded honestly.

---

## RX-806 — Strengthen CI and supply-chain checks

**Priority:** P1  
**Risk:** High

### Add or verify

- CodeQL or equivalent static analysis;
- secret scanning;
- dependency review for pull requests;
- Composer and npm audit policy;
- container vulnerability scanning;
- SBOM generation;
- ShellCheck;
- immutable SHA pinning for GitHub Actions after verification;
- artifact retention policy;
- least-privilege workflow permissions.

### Acceptance criteria

- Critical vulnerabilities block release or have an approved, time-bounded exception.
- No secrets exist in repository history or CI logs.

---

## RX-807 — Validate production security headers and controls

**Priority:** P1  
**Risk:** High

### Application and infrastructure review

- HTTPS only;
- HSTS at infrastructure level;
- Content Security Policy in report-only mode before enforcement;
- `X-Content-Type-Options`;
- `Referrer-Policy`;
- `Permissions-Policy`;
- frame restrictions;
- secure and HTTP-only cookies;
- login rate limits;
- REST and form abuse controls;
- MFA for privileged accounts;
- named accounts, no shared admin;
- file editor disabled;
- backup encryption and off-site storage;
- restore drill;
- SMTP authentication;
- monitoring and incident alerts.

### Acceptance criteria

- Security checklist is environment-specific and signed.
- Application headers do not conflict with proxy/CDN headers.
- CSP does not break WordPress admin or public functionality.

---

## RX-808 — Define a release evidence bundle

**Priority:** P0  
**Risk:** Low

Every release candidate must produce:

```text
reports/release/<version>/
  commit.txt
  environment.txt
  validation.txt
  php-tests.xml
  integration-tests.txt
  playwright-report/
  accessibility-report/
  visual-diff-report/
  lighthouse-mobile/
  lighthouse-desktop/
  route-crawl.csv
  security-scan/
  manual-qa.md
  backup-restore-evidence.md
  known-limitations.md
```

### Acceptance criteria

A release cannot be labelled production-ready when required evidence is missing, even if code review is complete.

---

## Phase 8 exit criteria

- Tests are coherent and reproducible.
- Visual regression is enforced.
- Mobile performance is measured repeatedly.
- Critical paths run across browsers.
- Accessibility has automated and manual evidence.
- Security and restore controls are documented and exercised.

---

# Phase 9 — Trust-first content launch

## RX-901 — Prepare trust and policy pages

**Priority:** P0  
**Risk:** High editorial/legal

AI may structure and format these pages but human owners must approve and publish:

- About
- Editorial Policy
- Evidence Methodology
- Testing Methodology
- Medical Disclaimer
- Affiliate Disclosure
- Corrections
- Privacy
- Terms
- Contact
- AI-Assisted Work Disclosure, when approved

### Requirements

- No policy page is published solely to satisfy a route test.
- Policy reviewed date is recorded.
- Corrections channel is functional.
- Contact method is protected against abuse.
- Privacy page reflects actual tools, not planned tools.

---

## RX-902 — Publish foundational content before commercial content

**Priority:** P1  
**Risk:** High editorial/medical

Recommended first sequence:

1. What Longevity Evidence Lab Does—and Does Not Claim
2. What Is Biohacking? An Evidence and Risk Framework
3. How to Read a Health Study Without Being Misled
4. How We Grade Evidence and Test Consumer Products
5. How to Improve Sleep Before Buying Another Device
6. How Accurate Are Consumer Sleep Trackers?
7. Resistance Training for Healthy Aging: A Beginner Framework
8. Foods and Dietary Patterns Associated With Healthy Aging

Each article must include:

- named accountable author;
- exact material claims in the claim registry;
- verified sources;
- limitations;
- scoped conclusion confidence;
- evidence cutoff;
- required medical review and attestation;
- original diagram, worksheet, matrix, or tool;
- intentional next-step links;
- update trigger and next review date;
- accessibility QA; and
- publication-gate pass without AI override.

### Acceptance criteria

- At least eight substantive non-commercial resources are public before broad commercial publishing.
- No material claim lacks a traceable verified record.
- No article is published by the AI.

---

## RX-903 — Conduct real Consumer Lab testing

**Priority:** P1 after foundation  
**Risk:** High

Before a product report uses tested language:

- product is genuinely obtained;
- acquisition route is documented;
- protocol is approved before testing;
- exact model and version are recorded;
- dates, environment, deviations, failures, and missing observations are recorded;
- score recalculates under the versioned model;
- pricing, warranty, subscription, privacy, and return facts are checked with dates and regions;
- disclosure is approved;
- required medical review is completed;
- comparison set is honest; and
- test record is approved.

A single report may be published as a report. It must not become a numbered ranking until the category meets Phase 6 requirements.

---

## RX-904 — Add content QA and lifecycle checks

**Priority:** P1  
**Risk:** Medium

For each published page:

- validate internal links;
- verify snippets and social previews;
- validate schema against visible content;
- review mobile and print rendering;
- check correction presentation;
- confirm update dates;
- add freshness triggers;
- review analytics payloads; and
- schedule human recheck.

---

## Phase 9 exit criteria

- Trust pages are approved and public.
- At least eight useful non-commercial resources exist.
- Product testing is real and documented.
- No ranking is premature.
- Content lifecycle and corrections are operational.

---

# Phase 10 — Measurement and iteration

## RX-1001 — Define privacy-safe funnel events

**Priority:** P2  
**Risk:** Medium

Use allowlisted events only, for example:

- `start_here_open`
- `topic_open`
- `guide_open`
- `methodology_open`
- `source_open`
- `claim_matrix_expand`
- `correction_open`
- `review_report_open`
- `ranking_filter`
- `newsletter_submit` after activation

### Prohibited payloads

- raw search terms;
- article body text;
- free-form medical text;
- email addresses;
- user identifiers not required for the approved analytics purpose;
- inferred health conditions;
- medication or supplement details entered by a reader.

### Acceptance criteria

- Event spec describes purpose, fields, retention, and lawful basis/consent behavior.
- No vendor is installed silently.

---

## RX-1002 — Define product and content KPIs

**Priority:** P2  
**Risk:** Low

Track quality and utility, not only traffic:

- Start Here completion or next-step rate;
- topic-to-guide click rate;
- search no-result rate;
- internal next-step use;
- return visits;
- source-list engagement;
- correction rate and correction time;
- freshness compliance;
- claim/source completion rate;
- accessibility defects;
- performance regressions;
- eligible review inventory;
- ranking confidence distribution;
- affiliate click quality without sensitive profiling; and
- backup restore reliability.

Do not treat traffic, conversion, or revenue as guaranteed outcomes.

---

## RX-1003 — Run structured usability reviews

**Priority:** P2  
**Risk:** Low

Test with readers representing:

- evidence-literate professionals;
- non-specialist adults;
- older adults;
- mobile-first users;
- keyboard users; and
- users comparing a product purchase.

Tasks should include:

- explain what the publication does;
- find a sleep foundation guide;
- determine whether a claim is strongly supported;
- identify who reviewed an article;
- find limitations;
- determine whether a product was actually tested;
- find subscription and privacy facts;
- report an error.

Record success, time, confusion, and language issues. Do not collect private health histories.

---

# 11. Test matrix

| Area | Unit | Integration | Browser | Manual |
|---|---|---|---|---|
| Route registry | Yes | Yes | Yes | Spot check |
| Draft/public state | Yes | Yes | Yes | Yes |
| Canonical/redirect | Yes | Yes | Yes | Source inspection |
| Navigation suppression | Yes where possible | Yes | Yes | Keyboard/SR |
| Search filters | Yes | Yes | Yes | Mobile/keyboard |
| Topic and guide directories | Yes | Yes | Yes | Content review |
| Claim matrix | Yes | Yes | Yes | Privacy review |
| Source list | Yes | Yes | Yes | Editorial review |
| Related content | Yes | Yes | Yes | Journey review |
| Ranking threshold | Yes | Yes | Yes | Editorial comparability |
| Score bands | Yes | Yes | Yes | Method review |
| Buyer facts | Yes | Yes | Yes | Fact verification |
| Homepage modes | Yes | Yes | Yes | Visual/usability |
| Accessibility | Limited | Limited | axe/cross-browser | AT/zoom/keyboard |
| Performance | No | No | Lighthouse | Real-user monitoring |
| Security headers | Yes where possible | Yes | Yes | Infrastructure review |
| Publication gates | Yes | Yes | Fixture browser | Human workflow |

---

# 12. Pull-request sequence

Recommended PR breakdown:

1. `chore/baseline-reader-experience-v3`
2. `test/route-state-contract`
3. `fix/canonical-and-legacy-redirects`
4. `feat/route-aware-navigation`
5. `test/runtime-internal-link-crawl`
6. `content/consolidate-launch-source-of-truth`
7. `content/create-launch-briefs`
8. `feat/homepage-reader-first-structure`
9. `feat/topics-hub`
10. `feat/guides-directory`
11. `feat/start-here-onboarding`
12. `feat/search-topic-filters`
13. `feat/claim-evidence-matrix`
14. `feat/editorial-related-content`
15. `feat/consumer-lab-inventory-threshold`
16. `feat/review-buyer-facts`
17. `refactor/css-cascade-layers`
18. `test/visual-regression`
19. `test/cross-browser-and-mobile-lighthouse`
20. `ci/security-and-release-evidence`

Do not combine route-state fixes, homepage redesign, CSS refactor, and content migration in one PR.

---

# 13. Definition of done for each ticket

A ticket is done only when all applicable boxes are checked.

## Engineering

- [ ] Scope matches the ticket.
- [ ] No unexplained architecture change.
- [ ] Input is sanitized and output escaped.
- [ ] Backwards compatibility is addressed.
- [ ] Migration is idempotent and dry-runnable.
- [ ] Documentation is updated.
- [ ] Static checks pass.
- [ ] Unit tests pass.
- [ ] Integration tests pass.
- [ ] Browser tests pass.
- [ ] Failure artifacts are available.

## UX and accessibility

- [ ] Desktop, tablet, and mobile reviewed.
- [ ] Keyboard flow reviewed.
- [ ] Visible focus preserved.
- [ ] Screen-reader names and landmarks checked.
- [ ] Empty, missing, long, and error states reviewed.
- [ ] Reduced motion and forced colors considered.
- [ ] Print behavior reviewed where relevant.

## Editorial and safety

- [ ] No claim, source, test result, credential, or disclosure was invented.
- [ ] Publication gates were not weakened.
- [ ] Human approval requirements are listed.
- [ ] Draft content was not autonomously published.
- [ ] Private metadata is not exposed.
- [ ] Analytics contain no sensitive payload.

## Release

- [ ] Acceptance criteria are evidenced.
- [ ] Known limitations are listed.
- [ ] Rollback is documented.
- [ ] Human reviewer is identified for high-risk work.

---

# 14. Stop conditions

The AI must stop the affected change and report clearly when:

- a requested implementation would fabricate medical or testing evidence;
- a requested change bypasses publication gates;
- reviewer credentials cannot be verified;
- route or content ownership is ambiguous and a migration could overwrite human content;
- a destructive migration lacks a backup;
- test fixtures would run in production;
- a security control must be weakened to make a test pass;
- a dependency has a critical unresolved vulnerability;
- a public component would expose private editorial metadata;
- a ranking would be created from an inadequate comparison set;
- a policy page requires legal or privacy approval; or
- automated tests conflict with documented production behavior and the correct product behavior has not been decided.

When stopped, the AI should still prepare safe code scaffolding, tests, documentation, or a human decision checklist where possible.

---

# 15. Rollback strategy

Every behavioral PR must describe rollback.

### Code rollback

- Revert the PR commit.
- Restore prior snapshots only when the prior UI is intentionally restored.
- Clear WordPress object and page caches.
- Flush rewrite rules only when route definitions changed.

### Data rollback

- Use pre-migration database and files backup.
- Prefer reversible metadata flags and copied records over deletion.
- Record migrated object IDs.
- Do not automatically delete human-edited records during rollback.

### Content rollback

- Revert to draft rather than delete.
- Preserve corrections and audit history.
- Remove navigation link before unpublishing a destination where possible.
- Maintain redirects when a published URL has been retired.

---

# 16. Master instruction prompt for the coding AI

Use the following prompt at the beginning of an implementation session.

```text
You are implementing the Longevity Evidence Lab Reader Experience v3 plan in a governed WordPress repository.

Read, in order:
1. AGENTS.md
2. docs/implementation/reader-experience-v3-plan.md
3. content/governance/ai-assisted-work-policy.md
4. the architecture, test, editorial, and operations documents referenced by the ticket
5. every production file and test affected by the ticket

Non-negotiable rules:
- Do not invent or approve medical claims, evidence grades, sources, citations, reviewer credentials, disclosures, prices, specifications, test observations, or product results.
- Do not publish or schedule health content.
- Do not weaken publication gates or test eligibility.
- Preserve WordPress as the CMS, longevity-core as the governance layer, and longevity-starter as the presentation layer.
- Do not overwrite human-owned content.
- Migrations must be idempotent, dry-runnable, and backed up.
- Draft pages must remain anonymous 404s and must not appear in public navigation.
- CI fixtures may simulate public states but must never run in production.
- Use server-rendered, accessible, privacy-safe implementation by default.

For the assigned ticket:
1. State the ticket ID, objective, affected files, risk level, and acceptance criteria.
2. Record the baseline and current failures before editing.
3. Implement only the ticket scope.
4. Add or update unit, integration, browser, accessibility, and visual tests as applicable.
5. Run the full relevant validation commands.
6. Inspect desktop, tablet, mobile, keyboard, empty, error, and missing-data states.
7. Report exact tests run, results, limitations, human approvals needed, and rollback steps.

Never call work complete merely because syntax passes. Completion requires the ticket acceptance criteria and evidence defined in the implementation plan.
```

---

# 17. AI ticket response template

The AI should use this structure when reporting a completed ticket.

```markdown
## Ticket
RX-### — Ticket title

## Objective
One paragraph.

## Baseline
- Commit:
- Relevant pre-change tests:
- Existing failures:

## Files changed
- `path`

## Implementation
- Key behavior changes
- Data or migration behavior
- Backwards compatibility

## Safety and governance
- Publication gate impact
- Medical/editorial data impact
- Private-data review
- Human approvals required

## Acceptance criteria
- [x] Criterion with evidence
- [ ] Criterion not completed, with reason

## Tests run
```bash
commands
```

## Results
- Static:
- Unit:
- Integration:
- E2E:
- Accessibility:
- Visual:
- Performance:

## Manual review
- Desktop:
- Tablet:
- Mobile:
- Keyboard:
- Screen reader:
- Print:

## Known limitations
- ...

## Rollback
- ...
```

---

# 18. Final production-readiness checklist

## Product and content

- [ ] Homepage value proposition is clear.
- [ ] Start Here is the primary action.
- [ ] Topics and Guides are functional.
- [ ] Consumer Lab prelaunch/live state is accurate.
- [ ] No public “not configured” state.
- [ ] One canonical launch calendar.
- [ ] Active launch briefs are complete structurally.
- [ ] At least eight substantive non-commercial resources are approved and published.
- [ ] Every material claim is traceable.
- [ ] Medical review is scoped, verified, and attested where required.
- [ ] No fabricated test result or ranking.

## Routes and SEO

- [ ] No navigation link returns anonymous 404.
- [ ] Draft routes return 404 anonymously.
- [ ] Legacy redirects return one 301.
- [ ] Exactly one canonical per indexable page.
- [ ] Search and filtered utility pages have correct robots behavior.
- [ ] Sitemap contains only canonical public URLs.
- [ ] Open Graph and Twitter output have valid fallbacks.
- [ ] Structured data matches visible content.

## UX and accessibility

- [ ] Header and footer are simplified.
- [ ] Mobile menu works.
- [ ] Search dialog works without mouse.
- [ ] Topic, guide, search, and ranking filters are accessible.
- [ ] Empty and no-result states are useful.
- [ ] 320-pixel width has no material horizontal overflow.
- [ ] 200% and 400% zoom are usable.
- [ ] Reduced motion and forced colors work.
- [ ] VoiceOver and NVDA checks are recorded.
- [ ] No critical or serious automated accessibility failure.

## Engineering and performance

- [ ] Static validation passes.
- [ ] PHP tests pass.
- [ ] WordPress integration passes.
- [ ] Browser tests pass.
- [ ] Cross-browser critical suite passes.
- [ ] Visual regression is approved.
- [ ] Mobile and desktop Lighthouse medians pass.
- [ ] Runtime internal-link crawl passes.
- [ ] No unexpected third-party request.
- [ ] Release evidence bundle is complete.

## Security and operations

- [ ] No default or shared privileged account.
- [ ] MFA enabled for privileged accounts.
- [ ] HTTPS and security headers verified.
- [ ] SMTP works.
- [ ] Cron strategy is singular and tested.
- [ ] Backups are encrypted and off-site.
- [ ] Restore drill is recorded.
- [ ] Monitoring and incident notifications work.
- [ ] Dependency, secret, static, and container scans pass or have approved exceptions.
- [ ] Production analytics and consent behavior match privacy documentation.

## Human approvals

- [ ] Editorial owner
- [ ] Medical reviewer where required
- [ ] Product-testing owner where required
- [ ] Privacy/legal owner for policies and forms
- [ ] Accessibility reviewer
- [ ] Engineering reviewer
- [ ] Release owner

---

# 19. Recommended first execution batch

Begin with this exact order:

1. RX-000 — baseline branch and artifacts.
2. RX-001 — route inventory.
3. RX-002 — approve product decisions.
4. RX-101 — centralized route expectations.
5. RX-102 — canonical and redirect corrections.
6. RX-103 — route-publication helpers.
7. RX-104 — route-aware navigation.
8. RX-105 — runtime internal-link crawl.
9. RX-201 through RX-203 — editorial source consolidation.
10. RX-301 through RX-306 — homepage and global navigation.

Do not begin broad CSS refactoring or claim-matrix work until this batch is merged and the route/navigation baseline is stable.

---

# 20. Success definition

This program succeeds when the site’s public experience is as disciplined as its governance architecture:

- the reader immediately understands the publication;
- the site helps before it sells;
- drafts never masquerade as public pages;
- content is structured around real decisions;
- uncertainty and limitations remain visible;
- evidence is inspectable at claim level;
- product reports never imply testing that did not occur;
- rankings are based on meaningful comparison inventory;
- accessibility, performance, security, and release quality are evidenced; and
- AI accelerates implementation without becoming the accountable publisher, medical reviewer, product tester, or approver.
