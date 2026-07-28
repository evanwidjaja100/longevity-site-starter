# CSP Enforcement Plan

**Owner:** Security engineering
**Last reviewed:** 2026-07-28

## Repository capability (implemented)

`Bootstrap::send_security_headers()` sends one nonce-based policy. Framing is intentionally disabled by both mechanisms:

- `frame-ancestors 'none'`
- `X-Frame-Options: DENY`

No same-origin framing requirement is known. A reviewed functional requirement and route test are required before weakening both headers together.

The release configuration is `LEL_CSP_MODE`, set as a PHP constant or environment variable:

| Value | Header |
|---|---|
| `enforce` | `Content-Security-Policy` |
| `report-only`, missing, empty, or invalid | `Content-Security-Policy-Report-Only` |

The safe default is always report-only; WordPress environment type does not implicitly enable enforcement. Roll back immediately by setting `LEL_CSP_MODE=report-only` and redeploying/restarting configuration. Do not use the retired boolean `LEL_CSP_ENFORCE` setting.

Current application policy:

```text
default-src 'self';
script-src 'self' 'nonce-<per-request nonce>';
style-src 'self' 'nonce-<per-request nonce>';
style-src-attr 'unsafe-inline';
img-src 'self' data: https:;
font-src 'self' data:;
connect-src 'self';
frame-ancestors 'none';
base-uri 'self';
form-action 'self';
report-uri <site REST URL>/longevity/v1/csp-report
```

The report endpoint has these application controls:

- exact `application/csp-report` media type and an 8 KiB body limit;
- bounded, flat `csp-report` JSON schema with a required directive;
- malformed and oversized input rejected before database or log writes;
- one atomic global budget counter in the existing `lel_rate_limits` table: 60 reports per 300-second window, failing closed when unavailable;
- one bounded database budget key and at most one budget write per request;
- a privacy-safe log sample on the first and every tenth accepted report, identified by a short fingerprint;
- metrics updated in batches of ten rather than once per report; and
- URL query, fragment, credentials, and path removed; HTTP(S) values retain only origin and other schemes retain only the scheme.

This code does **not** configure CDN/WAF limits, prove that the policy works on staging, approve third-party origins, or authorize enforcement.

## Human staging observation (required)

The security/platform owner must complete these steps for the exact release candidate and production-like environment:

1. Keep `LEL_CSP_MODE=report-only` for the approved observation window (target: 30 days, including a final clean 7 days).
2. Configure an edge body cap no larger than 8 KiB and per-source/global rate limits at the CDN/WAF. The application global budget is defense in depth, not an edge substitute.
3. Inventory every route and approved script, style, image, font, connection, worker, frame, and form target.
4. Trigger a controlled violation and confirm sampled, redacted reports and alert delivery without raw secrets or personal data.
5. Review unexplained first-party violations and either remove the dependency or record human approval for the minimum required origin.
6. Run manual route, login, editor, contact, checkout/affiliate (if applicable), responsive, and accessibility coverage under enforcement on staging.
7. Exercise rollback by returning staging to `report-only` without reverting code.

Retain this release evidence:

- policy SHA-256, observation start/end UTC, environment identity, source SHA, and release artifact checksum;
- route/asset inventory and a redacted aggregate report summary;
- edge-limit configuration evidence and high-volume test results;
- unexplained/accepted violation decisions with owner and date;
- staging enforcement and rollback results; and
- security/platform approver name, approval time, and change ticket.

## Enforcement approval gate (human-owned)

Repository capability is not enforcement approval. Set `LEL_CSP_MODE=enforce` only when all boxes are completed by named humans:

- [ ] Exact candidate observed in report-only for the approved window.
- [ ] Final clean period has no unexplained first-party violations.
- [ ] CDN/WAF body and rate limits are active and tested.
- [ ] Third-party targets have security/privacy approval.
- [ ] Production-like route and accessibility coverage passes under enforcement.
- [ ] Policy hash and candidate checksums match the release evidence.
- [ ] Rollback to report-only is tested.
- [ ] Security/platform owner records enforcement approval and monitoring owner.

After enforcement, review sampled aggregates daily for the first week and monthly thereafter. Alert thresholds must be set from the staging baseline; a sudden accepted, throttled, or edge-rejected volume increase requires investigation.

## Related

- `wp-content/mu-plugins/longevity-core/bootstrap.php`
- `wp-content/mu-plugins/longevity-core/class-rest-api.php`
- `docs/operations/security-hardening.md`
- `docs/operations/security-checklist.md`
