# Performance Baselines and SLOs

**Owner:** Operations
**Last reviewed:** 2026-07-30

## Approved load assumptions

| Dimension | Target | Surge | Notes |
|---|---|---|---|
| Public request rate | 50 RPS | 150 RPS | Peak hour, all public routes |
| Concurrent browser users | 100 | 300 | Real-user concurrency |
| Editorial users (concurrent) | 5 | 10 | Authenticated admin/editor sessions |
| Cron overlap | 1 | 1 | Single worker per cron type |
| Contact submissions | 10/min | 30/min | Rate-limited per IP |
| Search queries | 20 RPS | 50 RPS | `/?s=` endpoint |

## Service-level objectives

| Metric | SLO | Alert threshold |
|---|---|---|
| p95 response time (public) | < 500 ms | > 1000 ms |
| p99 response time (public) | < 1000 ms | > 2000 ms |
| Error rate (5xx) | < 1% | > 2% |
| Contact form success | 99% | < 95% |
| Health endpoint | 200 OK | Any non-200 |
| REST API p95 | < 300 ms | > 600 ms |

## CI smoke load

- **Scenario:** `tests/load/k6-scenario.js` (routes derived from `config/routes.json`)
- **Image:** `grafana/k6:2.1.0` pinned by digest in CI
- **Profile:** 5 VUs, 30s hold, 15s ramp-down (smoke, default)
- **Surge:** 20 VUs, 20s hold — enabled only with `K6_PROFILE=load` (staging/perf environments)
- **Thresholds:** p95 < 1000 ms, p99 < 2000 ms, error rate < 1%
- **Blocking in CI:** the candidate smoke gate fails the `load-smoke` job on any threshold breach; deeper staging load tests remain a separate blocking acceptance gate (PRV3-ACC-04)

## Abort thresholds

- p95 > 2000 ms sustained for 30s
- Error rate > 5% sustained for 30s
- Queue depth > 1000 pending jobs
- Cron heartbeat > 2x expected interval

## Representative dataset

- 50 published posts with claims, approvals, rankings
- 10 review records with test protocols
- 500 contact submissions (retention test)
- 1000 dependency edges in the dependency index

## Cache conditions

- **Cold:** first request after deploy/restart
- **Warm:** after 100 requests to each route
- **Hot:** after 1000 requests to each route

## Measurement

- k6 for load generation
- Application metrics via `class-metrics.php` (Prometheus format)
- Database slow-query log
- PHP-FPM status
- WordPress object cache hit ratio
