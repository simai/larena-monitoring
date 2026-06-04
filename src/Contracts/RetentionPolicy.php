<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

final readonly class RetentionPolicy
{
    public function __construct(
        public int $rawSampleDays,
        public int $aggregateDays,
        public bool $privacySafeSummaryRequired = true,
    ) {
    }

    public function bounded(): bool
    {
        return $this->rawSampleDays > 0 && $this->aggregateDays >= $this->rawSampleDays;
    }
}
