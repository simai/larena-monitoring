# Larena Monitoring

Shared system package for Larena health checks, metrics, incidents and operational dashboards across packages and runtime environments.

## Runtime metrics

Packages report how their operations go through `Larena\Monitoring\Contracts\Metrics`:

```php
$metrics->count('storage.record_saved', 1, ['outcome' => 'ok']);
$metrics->timing('storage.query', $ms, ['operation' => 'list']);
$metrics->gauge('queue.backlog_size', $size, ['queue_profile' => 'default']);
$rows = $metrics->measure('storage.query', fn () => $query->get(), ['operation' => 'list']);
```

- Every metric id is declared in the package's `monitoring.yaml` (`metrics:` section: type, tag keys,
  thresholds `p95_ms` / `error_rate`, retention). A package registers its file with
  `MetricCatalog::register('larena/<package>', $path)` or the `larena-monitoring.catalogs` config list.
- Tags are short codes only: keys `package`, `subsystem`, `operation`, `outcome`, `reason` plus the declared ones;
  values match `^[a-z][a-z0-9_.-]{0,59}$`, never text, paths, e-mails or identifiers; at most 8 tags and 1 KB per
  record. A record that breaks a rule is dropped and counted as `monitoring.dropped`.
- `measure()` records `outcome=ok`, or `outcome=error` with the exception class as `reason`, and rethrows the
  exception unchanged.
- Records are buffered in memory (at most 500 per request, command or job; identical counters are summed) and
  written in one insert when the request ends or a queued job finishes. A write failure never reaches the caller;
  it is counted as `monitoring.write_failed`.
- `LARENA_MONITORING=false` switches recording off (`NullMetrics`); so do absent tables.

Request timing: `Larena\Monitoring\Http\RequestTimingMiddleware` records `http.request` (duration, threshold
p95 500 ms, error share 5%) when the response has been sent, with tags `route` (the route name as a code,
`unnamed` without one — never the URI or its parameters), `method`, `status_class` (`http_2xx` … `http_5xx`; tag
values must start with a letter) and `outcome` (`error` for 5xx). The application appends it as global middleware
in `bootstrap/app.php`: `$middleware->append(\Larena\Monitoring\Http\RequestTimingMiddleware::class);`.

Scheduled commands: `larena:monitoring:rollup` (every minute: minute, hour and day rollups),
`larena:monitoring:prune` (daily: raw samples and minute rollups 7 days, hour and day rollups 90 days) and
`larena:monitoring:report --days=7 --package= --json` with a verdict per metric: working, degraded (error share
above 5% or p95 above the declared threshold), unused or no data.

Health probes, incidents and the admin dashboard are not implemented yet; the contracts in `src/Contracts`
describe them.

Canonical specifications are in `simai/larena-specs`.
