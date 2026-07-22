# ADR-0014: Append-only Governance Audit Storage

**Status:** Implemented; retention period pending human privacy/governance approval

## Context

A bounded mutable post-meta array was unsuitable for security and governance evidence and could not support reliable object/time or actor/time queries.

## Decision

New governance events are inserted through `Audit_Log::record()` into `lel_audit_events`. Records include actor, object, request correlation ID, channel, bounded sanitized payload, schema version, and hash-chain fields. Ordinary editorial paths have no update/delete API. A bounded legacy writer remains only as a pre-migration compatibility fallback.

## Consequences

Database migration is additive and idempotent. Audit payloads explicitly exclude contact text, credential evidence, raw observations, and private evidence locations. Retention and external archival remain human policy decisions.
