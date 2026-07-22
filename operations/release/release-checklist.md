# Release Checklist

- [ ] Scope and rollback identified
- [ ] `make validate` and `make test` pass
- [ ] Composer/npm security checks reviewed
- [ ] Docker configuration, bootstrap, and smoke pass in staging
- [ ] Capability and publication-gate scenarios pass
- [ ] Schema and analytics payloads inspected
- [ ] Keyboard, axe, responsive, and print checks pass
- [ ] Backup and restore evidence is current
- [ ] Editorial and technical approvals recorded
- [ ] Post-deploy monitoring owner assigned
## Production Readiness v2 evidence

- [ ] Clean-build CI verifies both lockfiles, manifest, dependency audits, and named test discovery.
- [ ] Authorization, reviewer credentials, approval invalidation, REST boundary, protocol/test approval, affiliate lifecycle, contact privacy, and readiness contracts pass.
- [ ] Release evidence includes exact job outcomes and does not convert `FAIL` or `UNAVAILABLE` into a pass.
- [ ] Additive migration and rollback behavior is reviewed; approval/audit rows are retained.
- [ ] Human governance owner approves capability ownership, invalidation policy, retention, and public projection.
