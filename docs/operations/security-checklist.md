# Security Controls Checklist

**Owner:** Security engineering
**Last reviewed:** 2026-07-28

## Application headers (applied in Bootstrap::send_security_headers)

| Header | Value | Status |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | ✅ Applied |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | ✅ Applied |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | ✅ Applied |
| `X-Frame-Options` | `SAMEORIGIN` | ✅ Applied |
| `X-XSS-Protection` | `0` | ✅ Applied (deprecated but disables legacy behaviour) |
| `Content-Security-Policy-Report-Only` | `default-src 'self'; script-src 'self' ...` | ✅ Applied (report-only; enforce after testing) |

## Infrastructure-level (not set in application code)

| Control | Guideline |
|---|---|
| HTTPS only | HSTS at infrastructure level with `includeSubDomains` |
| HSTS | `max-age=63072000; includeSubDomains; preload` |
| Secure cookies | `Secure` and `HttpOnly` flags on session and auth cookies |
| Login rate limits | Enforce at WAF or reverse proxy |
| REST/Form abuse | Rate limiting per IP per endpoint |

## WordPress-specific

| Control | Status |
|---|---|
| `DISALLOW_FILE_EDIT` | ✅ `true` in `compose.yaml` |
| `DISALLOW_FILE_MODS` | ✅ Configurable per environment |
| `WP_AUTO_UPDATE_CORE` | ✅ `minor` |
| Named accounts, no shared admin | ⚠️ Human policy — enforce via user management |
| MFA for privileged accounts | ⚠️ Human policy — requires third-party plugin or SSO |

## Backup and restore

| Control | Guideline |
|---|---|
| Database | Automated daily dumps with 30-day retention |
| Files | `wp-content/uploads` included in backup |
| Encryption | Backups encrypted at rest (AES-256) |
| Off-site storage | At least one copy stored off-site |
| Restore drill | Quarterly restore test with documented results |

## Monitoring and alerts

| Control | Guideline |
|---|---|
| Failed login attempts | Alert after 10 failed attempts per user in 15 minutes |
| Uptime monitoring | External health check every 5 minutes |
| Certificate expiry | Alert 30 days before expiry |
| Application errors | `WP_DEBUG_LOG` in dev; error monitoring in production |

## Supply chain (Phase 8 — RX-806)

| Control | Status |
|---|---|
| CodeQL analysis | ✅ `.github/workflows/ci.yml` |
| Secret scanning | ✅ trufflehog in CI |
| Dependency review | ✅ On PRs via `dependency-review-action` |
| Container scan | ✅ Trivy on compose config |
| SBOM generation | ✅ Anchore SBOM on push |
| Immutable SHA pinning | ⚠️ Verify all GH Actions use SHA pinning |
| Artifact retention | Set 30-day retention in repo settings |
| Least-privilege workflows | ✅ `contents: read` with `security-events: write` only |

## Manual sign-off

- [ ] HTTPS and HSTS verified on production domain
- [ ] CSP tested in report-only → no breakage → switch to enforce
- [ ] Login rate limits configured
- [ ] MFA enforced for admin accounts
- [ ] Backup restore drill completed and documented
- [ ] SMTP authenticated and configured
- [ ] Incident alert channel configured
