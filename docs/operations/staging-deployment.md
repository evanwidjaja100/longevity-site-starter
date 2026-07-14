# Staging Deployment

Use a production-like PHP and WordPress version, HTTPS, isolated credentials, non-production payment/affiliate identifiers, and blocked indexing. Import a sanitized content snapshot, never an unreviewed production database with unnecessary personal data.

Run environment validation, database migration or import, theme/MU-plugin activation, WP-CLI bootstrap only when the site is new, smoke tests, role tests, publication-gate tests, schema inspection, accessibility checks, and a backup/restore drill. Obtain editorial sign-off before production promotion.
