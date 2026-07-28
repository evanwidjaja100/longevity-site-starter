import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';

const latency = new Trend('longevity_request_latency', true);
const errors = new Counter('longevity_request_errors');

const BASE_URL = __ENV.WP_SITE_URL || 'http://localhost:8080';

const PUBLIC_ROUTES = [
  '/',
  '/start-here/',
  '/about/',
  '/evidence/',
  '/reviews/',
  '/contact/',
  '/privacy/',
  '/terms/',
  '/editorial-policy/',
  '/medical-disclaimer/',
  '/affiliate-disclosure/',
  '/corrections/',
  '/ai-assisted-work-disclosure/',
  '/evidence-methodology/',
  '/testing-methodology/',
];

const SEARCH_QUERIES = [
  'longevity',
  'supplements',
  'review',
  'evidence',
  'health',
];

const REST_ENDPOINTS = [
  '/wp-json/longevity/v1/health',
  '/wp-json/longevity/v1/rankings',
  '/wp-json/longevity/v1/claims',
];

const CONTACT_ASSETS = [
  '/wp-content/themes/longevity-starter/assets/css/contact-form.css',
  '/wp-content/mu-plugins/longevity-core/assets/js/contact-form.js',
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

export const options = {
  scenarios: {
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
    surge: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 20 },
        { duration: '20s', target: 20 },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '10s',
      startTime: '1m',
    },
  },
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
  res = http.get(BASE_URL + '/?s=' + encodeURIComponent(searchQuery), {
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

  const asset = getRandom(CONTACT_ASSETS);
  res = http.get(BASE_URL + asset, {
    tags: { route: asset, category: 'asset' },
  });
  check(res, {
    'asset status is 200': (r) => r.status === 200,
  });

  sleep(0.3);
}
