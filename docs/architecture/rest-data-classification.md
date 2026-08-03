# REST Data Classification

## Public

- `GET /wp-json/longevity/v1/health`: exact liveness object, `{"status":"ok"}`.
- `longevity_public` post/review projection: allowlisted summary, scope, limitations, evidence summary, and only current approved medical/testing/commercial facts.
- Standard public WordPress post/review fields already intended for public rendering.

## Authenticated operator

- `GET /wp-json/longevity/v1/system-readiness`: protected by operational-readiness/publication capability and returns internal readiness categories without filesystem paths or secrets.

## Workflow-private

All raw editorial metadata, reviewer assignments, requirement flags, status fields, revision requests, claim/source records, protocols, test records, affiliate registry records, correction workflow state, and approval snapshots.

## Security-private

Credential evidence references, audit payloads, approval hashes/payloads, override reasons, contact records, HMAC rate-limit identifiers, migration errors, and external infrastructure evidence.

## Enforcement

Private CPTs use `show_in_rest=false`. Raw registered metadata uses `show_in_rest=false`. Public output is assembled by an explicit projection and fails closed when an approval is missing, stale, expired, or bound to an invalid credential/configuration state.
