'use strict';

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

const assertions = {
  'categories:performance': ['error', { minScore: 0.9 }],
  'categories:accessibility': ['error', { minScore: 0.95 }],
  'categories:best-practices': ['error', { minScore: 0.95 }],
  'categories:seo': ['error', { minScore: 0.95 }],
  'largest-contentful-paint': ['error', { maxNumericValue: 2500 }],
  'cumulative-layout-shift': ['error', { maxNumericValue: 0.1 }],
  'total-blocking-time': ['error', { maxNumericValue: 200 }],
  'total-byte-weight': ['error', { maxNumericValue: 1536000 }],
  'unused-javascript': ['warn', { maxLength: 0 }],
  'unused-css-rules': ['warn', { maxLength: 0 }],
};

module.exports = { urls, assertions };
