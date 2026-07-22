# ADR-0010: Explicit Metadata Authorization

**Status:** Implemented; governance ownership pending human approval

## Context

The prior authorization model allowed broad `edit_post` access and inferred sensitive behavior from field naming. That made forged classic-editor, REST, or direct metadata writes capable of weakening publication gates.

## Decision

Every editorial field declares exactly one explicit `write_policy`. `Meta_Authorization` is the shared deny-by-default decision service for classic editor, REST, CLI/workflow, and same-request gate evaluation. Unknown fields and `system_only` fields are rejected through generic mutation channels. Protected booleans are changed only when an explicit presence marker is submitted.

## Consequences

Adding a field without a policy fails architecture/tests. Legitimate workflow changes require an explicit policy-matrix change and governance review rather than an implicit role-name or prefix fallback.
