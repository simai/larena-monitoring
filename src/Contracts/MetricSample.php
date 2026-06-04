<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

final readonly class MetricSample
{
    /**
     * @param array<string, string> $tags
     */
    public function __construct(
        public string $metricId,
        public int|float|string $value,
        public string $recordedAtUtc,
        public array $tags = [],
        public bool $sanitized = true,
    ) {
    }
}
