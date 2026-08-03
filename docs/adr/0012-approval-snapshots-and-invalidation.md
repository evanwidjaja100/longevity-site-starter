# ADR-0012: Approval Snapshots and Automatic Invalidation

**Status:** Implemented; production migration and editorial invalidation policy pending human approval

## Context

Mutable status strings did not prove that an approval applied to the exact content, governed metadata, claims, test records, affiliate state, or reviewer credential snapshot currently displayed.

## Decision

Approvals are append-only records in `lel_approval_snapshots`, bound to deterministic SHA-256 fingerprints of content, governed metadata, and approval-specific dependencies. `Approval_Service` is authoritative. Material changes invalidate current snapshots and project an explicit `stale` state; no change can auto-reapprove content.

## Consequences

Legacy completed statuses are not synthesized into trusted approvals. Existing published content is not automatically unpublished, but stale state blocks future publication/republish and suppresses public trust claims that are no longer current.
