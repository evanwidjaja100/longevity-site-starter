# Analytics Event Specification

Do not send diagnoses, symptoms, medication names, supplement regimens, or other sensitive health information in event names, URLs, dimensions, or form fields.

| Event | Trigger | Parameters |
|---|---|---|
| `newsletter_signup` | Confirmed subscription or successful form completion | `placement`, `content_group` |
| `affiliate_click` | Click on a disclosed affiliate link | `merchant`, `content_id`, `placement` |
| `outbound_click` | Non-affiliate external citation click | `domain`, `content_id` |
| `lead_magnet_download` | Successful asset delivery | `asset_id`, `placement` |
| `review_method_open` | Reader expands test method | `content_id`, `product_category` |
| `correction_submit` | Corrections form successfully submitted | `content_id` |

Store experiment assignment separately from personal data, document consent behavior by region, and reconcile affiliate reports with first-party click counts monthly.
