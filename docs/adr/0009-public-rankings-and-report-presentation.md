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

## Migration and reversal

Migration version 2 adds version options only. Existing records remain valid and may add structured rows incrementally. Reversal consists of removing the four public blocks/service registration and leaving new metadata unused; no destructive rollback is required.
