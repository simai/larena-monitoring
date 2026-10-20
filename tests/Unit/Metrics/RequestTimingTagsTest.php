<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests\Unit\Metrics;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Larena\Monitoring\Http\RequestTimingMiddleware;
use Larena\Monitoring\Runtime\MetricGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTimingTagsTest extends TestCase
{
    /** @return iterable<string, array{string|null, string}> */
    public static function names(): iterable
    {
        yield 'route name code' => ['larena.admin.records.index', 'larena.admin.records.index'];
        yield 'upper case and spaces' => ['Admin Records:Index', 'admin_records_index'];
        yield 'cyrillic' => ['раздел', 'unnamed'];
        yield 'starts with a digit' => ['2fa.verify', 'unnamed'];
        yield 'identifier inside' => ['export.1234567', 'unnamed'];
        yield 'empty' => ['', 'unnamed'];
        yield 'none' => [null, 'unnamed'];
        yield 'cut at sixty' => [str_repeat('a', 80), str_repeat('a', 60)];
    }

    #[DataProvider('names')]
    public function test_names_become_tag_value_codes(?string $name, string $expected): void
    {
        $code = MetricGuard::code($name);

        self::assertSame($expected, $code);
        self::assertMatchesRegularExpression(MetricGuard::TAG_VALUE_PATTERN, $code);
    }

    public function test_tags_use_the_route_name_and_never_the_uri(): void
    {
        $request = Request::create('/admin/records/12345?email=rim@zabarov.com', 'POST');
        $route = (new Route(['POST'], 'admin/records/{record}', static fn (): string => 'ok'))->name('admin.records.update');
        $request->setRouteResolver(static fn (): Route => $route->bind($request));

        self::assertSame(
            ['route' => 'admin.records.update', 'method' => 'post', 'status_class' => 'http_2xx', 'outcome' => 'ok'],
            RequestTimingMiddleware::tags($request, 204),
        );
        self::assertSame('http_4xx', RequestTimingMiddleware::tags($request, 404)['status_class']);
        self::assertSame('ok', RequestTimingMiddleware::tags($request, 404)['outcome']);
        self::assertSame(['status_class' => 'http_5xx', 'outcome' => 'error'], array_intersect_key(
            RequestTimingMiddleware::tags($request, 503),
            ['status_class' => true, 'outcome' => true],
        ));
    }

    public function test_a_request_without_a_route_is_unnamed(): void
    {
        $tags = RequestTimingMiddleware::tags(Request::create('/missing/page'), 404);

        self::assertSame('unnamed', $tags['route']);
        self::assertSame('get', $tags['method']);
    }
}
