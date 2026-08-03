/**
 * k6 load scenario derived from the canonical route-state contract
 * (config/routes.json, PRV3-PERF-01).
 *
 * Profiles:
 *   default            – candidate smoke profile (blocking gate in CI).
 *   K6_PROFILE=load    – adds the surge stage; staging/perf environments only.
 *
 * Every requested route, REST endpoint, and asset exists in CI fixture mode:
 * pages flagged ci_fixture_public, category archives, watermarked synthetic
 * fixture content, the public health endpoint, and real static assets.
 * Contact submission is intentionally not load-tested (PRV3-PERF-01: no
 * anti-abuse-bypassing write traffic without an isolated sink).
 *
 * Thresholds mirror docs/operations/performance-baselines.md (CI smoke load).
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';

const latency = new Trend('longevity_request_latency', true);
const errors = new Counter('longevity_request_errors');

const BASE_URL = __ENV.WP_SITE_URL || 'http://localhost:8080';
const PROFILE = __ENV.K6_PROFILE || 'smoke';

const contract = JSON.parse(open('../../config/routes.json'));

const PUBLIC_ROUTES = [
  ...Object.values(contract.pages)
    .filter((page) => page.ci_fixture_public)
    .map((page) => page.path),
  ...Object.values(contract.categories).map((category) => category.path),
  ...contract.ci_fixture_content.published_paths,
];

const SEARCH_QUERIES = ['test', 'synthetic', 'evidence', 'review'];

/** Public, unauthenticated REST endpoints only. */
const REST_ENDPOINTS = ['/wp-json/longevity/v1/health'];

/** Real static assets shipped by the theme and MU plugin. */
const STATIC_ASSETS = [
  '/wp-content/themes/longevity-starter/style.css',
  '/wp-content/themes/longevity-starter/assets/js/site-ui.js',
  '/wp-content/mu-plugins/longevity-core/assets/contact-form.js',
];

function getRandom(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

function checkResponse(res, route) {
  const ok = check(res, {
    'status is 200': (r) => r.status === 200,
    'has title': (r) => r.html().find('title').text().length > 0,
    'no 5xx': (r) => r.status < 500,
    'has no PHP errors': (r) => !r.body.match(/Fatal error|Uncaught|Warning:|Notice:/),
  });
  if (!ok) {
    errors.add(1);
  }
  latency.add(res.timings.duration);
}

const scenarios = {
  smoke: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '15s', target: 5 },
      { duration: '30s', target: 5 },
      { duration: '15s', target: 0 },
    ],
    gracefulRampDown: '10s',
  },
};

if (PROFILE === 'load') {
  scenarios.surge = {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '10s', target: 20 },
      { duration: '20s', target: 20 },
      { duration: '10s', target: 0 },
    ],
    gracefulRampDown: '10s',
    startTime: '1m',
  };
}

export const options = {
  scenarios,
  thresholds: {
    http_req_duration: ['p(95)<1000', 'p(99)<2000'],
    http_req_failed: ['rate<0.01'],
    longevity_request_errors: ['count<10'],
  },
};

export default function () {
  const route = getRandom(PUBLIC_ROUTES);
  let res = http.get(BASE_URL + route, {
    tags: { route: route, category: 'public' },
  });
  checkResponse(res, route);

  const searchQuery = getRandom(SEARCH_QUERIES);
  res = http.get(BASE_URL + contract.search.path + encodeURIComponent(searchQuery), {
    tags: { route: '/search/', category: 'search' },
  });
  checkResponse(res, '/search/');

  const restEndpoint = getRandom(REST_ENDPOINTS);
  res = http.get(BASE_URL + restEndpoint, {
    tags: { route: restEndpoint, category: 'rest' },
  });
  check(res, {
    'rest status is 200': (r) => r.status === 200,
    'rest has json': (r) => {
      try {
        JSON.parse(r.body);
        return true;
      } catch (e) {
        return false;
      }
    },
  });

  const asset = getRandom(STATIC_ASSETS);
  res = http.get(BASE_URL + asset, {
    tags: { route: asset, category: 'asset' },
  });
  check(res, {
    'asset status is 200': (r) => r.status === 200,
  });

  sleep(0.3);
}
