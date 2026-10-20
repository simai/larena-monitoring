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
use RuntimeException;

final class RequestTimingMiddlewareTest extends TestCase
{
    /** @param Application $app */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $kernel = $app->make(Kernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);
        $kernel->pushMiddleware(RequestTimingMiddleware::class);
    }

    /** @param Router $router */
    protected function defineRoutes($router): void
    {
        $router->get('/records/{record}', static fn (string $record): string => 'record '.$record)->name('demo.records.show');
        $router->get('/plain', static fn (): string => 'plain');
        $router->get('/broken', static function (): never {
            throw new RuntimeException('broken');
        })->name('demo.broken');
    }

    /** @return list<array{metric_id: string, tags: array<string, string>, value: float}> */
    private function rows(): array
    {
        return DB::table(SampleWriter::TABLE)->orderBy('id')->get()->map(static fn ($row): array => [
            'metric_id' => (string) $row->metric_id,
            'tags' => json_decode((string) $row->tags_json, true, 512, JSON_THROW_ON_ERROR),
            'value' => (float) $row->value,
        ])->all();
    }

    public function test_a_named_route_records_one_sample_with_code_tags(): void
    {
        $this->get('/records/1234567?email=rim@zabarov.com&q=secret+text')->assertOk();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(RequestTimingMiddleware::METRIC, $rows[0]['metric_id']);
        self::assertEqualsCanonicalizing(
            ['route' => 'demo.records.show', 'method' => 'get', 'status_class' => 'http_2xx', 'outcome' => 'ok'],
            $rows[0]['tags'],
        );
        self::assertGreaterThanOrEqual(0.0, $rows[0]['value']);
    }

    public function test_no_url_or_parameter_reaches_the_table(): void
    {
        $this->get('/records/1234567?email=rim@zabarov.com&q=secret+text')->assertOk();
        $this->get('/plain?token=abcdef0123456789')->assertOk();

        $stored = (string) json_encode(DB::table(SampleWriter::TABLE)->get()->all());
        foreach (['records/', '1234567', 'rim@zabarov.com', 'secret', '/plain', 'abcdef0123456789', 'token'] as $leak) {
            self::assertStringNotContainsString($leak, $stored);
        }
        self::assertSame('unnamed', $this->rows()[1]['tags']['route']);
    }

    public function test_a_server_error_counts_as_an_error(): void
    {
        $this->get('/broken')->assertStatus(500);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertEqualsCanonicalizing(
            ['route' => 'demo.broken', 'method' => 'get', 'status_class' => 'http_5xx', 'outcome' => 'error'],
            $rows[0]['tags'],
        );
    }

    public function test_a_missing_route_is_an_unnamed_client_error(): void
    {
        $this->get('/no/such/page')->assertNotFound();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('unnamed', $rows[0]['tags']['route']);
        self::assertSame('http_4xx', $rows[0]['tags']['status_class']);
        self::assertSame('ok', $rows[0]['tags']['outcome']);
    }
}
