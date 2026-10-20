# Implementation summary

- `Contracts\Metrics`: count, timing, gauge, measure; `Runtime\NullMetrics` records nothing.
- `Runtime\MetricCatalog`: the `metrics:` section of registered package monitoring.yaml files (type, tag keys,
  thresholds, retention); invalid entries are skipped and listed as problem codes.
- `Runtime\MetricGuard`: declared id of the right type, common or declared tag keys, code values, no identifiers,
  outcome from a fixed list, at most 8 tags and 1 KB; otherwise drop with a reason code.
- `Runtime\BufferedMetrics`: in-memory buffer (500), counters pre-aggregated, sampling of ok timings and gauges,
  drops counted as monitoring.dropped. `Runtime\SampleWriter`: one bulk insert on terminate and after queued
  jobs; failures swallowed and counted as monitoring.write_failed.
- Tables `larena_monitoring_samples` and `larena_monitoring_rollups` (the window column is named `period`, values
  m/h/d, because `window` is a reserved word in MySQL 8).
- `Runtime\RollupBuilder` (closed buckets after a 2-minute grace, upsert), `Runtime\RetentionPruner`
  (raw and minute rollups 7 days, hour and day rollups 90 days), `Runtime\MetricVerdicts`, `Runtime\MetricsReport`
  (schema `larena.monitoring_metrics_report`, `schema_version` 1).
- Commands `larena:monitoring:rollup` (every minute, without overlapping), `larena:monitoring:prune` (daily),
  `larena:monitoring:report`.
- `Runtime\RuntimeMonitoringGateway` implements MonitoringGateway: record() goes through the guard and buffer.
- `scripts/validate-larena-package.php` also refuses any larena/* package in composer require: monitoring is a leaf.
- The report rows also carry `p50` (count-weighted mean of bucket p50, like p95) and `last_event_at` (newest raw
  sample time or rollup bucket start over all kept data, UTC ISO-8601, null when never recorded).
- `Access\MonitoringOperations::DASHBOARD_READ` = `monitoring.dashboard.read` (target `monitoring.dashboard:system`,
  grant read, risk high, labels `larena-monitoring::operations.dashboard_read` in ru/en).
  `Access\AccessOperationRegistrar` registers it into larena/access's AccessOperationRegistry only when that class is
  bound, by class name, so monitoring keeps no Larena dependency; idempotent, a conflicting descriptor fails.
