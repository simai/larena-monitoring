<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

use Larena\Monitoring\Enums\MetricType;

final readonly class MetricDescriptor
{
    /**
     * @param list<string> $tagKeys
     * @param array<string, mixed> $thresholds
     */
    public function __construct(
        public string $id,
        public string $package,
        public MetricType $type,
        public array $tagKeys = [],
        public array $thresholds = [],
        public ?string $retentionPolicyRef = null,
    ) {
    }
}
