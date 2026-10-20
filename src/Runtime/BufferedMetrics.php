<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Closure;
use Illuminate\Support\Carbon;
use Larena\Monitoring\Contracts\Metrics;
use Throwable;

/**
 * Keeps admitted records in memory until SampleWriter writes them in one bulk insert when the request, command or
 * job ends. Identical counters (same id and tags) are pre-aggregated into one record. The buffer holds at most
 * `limit` records; a record beyond that, or one the guard refuses, is dropped and counted as monitoring.dropped
 * with the reason code. Timings and gauges with outcome ok are sampled at `sampleRate`; errors are always kept.
 */
final class BufferedMetrics implements Metrics
{
    public const string DROPPED = 'monitoring.dropped';

    /** @var array<string, array{metric_id: string, type: string, value: float, tags_json: string, tag_hash: string, recorded_at: string}> */
    private array $rows = [];

    private int $sequence = 0;

    /** @var Closure(): float */
    private Closure $random;

    /** @param (Closure(): float)|null $random a number in [0, 1) */
    public function __construct(
        private readonly MetricGuard $guard,
        private readonly MetricCatalog $catalog,
        private readonly int $limit = 500,
        private readonly float $sampleRate = 1.0,
        ?Closure $random = null,
    ) {
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    public function count(string $id, int $n = 1, array $tags = []): void
    {
        $this->admit('count', $id, (float) $n, $tags);
    }

    public function timing(string $id, float $ms, array $tags = []): void
    {
        $this->admit('timing', $id, $ms, $tags);
    }

    public function gauge(string $id, float $value, array $tags = []): void
    {
        $this->admit('gauge', $id, $value, $tags);
    }

    public function measure(string $id, callable $operation, array $tags = []): mixed
    {
        $started = hrtime(true);
        try {
            $result = $operation();
        } catch (Throwable $error) {
            $this->timing($id, self::elapsedMs($started), ['outcome' => 'error', 'reason' => self::reasonCode($error)] + $tags);

            throw $error;
        }
        $this->timing($id, self::elapsedMs($started), ['outcome' => 'ok'] + $tags);

        return $result;
    }

    /**
     * Buffers one record; false when it was dropped (refused, over the limit or not sampled).
     *
     * @param 'count'|'timing'|'gauge' $kind
     * @param array<mixed> $tags
     */
    public function admit(string $kind, string $id, float $value, array $tags): bool
    {
        // The guard admits only string keys and string values, so $codes below equals $tags for admitted records.
        try {
            $reason = $this->guard->check($id, $kind, $value, $tags);
            if ($reason !== null) {
                $this->dropped($reason);

                return false;
            }
            $codes = [];
            foreach ($tags as $key => $code) {
                if (is_string($key) && is_string($code)) {
                    $codes[$key] = $code;
                }
            }
            if ($kind !== 'count' && $this->sampleRate < 1.0 && !in_array($codes['outcome'] ?? 'ok', ['error', 'timeout'], true)
                && ($this->random)() >= $this->sampleRate) {
                return false;
            }
            $type = $this->catalog->find($id)?->type->value ?? 'gauge';

            return $this->push($kind === 'count', $id, $type, $value, $codes, false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Counts an internal monitoring event; never refused by the limit. */
    public function internal(string $id, int $n, string $reason): void
    {
        $this->push(true, $id, 'counter', (float) $n, ['reason' => $reason], true);
    }

    /**
     * Hands the buffered records over and empties the buffer.
     *
     * @return list<array{metric_id: string, type: string, value: float, tags_json: string, tag_hash: string, recorded_at: string}>
     */
    public function drain(): array
    {
        $rows = array_values($this->rows);
        $this->rows = [];
        $this->sequence = 0;

        return $rows;
    }

    public function pending(): int
    {
        return count($this->rows);
    }

    /** @param array<string, string> $tags */
    private function push(bool $aggregate, string $id, string $type, float $value, array $tags, bool $internal): bool
    {
        ksort($tags);
        $tagsJson = json_encode((object) $tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tagHash = sha1($tagsJson);
        $key = $aggregate ? 'c|'.$id.'|'.$tagHash : 's|'.$this->sequence++;
        if ($aggregate && isset($this->rows[$key])) {
            $this->rows[$key]['value'] += $value;

            return true;
        }
        if (!$internal && count($this->rows) >= $this->limit) {
            $this->dropped('buffer_full');

            return false;
        }
        $this->rows[$key] = [
            'metric_id' => $id,
            'type' => $type,
            'value' => $value,
            'tags_json' => $tagsJson,
            'tag_hash' => $tagHash,
            'recorded_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ];

        return true;
    }

    private function dropped(string $reason): void
    {
        $this->internal(self::DROPPED, 1, $reason);
    }

    private static function elapsedMs(int|float $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }

    /** The exception class as a short code: QueryException -> query_exception. */
    public static function reasonCode(Throwable $error): string
    {
        $class = $error::class;
        if (str_contains($class, '@')) {
            // An anonymous class name carries the file path: use its parent class instead.
            $class = get_parent_class($error) ?: 'Exception';
        }
        $short = ($slash = strrchr($class, '\\')) === false ? $class : substr($slash, 1);
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $short));
        $code = trim((string) preg_replace('/[^a-z0-9_]+/', '_', $snake), '_');
        $code = substr($code, 0, 60);

        return preg_match(MetricGuard::TAG_VALUE_PATTERN, $code) === 1 ? $code : 'exception';
    }
}
