<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Monitoring\Runtime\NullMetrics;
use Larena\Monitoring\Runtime\SampleWriter;
use Larena\Monitoring\Tests\TestCase;

final class MetricsKillSwitchTest extends TestCase
{
    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('larena-monitoring.enabled', false);
    }

    public function test_the_kill_switch_records_nothing(): void
    {
        $metrics = $this->app->make(Metrics::class);
        self::assertInstanceOf(NullMetrics::class, $metrics);

        $metrics->count('demo.hits', 5);
        self::assertSame(7, $metrics->measure('demo.request', static fn (): int => 7));
        $this->app->terminate();

        self::assertSame(0, DB::table(SampleWriter::TABLE)->count());
        $commands = array_map(static fn ($event): string => (string) $event->command, $this->app->make(Schedule::class)->events());
        self::assertSame([], array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'larena:monitoring'))));
    }
}
