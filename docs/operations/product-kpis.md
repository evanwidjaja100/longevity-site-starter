# Product and Content KPIs

**Owner:** Product and editorial operations
**Last reviewed:** 2026-07-28

## 1. Reader experience

| KPI | Definition | Target | Measurement |
|---|---|---|---|
| Start Here completion rate | % of Start Here visitors who click a next-step link | TBD after baseline | Analytics: `start_here_open` → subsequent guide/topic click |
| Topic-to-guide conversion | % of topic hub visitors who click into a guide | TBD after baseline | Analytics: `topic_open` → `guide_open` |
| Search no-result rate | % of searches returning zero results | < 15% | Search log analysis |
| Internal next-step engagement | % of article/review readers who click a related-content link | TBD after baseline | Analytics: click on related-content links |
| Return visit rate | % of visitors with 2+ sessions in 90 days | TBD after baseline | Analytics: session-based |
| Source-list engagement | % of article/review readers who click at least one source link | TBD after baseline | Analytics: `outbound_citation_click` |
| Featured resource click-through | % of homepage visitors who click a featured resource | TBD after baseline | Analytics: click on featured resource cards |

## 2. Editorial quality

| KPI | Definition | Target | Measurement |
|---|---|---|---|
| Correction rate | Corrections submitted per 1,000 published pages | < 5 per 1,000 | CPT `lel_correction` count |
| Correction resolution time | Median hours from submission to resolution | < 72 hours | CPT `lel_correction` timestamps |
| Freshness compliance | % of published content reviewed within scheduled interval | 100% | Freshness register audit |
| Claim registry completion | % of published articles with all material claims registered | 100% | Claim registry audit |
| Source verification rate | % of material claims with at least one verified source | 100% | Claim registry audit |

## 3. Product testing

| KPI | Definition | Target | Measurement |
|---|---|---|---|
| Eligible review inventory | Number of published protocol-complete reviews | Grow quarterly | CPT `review` count |
| Ranking confidence distribution | % of ranked products by confidence band (high/moderate/limited) | Majority moderate+ | Scoring model audit |
| Testing protocol compliance | % of reviews with complete test records matching protocol version | 100% | Test record audit |

## 4. Engineering

| KPI | Definition | Target | Measurement |
|---|---|---|---|
| Accessibility defects | Critical or serious axe violations on public pages | 0 | `npm run test:a11y` |
| Performance regressions | Lighthouse regression from baseline | No material regression | Lighthouse CI |
| Backup restore reliability | Successful restore from encrypted off-site backup | 100% | Quarterly drill |
| Release evidence completeness | % of required evidence artifacts present per release | 100% | Release checklist |

## 5. Operations

| KPI | Definition | Target | Measurement |
|---|---|---|---|
| Security scan compliance | No critical unresolved vulnerabilities on production | 0 | Trivy / CodeQL / Dependabot |

## Data sources

- **Analytics:** First-party event queue (`window.longevityAnalytics`) with consent-gated forwarding.
- **WordPress:** CPT counts, meta fields, timestamps, user roles.
- **CI/CD:** Lighthouse reports, axe reports, test results, security scan artifacts.

## Review cadence

- KPIs reviewed quarterly by the editorial and engineering leads.
- Targets adjusted after first 90 days of production data.
- New KPIs proposed via the ADR process.
