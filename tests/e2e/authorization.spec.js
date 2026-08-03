import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

// The browser lane invokes the same real WordPress service contract used by the
// integration lane so role/capability enforcement cannot silently become a mock-only test.
test('forged governance writes and stale approvals are rejected server-side', () => {
  const output = execFileSync('sh', ['tests/integration/pr2-security-contracts.sh'], {
    encoding: 'utf8',
    timeout: 120000,
  });
  expect(output).toContain('PR2 authorization, approval invalidation');
});
