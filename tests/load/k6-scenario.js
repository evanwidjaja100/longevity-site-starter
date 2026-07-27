import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';

const latency = new Trend('longevity_request_latency', true);
const errors = new Counter('longevity_request_errors');

export const options = {
  scenarios: {
    soak: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 10 },   // ramp up to 10 VUs
        { duration: '1m', target: 10 },    // hold at 10 VUs
        { duration: '30s', target: 0 },    // ramp down
      ],
      gracefulRampDown: '15s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    http_req_failed: ['rate<0.01'],
    longevity_request_errors: ['count<10'],
  },
};

const BASE_URL = __ENV.WP_SITE_URL || 'http://localhost:8080';

const routes = [
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

export default function () {
  const route = routes[Math.floor(Math.random() * routes.length)];
  const res = http.get(BASE_URL + route, {
    tags: { route: route },
  });

  const ok = check(res, {
    'status is 200': (r) => r.status === 200,
    'has title': (r) => r.html().find('title').text().length > 0,
    'no 5xx': (r) => r.status < 500,
  });

  if (!ok) {
    errors.add(1);
  }

  latency.add(res.timings.duration);
  sleep(0.5);
}
