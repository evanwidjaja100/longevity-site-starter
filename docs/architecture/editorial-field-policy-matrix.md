# Editorial Field Policy Matrix

This inventory is generated from `Meta_Registry::definitions()` and contains **76** registered editorial fields. Unknown fields deny by default. Raw governance metadata is not an anonymous REST contract.

| Field | Write policy | Public REST |
|---|---|---|
| `content_summary` | `post_editor` | No |
| `content_scope` | `post_editor` | No |
| `content_limitations` | `post_editor` | No |
| `original_contribution` | `post_editor` | No |
| `evidence_grade` | `evidence_manager` | No |
| `evidence_grade_rationale` | `evidence_manager` | No |
| `material_health_claims` | `risk_classifier` | No |
| `medical_review_required` | `risk_classifier` | No |
| `medical_review_status` | `system_only` | No |
| `medical_reviewer_user_id` | `medical_assigner` | No |
| `medical_reviewer_name_fallback` | `medical_assigner` | No |
| `medical_reviewer_credentials` | `system_only` | No |
| `medical_review_scope` | `medical_assigner` | No |
| `medical_review_sections` | `assigned_medical_reviewer` | No |
| `medical_review_claim_ids` | `assigned_medical_reviewer` | No |
| `medical_review_limitations` | `assigned_medical_reviewer` | No |
| `medical_review_required_revisions` | `assigned_medical_reviewer` | No |
| `medical_review_revision_status` | `assigned_medical_reviewer` | No |
| `medical_review_conflicts` | `assigned_medical_reviewer` | No |
| `medical_review_date` | `system_only` | No |
| `next_medical_review_date` | `assigned_medical_reviewer` | No |
| `medical_review_version` | `assigned_medical_reviewer` | No |
| `medical_review_attested` | `assigned_medical_reviewer` | No |
| `fact_check_status` | `fact_checker` | No |
| `fact_checked_by` | `system_only` | No |
| `fact_checked_date` | `system_only` | No |
| `next_fact_check_date` | `fact_checker` | No |
| `testing_required` | `risk_classifier` | No |
| `testing_status` | `testing_editor` | No |
| `testing_start_date` | `testing_editor` | No |
| `testing_end_date` | `testing_editor` | No |
| `testing_duration` | `testing_editor` | No |
| `testing_methodology_url` | `testing_editor` | No |
| `testing_protocol_version` | `testing_editor` | No |
| `test_record_id` | `testing_approver` | No |
| `product_acquisition_method` | `testing_editor` | No |
| `commercial_relationship` | `commercial_approver` | No |
| `affiliate_disclosure_required` | `risk_classifier` | No |
| `affiliate_disclosure_status` | `commercial_approver` | No |
| `affiliate_registry_verified` | `system_only` | No |
| `editorial_approval_status` | `editorial_approver` | No |
| `correction_status` | `corrections_manager` | No |
| `last_material_update` | `system_only` | No |
| `next_content_review_date` | `editorial_approver` | No |
| `_longevity_related_post_ids` | `post_editor` | No |
| `region_scope` | `post_editor` | No |
| `evidence_cutoff_date` | `evidence_manager` | No |
| `uncertainty_statement_present` | `post_editor` | No |
| `review_score` | `testing_approver` | No |
| `review_score_version` | `testing_approver` | No |
| `review_score_confidence` | `testing_approver` | No |
| `review_score_dimensions` | `testing_approver` | No |
| `review_score_override_reason` | `testing_approver` | No |
| `best_for` | `testing_editor` | No |
| `not_for` | `testing_editor` | No |
| `price_checked_date` | `testing_editor` | No |
| `price_region` | `testing_editor` | No |
| `tested_product_model` | `testing_editor` | No |
| `tested_firmware_version` | `testing_editor` | No |
| `tested_app_version` | `testing_editor` | No |
| `test_unit_identifier` | `testing_editor` | No |
| `comparison_set` | `testing_editor` | No |
| `major_failures` | `testing_editor` | No |
| `data_export_available` | `testing_editor` | No |
| `subscription_required` | `testing_editor` | No |
| `warranty_checked_date` | `testing_editor` | No |
| `return_policy_checked_date` | `testing_editor` | No |
| `privacy_policy_checked_date` | `testing_editor` | No |
| `billing_interval` | `testing_editor` | No |
| `product_brand` | `testing_editor` | No |
| `product_variant` | `testing_editor` | No |
| `product_price_amount` | `testing_editor` | No |
| `product_price_currency` | `testing_editor` | No |
| `medical_reviewer` | `system_only` | No |
| `last_fact_checked` | `system_only` | No |
| `evidence_level` | `system_only` | No |

## Enforcement

Classic editor, REST, CLI/workflow services, and publication same-request evaluation delegate to `Meta_Authorization`. `system_only` fields cannot be changed through generic editor or REST metadata writes. Protected checkboxes are changed only when an explicit `lel_present[field]` marker is submitted. Final workflow transitions (`complete`, `approved`, `ready`, and medical attestation) are service-only: the UI and REST layer may collect supporting fields, but only `Approval_Service` may project a final legacy status after an immutable snapshot is stored.

## Human governance gate

The capability ownership represented here must be reviewed by the editorial/governance owner before production merge. This document records implemented behavior; it does not itself grant or approve real-world authority.
