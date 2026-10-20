<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests;

use Illuminate\Foundation\Application;
use Larena\Monitoring\Providers\MonitoringServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected string $databasePath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'larena-monitoring-');
        if ($path === false) {
            self::fail('Could not allocate a temporary SQLite database.');
        }

        $this->databasePath = $path;
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'monitoring_testing', '--force' => true])
            ->assertSuccessful();
    }

    protected function tearDown(): void
    {
        $databasePath = $this->databasePath;
        parent::tearDown();

        if (is_file($databasePath) && !unlink($databasePath)) {
            self::fail("Could not remove temporary SQLite database: {$databasePath}");
        }
    }

    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'monitoring_testing');
        $app['config']->set('database.connections.monitoring_testing', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('larena-monitoring.catalogs', ['larena/demo' => __DIR__.'/Fixtures/demo-monitoring.yaml']);
    }

    /** @param Application $app
     *  @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MonitoringServiceProvider::class];
    }
}
