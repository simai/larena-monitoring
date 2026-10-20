<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;

/**
 * Builds minute (m), hour (h) and day (d) rollups from raw samples: count, sum, min, max, p50, p95 and errors
 * (outcome error or timeout) per metric, tag set and bucket. Only closed buckets are built — a bucket closes
 * `graceSeconds` after its end, so late writes from finishing requests are still counted — and each period
 * continues from its last built bucket. Rebuilding a bucket overwrites it (upsert), so a run can be repeated.
 * For counters, count and errors are the number of counted events (the sum of the samples).
 */
final class RollupBuilder
{
    public const string TABLE = 'larena_monitoring_rollups';

    /** @var array<string, int> period => bucket length in seconds */
    public const array PERIODS = ['m' => 60, 'h' => 3600, 'd' => 86400];

    /** @var array<string, int> period => seconds read from raw samples per pass */
    private const array SLICES = ['m' => 3600, 'h' => 3600, 'd' => 86400];

    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly ?string $connection = null,
        private readonly int $rawDays = 7,
        private readonly int $graceSeconds = 120,
    ) {
    }

    /** @return array<string, int> period => rollup rows written */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now('UTC');
        $result = [];
        foreach (self::PERIODS as $period => $length) {
            $result[$period] = $this->buildPeriod($period, $length, $now->getTimestamp());
        }

        return $result;
    }

    private function buildPeriod(string $period, int $length, int $now): int
    {
        $db = $this->db();
        $to = self::floor($now - $this->graceSeconds, $length);
        $last = $db->table(self::TABLE)->where('period', $period)->max('bucket_start');
        if (is_string($last) && $last !== '') {
            $from = self::timestamp($last) + $length;
        } else {
            $oldest = $db->table(SampleWriter::TABLE)->min('recorded_at');
            if (!is_string($oldest) || $oldest === '') {
                return 0;
            }
            $from = self::floor(self::timestamp($oldest), $length);
        }
        $from = max($from, self::floor($now - $this->rawDays * 86400, $length));
        $written = 0;
        for ($sliceStart = $from; $sliceStart < $to; $sliceStart += self::SLICES[$period]) {
            $written += $this->buildSlice($db, $period, $length, $sliceStart, min($to, $sliceStart + self::SLICES[$period]));
        }

        return $written;
    }

    private function buildSlice(ConnectionInterface $db, string $period, int $length, int $from, int $to): int
    {
        /** @var array<string, array{metric_id: string, tag_hash: string, tags_json: string, bucket: int, counter: bool, count: int, sum: float, min: float, max: float, errors: int, values: list<float>}> $groups */
        $groups = [];
        $rows = $db->table(SampleWriter::TABLE)
            ->select(['id', 'metric_id', 'type', 'value', 'tags_json', 'tag_hash', 'recorded_at'])
            ->where('recorded_at', '>=', self::format($from))
            ->where('recorded_at', '<', self::format($to))
            ->lazyById(2000);
        foreach ($rows as $row) {
            $value = (float) $row->value;
            $tagsJson = (string) $row->tags_json;
            $bucket = self::floor(self::timestamp((string) $row->recorded_at), $length);
            $key = $row->metric_id.'|'.$row->tag_hash.'|'.$bucket;
            $counter = $row->type === 'counter';
            $failed = str_contains($tagsJson, '"outcome":"error"') || str_contains($tagsJson, '"outcome":"timeout"');
            $events = $counter ? (int) round($value) : 1;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'metric_id' => (string) $row->metric_id,
                    'tag_hash' => (string) $row->tag_hash,
                    'tags_json' => $tagsJson,
                    'bucket' => $bucket,
                    'counter' => $counter,
                    'count' => 0,
                    'sum' => 0.0,
                    'min' => $value,
                    'max' => $value,
                    'errors' => 0,
                    'values' => [],
                ];
            }
            $group = &$groups[$key];
            $group['count'] += $events;
            $group['sum'] += $value;
            $group['min'] = min($group['min'], $value);
            $group['max'] = max($group['max'], $value);
            $group['errors'] += $failed ? $events : 0;
            if (!$counter) {
                $group['values'][] = $value;
            }
            unset($group);
        }

        $upserts = [];
        foreach ($groups as $group) {
            $values = $group['values'];
            sort($values);
            $upserts[] = [
                'metric_id' => $group['metric_id'],
                'tag_hash' => $group['tag_hash'],
                'tags_json' => $group['tags_json'],
                'period' => $period,
                'bucket_start' => self::format($group['bucket']),
                'count' => $group['count'],
                'sum' => $group['sum'],
                'min' => $group['min'],
                'max' => $group['max'],
                'p50' => $group['counter'] ? null : self::percentile($values, 0.50),
                'p95' => $group['counter'] ? null : self::percentile($values, 0.95),
                'errors' => $group['errors'],
            ];
        }
        foreach (array_chunk($upserts, 500) as $chunk) {
            $db->table(self::TABLE)->upsert(
                $chunk,
                ['metric_id', 'tag_hash', 'period', 'bucket_start'],
                ['tags_json', 'count', 'sum', 'min', 'max', 'p50', 'p95', 'errors'],
            );
        }

        return count($upserts);
    }

    /**
     * Nearest-rank percentile of sorted values.
     *
     * @param list<float> $sorted
     */
    public static function percentile(array $sorted, float $quantile): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }

        return $sorted[max(0, (int) ceil($quantile * $n) - 1)];
    }

    public static function floor(int $timestamp, int $length): int
    {
        return intdiv($timestamp, $length) * $length;
    }

    public static function format(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function timestamp(string $dateTime): int
    {
        $timestamp = strtotime($dateTime.' UTC');

        return $timestamp === false ? 0 : $timestamp;
    }

    private function db(): ConnectionInterface
    {
        return $this->database->connection($this->connection);
    }
}
