# Release Process

1. Open a focused change with documentation and tests.
2. Run static validation, unit tests, dependency checks, and Docker configuration validation.
3. Deploy to staging and run bootstrap only for a new installation.
4. Execute smoke, permission, publication-gate, schema, accessibility, and responsive checks.
5. Create and verify a backup; identify rollback commit and database plan.
6. Obtain technical and editorial approval.
7. Deploy code, run required migrations, clear caches, and repeat smoke checks.
8. Observe logs and health metrics; record release and any follow-up.

Do not combine unrelated schema, workflow, theme, and infrastructure changes in one emergency release.
