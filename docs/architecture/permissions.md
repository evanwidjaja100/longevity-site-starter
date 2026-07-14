# Roles and Capabilities

## Principles
Capabilities govern actions; role names are convenience bundles. Medical reviewers and product testers do not receive administrator access. Every write path also checks a nonce and post-specific authorization where applicable.

## First-party roles
- **Writer:** drafts and submits work.
- **Fact checker:** records claim verification and fact-check completion.
- **Medical reviewer:** completes a scoped attestation only for an assigned review.
- **Product tester:** manages protocols and test records.
- **Managing editor:** approves publication, disclosures, corrections, and exceptional overrides.

## Sensitive capabilities
`complete_medical_review`, `complete_fact_check`, `approve_commercial_disclosure`, `approve_publication`, `manage_corrections`, `manage_test_protocols`, `manage_affiliate_registry`, and `approve_publication_override` are deliberately separate.

## Operational caveat
A reviewer must be able to access the assigned post in the WordPress administration interface. Hosts should test the role mapping against their editor and security plugins. The attestation itself still requires the matching assigned user ID and the dedicated capability, preventing another editor from impersonating the reviewer.
