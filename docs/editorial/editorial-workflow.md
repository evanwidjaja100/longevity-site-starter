# Editorial Workflow

## States
Idea → Assigned → Researching → Drafting → Editorial review → Fact-check → Medical review or Testing incomplete when applicable → Commercial review → Ready for publication → Published. Published work can move to Update due, Correction pending, or Archived.

## Readiness
The editor sidebar reports applicable, passed, warning, and blocking checks. Draft saving remains available at every stage. Publishing triggers the same readiness service in classic and REST-based flows.

## Blocking examples
Missing scope/limitations, incomplete required fact-check, missing assigned medical reviewer or attestation, unsupported hands-on claims, unresolved commercial relationship, affiliate links without disclosure, unversioned scoring, stale price data, missing product model, missing evidence cutoff, placeholders, or missing review dates.

## Overrides
Only a user with `approve_publication_override` may use an emergency override. A written reason is mandatory. User, time, reason, and affected post are recorded. Routine deadline pressure is not an emergency.
## Production Readiness v2 approval semantics

Workflow status metadata is a compatibility projection, not authoritative approval evidence. Fact-check, medical, testing, commercial, and editorial completion require a current immutable approval snapshot whose content, governed metadata, and dependent-record fingerprints still match. Material changes mark prior approval `stale`; legacy completion records are `legacy_unbound` until a human re-approves the exact current state.

Preparation and approval are distinct actions. The actor combinations prohibited by `docs/architecture/permissions.md` remain prohibited for administrators unless an explicit emergency-override policy applies. No stale or missing approval may be rendered publicly as current.
