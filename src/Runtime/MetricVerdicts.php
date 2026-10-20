<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Larena\Monitoring\Contracts\MetricDescriptor;

/**
 * One verdict per metric for a window, as in the Federation metrics report:
 * - no_data: the metric was never recorded (within retention);
 * - unused: it has history but no events in the window;
 * - degraded: the error share is above the threshold (5% by default, over at least 5 events) or p95 is above the
 *   declared p95_ms;
 * - working: otherwise.
 */
final class MetricVerdicts
{
    public const string WORKING = 'working';

    public const string DEGRADED = 'degraded';

    public const string UNUSED = 'unused';

    public const string NO_DATA = 'no_data';

    public const float DEFAULT_ERROR_RATE = 0.05;

    public const int MIN_EVENTS_FOR_ERROR_SHARE = 5;

    /** @return array{verdict: string, reasons: list<string>} */
    public static function judge(MetricDescriptor $descriptor, bool $everRecorded, int $count, int $errors, ?float $p95): array
    {
        if (!$everRecorded) {
            return ['verdict' => self::NO_DATA, 'reasons' => []];
        }
        if ($count === 0) {
            return ['verdict' => self::UNUSED, 'reasons' => []];
        }
        $reasons = [];
        $errorRate = $descriptor->thresholds['error_rate'] ?? self::DEFAULT_ERROR_RATE;
        if ($count >= self::MIN_EVENTS_FOR_ERROR_SHARE && $errors / $count > $errorRate) {
            $reasons[] = 'error_share_above_threshold';
        }
        $p95Limit = $descriptor->thresholds['p95_ms'] ?? null;
        if (is_float($p95Limit) && $p95 !== null && $p95 > $p95Limit) {
            $reasons[] = 'p95_above_threshold';
        }

        return ['verdict' => $reasons === [] ? self::WORKING : self::DEGRADED, 'reasons' => $reasons];
    }
}
