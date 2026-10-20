<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Larena\Monitoring\Contracts\MetricDescriptor;
use Larena\Monitoring\Contracts\MetricSample;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Monitoring\Contracts\MonitoringGateway;
use Larena\Monitoring\Enums\MetricType;
use Larena\Monitoring\Runtime\BufferedMetrics;
use Larena\Monitoring\Runtime\MetricsReport;
use Larena\Monitoring\Runtime\NullMetrics;
use Larena\Monitoring\Runtime\RetentionPruner;
use Larena\Monitoring\Runtime\RollupBuilder;
use Larena\Monitoring\Runtime\SampleWriter;
use Larena\Monitoring\Tests\TestCase;
use RuntimeException;

final class MetricsRuntimeTest extends TestCase
{
    private function metrics(): Metrics
    {
        return $this->app->make(Metrics::class);
    }

    private function flush(): int
    {
        return $this->app->make(SampleWriter::class)->flush();
    }

    /** @return array<string, float> metric and tags => summed value */
    private function samples(): array
    {
        $result = [];
        foreach (DB::table(SampleWriter::TABLE)->orderBy('id')->get() as $row) {
            $key = $row->metric_id.' '.$row->tags_json;
            $result[$key] = ($result[$key] ?? 0.0) + (float) $row->value;
        }

        return $result;
    }

