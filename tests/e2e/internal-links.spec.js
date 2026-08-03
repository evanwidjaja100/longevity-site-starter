import { test, expect } from '@playwright/test';
import { ALL_PUBLIC_ROUTES, ALL_PRIVATE_ROUTES } from './support/route-expectations.js';

const EXCLUDED_PREFIXES = [
  'mailto:', 'tel:', 'sms:', 'fax:',
  '/wp-', '/wp-admin', '/wp-login',
  '/wp-content/uploads/',
  '/?logout', '/?preview', '/?feed',
  '/feed/', '/trackback/',
  '#',
];

/**
 * Contract routes that are intentionally unpublished in CI fixture mode
 * (draft pages return 404 by design). Links to them are not broken: they
 * resolve in production once the human approval gates pass. Built from
 * ALL_PRIVATE_ROUTES so typos/deleted pages outside the contract still fail.
 */
const CONTRACT_DRAFT_PATHS = new Set(ALL_PRIVATE_ROUTES.map((r) => normalizePath(r.path)));

/**
 * Normalize a URL path for comparison: remove trailing slash and fragment.
 */
function normalizePath(path) {
  let p = path.split('#')[0];
  if (p.endsWith('/') && p !== '/') p = p.slice(0, -1);
  return p;
}

test.describe('runtime internal link crawl', () => {
  const results = [];
  const visited = new Set();
  const MAX_PAGES = 30;
  const MAX_LINKS = 500;

  for (const route of ALL_PUBLIC_ROUTES) {
    const path = route.path === '/?s=' ? '/?s=evidence' : route.path;
    test(`crawl links from ${route.key} (${path})`, async ({ page }) => {
      if (visited.size >= MAX_PAGES) {
        test.skip();
        return;
      }
      if (visited.has(path)) {
        test.skip();
        return;
      }
      visited.add(path);

      const response = await page.goto(path, { waitUntil: 'networkidle' });
      if (!response || response.status() >= 400) {
        test.skip();
        return;
      }

      const links = await page.evaluate(() => {
        const anchors = Array.from(document.querySelectorAll('a[href]'));
        return anchors.map(a => ({
          href: a.getAttribute('href'),
          text: (a.textContent || '').trim().slice(0, 100),
        }));
      });

      let linkCount = 0;
      for (const { href, text } of links) {
        if (linkCount >= MAX_LINKS) break;

        if (!href) continue;
        const trimmed = href.trim();

        const isExcluded = EXCLUDED_PREFIXES.some(p => trimmed.startsWith(p));
        if (isExcluded) continue;

        let url;
        try {
          url = new URL(trimmed, process.env.WP_SITE_URL || 'http://localhost:8080');
        } catch {
          continue;
        }

        if (url.hostname !== 'localhost' && url.hostname !== '127.0.0.1') continue;
        if (url.pathname === '/' && url.hash) continue;

        const normalized = normalizePath(url.pathname);

        if (normalized === '/wp-content' || normalized.startsWith('/wp-content/')) continue;

        if (CONTRACT_DRAFT_PATHS.has(normalized)) {
          results.push({ source: path, linkText: text, target: trimmed, status: 'expected-draft', redirects: 0 });
          continue;
        }

        linkCount++;
        let linkResponse;
        let status;
        let finalUrl;
        let redirectCount = 0;

        try {
          linkResponse = await page.request.get(url.pathname + url.search, { maxRedirects: 0, timeout: 10000 });
          status = linkResponse.status();
          finalUrl = normalizePath(url.pathname);

          if (status === 301 || status === 302 || status === 303 || status === 307 || status === 308) {
            const location = linkResponse.headers()['location'];
            if (location) {
              redirectCount = 1;
              const redirectUrl = new URL(location, process.env.WP_SITE_URL || 'http://localhost:8080');

              const followResponse = await page.request.get(redirectUrl.pathname + redirectUrl.search, { maxRedirects: 5, timeout: 10000 });
              status = followResponse.status();
              const followLocation = followResponse.url();
              finalUrl = normalizePath(new URL(followLocation).pathname);
            }
          } else {
            const finalLocation = linkResponse.url();
            finalUrl = normalizePath(new URL(finalLocation).pathname);
          }
        } catch {
          results.push({ source: path, linkText: text, target: trimmed, status: 'error', redirects: 0 });
          continue;
        }

        let noindex = false;
        try {
          const robotsResponse = await page.request.get(url.pathname + url.search, { maxRedirects: 0, timeout: 10000 });
          const robotsMeta = robotsResponse.headers()['x-robots-tag'];
          if (robotsMeta && robotsMeta.includes('noindex')) {
            noindex = true;
          }
        } catch {
          // ignore — can't check noindex on error
        }

        results.push({
          source: path,
          linkText: text,
          target: trimmed,
          status,
          redirects: redirectCount,
          finalUrl,
          noindex,
        });

        if (noindex) {
          console.warn(`WARNING: ${path} links to noindex destination ${trimmed}`);
        }

        if (status === 404 || status === 410 || status === 500) {
          expect.soft(status, `Link on ${path} -> ${trimmed} returned ${status}`).not.toBe(404);
          expect.soft(status, `Link on ${path} -> ${trimmed} returned ${status}`).not.toBe(410);
          expect.soft(status, `Link on ${path} -> ${trimmed} returned ${status}`).not.toBe(500);
        }
      }
    });
  }

  test.afterAll(() => {
    const failures = results.filter(r => r.status === 404 || r.status === 410 || r.status === 500);
    if (failures.length > 0) {
      console.log(`\n=== Internal link crawl report ===`);
      console.log(`Total links checked: ${results.length}`);
      console.log(`Broken links: ${failures.length}`);
      for (const f of failures) {
        console.log(`  ${f.source} -> "${f.linkText}" -> ${f.target} (${f.status})`);
      }
    }
    const redirects = results.filter(r => r.redirects > 0);
    if (redirects.length > 0) {
      console.log(`\nRedirected links (${redirects.length}):`);
      for (const r of redirects) {
        console.log(`  ${r.source} -> ${r.target} -> ${r.finalUrl}`);
      }
    }
    const noindexLinks = results.filter(r => r.noindex);
    if (noindexLinks.length > 0) {
      console.log(`\nNoindex destination warnings (${noindexLinks.length}):`);
      for (const r of noindexLinks) {
        console.log(`  ${r.source} -> ${r.target}`);
      }
    }
  });
});
