<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The metrics report for the last N days, built from rollups: closed hours from hour rollups, the rest of the
 * window from minute rollups. Count, errors, sum, min and max are exact; p50 and p95 are the count-weighted means
 * of the bucket p50 and p95 values (an approximation, the same for both). `last_event_at` is the newest raw sample
 * time or rollup bucket start of the metric over all kept data (not only the window), UTC ISO-8601, or null.
 * Every declared metric gets a verdict (see MetricVerdicts).
 */
final class MetricsReport
{
    public const string SCHEMA = 'larena.monitoring_metrics_report';

    public const int SCHEMA_VERSION = 1;

    public function __construct(
        private readonly MetricCatalog $catalog,
        private readonly ConnectionResolverInterface $database,
        private readonly ?string $connection = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(int $days = 7, ?string $package = null, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now('UTC'))->getTimestamp();
        $from = RollupBuilder::floor($now - $days * 86400, 3600);
        $stats = [];
        $recorded = [];
        $lastEvents = [];
        $tablesPresent = true;
        try {
            $db = $this->database->connection($this->connection);
            $tablesPresent = false;
            if ($db instanceof Connection) {
                $schema = $db->getSchemaBuilder();
                $tablesPresent = $schema->hasTable(SampleWriter::TABLE) && $schema->hasTable(RollupBuilder::TABLE);
                if ($tablesPresent) {
                    [$stats, $lastEvents] = $this->collect($db, $from, $now);
                    $recorded = array_fill_keys(array_keys($lastEvents), true);
                }
            }
        } catch (Throwable) {
            $tablesPresent = false;
        }

        $summary = [MetricVerdicts::WORKING => 0, MetricVerdicts::DEGRADED => 0, MetricVerdicts::UNUSED => 0, MetricVerdicts::NO_DATA => 0];
        $metrics = [];
        foreach ($this->catalog->all() as $id => $descriptor) {
            if ($package !== null && $descriptor->package !== $package) {
                continue;
            }
            $row = $stats[$id] ?? null;
            $count = $row['count'] ?? 0;
            $errors = $row['errors'] ?? 0;
            $p50 = $row['p50'] ?? null;
            $p95 = $row['p95'] ?? null;
            $verdict = MetricVerdicts::judge($descriptor, isset($recorded[$id]), $count, $errors, $p95);
            $summary[$verdict['verdict']]++;
            $metrics[] = [
                'id' => $id,
                'package' => $descriptor->package,
                'type' => $descriptor->type->value,
                'verdict' => $verdict['verdict'],
                'reasons' => $verdict['reasons'],
                'count' => $count,
                'errors' => $errors,
                'error_share' => $count > 0 ? round($errors / $count, 4) : null,
                'sum' => $row !== null ? round($row['sum'], 3) : null,
                'min' => $row['min'] ?? null,
                'max' => $row['max'] ?? null,
                'p50' => $p50 !== null ? round($p50, 3) : null,
                'p95' => $p95 !== null ? round($p95, 3) : null,
                'last_event_at' => isset($lastEvents[$id]) ? gmdate('Y-m-d\TH:i:s\Z', $lastEvents[$id]) : null,
                'thresholds' => $descriptor->thresholds,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'window' => ['days' => $days, 'from' => gmdate('Y-m-d\TH:i:s\Z', $from), 'to' => gmdate('Y-m-d\TH:i:s\Z', $now)],
            'package' => $package,
            'tables_present' => $tablesPresent,
            'summary' => $summary,
            'metrics' => $metrics,
            'catalog_problems' => $this->catalog->problems(),
        ];
    }

    /**
     * @return array{0: array<string, array{count: int, errors: int, sum: float, min: ?float, max: ?float, p50: ?float, p95: ?float}>, 1: array<string, int>}
     */
    private function collect(Connection $db, int $from, int $now): array
    {
        $lastHour = $db->table(RollupBuilder::TABLE)
            ->where('period', 'h')
            ->where('bucket_start', '>=', RollupBuilder::format($from))
            ->max('bucket_start');
        $boundary = is_string($lastHour) && $lastHour !== '' ? RollupBuilder::timestamp($lastHour) + 3600 : $from;

        /** @var array<string, array{count: int, errors: int, sum: float, min: ?float, max: ?float, p50_weight: float, p50_count: int, p95_weight: float, p95_count: int}> $acc */
        $acc = [];
        $grammar = $db->getQueryGrammar();
        foreach ([['h', $from, $boundary], ['m', $boundary, $now]] as [$period, $start, $end]) {
            if ($start >= $end) {
                continue;
            }
            $rows = $db->table(RollupBuilder::TABLE)
                ->where('period', $period)
                ->where('bucket_start', '>=', RollupBuilder::format($start))
                ->where('bucket_start', '<', RollupBuilder::format($end))
                ->groupBy('metric_id')
                ->selectRaw(sprintf(
                    'metric_id, SUM(%1$s) AS events, SUM(%2$s) AS errors, SUM(%3$s) AS total, MIN(%4$s) AS low, MAX(%5$s) AS high,'
                    .' SUM(CASE WHEN %6$s IS NULL THEN 0 ELSE %6$s * %1$s END) AS p95_weight,'
                    .' SUM(CASE WHEN %6$s IS NULL THEN 0 ELSE %1$s END) AS p95_count,'
                    .' SUM(CASE WHEN %7$s IS NULL THEN 0 ELSE %7$s * %1$s END) AS p50_weight,'
                    .' SUM(CASE WHEN %7$s IS NULL THEN 0 ELSE %1$s END) AS p50_count',
                    ...array_map($grammar->wrap(...), ['count', 'errors', 'sum', 'min', 'max', 'p95', 'p50']),
                ))
                ->get();
            foreach ($rows as $row) {
                $id = (string) $row->metric_id;
                $current = $acc[$id] ?? ['count' => 0, 'errors' => 0, 'sum' => 0.0, 'min' => null, 'max' => null, 'p50_weight' => 0.0, 'p50_count' => 0, 'p95_weight' => 0.0, 'p95_count' => 0];
                $low = $row->low === null ? null : (float) $row->low;
                $high = $row->high === null ? null : (float) $row->high;
                $acc[$id] = [
                    'count' => $current['count'] + (int) $row->events,
                    'errors' => $current['errors'] + (int) $row->errors,
                    'sum' => $current['sum'] + (float) $row->total,
                    'min' => $current['min'] === null ? $low : ($low === null ? $current['min'] : min($current['min'], $low)),
                    'max' => $current['max'] === null ? $high : ($high === null ? $current['max'] : max($current['max'], $high)),
                    'p50_weight' => $current['p50_weight'] + (float) $row->p50_weight,
                    'p50_count' => $current['p50_count'] + (int) $row->p50_count,
                    'p95_weight' => $current['p95_weight'] + (float) $row->p95_weight,
                    'p95_count' => $current['p95_count'] + (int) $row->p95_count,
                ];
            }
        }

        $stats = [];
        foreach ($acc as $id => $row) {
            $stats[$id] = [
                'count' => $row['count'],
                'errors' => $row['errors'],
                'sum' => $row['sum'],
                'min' => $row['min'],
                'max' => $row['max'],
                'p50' => $row['p50_count'] > 0 ? $row['p50_weight'] / $row['p50_count'] : null,
                'p95' => $row['p95_count'] > 0 ? $row['p95_weight'] / $row['p95_count'] : null,
            ];
        }

        // The newest moment per metric: raw sample time, or the rollup bucket start once raw samples are pruned.
        $lastEvents = [];
        foreach ([[RollupBuilder::TABLE, 'bucket_start'], [SampleWriter::TABLE, 'recorded_at']] as [$table, $column]) {
            $rows = $db->table($table)->groupBy('metric_id')->selectRaw(sprintf('metric_id, MAX(%s) AS newest', $grammar->wrap($column)))->get();
            foreach ($rows as $row) {
                $id = (string) $row->metric_id;
                $newest = is_string($row->newest) && $row->newest !== '' ? RollupBuilder::timestamp($row->newest) : 0;
                $lastEvents[$id] = max($lastEvents[$id] ?? 0, $newest);
            }
        }

        return [$stats, $lastEvents];
    }
}
