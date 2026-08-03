# Launch Checklist

## Infrastructure
- [ ] Production domain and HTTPS configured
- [ ] Off-site encrypted backups restored successfully to staging
- [ ] CDN/WAF, rate limiting, SMTP, SPF, DKIM, and DMARC configured
- [ ] Unique admin accounts and MFA where supported
- [ ] Uptime and error monitoring active
- [ ] Robots and sitemap reviewed after the production URL is final

## Legal and trust
- [ ] Ownership, contact, editorial, corrections, medical, affiliate, privacy, and terms pages approved
- [ ] Consent banner reflects actual cookies and vendors
- [ ] Reviewer credentials verified
- [ ] Sponsorship and supplied-product labels tested

## Content
- [ ] Eight launch articles pass brief and fact-check gates
- [ ] Original experience is documented for every review claim
- [ ] No unsupported disease, cure, prevention, anti-aging, or guaranteed-result language
- [ ] Dates, author, reviewer, update history, and limitations visible
- [ ] Images are licensed and accessible

## Measurement and release
- [ ] GA4 page_view, newsletter_signup, affiliate_click, outbound_click, and lead_magnet_download tested
- [ ] No health-sensitive personal data appears in analytics parameters
- [ ] Mobile, keyboard, contrast, broken-link, and structured-data checks passed
- [ ] Rollback owner and decision path documented
## Production Readiness v2 final go/no-go

- [ ] P0 enforcement phases are merged in the authoritative repository and clean-checkout CI is green.
- [ ] No stale, legacy-unbound, or status-only approval is accepted as current.
- [ ] Production-like staging completes all high-risk workflow scenarios with synthetic data.
- [ ] Protected readiness contains no unresolved internal `blocked` check and external controls have operator evidence.
- [ ] Restore and rollback drills succeed, with measured recovery evidence.
- [ ] Editorial, medical, testing, commercial, privacy/legal, technical, and operational owners sign the launch decision.
