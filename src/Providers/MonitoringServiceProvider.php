<?php

declare(strict_types=1);

namespace Larena\Monitoring\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Larena\Monitoring\Access\AccessOperationRegistrar;
use Larena\Monitoring\Console\MetricsReportCommand;
use Larena\Monitoring\Console\PruneMetricsCommand;
use Larena\Monitoring\Console\RollupMetricsCommand;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Monitoring\Contracts\MonitoringGateway;
use Larena\Monitoring\Contracts\RetentionPolicy;
use Larena\Monitoring\Runtime\BufferedMetrics;
use Larena\Monitoring\Runtime\MetricCatalog;
use Larena\Monitoring\Runtime\MetricGuard;
use Larena\Monitoring\Runtime\MetricsReport;
use Larena\Monitoring\Runtime\NullMetrics;
use Larena\Monitoring\Runtime\RetentionPruner;
use Larena\Monitoring\Runtime\RollupBuilder;
use Larena\Monitoring\Runtime\RuntimeMonitoringGateway;
use Larena\Monitoring\Runtime\SampleWriter;
use Throwable;

/**
 * Runtime metrics: the metric catalog (package monitoring.yaml files), the guarded in-memory buffer written in one
 * bulk insert when the request, command or job ends, minute/hour/day rollups, retention and the report.
 * Metrics resolves to BufferedMetrics when monitoring is enabled and its tables exist, otherwise to NullMetrics.
 * The package's access operations (MonitoringOperations) are registered into larena/access only when it is bound.
 */
final class MonitoringServiceProvider extends ServiceProvider
{
    /** Job events after which buffered records are written, so long-running workers do not hold them. */
    private const array FLUSH_EVENTS = [
        'Illuminate\Queue\Events\JobProcessed',
        'Illuminate\Queue\Events\JobFailed',
        'Illuminate\Queue\Events\JobExceptionOccurred',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/larena-monitoring.php', 'larena-monitoring');

        $this->app->singleton(RetentionPolicy::class, fn (): RetentionPolicy => new RetentionPolicy(
            $this->intConfig('raw_days', 7),
            $this->intConfig('rollup_days', 90),
        ));
        $this->app->singleton(MetricCatalog::class, function (Application $app): MetricCatalog {
            $catalog = new MetricCatalog($app->make(RetentionPolicy::class));
            $catalog->register('larena/monitoring', __DIR__.'/../../monitoring.yaml');
            $catalogs = $app->make('config')->get('larena-monitoring.catalogs', []);
            foreach (is_array($catalogs) ? $catalogs : [] as $package => $path) {
                if (is_string($package) && is_string($path)) {
                    $catalog->register($package, $path);
                }
            }

            return $catalog;
        });
        $this->app->singleton(MetricGuard::class);
        $this->app->singleton(BufferedMetrics::class, fn (Application $app): BufferedMetrics => new BufferedMetrics(
            $app->make(MetricGuard::class),
            $app->make(MetricCatalog::class),
            $this->intConfig('buffer_limit', 500),
            min(1.0, max(0.0, (float) $app->make('config')->get('larena-monitoring.sample_rate', 1.0))),
        ));
        $this->app->singleton(SampleWriter::class, fn (Application $app): SampleWriter => new SampleWriter(
            $app->make(BufferedMetrics::class),
            $app->make(ConnectionResolverInterface::class),
            $this->connectionName(),
        ));
        $this->app->singleton(Metrics::class, fn (Application $app): Metrics => $this->enabled() && $this->tablesExist($app)
            ? $app->make(BufferedMetrics::class)
            : new NullMetrics());
        $this->app->bind(RollupBuilder::class, fn (Application $app): RollupBuilder => new RollupBuilder(
            $app->make(ConnectionResolverInterface::class),
            $this->connectionName(),
            $this->intConfig('raw_days', 7),
        ));
        $this->app->bind(RetentionPruner::class, fn (Application $app): RetentionPruner => new RetentionPruner(
            $app->make(ConnectionResolverInterface::class),
            $app->make(RetentionPolicy::class),
            $this->connectionName(),
        ));
        $this->app->bind(MetricsReport::class, fn (Application $app): MetricsReport => new MetricsReport(
            $app->make(MetricCatalog::class),
            $app->make(ConnectionResolverInterface::class),
            $this->connectionName(),
        ));
        $this->app->singleton(MonitoringGateway::class, fn (Application $app): MonitoringGateway => new RuntimeMonitoringGateway(
            $app->make(Metrics::class),
            $app->make(MetricsReport::class),
        ));

        $this->app->singleton(AccessOperationRegistrar::class);
        if (class_exists(AccessOperationRegistrar::REGISTRY_CLASS)) {
            $this->app->afterResolving(
                AccessOperationRegistrar::REGISTRY_CLASS,
                static fn (object $registry, Application $app): int => $app->make(AccessOperationRegistrar::class)->register($registry),
            );
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'larena-monitoring');
        if ($this->app->bound(AccessOperationRegistrar::REGISTRY_CLASS)) {
            $this->app->make(AccessOperationRegistrar::class)->register($this->app->make(AccessOperationRegistrar::REGISTRY_CLASS));
        }
        $this->publishes([__DIR__.'/../../config/larena-monitoring.php' => $this->app->configPath('larena-monitoring.php')], 'larena-monitoring-config');
        if ($this->app->runningInConsole()) {
            $this->commands([RollupMetricsCommand::class, PruneMetricsCommand::class, MetricsReportCommand::class]);
        }
        if (!$this->enabled()) {
            return;
        }

        $flush = function (): void {
            if ($this->app->resolved(BufferedMetrics::class)) {
                $this->app->make(SampleWriter::class)->flush();
            }
        };
        $this->app->terminating($flush);
        $this->app->make('events')->listen(self::FLUSH_EVENTS, $flush);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('larena:monitoring:rollup')->everyMinute()->withoutOverlapping();
            $schedule->command('larena:monitoring:prune')->daily()->withoutOverlapping();
        });
    }

    private function enabled(): bool
    {
        return $this->app->make('config')->get('larena-monitoring.enabled', true) !== false;
    }

    private function tablesExist(Application $app): bool
    {
        try {
            $connection = $app->make(ConnectionResolverInterface::class)->connection($this->connectionName());
            if (!$connection instanceof Connection) {
                return false;
            }
            $schema = $connection->getSchemaBuilder();

            return $schema->hasTable(SampleWriter::TABLE) && $schema->hasTable(RollupBuilder::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    private function connectionName(): ?string
    {
        $name = $this->app->make('config')->get('larena-monitoring.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = filter_var($this->app->make('config')->get('larena-monitoring.'.$key, $default), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? $default : $value;
    }
}
