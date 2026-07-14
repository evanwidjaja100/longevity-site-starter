# ADR: Product-testing data model

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Store versioned protocols and test records as private WordPress content types, separate from public article bodies; publish only approved summaries.

## Alternatives considered

Article-only testing notes, spreadsheets as the sole record, and arbitrary star ratings.

## Consequences

Testing evidence becomes reusable and auditable, with additional editorial administration overhead.

## Security implications

Private records require explicit capabilities and must not expose account credentials or sensitive tester data.

## Editorial implications

Articles cannot claim hands-on testing without an approved record and protocol version.

## Reversal strategy

Export protocol/test metadata to CSV or JSON and migrate to a dedicated lab system.
