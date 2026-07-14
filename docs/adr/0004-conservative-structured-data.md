# ADR: Conservative structured data

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Emit only supported entities backed by visible page content, and suppress the first-party graph when a recognized SEO provider owns equivalent schema.

## Alternatives considered

Aggressive automatic FAQ, MedicalWebPage, Product, or aggregate-rating markup.

## Consequences

Rich-result opportunities may be narrower, but misleading or duplicate schema risk is reduced.

## Security implications

No private metadata or invented identifiers are exposed.

## Editorial implications

Schema reflects actual authorship, review scope, citations, and dates.

## Reversal strategy

Disable the graph builder or hand control to a compatible SEO integration.
