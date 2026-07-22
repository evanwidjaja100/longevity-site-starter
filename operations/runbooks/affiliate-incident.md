# Affiliate and Commercial Incident Runbook

1. Disable or remove the affected link or campaign.
2. Determine whether the merchant, destination, pricing, disclosure, tracking, or editorial independence is compromised.
3. Preserve click and configuration evidence without collecting sensitive health data.
4. Correct disclosures and affiliate-registry status; review every affected article.
5. Notify the partner only through approved business channels and never negotiate editorial scores.
6. Publish a correction when readers could have made a materially different decision.
## Production Readiness v2 containment steps

1. Mark the affected relationship inactive or expired; do not rely on a manual `active` value when lifecycle dates fail.
2. Record the normalized destination, relationship record ID, discovery time, and append-only audit event ID without copying credentials or sensitive query values.
3. Invalidate current commercial and dependent editorial approvals for affected content.
4. Confirm public affiliate links and disclosures fail closed, including redirects or destination changes handled outside WordPress.
5. Require an independent commercial approver to review the corrected exact destination set before reapproval.
