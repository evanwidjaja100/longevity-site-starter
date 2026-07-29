# ADR: Public rankings and product-report presentation

Status: accepted
Date: 2026-07-15

## Context

The publication needs a consumer comparison experience without copying a third-party brand, weakening editorial gates, exposing private test records, or introducing a client application.

## Decision

Rankings remain WordPress-native and server-rendered. The `Rankings` service checks publication state, editorial approval, required test completion, approved protocol-matched records, reproducible 0–5 scores, confidence, model and comparison context, required medical review, correction state, commercial declaration, and freshness. Ordering is deterministic: score, confidence, material update, title, then ID.

Approved private test records may expose only the sanitized `public_test_results` projection. Each row has a bounded label, observed value, optional unit/reference, allowlisted status, note, and order. Private raw observations and identifiers remain outside public rendering.

Commercial relationships cannot change score or ordering. Affiliate output continues through the registry and disclosure controls. The design and copy are original to Longevity Evidence Lab and use none of Labdoor's branding, ratings, assets, or datasets.

## Consequences

Rankings work without JavaScript and remain crawlable, linkable, cacheable, and governed. Eligibility checks cost more than a raw meta query, so aggregates are bounded, object-cacheable, and invalidated when reviews, records, protocols, status, or terms change.

## Completeness, ordering, and supported inventory

The eligible population is evaluated in full. Prior implicit caps (a 500-review
eligibility limit and a 100-review directory limit) are removed: they could hide
a top-ranked review or undercount a category. Published review IDs are collected
in deterministic ascending-ID keyset batches (default 200, filterable via
`longevity_ranking_batch_size`), warming the per-batch metadata cache to avoid
N+1 queries. Filtering and sorting run over the **complete** eligible population;
the caller's limit is applied only after ordering, and full `WP_Post` objects are
hydrated only for the final selected IDs. Directory and category counts aggregate
from the complete eligible population.

The expected supported inventory is **5,000 eligible reviews**
(`longevity_max_ranked_reviews`). Above this documented ceiling the projection
fails closed: it returns no ranking, records a bounded diagnostic
(`rankings_population_ceiling_exceeded`, no private data), and the
`rankings_projection` readiness check reports `blocked`. Partial rankings are
never silently returned. Raising the ceiling requires a capacity review; a custom
materialized ranking table would require its own ADR and migration design.

## Migration and reversal

Migration version 2 adds version options only. Existing records remain valid and may add structured rows incrementally. Reversal consists of removing the four public blocks/service registration and leaving new metadata unused; no destructive rollback is required.
