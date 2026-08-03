# Product and Content KPIs

## Guiding principle

Track quality and utility, not only traffic. Do not treat conversion, traffic, or revenue as guaranteed outcomes.

## Content quality KPIs

| KPI | Measurement | Source | Cadence |
|---|---|---|---|
| **Evidence grading rate** | % of published articles with at least one evidence-graded claim | Internal audit | Monthly |
| **Limitations disclosure rate** | % of articles with a published limitations section | CMS field check | Monthly |
| **Medical review completion rate** | % of flagged articles reviewed within 14 days | CMS field check | Weekly |
| **Correction rate** | # of corrections / # of published articles | `lel_correction` CPT | Monthly |
| **Correction time** | Median hours from report to correction publication | `lel_correction` CPT | Per incident |
| **Freshness compliance** | % of eligible articles reviewed within 12 months | Freshness cron audit | Monthly |
| **Source completeness** | Mean sources per claim article | Claims query | Monthly |
| **Claim completeness** | Mean verified claims per claim article | Claims query | Monthly |
| **Commercial relationship disclosure** | % of articles with relationship field filled | CMS field check | Monthly |
| **Affiliate disclosure presence** | % of pages with affiliate link that show disclosure | Crawl | Per release |

## Reader engagement KPIs

| KPI | Measurement | Source | Cadence |
|---|---|---|---|
| **Start Here completion** | % of Start Here page views with a next-step click (topic, guide, or review) | `start_here_open` + subsequent event | Monthly |
| **Topic-to-guide click rate** | % of topic page views that result in a guide click | `topic_open` → `guide_open` | Monthly |
| **Search no-result rate** | % of search queries returning zero results | Search logs | Monthly |
| **Internal next-step use** | % of article views with related-content click | `guide_open` / `ranking_report_open` (related-content) | Monthly |
| **Return visits** | % of readers with 2+ sessions in 30 days | Analytics session data* | Monthly |
| **Source-list engagement** | % of article views with source section interaction | `source_open` event | Monthly |
| **Claim matrix engagement** | % of article views with claim matrix expansion | `claim_matrix_expand` event | Monthly |
| **Evidence summary views** | % of article views where evidence summary is visible | `evidence_summary_open` event | Monthly |

*Consent-gated. Only reported when analytics consent is granted.

## Product/review KPIs

| KPI | Measurement | Source | Cadence |
|---|---|---|---|
| **Eligible review inventory** | # of reviews meeting ranking minimum (protocol-complete, tested) | Rankings query | Weekly |
| **Ranking confidence distribution** | % of rankings by confidence band (high/medium/low) | Rankings query | Per ranking |
| **Testing protocol compliance** | % of reviews with a published, linked protocol | CMS field check | Per review |
| **Product acquisition disclosure** | % of reviews with acquisition method disclosed | CMS field check | Per review |
| **Methodology engagement** | % of review views with method section open | `review_method_open` event | Monthly |

## Operational KPIs

| KPI | Measurement | Source | Cadence |
|---|---|---|---|
| **Accessibility defects** | # of axe-core violations per route | CI a11y run | Per release |
| **Performance regressions** | Lighthouse score delta vs baseline | CI Lighthouse run | Per release |
| **Backup restore reliability** | Successful restore drill rate | Operations runbook | Monthly |
| **Correction SLA** | % of corrections published within 48 hours of confirmation | `lel_correction` CPT | Per incident |
| **Analytics event integrity** | # of rejected unknown events (expected: 0) | Analytics JS logging | Monthly |

## What we do not track

- Individual reader health histories, search terms, or form text
- Email addresses (except directly from consent-gated newsletter forms)
- Inferred health conditions, diagnoses, or supplement regimens
- Non-consented behavioral or demographic profiling
- Raw article body text in analytics payloads

## Review cadence

KPI definitions and targets should be reviewed quarterly by the editorial and product team. Threshold adjustments should be documented in an ADR.

## Data sources

- **Event layer**: `window.longevityAnalytics` — first-party event queue
- **CMS fields**: WordPress postmeta, `lel_claim`, `lel_correction` CPTs
- **Freshness**: `Freshness` cron class (`class-freshness.php`)
- **Crawl**: Internal link validator (`tests/e2e/internal-links.spec.js`)
- **CI**: Playwright accessibility/performance specs (`tests/e2e/accessibility.spec.js`)
## Production Readiness v2 operational additions

Track freshness cycle completion/coverage, stale approval count by type, legacy-unbound records awaiting reapproval, credential snapshots nearing expiry, denied governance writes, audit-chain continuity, contact-retention deletions, and readiness checks in `blocked` or `unknown_external` state. These operational metrics must not contain contact text, raw IP addresses, credential evidence, or private source/test content.
