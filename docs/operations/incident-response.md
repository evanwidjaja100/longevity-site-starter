# Incident Response

**Owner:** Security and editorial operations
**Last reviewed:** 2026-07-28

## Severity classification

| Severity | Label | Examples | Response time |
|---|---|---|---|
| P0 | Medical safety | Incorrect dosing info, contraindication missing, harmful claim published | Immediate (< 30 min) |
| P1 | Security / privacy | Credential breach, private data exposure, unauthorized publish | Within 1 hour |
| P2 | Editorial integrity | Factual error in published content, incomplete correction, missing disclosure | Within 4 hours |
| P3 | Availability | Site unreachable, search broken, form submission failing | Within 8 hours |
| P4 | Cosmetic / minor | Styling regression, broken internal link, typo | Next business day |

## Roles

| Role | Responsibility |
|---|---|
| **Incident lead** | Assigns severity, coordinates response, decides communications, records timeline |
| **Technical responder** | Identifies root cause, applies technical fix, documents technical detail |
| **Editorial responder** | Reviews content impact, determines whether to unpublish, add warning, or correct |
| **Medical reviewer** | Required for P0 — assesses medical-safety impact |
| **Legal counsel** | Required for P1 with breach-of-data — advises on notification duties |

## Process

### 1. Detection and containment

1. Preserve evidence: do not delete logs, records, or error messages
2. Restrict further harm:
   - P0: Unpublish or add prominent warning if delay creates risk
   - P1: Rotate credentials, contain access, preserve logs
   - P2: Mark post as draft pending correction review
3. Notify the incident lead

### 2. Assessment

1. Incident lead confirms severity classification
2. Determine scope: single post, category, all content, or infrastructure
3. Determine if a public notification is required (P0/P1 with user impact)
4. Assign responders

### 3. Remediation

1. **Reader safety (P0)**: Correct the underlying claim, preserve a public correction record via the `lel_correction` CPT, and unpublish or add warning
2. **Security/privacy (P1)**: Rotate all affected credentials, audit access logs, restore from known-good backup if tampering is suspected. Assess notification duties with qualified legal counsel
3. **Editorial error (P2)**: Correct the content, preserve the correction history via `[correction_update_timeline]` block, and update `_lel_reviewed_date` meta
4. **Availability (P3)**: Check WP health endpoint, restart services, verify DNS, escalate to host provider
5. **Cosmetic (P4)**: Schedule fix in normal editorial workflow

### 4. Post-mortem

Document for every incident:

- Date/time of first detection
- Severity classification
- Root cause (2-3 sentences)
- What went well
- What went poorly
- Action items with owners and deadlines
- Whether a public correction or reader notification was published

Store post-mortems in `operations/incident-response/<date>-<brief-description>.md`.

## Runbooks

| Runbook | Path |
|---|---|
| Medical safety correction | `operations/runbooks/medical-safety-correction.md` |
| Affiliate incident | `operations/runbooks/affiliate-incident.md` |
| Database unavailable | `operations/runbooks/database-unavailable.md` |
| Freshness cron stuck | `operations/runbooks/freshness-cron-stuck.md` |
| Audit chain integrity failure | `operations/runbooks/audit-chain-integrity-failure.md` |
| Lock exhaustion | `operations/runbooks/lock-exhaustion.md` |

## Communication templates

### Internal alert (P0/P1)

```
[SEVERITY] <P0|P1> — <Brief description>
Detected at: <time>
URL(s): <affected URLs>
Reporter: <name>
Assigned: <incident lead>
Status: <contained | assessing | remediating | post-mortem>
```

### Public correction notice (P0 reader safety)

Published via the `lel_correction` CPT and rendered on the affected article:

```
Correction notice — <date>

What changed: <1-2 sentence description of the correction>
Previous version: <summary of what was published>
Reason: <why the correction was necessary>
Impact: <whether readers should take any action>
```

### Breach notification (P1 — consult legal counsel before sending)

Do not send a breach notification before legal counsel has reviewed the content and the jurisdiction's notification requirements.

## Related

- `operations/runbooks/medical-safety-correction.md` — P0-specific runbook
- `operations/runbooks/affiliate-incident.md` — commercial incident runbook
- `docs/operations/security-checklist.md` — pre-launch security controls
- `docs/operations/monitoring.md` — detection tools and alerts
- `content/governance/corrections-policy.md` — editorial corrections policy
## Production Readiness v2 governance evidence

Security- and governance-relevant incidents must reference append-only audit event IDs and the applicable approval snapshot IDs. Do not edit or delete audit rows as part of remediation. Record invalidation reasons, affected object IDs, actor IDs, request/correlation IDs, containment actions, and whether public content remained live or was withdrawn.

Sensitive contact text, raw IP addresses, credential evidence, private source content, and test observations must not be copied into audit payloads or incident tickets unless an authorized human determines that protected storage is necessary. Backup, SMTP, WAF, and monitoring status remains `unknown_external` until operator evidence is supplied.
