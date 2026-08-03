import { test, expect } from '@playwright/test';

const forbiddenKeys = [
  'medical_review_required',
  'medical_reviewer_user_id',
  'credential_verification_evidence_ref',
  'approval_snapshot_hash',
  'raw_observations',
  'evidence_references',
  '_longevity_audit_log',
];

test('anonymous REST exposes liveness and allowlisted content only', async ({ request }) => {
  const health = await request.get('/wp-json/longevity/v1/health');
  expect(health.ok()).toBeTruthy();
  expect(await health.json()).toEqual({ status: 'ok' });

  const posts = await request.get('/wp-json/wp/v2/posts?per_page=5&context=view');
  expect(posts.ok()).toBeTruthy();
  const serialized = JSON.stringify(await posts.json());
  for (const key of forbiddenKeys) {
    expect(serialized, `${key} must not be anonymous`).not.toContain(key);
  }
});

test('private governance post types are not anonymous routes', async ({ request }) => {
  const index = await request.get('/wp-json/');
  expect(index.ok()).toBeTruthy();
  const serialized = JSON.stringify(await index.json());
  for (const route of ['lel_claim', 'lel_source', 'lel_test_record', 'lel_protocol', 'lel_affiliate']) {
    expect(serialized).not.toContain(`/wp/v2/${route}`);
  }
});
