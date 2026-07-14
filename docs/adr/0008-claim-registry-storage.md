# ADR: Claim registry storage

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Use private WordPress custom post types for claims and sources in the first production iteration.

## Alternatives considered

Post metadata and a custom database table.

## Consequences

Private CPTs provide revisions, permissions, REST controls, and portability without custom SQL; very high volumes may later justify tables.

## Security implications

Records are non-public and require claim-management capabilities.

## Editorial implications

Claims can be linked to posts and sources with stable IDs and exported safely.

## Reversal strategy

Export stable IDs, introduce normalized tables, migrate in batches, and retain read-only CPT compatibility during cutover.
