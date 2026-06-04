<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

use Larena\Monitoring\Enums\IncidentState;
use Larena\Monitoring\Enums\Severity;

final readonly class IncidentRecord
{
    public function __construct(
        public string $id,
        public string $correlationKey,
        public string $sourcePackage,
        public Severity $severity,
        public IncidentState $state,
        public ?string $notificationRequestRef = null,
        public ?string $auditCorrelationRef = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === IncidentState::Open || $this->state === IncidentState::Acknowledged;
    }
}
