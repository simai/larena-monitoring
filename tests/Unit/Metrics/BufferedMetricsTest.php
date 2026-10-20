<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests\Unit\Metrics;

use Larena\Monitoring\Runtime\BufferedMetrics;
use Larena\Monitoring\Runtime\MetricGuard;
use Larena\Monitoring\Runtime\NullMetrics;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BufferedMetricsTest extends TestCase
{
    private function buffer(int $limit = 500, float $sampleRate = 1.0, float $random = 0.0): BufferedMetrics
    {
        $catalog = MetricGuardTest::catalog();

        return new BufferedMetrics(new MetricGuard($catalog), $catalog, $limit, $sampleRate, static fn (): float => $random);
    }

    /**
     * @param list<array{metric_id: string, type: string, value: float, tags_json: string, tag_hash: string, recorded_at: string}> $rows
     *
     * @return array<string, float>
     */
    private static function byMetricAndTags(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = $row['metric_id'].' '.$row['tags_json'];
            $result[$key] = ($result[$key] ?? 0.0) + $row['value'];
        }
        ksort($result);

        return $result;
    }

    public function test_null_metrics_records_nothing_and_returns_the_result(): void
    {
        $metrics = new NullMetrics();
        $metrics->count('anything.at_all', 5, ['free text' => 'is ignored']);
        $metrics->timing('x', 1.0);
        $metrics->gauge('x', 1.0);

        self::assertSame(42, $metrics->measure('x', static fn (): int => 42));
        $error = new RuntimeException('boom');
        $caught = null;
        try {
            $metrics->measure('x', static function () use ($error): never {
                throw $error;
            });
        } catch (RuntimeException $thrown) {
            $caught = $thrown;
        }
        self::assertSame($error, $caught);
    }

    public function test_identical_counters_are_pre_aggregated(): void
    {
        $buffer = $this->buffer();
        $buffer->count('demo.hits', 1, ['operation' => 'read']);
        $buffer->count('demo.hits', 2, ['operation' => 'read']);
        $buffer->count('demo.hits', 1, ['operation' => 'write']);

        self::assertSame(2, $buffer->pending());
        self::assertSame([
            'demo.hits {"operation":"read"}' => 3.0,
            'demo.hits {"operation":"write"}' => 1.0,
        ], self::byMetricAndTags($buffer->drain()));
        self::assertSame(0, $buffer->pending());
    }

    public function test_overflow_is_dropped_and_counted(): void
    {
        $buffer = $this->buffer(limit: 3);
        for ($i = 0; $i < 5; $i++) {
            $buffer->timing('demo.request', 10.0 + $i, ['outcome' => 'ok']);
        }
        $buffer->count('demo.hits', 1, ['user' => 'admin']);

        $rows = $buffer->drain();
        self::assertCount(5, $rows);
        $totals = self::byMetricAndTags($rows);
        self::assertSame(2.0, $totals['monitoring.dropped {"reason":"buffer_full"}']);
        self::assertSame(1.0, $totals['monitoring.dropped {"reason":"tag_key_undeclared"}']);
    }

    public function test_measure_records_the_error_and_rethrows_it_unchanged(): void
    {
        $buffer = $this->buffer();
        self::assertSame('done', $buffer->measure('demo.request', static fn (): string => 'done', ['route_group' => 'admin']));
        $error = new RuntimeException('Не удалось /var/www/secret');
        $caught = null;
        try {
            $buffer->measure('demo.request', static function () use ($error): never {
                throw $error;
            });
        } catch (RuntimeException $thrown) {
            $caught = $thrown;
        }
        self::assertSame($error, $caught);

        $rows = $buffer->drain();
        self::assertCount(2, $rows);
        self::assertSame('{"outcome":"ok","route_group":"admin"}', $rows[0]['tags_json']);
        self::assertSame('{"outcome":"error","reason":"runtime_exception"}', $rows[1]['tags_json']);
        self::assertSame('duration', $rows[1]['type']);
    }

    public function test_ok_timings_are_sampled_but_errors_are_kept(): void
    {
        $buffer = $this->buffer(sampleRate: 0.25, random: 0.5);
        $buffer->timing('demo.request', 5.0, ['outcome' => 'ok']);
        $buffer->timing('demo.request', 5.0, ['outcome' => 'error']);
        $buffer->count('demo.hits');

        self::assertSame([
            'demo.hits {}' => 1.0,
            'demo.request {"outcome":"error"}' => 5.0,
        ], self::byMetricAndTags($buffer->drain()));
    }

    public function test_reason_codes_never_carry_paths(): void
    {
        $anonymous = new class ('x') extends RuntimeException {
        };

        self::assertSame('runtime_exception', BufferedMetrics::reasonCode($anonymous));
        self::assertSame('runtime_exception', BufferedMetrics::reasonCode(new RuntimeException('/etc/passwd')));
    }
}
