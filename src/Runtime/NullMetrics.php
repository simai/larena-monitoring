<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Larena\Monitoring\Contracts\Metrics;

/** Metrics that record nothing: used when monitoring is switched off or its tables are absent. */
final class NullMetrics implements Metrics
{
    public function count(string $id, int $n = 1, array $tags = []): void
    {
    }

    public function timing(string $id, float $ms, array $tags = []): void
    {
    }

    public function gauge(string $id, float $value, array $tags = []): void
    {
    }

    public function measure(string $id, callable $operation, array $tags = []): mixed
    {
        return $operation();
    }
}
