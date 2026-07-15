# Content and Evidence Model

## Public content
Standard posts cover educational evidence guides. The `review` post type covers commercial or hands-on reviews. Both use registered editorial metadata and WordPress revisions.

## Private operational records
- `lel_claim`: material claim, category, importance, source reference, verification status, and recheck date.
- `lel_source`: bibliographic or documentary source metadata, identifiers, jurisdiction, conflicts, and evidence notes.
- `lel_protocol`: versioned category-specific testing method and scoring model.
- `lel_test_record`: real observations for a specific unit, dates, environment, deviations, evidence references, and approval state.
- `lel_correction`: correction status, reason, dates, and public notice.
- `lel_affiliate`: merchant/domain relationship, approval, and review date.

## Identity and review
Medical reviewers are WordPress users with optional public professional fields. The publication record links to the user ID and records scope, dates, attestation, conflicts, and next review. Fallback reviewer text exists only for legacy display and does not satisfy authenticated attestation gates.

## Source of truth
Operational publication state lives in WordPress. Repository CSV and Markdown files define assignments, protocols, governance, and exchange formats; they do not silently overwrite WordPress records. CSV import is explicit, capability-controlled, validated, and supports dry runs.

## Lifecycle

## Public ranking projection

Reviews use the existing built-in `category` taxonomy. A category becomes a public ranking category only when it contains at least one eligible review. `public_test_results` belongs to `lel_test_record`, not the public review, and is limited to 30 sanitized rows. Review product metadata may include brand, variant, non-negative observed price amount, ISO currency, existing region, and checked date. These fields do not enable value sorting by themselves.
Records retain stable IDs. Superseded claims point to replacements. Protocols are versioned rather than edited retroactively. Corrections are append-oriented, and material workflow events are stored in a bounded audit log.
