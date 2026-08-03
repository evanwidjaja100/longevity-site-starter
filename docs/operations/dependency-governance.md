# Dependency, SBOM, and License Governance

**Owner:** Security engineering
**Last reviewed:** 2026-07-28

`composer.lock` and `package-lock.json` are the only accepted dependency-resolution inputs. CI installs them exactly, runs `composer audit --locked` and `npm audit --audit-level=high --json`, and generates a dependency-only SPDX 2.3 SBOM with `php scripts/generate-dependency-sbom.php`. The release evidence index binds those reports to the commit and workflow run. `scan-metadata.json` records exact scan time, tool versions, and lock hashes. Composer and npm's registry APIs do not expose immutable advisory-database snapshot timestamps, so the report records `not_exposed_by_upstream_api` rather than inventing one; release review must account for that upstream limitation.

License policy is scope-specific: runtime dependencies allow only the script's permissive SPDX expressions; development dependencies additionally allow MPL-2.0, LGPL-3.0-or-later, and OSL-3.0. AGPL, BUSL, and SSPL are prohibited in every scope. Unknown or unlisted expressions fail for named security-owner review; exceptions require a separately reviewed repository change with owner, reason, and expiry. The production runtime artifact contains no Composer or npm packages.

The weekly `Security` workflow repeats advisory, SAST, SBOM, and license checks. Advisory response targets are: critical within one business day, high within three business days, and lower severities in the next planned update. A temporary risk acceptance must name an owner, document exploitability, identify compensating controls, and expire within 30 days.

Dependency changes require full CI, release-artifact reproducibility, and release-evidence aggregation. Repository-side automation does not prove that GitHub update settings, branch protection, or external advisory triage are enabled; those remain operator evidence.
