# ADR: First-party editorial controls

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Implement critical gates, claims, review, corrections, testing, and disclosure behavior in the MU plugin rather than relying on optional third-party plugins.

## Alternatives considered

ACF plus workflow plugins, a SaaS editorial system, and theme-only metadata.

## Consequences

Reduces dependency risk and keeps controls active across themes, but increases first-party maintenance responsibility.

## Security implications

Capability checks, nonce validation, sanitization, and conservative defaults are required in every write path.

## Editorial implications

Editorial controls cannot be deactivated accidentally through the normal plugin screen.

## Reversal strategy

Provide CSV/JSON exports and documented metadata keys to support migration.
