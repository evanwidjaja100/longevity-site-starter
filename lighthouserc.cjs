/**
 * Lighthouse CI config — two presets: mobile (primary gate) and desktop.
 * Run with: lhci collect --config=lighthouserc.cjs --preset=mobile
 *           lhci collect --config=lighthouserc.cjs --preset=desktop
 */

const urls = [
  'http://localhost:8080/',
  'http://localhost:8080/start-here/',
  'http://localhost:8080/topics/',
  'http://localhost:8080/test-evidence-guide/',
  'http://localhost:8080/reviews/test-valid-review/',
  'http://localhost:8080/reviews/',
  'http://localhost:8080/category/evidence-literacy/',
  'http://localhost:8080/?s=evidence',
  'http://localhost:8080/?s=nonexistent',
  'http://localhost:8080/this-route-does-not-exist/',
  'http://localhost:8080/editorial-policy/',
  'http://localhost:8080/testing-methodology/',
];

const budget = [
  { resourceSizes: [
    { resourceType: 'total', budget: 1500 * 1024 },
    { resourceType: 'document', budget: 50 * 1024 },
    { resourceType: 'script', budget: 400 * 1024 },
    { resourceType: 'stylesheet', budget: 100 * 1024 },
    { resourceType: 'image', budget: 800 * 1024 },
    { resourceType: 'third-party', budget: 200 * 1024 },
  ]},
];

const sharedAssert = {
  assertions: {
    'categories:performance': ['error', { minScore: 0.9 }],
    'categories:accessibility': ['error', { minScore: 0.95 }],
    'categories:best-practices': ['error', { minScore: 0.95 }],
    'categories:seo': ['error', { minScore: 0.95 }],
    'largest-contentful-paint': ['error', { maxNumericValue: 2500 }],
    'cumulative-layout-shift': ['error', { maxNumericValue: 0.1 }],
    'total-blocking-time': ['error', { maxNumericValue: 200 }],
    'unused-javascript': ['warn', { maxLength: 0 }],
    'unused-css-rules': ['warn', { maxLength: 0 }],
    'no-unused-lighthouse-results': ['off'],
  },
};

const upload = { target: 'filesystem', outputDir: './reports/lighthouse' };

module.exports = {
  ci: {
    mobile: {
      collect: {
        numberOfRuns: 3,
        settings: { chromeFlags: '--no-sandbox --disable-dev-shm-usage' },
        url: urls,
      },
      assert: { ...sharedAssert, budgets: budget },
      upload,
    },
    desktop: {
      collect: {
        numberOfRuns: 3,
        settings: { chromeFlags: '--no-sandbox --disable-dev-shm-usage', preset: 'desktop' },
        url: urls,
      },
      assert: sharedAssert,
      upload,
    },
  },
};
