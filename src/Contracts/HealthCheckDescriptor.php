<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

final readonly class HealthCheckDescriptor
{
    /**
     * @param list<string> $dependencies
     * @param array<string, mixed> $thresholds
     */
    public function __construct(
        public string $id,
        public string $package,
        public string $probeRef,
        public int $timeoutMs,
        public array $dependencies = [],
        public array $thresholds = [],
        public bool $safeDiagnosticsOnly = true,
    ) {
    }
}
