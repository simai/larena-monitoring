<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

interface MonitoringGateway
{
    public function evaluate(HealthCheckDescriptor $descriptor): HealthStatus;

    public function record(MetricDescriptor $descriptor, MetricSample $sample): MetricSample;

    public function snapshot(): MonitoringDashboardSnapshot;
}
