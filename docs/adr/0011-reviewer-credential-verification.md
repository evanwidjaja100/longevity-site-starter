# ADR-0011: Independent Reviewer Credential Snapshots

**Status:** Implemented; verifier-role assignment pending human approval

## Context

Reviewer-editable profile values cannot serve as independent evidence that credentials, scope, or jurisdiction were verified.

## Decision

Claimed profile fields remain reviewer-editable, while verification fields are controlled by `verify_reviewer_credentials`. Verification requires a different actor, a controlled evidence reference, verification date, verified credential text, scope, jurisdictions, and a recognized snapshot version. Publication and public rendering use only the verified snapshot. Claimed-profile changes mark the snapshot stale.

## Consequences

Legacy `verified` values without an independent verifier become `legacy_unbound` and require human re-verification. Evidence references remain private and are excluded from REST, public output, approval payloads, and audit payloads.
