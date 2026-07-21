# Security Hardening

This document covers application-layer hardening applied by the longevity-core MU plugin. Infrastructure-level controls (HTTPS, WAF, network segmentation) are managed at the hosting layer per the deployment model.

## Application headers (applied on every response)

The `Bootstrap::send_security_headers()` method fires on the `send_headers` action and applies the following headers:

| Header | Value | Notes |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-type sniffing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Leaks origin only on same-origin navigations |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Opts out of sensitive-device APIs |
| `X-Frame-Options` | `SAMEORIGIN` | Prevents clickjacking (CSP `frame-ancestors` is the modern equivalent) |
| `X-XSS-Protection` | `0` | Disables the legacy XSS auditor (can be bypassed; CSP is the modern defense) |
| `Content-Security-Policy-Report-Only` | See `csp-enforcement-plan.md` | Blocks injection after enforcement window |

To verify headers on a running instance:
```bash
curl -sI https://staging.longevityevidencelab.com | grep -i '^\(content-security\|x-content\|referrer\|permissions\|x-frame\|x-xss\)'
```

## CSP enforcement timeline

See `docs/operations/csp-enforcement-plan.md` for the 30-day observation-to-enforcement plan. Current policy is Report-Only to collect violations before breakage.

## Input validation

All `$_POST`, `$_GET`, and `$_SERVER` accesses in longevity-core use `wp_unslash()` followed by typed sanitization (`sanitize_text_field`, `sanitize_email`, `absint`, `sanitize_key`, `sanitize_textarea_field`). No raw `$_REQUEST`, `$_COOKIE`, or `$_FILES` access bypasses sanitization.

## Authorization

- Admin form handlers verify `wp_verify_nonce()` and `current_user_can()` before mutation
- REST endpoints use `permission_callback` with `current_user_can('edit_post', $id)` for protected endpoints; the health endpoint is intentionally public (no sensitive data)
- Every `register_post_meta` / `register_meta` call has a field-level `auth_callback`
- Attestation fields require the assigned reviewer specifically; medical fields require `complete_medical_review`; affiliate fields require `approve_commercial_disclosure`

## Output escaping

All public renderers use `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()` before concatenation. No raw values reach the DOM. The contact form uses a honeypot + CSRF nonce + IP-based rate limiting (5/hr, configurable via `LONGEVITY_TRUSTED_PROXIES` constant).

## Safe defaults in compose.yaml

| Setting | Value |
|---|---|
| `DISALLOW_FILE_EDIT` | `true` |
| `DISALLOW_FILE_MODS` | Configurable per environment (default `false` for dev) |
| `WP_AUTO_UPDATE_CORE` | `minor` |
| `WP_POST_REVISIONS` | 30 |
| `EMPTY_TRASH_DAYS` | 14 |
| `AUTOSAVE_INTERVAL` | 120 |
| Database character set | `utf8mb4_unicode_ci` |

## Supply-chain controls (CI)

- **CodeQL**: JS/Python/PHP analysis on every push (`ci.yml`)
- **trufflehog**: Secrets scan on every push
- **Dependency review**: Block high-severity advisories on PRs
- **Trivy**: Container image scan on compose config
- **SBOM**: Anchore SPDX generation on push
- **Scheduled audit**: Weekly `composer audit --locked` and `npm audit --audit-level=high`
- **Secret patterns**: Rejects tracked `.env` files, private-key patterns, and world-writable files

## Managed WordPress hardening

When deploying to a managed host (WP Engine, Kinsta, etc.):

1. Enable the host's WAF and login rate-limiting
2. Set `DISALLOW_FILE_MODS=true` in `wp-config.php`
3. Enable MFA for all admin accounts
4. Restrict `wp-admin` access to a VPN or allowlist
5. Enable the host's automated off-site backups
6. Verify the host supports MU plugins (`wp-content/mu-plugins/longevity-core/`)
7. Ensure the host supports custom post types, capabilities, and role modifications

## Related

- `docs/operations/security-checklist.md` — sign-off checklist for pre-launch
- `docs/operations/csp-enforcement-plan.md` — CSP enforcement timeline
- `docs/operations/incident-response.md` — incident classification and response
