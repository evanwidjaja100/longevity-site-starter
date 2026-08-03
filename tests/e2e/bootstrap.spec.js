import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

const WPCLI = 'docker compose run --rm wpcli wp longevity bootstrap';

/**
 * Run a WP-CLI bootstrap command and return its stdout string.
 */
function run(...args) {
  const cmd = `${WPCLI} ${args.join(' ')} --allow-root 2>&1`;
  return execSync(cmd, { encoding: 'utf8', timeout: 60000 });
}

test.describe('bootstrap commands', () => {

  test('bootstrap pages --dry-run creates no pages', () => {
    const output = run('pages', '--dry-run');
    expect(output).toContain('Success: Bootstrap complete:');
    expect(output).toContain('0 created');
    expect(output).not.toContain('Would create');
  });

  test('bootstrap categories --dry-run creates no categories', () => {
    const output = run('categories', '--dry-run');
    expect(output).toContain('Success: Bootstrap complete:');
    expect(output).toContain('0 created');
    expect(output).not.toContain('Would create');
  });

  test('bootstrap content --dry-run creates no content', () => {
    const output = run('content', '--dry-run');
    expect(output).toContain('Success: Bootstrap complete:');
    expect(output).toContain('0 created');
    expect(output).not.toContain('Would create');
  });

  test('bootstrap all --dry-run reports all three sections', () => {
    const output = run('all', '--dry-run');
    expect(output).toContain('=== Bootstrap: pages ===');
    expect(output).toContain('=== Bootstrap: categories ===');
    expect(output).toContain('=== Bootstrap: content ===');
    expect(output).toContain('Full bootstrap complete');
    expect(output).toContain('0 created, 16 existing');
    expect(output).toContain('0 created, 7 existing');
  });

  test('bootstrap pages is idempotent (no duplicates on re-run)', () => {
    const output = run('pages', '--dry-run');
    const lines = output.split('\n').filter(l => l.includes('already exists'));
    expect(lines.length).toBeGreaterThanOrEqual(16);
  });

  test('bootstrap content is idempotent (no duplicates on re-run)', () => {
    const output = run('content', '--dry-run');
    const lines = output.split('\n').filter(l => l.includes('already exists'));
    expect(lines.length).toBeGreaterThanOrEqual(7);
  });

});
