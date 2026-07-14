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
