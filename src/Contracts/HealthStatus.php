<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

use Larena\Monitoring\Enums\HealthState;

final readonly class HealthStatus
{
    /**
     * @param list<string> $reasons
     * @param list<string> $remediationHints
     */
    public function __construct(
        public string $checkId,
        public HealthState $state,
        public bool $sanitized,
        public array $reasons = [],
        public array $remediationHints = [],
    ) {
    }

    public function canOpenIncident(): bool
    {
        return $this->sanitized && $this->state->operationalProblem();
    }
}
