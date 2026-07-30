#!/usr/bin/env node
/**
 * Lighthouse target preflight (PRV3-PERF-02).
 *
 * Verifies every audit target from lighthouserc.shared.cjs responds with the
 * expected status before any score is collected, so Lighthouse never scores
 * an error page, login redirect, draft route, or missing fixture as a target.
 */

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { okPaths, notFoundPaths, BASE_URL } = require('../lighthouserc.shared.cjs');

let failures = 0;

async function assertStatus(path, expected) {
  const url = BASE_URL + path;
  let response;
  try {
    response = await fetch(url, { redirect: 'manual' });
  } catch (error) {
    console.error(`FAIL ${url} — request error: ${error.message}`);
    failures += 1;
    return;
  }
  if (response.status >= 300 && response.status < 400) {
    const location = response.headers.get('location') || '';
    console.error(`FAIL ${url} — unexpected redirect (${response.status}) to ${location}; targets must be directly reachable.`);
    failures += 1;
    return;
  }
  if (response.status !== expected) {
    console.error(`FAIL ${url} — expected HTTP ${expected}, got ${response.status}.`);
    failures += 1;
    return;
  }
  const body = await response.text();
  if (body.includes('wp-login.php') && expected === 200 && /name="log"/.test(body)) {
    console.error(`FAIL ${url} — target rendered a login form instead of public content.`);
    failures += 1;
    return;
  }
  console.log(`ok ${url} (${response.status})`);
}

for (const path of okPaths) {
  await assertStatus(path, 200);
}
for (const path of notFoundPaths) {
  await assertStatus(path, 404);
}

if (failures > 0) {
  console.error(`Lighthouse preflight failed: ${failures} target(s) are not in the expected state. Refusing to score.`);
  process.exit(1);
}
console.log('Lighthouse preflight passed: all targets are in the expected state.');
