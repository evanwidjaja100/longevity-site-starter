# ADR-0013: Allowlisted Public REST Projection

**Status:** Implemented

## Context

Raw governance metadata and private CPT schemas exposed workflow internals, identifiers, approval state, and potentially sensitive operational data without a stable public use case.

## Decision

Raw editorial, claim, source, test, protocol, affiliate, credential, approval, audit, and contact metadata is not exposed anonymously. Private CPTs are not REST-enumerable. Public consumers receive only the allowlisted `longevity_public` projection, and approval-dependent fields disappear when their snapshot is missing or stale. The anonymous health endpoint returns liveness only.

## Consequences

Authenticated editor integrations must document a concrete field need and pass role-specific authorization tests before any raw field is restored to REST.
