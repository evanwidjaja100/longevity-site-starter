# ADR: Managed-host and Docker portability

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Treat Docker as a development and disposable-CI tool while keeping first-party `wp-content` code free of container-specific assumptions. Production delivery is only to a managed WordPress host under ADR-0015.

## Alternatives considered

Docker-only production and host-specific APIs.

## Consequences

First-party code remains portable, while the supported production operating model stays intentionally singular and host-managed.

## Security implications

Secrets and TLS are never embedded in the repository.

## Editorial implications

Editors receive consistent workflow behavior across environments.

## Reversal strategy

Remove environment adapters and migrate `wp-content` plus the database using standard WordPress tooling.
