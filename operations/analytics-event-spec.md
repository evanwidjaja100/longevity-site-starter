# Analytics Event Specification

Do not send diagnoses, symptoms, medication names, supplement regimens, or other sensitive health information in event names, URLs, dimensions, or form fields.

## Consent model

- `analytics` consent gates all event forwarding to third-party destinations (e.g., Google Tag Manager `dataLayer`).
- `advertising` consent is additionally required for `affiliate_click` events.
- Without consent, events are queued in `window.longevityAnalytics` and dispatched as custom `longevity:analytics` events for first-party use only.
- No analytics vendor script (GTM, GA4, etc.) is ever enqueued without explicit, documented consent.

## Event allowlist

| Event | Trigger | Parameters | Retention | Purpose |
|---|---|---|---|---|
| `newsletter_signup` | Confirmed subscription or successful form completion | `placement`, `content_group` | 26 months | Measure newsletter conversion by placement |
| `affiliate_click` | Click on a disclosed affiliate link | `merchant`, `content_id`, `placement` | 26 months | Attribute affiliate revenue, reconcile with merchant reports |
| `outbound_citation_click` | Click on a source/citation external link | `destination_domain`, `content_id` | 26 months | Measure source engagement |
| `outbound_click` | Click on a non-affiliate, non-citation external link | `content_id`, `placement` | 26 months | Measure outbound navigation patterns |
| `lead_magnet_download` | Successful asset delivery (e.g., PDF worksheet) | `asset_id`, `placement` | 26 months | Measure resource utility |
| `review_method_open` | Reader expands test method `<details>` | `content_id`, `product_category` | 26 months | Measure methodology engagement |
| `evidence_summary_open` | Trust summary section is visible/rendered | `content_id` | 26 months | Measure evidence summary views |
| `correction_submit` | Corrections form successfully submitted | `content_id` | 26 months | Track correction submission rate |
| `comparison_filter_use` | Ranking filter select changed | `content_id`, `product_category` | 26 months | Measure comparison tool usage |
| `methodology_download` | Click on "Read the full methodology" | `content_id`, `placement` | 26 months | Measure methodology access |
| `test_data_download` | Click to download test data | `content_id`, `placement` | 26 months | Measure test data utility |
| `ranking_sort` | Ranking sort order changed | `category`, `sort` | 26 months | Measure preferred sort order |
| `ranking_filter` | Ranking filter applied | `category`, `filter_name` | 26 months | Measure filter usage |
| `ranking_report_open` | Click on "View report" in ranking table | `content_id`, `category`, `placement` | 26 months | Measure ranking-to-report conversion |
| `search_open` | Search dialog opened | `placement` | 26 months | Measure search feature usage |

## Prohibited payloads

- Raw search terms typed by the user.
- Article body text or free-form medical text.
- Email addresses or user identifiers not required for the approved analytics purpose.
- Inferred health conditions, diagnoses, symptoms, medication names, or supplement regimens.
- Any value longer than 120 characters (enforced by `cleanValue()` in `analytics.js`).

## Data layer bridge

Events are forwarded to `window.dataLayer` (for GTM integration) only when:
1. `window.longevityConsent.analytics === true`; and
2. For `affiliate_click` events, additionally `window.longevityConsent.advertising === true`.

## Implementation

- PHP: `class-analytics.php` — enqueues JS, injects inline config with `eventSchemas` allowlist.
- JS: `assets/analytics.js` — collects events from `[data-lel-event]` attributes, `<details>` toggle listener, and programmatic `window.longevityTrack()` calls.
- HTML: `data-lel-event="event_name"` attribute on interactive elements.
- Storage: Events are stored in `window.longevityAnalytics` array. No server-side storage of raw events.

## Reconciliation

Store experiment assignment separately from personal data, document consent behavior by region, and reconcile affiliate reports with first-party click counts monthly.
