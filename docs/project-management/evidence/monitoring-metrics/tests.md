# Tests

`composer run quality:gate`: validate, lint, PHPStan (level 5), contract assert scripts,
`tests/Unit/Metrics` (guard rejections: paths, e-mail, free text, identifiers, extra tags, more than 8 tags,
over 1 KB; catalog problems; NullMetrics; counter aggregation; buffer overflow; measure rethrow; sampling) and
`tests/Feature` on Testbench SQLite (one insert at terminate, database failure swallowed and counted, minute/hour/day
rollups of a day, grace period, prune keeps rollups, verdicts and report JSON, gateway, schedule, missing tables,
kill switch).

Follow-up: report `p50` (weighted over hour buckets) and `last_event_at` (raw sample, then rollup fallback after
samples are pruned); `MonitoringAccessOperationsTest` (descriptor shape, ru/en labels, idempotent registration,
conflict, no-op without larena/access).
