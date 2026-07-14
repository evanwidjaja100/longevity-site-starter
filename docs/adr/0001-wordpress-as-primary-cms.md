# ADR: WordPress as the primary CMS

- Status: Accepted
- Date: 2026-07-14

## Context

The publication requires a portable, auditable system that minimizes health-claim and operational risk while remaining maintainable by a small team.

## Decision

Keep WordPress as the canonical store and editing interface for publication content, editorial metadata, and first-party workflow controls.

## Alternatives considered

A headless CMS, a static-site generator, and a custom application.

## Consequences

Preserves editor familiarity and hosting portability, while requiring careful use of WordPress APIs and migration discipline.

## Security implications

WordPress patching, role hardening, and least-privilege capabilities remain mandatory.

## Editorial implications

Editorial status, review, and audit behavior can remain close to the editor.

## Reversal strategy

Export standard content and metadata through WordPress APIs before replacing the CMS.
