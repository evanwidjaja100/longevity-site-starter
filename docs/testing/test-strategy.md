# Test Strategy

## Static and content checks
PHP, shell, JSON, YAML, environment configuration, content calendars, briefs, internal links, freshness, placeholders, trailing whitespace, and manifest integrity.

## Unit tests
Pure services cover sanitization definitions, scoring arithmetic, evidence/readiness combinations, and blocking-versus-warning behavior. A dependency-free fallback runner provides a minimal local safety net when Composer is unavailable.

## WordPress integration
CI boots WordPress, activates the theme and MU plugin, creates representative records, checks REST health, and exercises bootstrap/smoke behavior. Additional fixtures should cover every publication-gate combination and capability boundary.

## Browser tests
Playwright checks key templates, navigation, disclosure visibility, and responsive behavior. Axe checks detectable WCAG violations. Manual checks remain required for semantics, screen-reader quality, copy accuracy, and health-claim meaning.
