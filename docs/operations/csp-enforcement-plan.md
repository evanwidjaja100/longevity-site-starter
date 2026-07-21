# CSP Enforcement Plan

Current policy is `Content-Security-Policy-Report-Only` (set by `Bootstrap::send_security_headers()`). This document outlines the 30-day observation-to-enforcement timeline.

## Current policy directives

```
default-src 'self';
script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com;
style-src 'self' 'unsafe-inline';
img-src 'self' data: https://*.wp.com https://*.gravatar.com;
font-src 'self';
frame-ancestors 'none';
report-uri https://longevityevidencelab.report-uri.com/r/d/csp/enforce;
```

## Phase 1: Observation (days 1-30)

### Action items

1. **Configure report collection**
   - Set up a CSP reporting endpoint (Report URI, Sentry, or self-hosted `report-uri` endpoint)
   - Update the `report-uri` directive in `Bootstrap::send_security_headers()` to point to the configured collector
   - Verify reports are arriving by triggering a controlled violation

2. **Review reports weekly**
   - Identify which resources are loaded from non-`'self'` origins
   - For each reported violation, determine:
     - Is it a first-party resource that should be loaded from `'self'`?
     - Is it a third-party dependency that needs an explicit allowlist entry?
     - Is it a false positive (browser extension, dev tools)?

3. **Remove `'unsafe-eval'` from `script-src`**
   - Audit all JS in the site for `eval()`, `Function()`, `setTimeout(string)`, etc.
   - Longevity-core assets:
     - `admin-governance.js`: no eval usage — safe to remove `'unsafe-eval'`
     - `analytics.js`: no eval usage — safe
     - `contact-form.js`: no eval usage — safe
     - `site-ui.js`: no eval usage — safe
   - Third-party scripts (GTM, GA4): these may use eval. Test thoroughly. If they break, add a `'strict-dynamic'` nonce-based approach
   - Update the CSP directive to remove `'unsafe-eval'` and observe for 1 week of reports

4. **Remove `'unsafe-inline'` from `script-src`**
   - Move inline script config injection from `wp_add_inline_script` to JSON data attributes:
     - `Analytics::enqueue()` currently uses `wp_add_inline_script('longevity-analytics', 'window.longevityAnalytics=' . wp_json_encode(...))`
     - Replace with: `<script id="longevity-analytics-config" type="application/json">` read by `analytics.js` via `JSON.parse(document.getElementById('longevity-analytics-config')?.textContent)`
   - For GTM, use the `gtag.js` nonce attribute approach: `wp_add_inline_script` accepts a `$position` parameter; inject after the script tag so it loads as a separate resource
   - Update the CSP directive to remove `'unsafe-inline'` and observe for 1 week of reports

### Expected timeline

| Week | Action | Check |
|---|---|---|
| 1 | Configure report collection, verify reports arriving | All violations recorded |
| 2-3 | Remove `'unsafe-eval'`, observe violations | No new violations after initial fixes |
| 4 | Remove `'unsafe-inline'`, move inline configs | No new violations after fixes |

## Phase 2: Enforcement (after day 30)

When the following criteria are met:

- [ ] No unexpected violation reports in the final 7 days of observation
- [ ] Both `'unsafe-eval'` and `'unsafe-inline'` removed from `script-src`
- [ ] All third-party resources explicitly allowlisted
- [ ] `frame-ancestors 'none'` confirmed (already set)
- [ ] Report-only has collected at least 28 days of data without content breakage

Then switch from `Content-Security-Policy-Report-Only` to `Content-Security-Policy`:

```php
// In Bootstrap::send_security_headers()
header( 'Content-Security-Policy: ' . self::csp_directives() );
// Instead of:
header( 'Content-Security-Policy-Report-Only: ' . self::csp_directives() );
```

## Pinning enforcement to the constant

Add an environment-specific switch so that staging can keep Report-Only while production enforces:

```php
private static function csp_header_name(): string {
    // Production enforces; all other environments keep report-only
    return 'production' === wp_get_environment_type()
        ? 'Content-Security-Policy'
        : 'Content-Security-Policy-Report-Only';
}
```

## Post-enforcement monitoring

- Continue collecting violation reports for 30 more days
- Set up alerting if violation volume spikes by > 50% from baseline
- Review reports monthly for new third-party resources added by content updates

## Related

- `wp-content/mu-plugins/longevity-core/bootstrap.php` — CSP header implementation
- `docs/operations/security-hardening.md` — other security headers
- `docs/operations/security-checklist.md` — CSP enforcement sign-off checkbox
