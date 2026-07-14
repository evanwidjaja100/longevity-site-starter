# ADR: Content source of truth

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Use WordPress as the operational source for published content and workflow state, while version-controlled Markdown/CSV files define planning, templates, protocols, and portable exports.

## Alternatives considered

Git-only content and database-only planning artifacts.

## Consequences

Separates editorial operation from governance assets while maintaining reproducible configuration.

## Security implications

Repository data contains no secrets or private reviewer information.

## Editorial implications

Editors work in WordPress; policy and protocol changes remain reviewable in Git.

## Reversal strategy

Export WordPress content and promote the version-controlled content format if a Git-first model is adopted.
