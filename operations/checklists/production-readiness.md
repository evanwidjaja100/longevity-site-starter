# Production Readiness Checklist

- [ ] `.env` passes `scripts/validate-env.sh` with production settings.
- [ ] HTTPS, secure cookies, least-privilege accounts, and MFA are configured.
- [ ] Off-site encrypted backup and restore drill have succeeded.
- [ ] Policy, contact, ownership, corrections, reviewer, and methodology pages contain approved final text.
- [ ] Publication gates, medical review, affiliate disclosure, and correction workflows have been exercised on staging.
- [ ] CI, PHPUnit, content validation, Playwright, accessibility, and smoke tests pass.
- [ ] SMTP, WAF/CDN, uptime monitoring, error monitoring, real cron, and privacy consent are configured externally.
- [ ] No placeholder content, credentials, reviewer identity, test data, price, or citation remains.
- [ ] Rollback owner and release decision are recorded.
## Production Readiness v2 enforcement

- [ ] `composer.lock` and `package-lock.json` are tracked and locked installs pass from a clean checkout.
- [ ] Explicit metadata authorization attacks fail through classic, REST, CLI, and service paths.
- [ ] Reviewer self-verification and prohibited same-person approvals fail.
- [ ] Approval snapshot creation and automatic stale invalidation pass against real WordPress.
- [ ] Anonymous REST and schema responses contain only the approved projection.
- [ ] Scoring config, approval table, audit table, freshness cycle, and cron heartbeat pass protected readiness.
- [ ] Mobile/desktop Lighthouse, Linux visual, Chromium, Firefox, WebKit, axe, reflow, zoom, keyboard, and screen-reader evidence is retained.
- [ ] Backup restore and code rollback are rehearsed; external controls and human approvals are explicitly verified.
