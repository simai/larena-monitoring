<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Larena\Monitoring\Contracts\HealthCheckDescriptor;
use Larena\Monitoring\Contracts\HealthStatus;
use Larena\Monitoring\Contracts\MetricDescriptor;
use Larena\Monitoring\Contracts\MetricSample;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Monitoring\Contracts\MonitoringDashboardSnapshot;
use Larena\Monitoring\Contracts\MonitoringGateway;
use Larena\Monitoring\Enums\HealthState;
use Larena\Monitoring\Enums\MetricType;

/**
 * The gateway over the runtime metrics. record() passes the sample through the same guard and buffer as Metrics;
 * the returned sample is marked sanitized only when it was admitted. Probes are not run yet: evaluate() answers
 * unknown, and snapshot() carries the metric verdict summary of the last 7 days.
 */
final class RuntimeMonitoringGateway implements MonitoringGateway
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly MetricsReport $report,
    ) {
    }

    public function evaluate(HealthCheckDescriptor $descriptor): HealthStatus
    {
        return new HealthStatus($descriptor->id, HealthState::Unknown, true, ['probe_runner_not_available']);
    }

    public function record(MetricDescriptor $descriptor, MetricSample $sample): MetricSample
    {
        $kind = match ($descriptor->type) {
            MetricType::Counter => 'count',
            MetricType::Duration, MetricType::Histogram => 'timing',
            MetricType::Gauge, MetricType::State => 'gauge',
        };
        $admitted = false;
        if ($sample->metricId === $descriptor->id && is_numeric($sample->value) && $this->metrics instanceof BufferedMetrics) {
            $admitted = $this->metrics->admit($kind, $sample->metricId, (float) $sample->value, $sample->tags);
        }

        return new MetricSample($sample->metricId, $sample->value, $sample->recordedAtUtc, $sample->tags, $admitted);
    }

    public function snapshot(): MonitoringDashboardSnapshot
    {
        $report = $this->report->build(7);
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $overall = ($summary[MetricVerdicts::DEGRADED] ?? 0) > 0 ? HealthState::Degraded : HealthState::Unknown;

        return new MonitoringDashboardSnapshot($overall->value, [], [], ['metrics' => $summary]);
    }
}
