# Reader Experience v3 — Product Decisions

**Date:** 2026-07-20
**Status:** Approved for implementation

## Decisions

### 1. Primary launch product
Evidence-led reader education, not product rankings.

### 2. Primary homepage CTA
"Start Here" (/start-here/) is the primary CTA.

### 3. Top navigation at launch
Start Here, Topics, How We Work, About, Search.
Reviews prominence is enabled only when ranking inventory meets Phase 6 threshold.

### 4. Reviews visibility
Public reviews archive may exist, but top-navigation prominence is gated behind minimum 3 comparable eligible reports per category.

### 5. Canonical editorial calendar
content/calendar/launch-calendar.csv is the single source of truth.

### 6. Draft behavior
Draft pages return 404 to anonymous visitors. Not in public navigation. CI fixtures may simulate publishable states but must not change production defaults.

### 7. CI behavior
Synthetic test fixtures may represent publishable states for browser testing. Never run in production.

### 8. Newsletter behavior
Omitted until handler, consent copy, privacy review, accessible feedback, and abuse controls exist. No public "not configured" component.

## References
- Plan: docs/implementation/reader-experience-v3-plan.md
- Editorial policy: content/editorial-policy.md
