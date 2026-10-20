<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Larena\Monitoring\Http\RequestTimingMiddleware;
use Larena\Monitoring\Runtime\SampleWriter;
use Larena\Monitoring\Tests\TestCase;

final class RequestTimingKillSwitchTest extends TestCase
{
    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('larena-monitoring.enabled', false);
        $kernel = $app->make(Kernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);
        $kernel->pushMiddleware(RequestTimingMiddleware::class);
    }

    /** @param Router $router */
    protected function defineRoutes($router): void
    {
        $router->get('/records/{record}', static fn (string $record): string => 'record '.$record)->name('demo.records.show');
    }

    public function test_the_kill_switch_writes_no_request_samples(): void
    {
        $this->get('/records/7')->assertOk();

        self::assertSame(0, DB::table(SampleWriter::TABLE)->count());
    }
}