    public function test_records_are_written_in_one_insert_when_the_application_terminates(): void
    {
        self::assertInstanceOf(BufferedMetrics::class, $this->metrics());
        $this->metrics()->count('demo.hits', 2, ['operation' => 'read']);
        $this->metrics()->timing('demo.request', 12.5, ['outcome' => 'ok', 'route_group' => 'admin']);
        $this->metrics()->gauge('demo.queue.backlog', 7, ['queue_profile' => 'default']);
        $this->metrics()->count('demo.hits', 1, ['operation' => '/var/www/secret']);
        self::assertSame(0, DB::table(SampleWriter::TABLE)->count());

        DB::enableQueryLog();
        $this->app->terminate();
        $inserts = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'insert'));
        DB::disableQueryLog();

        self::assertCount(1, $inserts);
        self::assertSame([
            'demo.hits {"operation":"read"}' => 2.0,
            'demo.request {"outcome":"ok","route_group":"admin"}' => 12.5,
            'demo.queue.backlog {"queue_profile":"default"}' => 7.0,
            'monitoring.dropped {"reason":"tag_value_invalid"}' => 1.0,
        ], $this->samples());
    }

    public function test_a_database_failure_is_swallowed_and_counted_on_the_next_write(): void
    {
        $this->metrics()->count('demo.hits');
        Schema::rename(SampleWriter::TABLE, 'larena_monitoring_samples_away');

        $this->app->terminate();
        self::assertSame(['query_exception' => 1], $this->app->make(SampleWriter::class)->pendingFailures());

        Schema::rename('larena_monitoring_samples_away', SampleWriter::TABLE);
        $this->metrics()->count('demo.hits');
        self::assertSame(2, $this->flush());
        self::assertSame([
            'demo.hits {}' => 1.0,
            'monitoring.write_failed {"reason":"query_exception"}' => 1.0,
        ], $this->samples());
    }

    public function test_measure_rethrows_and_the_error_reaches_the_table(): void
    {
        $caught = null;
        try {
            $this->metrics()->measure('demo.request', static function (): never {
                throw new RuntimeException('Не удалось');
            }, ['route_group' => 'admin']);
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('Не удалось', $caught->getMessage());
        $this->flush();

        self::assertSame('{"outcome":"error","reason":"runtime_exception","route_group":"admin"}', DB::table(SampleWriter::TABLE)->value('tags_json'));
    }

    public function test_builds_minute_hour_and_day_rollups_for_a_day(): void
    {
        $day = Carbon::parse('2026-10-19 00:00:00', 'UTC');
        foreach ([[0, 10.0, 'ok'], [61, 20.0, 'ok'], [3600, 30.0, 'error'], [7200 + 30, 40.0, 'ok'], [86399, 1000.0, 'timeout']] as [$offset, $ms, $outcome]) {
            $this->travelTo($day->copy()->addSeconds($offset));
            $this->metrics()->timing('demo.request', $ms, ['outcome' => $outcome]);
            $this->metrics()->count('demo.hits', 2);
            $this->flush();
        }
        $this->travelTo(Carbon::parse('2026-10-20 00:05:00', 'UTC'));

        $written = $this->app->make(RollupBuilder::class)->run();
        self::assertSame(['m' => 10, 'h' => 8, 'd' => 4], $written);

        $dayRow = DB::table(RollupBuilder::TABLE)->where('period', 'd')->where('metric_id', 'demo.request')->get();
        self::assertSame(3, $dayRow->count(), 'one day rollup per tag set');
        $all = DB::table(RollupBuilder::TABLE)->where('period', 'd')->where('metric_id', 'demo.request')
            ->selectRaw('SUM(count) AS events, SUM(errors) AS errors, MIN(min) AS low, MAX(max) AS high')->first();
        self::assertNotNull($all);
        self::assertSame(5, (int) $all->events);
        self::assertSame(2, (int) $all->errors);
        self::assertSame(10.0, (float) $all->low);
        self::assertSame(1000.0, (float) $all->high);

        $ok = DB::table(RollupBuilder::TABLE)->where('period', 'd')->where('metric_id', 'demo.request')
            ->where('tags_json', '{"outcome":"ok"}')->first();
        self::assertNotNull($ok);
        self::assertSame('2026-10-19 00:00:00', $ok->bucket_start);
        self::assertSame(3, (int) $ok->count);
        self::assertSame(70.0, (float) $ok->sum);
        self::assertSame(20.0, (float) $ok->p50);
        self::assertSame(40.0, (float) $ok->p95);

        $hits = DB::table(RollupBuilder::TABLE)->where('period', 'd')->where('metric_id', 'demo.hits')->first();
        self::assertNotNull($hits);
        self::assertSame(10, (int) $hits->count);
        self::assertNull($hits->p95);

        self::assertSame(['m' => 0, 'h' => 0, 'd' => 0], $this->app->make(RollupBuilder::class)->run(), 'built buckets are not rebuilt');
    }

    public function test_an_open_bucket_waits_for_the_grace_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:30', 'UTC'));
        $this->metrics()->count('demo.hits');
        $this->flush();

        $this->travelTo(Carbon::parse('2026-10-20 10:02:00', 'UTC'));
        self::assertSame(['m' => 0, 'h' => 0, 'd' => 0], $this->app->make(RollupBuilder::class)->run());
        $this->travelTo(Carbon::parse('2026-10-20 10:03:00', 'UTC'));
        self::assertSame(['m' => 1, 'h' => 0, 'd' => 0], $this->app->make(RollupBuilder::class)->run());
    }

    public function test_prune_removes_old_raw_samples_and_keeps_rollups_for_ninety_days(): void
    {
        $now = Carbon::parse('2026-10-20 12:00:00', 'UTC');
        $sample = static fn (string $at): array => ['metric_id' => 'demo.hits', 'type' => 'counter', 'value' => 1.0, 'tags_json' => '{}', 'tag_hash' => sha1('{}'), 'recorded_at' => $at];
        DB::table(SampleWriter::TABLE)->insert([$sample('2026-10-12 11:00:00'), $sample('2026-10-14 12:00:00')]);
        $rollup = static fn (string $period, string $at): array => ['metric_id' => 'demo.hits', 'tag_hash' => sha1('{}'), 'tags_json' => '{}', 'period' => $period, 'bucket_start' => $at, 'count' => 1, 'sum' => 1.0, 'errors' => 0];
        DB::table(RollupBuilder::TABLE)->insert([
            $rollup('m', '2026-10-12 11:00:00'),
            $rollup('m', '2026-10-19 11:00:00'),
            $rollup('h', '2026-10-12 11:00:00'),
            $rollup('d', '2026-07-01 00:00:00'),
            $rollup('d', '2026-07-30 00:00:00'),
        ]);
        $this->travelTo($now);

        $this->artisan('larena:monitoring:prune')->assertSuccessful();

        self::assertSame(['2026-10-14 12:00:00'], DB::table(SampleWriter::TABLE)->pluck('recorded_at')->all());
        self::assertSame(
            ['d 2026-07-30 00:00:00', 'h 2026-10-12 11:00:00', 'm 2026-10-19 11:00:00'],
            DB::table(RollupBuilder::TABLE)->orderBy('period')->get()->map(static fn ($row): string => $row->period.' '.$row->bucket_start)->all(),
        );
        self::assertSame(['samples' => 0, 'rollups' => 0], $this->app->make(RetentionPruner::class)->prune());
    }

    public function test_verdicts_and_report_json(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'UTC'));
        $this->metrics()->count('demo.idle', 3);
        $this->flush();

        $this->travelTo(Carbon::parse('2026-10-19 10:00:00', 'UTC'));
        for ($i = 0; $i < 20; $i++) {
            $this->metrics()->timing('demo.request', 50.0, ['outcome' => $i < 3 ? 'error' : 'ok']);
            $this->metrics()->timing('demo.slow', 500.0, ['outcome' => 'ok']);
            $this->metrics()->count('demo.hits', 1, ['outcome' => $i === 0 ? 'error' : 'ok']);
        }
        $this->flush();
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        $this->app->make(RollupBuilder::class)->run();
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
        $this->app->make(RollupBuilder::class)->run();

        self::assertSame(0, Artisan::call('larena:monitoring:report', ['--json' => true, '--package' => 'larena/demo']));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(MetricsReport::SCHEMA, $report['schema']);
        self::assertSame('larena.monitoring_metrics_report', $report['schema']);
        self::assertSame(1, $report['schema_version']);
        self::assertSame(7, $report['window']['days']);
        self::assertTrue($report['tables_present']);
        $verdicts = array_column($report['metrics'], 'verdict', 'id');
        self::assertSame('degraded', $verdicts['demo.request']);
        self::assertSame('degraded', $verdicts['demo.slow']);
        self::assertSame('working', $verdicts['demo.hits']);
        self::assertSame('unused', $verdicts['demo.idle']);
        self::assertSame('no_data', $verdicts['demo.never']);
        self::assertArrayNotHasKey('monitoring.dropped', $verdicts);
        $byId = array_column($report['metrics'], null, 'id');
        self::assertSame(['error_share_above_threshold'], $byId['demo.request']['reasons']);
        self::assertSame(20, $byId['demo.request']['count']);
        self::assertSame(0.15, $byId['demo.request']['error_share']);
        self::assertSame(['p95_above_threshold'], $byId['demo.slow']['reasons']);
        self::assertSame(500.0, (float) $byId['demo.slow']['p95']);
        self::assertSame(0.05, $byId['demo.hits']['error_share']);
        self::assertSame(50.0, (float) $byId['demo.request']['p50']);
        self::assertSame(500.0, (float) $byId['demo.slow']['p50']);
        self::assertNull($byId['demo.hits']['p50']);
        self::assertNull($byId['demo.never']['p50']);
        self::assertSame('2026-10-19T10:00:00Z', $byId['demo.request']['last_event_at']);
        self::assertSame('2026-10-08T10:00:00Z', $byId['demo.idle']['last_event_at']);
        self::assertNull($byId['demo.never']['last_event_at']);
        self::assertSame(['working' => 1, 'degraded' => 2, 'unused' => 1, 'no_data' => 3], $report['summary']);
        self::assertContains('larena/demo:demo.bad_type:type_invalid', $report['catalog_problems']);

        self::assertSame(0, Artisan::call('larena:monitoring:report'));
        self::assertStringContainsString('demo.request', Artisan::output());
    }

    public function test_p50_is_count_weighted_and_last_event_falls_back_to_rollups(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 10:00:10', 'UTC'));
        for ($i = 0; $i < 10; $i++) {
            $this->metrics()->timing('demo.request', 10.0, ['outcome' => 'ok']);
        }
        $this->flush();
        $this->travelTo(Carbon::parse('2026-10-19 11:00:20', 'UTC'));
        for ($i = 0; $i < 30; $i++) {
            $this->metrics()->timing('demo.request', 40.0, ['outcome' => 'ok']);
        }
        $this->flush();
        $this->travelTo(Carbon::parse('2026-10-19 14:00:00', 'UTC'));
        $this->app->make(RollupBuilder::class)->run();

        $now = Carbon::parse('2026-10-19 14:00:00', 'UTC');
        $row = $this->reportRow('demo.request', $now);
        // Hour buckets: p50 10 over 10 events and p50 40 over 30 events → (10*10 + 40*30) / 40.
        self::assertSame(32.5, $row['p50']);
        self::assertSame(40, $row['count']);
        self::assertSame('2026-10-19T11:00:20Z', $row['last_event_at']);

        // Raw samples pruned: the newest rollup bucket start is the last known moment.
        DB::table(SampleWriter::TABLE)->delete();
        $row = $this->reportRow('demo.request', $now);
        self::assertSame('2026-10-19T11:00:00Z', $row['last_event_at']);
        self::assertSame(32.5, $row['p50']);
    }

    /** @return array<string, mixed> */
    private function reportRow(string $id, Carbon $now): array
    {
        $report = $this->app->make(MetricsReport::class)->build(7, 'larena/demo', $now);
        $byId = array_column($report['metrics'], null, 'id');
        self::assertArrayHasKey($id, $byId);

        return $byId[$id];
    }

    public function test_the_gateway_records_through_the_buffer(): void
    {
        $gateway = $this->app->make(MonitoringGateway::class);
        $descriptor = new MetricDescriptor('demo.queue.backlog', 'larena/demo', MetricType::Gauge, ['queue_profile']);

        $kept = $gateway->record($descriptor, new MetricSample('demo.queue.backlog', 12, '2026-10-20T12:00:00Z', ['queue_profile' => 'default']));
        $refused = $gateway->record($descriptor, new MetricSample('demo.queue.backlog', 12, '2026-10-20T12:00:00Z', ['queue_profile' => 'Очередь писем']));
        $this->flush();

        self::assertTrue($kept->sanitized);
        self::assertFalse($refused->sanitized);
        self::assertSame([
            'demo.queue.backlog {"queue_profile":"default"}' => 12.0,
            'monitoring.dropped {"reason":"tag_value_invalid"}' => 1.0,
        ], $this->samples());
    }

    public function test_schedules_rollup_every_minute_and_prune_daily(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());
        $rollup = $events->first(static fn ($event): bool => str_contains((string) $event->command, 'larena:monitoring:rollup'));
        $prune = $events->first(static fn ($event): bool => str_contains((string) $event->command, 'larena:monitoring:prune'));

        self::assertNotNull($rollup);
        self::assertSame('* * * * *', $rollup->expression);
        self::assertTrue($rollup->withoutOverlapping);
        self::assertNotNull($prune);
        self::assertSame('0 0 * * *', $prune->expression);
    }

    public function test_missing_tables_give_null_metrics(): void
    {
        Schema::rename(SampleWriter::TABLE, 'larena_monitoring_samples_away');
        $this->app->forgetInstance(Metrics::class);

        self::assertInstanceOf(NullMetrics::class, $this->metrics());
        Schema::rename('larena_monitoring_samples_away', SampleWriter::TABLE);
    }
}
