<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

final readonly class MonitoringDashboardSnapshot
{
    /**
     * @param list<HealthStatus> $healthStatuses
     * @param list<IncidentRecord> $incidents
     * @param array<string, mixed> $metricSummaries
     */
    public function __construct(
        public string $overallState,
        public array $healthStatuses = [],
        public array $incidents = [],
        public array $metricSummaries = [],
    ) {
    }
}
