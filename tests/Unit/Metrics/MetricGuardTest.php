<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests\Unit\Metrics;

use Larena\Monitoring\Runtime\MetricCatalog;
use Larena\Monitoring\Runtime\MetricGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricGuardTest extends TestCase
{
    private const string LONG_ID = 'demo_long_metric_identifier_for_sizes.second_segment_with_many_letters_abc'
        .'.third_segment_with_many_letters_xyz.fourth_segment_with_many_letters_qrs';

    public static function catalog(): MetricCatalog
    {
        $catalog = new MetricCatalog();
        $catalog->register('larena/monitoring', dirname(__DIR__, 3).'/monitoring.yaml');
        $catalog->register('larena/demo', dirname(__DIR__, 2).'/Fixtures/demo-monitoring.yaml');

        return $catalog;
    }

    public function test_admits_a_declared_metric_with_code_tags(): void
    {
        $guard = new MetricGuard(self::catalog());

        self::assertNull($guard->check('demo.request', 'timing', 12.5, ['outcome' => 'ok', 'route_group' => 'admin.records', 'operation' => 'list-records']));
        self::assertNull($guard->check('demo.hits', 'count', 1, []));
        self::assertNull($guard->check('monitoring.dropped', 'count', 1, ['reason' => 'buffer_full']));
    }

    /** @return iterable<string, array{string, string, float, array<mixed>, string}> */
    public static function rejections(): iterable
    {
        yield 'absolute path' => ['demo.hits', 'count', 1, ['operation' => '/var/www/larena/storage/app'], 'tag_value_invalid'];
        yield 'relative path' => ['demo.hits', 'count', 1, ['operation' => 'storage/app/file'], 'tag_value_invalid'];
        yield 'e-mail' => ['demo.hits', 'count', 1, ['operation' => 'rim@zabarov.com'], 'tag_value_invalid'];
        yield 'free text' => ['demo.hits', 'count', 1, ['reason' => 'disk is almost full'], 'tag_value_invalid'];
        yield 'cyrillic text' => ['demo.hits', 'count', 1, ['reason' => 'ошибка'], 'tag_value_invalid'];
        yield 'numeric id' => ['demo.hits', 'count', 1, ['operation' => 'record_1234567'], 'tag_value_identifier'];
        yield 'uuid part' => ['demo.hits', 'count', 1, ['operation' => 'a3f9c2d1-e8b4'], 'tag_value_identifier'];
        yield 'extra tag key' => ['demo.hits', 'count', 1, ['user' => 'admin'], 'tag_key_undeclared'];
        yield 'tag key of another metric' => ['demo.hits', 'count', 1, ['route_group' => 'admin'], 'tag_key_undeclared'];
        yield 'invalid tag key' => ['demo.hits', 'count', 1, ['Route Group' => 'admin'], 'tag_key_invalid'];
        yield 'non-string value' => ['demo.hits', 'count', 1, ['operation' => 12], 'tag_value_invalid'];
        yield 'unknown outcome' => ['demo.request', 'timing', 1, ['outcome' => 'failed'], 'outcome_invalid'];
        yield 'undeclared id' => ['demo.unknown', 'count', 1, [], 'id_undeclared'];
        yield 'invalid id' => ['Demo Hits', 'count', 1, [], 'id_invalid'];
        yield 'type mismatch' => ['demo.request', 'count', 1, [], 'type_mismatch'];
        yield 'negative count' => ['demo.hits', 'count', -1, [], 'value_invalid'];
        yield 'not finite' => ['demo.request', 'timing', INF, [], 'value_invalid'];
        yield 'more than eight tags' => ['demo.request', 'timing', 1, [
            'package' => 'a', 'subsystem' => 'b', 'operation' => 'c', 'outcome' => 'ok', 'reason' => 'e', 'route_group' => 'f',
            'x1' => 'g', 'x2' => 'h', 'x3' => 'i',
        ], 'too_many_tags'];
    }

    /** @param array<mixed> $tags */
    #[DataProvider('rejections')]
    public function test_rejects(string $id, string $kind, float $value, array $tags, string $reason): void
    {
        /** @var 'count'|'timing'|'gauge' $kind */
        self::assertSame($reason, (new MetricGuard(self::catalog()))->check($id, $kind, $value, $tags));
    }

    public function test_rejects_a_record_above_one_kilobyte(): void
    {
        $tags = [];
        foreach (['alpha_tag_key_with_a_long_descriptive_nm', 'bravo_tag_key_with_a_long_descriptive_nm',
            'charlie_tag_key_with_a_long_descript_nm', 'delta_tag_key_with_a_long_descriptive_nm',
            'echo_tag_key_with_a_long_descriptive_nam', 'foxtrot_tag_key_with_a_long_descript_nm',
            'golf_tag_key_with_a_long_descriptive_nam', 'hotel_tag_key_with_a_long_descriptive_nm'] as $key) {
            $tags[$key] = 'v'.str_repeat('x', 59);
        }
        $guard = new MetricGuard(self::catalog());

        self::assertSame('record_too_large', $guard->check(self::LONG_ID, 'count', 1, $tags));
        self::assertNull($guard->check(self::LONG_ID, 'count', 1, array_slice($tags, 0, 2, true)));
    }

    public function test_the_catalog_skips_invalid_declarations(): void
    {
        $catalog = self::catalog();

        self::assertNull($catalog->find('demo.bad_type'));
        self::assertContains('larena/demo:demo.bad_type:type_invalid', $catalog->problems());
        self::assertContains('larena/demo:metric_id_invalid', $catalog->problems());
        $request = $catalog->find('demo.request');
        self::assertNotNull($request);
        self::assertSame('larena/demo', $request->package);
        self::assertSame(['route_group'], $request->tagKeys);
        self::assertSame(['p95_ms' => 200.0, 'error_rate' => 0.05], $request->thresholds);
        self::assertSame(7, $catalog->retention('demo.request')->rawSampleDays);
        self::assertSame(90, $catalog->retention('demo.request')->aggregateDays);
    }

    public function test_an_unreadable_catalog_is_a_problem_not_an_exception(): void
    {
        $catalog = new MetricCatalog();
        $catalog->register('larena/missing', '/nonexistent/monitoring.yaml');

        self::assertSame([], $catalog->all());
        self::assertSame(['larena/missing:catalog_unreadable'], $catalog->problems());
    }
}
