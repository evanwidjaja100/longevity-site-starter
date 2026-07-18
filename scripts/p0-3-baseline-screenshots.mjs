import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'fs';
import { resolve } from 'path';

const BASE_URL = 'http://localhost:8080';
const SCREENSHOT_DIR = resolve('docs/testing/artifacts/pre-v2');
const VIEWPORTS = [
  { name: 'mobile', width: 360, height: 800 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 1000 },
];

const ROUTES = [
  { path: '/', name: 'homepage' },
  { path: '/start-here/', name: 'start-here' },
  { path: '/about/', name: 'about' },
  { path: '/editorial-policy/', name: 'editorial-policy' },
  { path: '/medical-disclaimer/', name: 'medical-disclaimer' },
  { path: '/affiliate-disclosure/', name: 'affiliate-disclosure' },
  { path: '/corrections/', name: 'corrections' },
  { path: '/testing-methodology/', name: 'testing-methodology' },
  { path: '/privacy/', name: 'privacy' },
  { path: '/terms/', name: 'terms' },
  { path: '/contact/', name: 'contact' },
  { path: '/reviews/', name: 'reviews-archive' },
  { path: '/category/evidence-literacy/', name: 'category-evidence-literacy' },
  { path: '/reviews/', name: 'reviews-empty' },
  { path: '/?s=test', name: 'search' },
  { path: '/nonexistent-page-xyz/', name: '404' },
];

mkdirSync(SCREENSHOT_DIR, { recursive: true });

function escapeCsv(val) {
  const s = String(val || '');
  return s.includes(',') || s.includes('"') || s.includes('\n') ? `"${s.replace(/"/g, '""')}"` : s;
}

async function getMeta(page, name, prop) {
  try {
    const val = await page.evaluate(({ n, p }) => {
      const el = document.querySelector(`meta[${p}="${n}"]`);
      return el ? el.getAttribute('content') || '' : '';
    }, { n: name, p: prop });
    return val;
  } catch { return ''; }
}

async function run() {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const context = await browser.newContext({ locale: 'en-US' });

  const inventory = [];

  for (const route of ROUTES) {
    const url = BASE_URL + route.path;
    const page = await context.newPage();
    
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });
      await page.waitForTimeout(1000);
      
      const statusCode = response ? response.status() : 0;
      const title = await page.title();
      const h1Count = await page.evaluate(() => document.querySelectorAll('h1').length);
      const h1Text = h1Count > 0 ? await page.evaluate(() => document.querySelector('h1').textContent.trim()) : '';
      const metaDescription = await getMeta(page, 'description', 'name');
      const canonical = await page.evaluate(() => {
        const el = document.querySelector('link[rel="canonical"]');
        return el ? el.getAttribute('href') || '' : '';
      });
      const robots = await getMeta(page, 'robots', 'name');
      const ogTitle = await getMeta(page, 'og:title', 'property');
      const ogDesc = await getMeta(page, 'og:description', 'property');
      
      inventory.push({
        path: route.path,
        name: route.name,
        status: statusCode,
        title: title.trim(),
        h1: h1Text,
        h1Count,
        metaDescription,
        canonical,
        robots,
        ogTitle,
        ogDescription: ogDesc,
      });

      for (const vp of VIEWPORTS) {
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.waitForTimeout(300);
        const filename = resolve(SCREENSHOT_DIR, `${route.name}-${vp.name}.png`);
        await page.screenshot({ path: filename, fullPage: false });
        console.log(`OK ${route.name} @ ${vp.name}`);
      }
    } catch (err) {
      console.error(`FAIL ${route.path}: ${err.message}`);
      inventory.push({
        path: route.path,
        name: route.name,
        status: 'ERROR',
        title: '', h1: '', h1Count: 0,
        metaDescription: '', canonical: '', robots: '', ogTitle: '', ogDescription: '',
      });
    } finally {
      await page.close();
    }
  }

  const headers = ['path','name','status','title','h1','h1Count','metaDescription','canonical','robots','ogTitle','ogDescription'];
  const lines = [headers.join(',')];
  for (const row of inventory) {
    lines.push(headers.map(h => escapeCsv(row[h])).join(','));
  }
  writeFileSync('docs/testing/pre-v2-route-inventory.csv', lines.join('\n'), 'utf-8');
  console.log(`\nDone. ${inventory.length} routes. Inventory: docs/testing/pre-v2-route-inventory.csv`);
  
  await browser.close();
}

run().catch(e => { console.error(e); process.exit(1); });
