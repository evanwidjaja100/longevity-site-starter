# ADR: Managed-host and Docker portability

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Treat Docker as a development/staging reference while keeping first-party `wp-content` code free of container-specific assumptions.

## Alternatives considered

Docker-only production and host-specific APIs.

## Consequences

The same code can move between local, VPS, and managed WordPress environments; infrastructure features remain external.

## Security implications

Secrets and TLS are never embedded in the repository.

## Editorial implications

Editors receive consistent workflow behavior across environments.

## Reversal strategy

Remove environment adapters and migrate `wp-content` plus the database using standard WordPress tooling.
