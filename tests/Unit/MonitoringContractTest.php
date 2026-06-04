<?php

declare(strict_types=1);

use Larena\Monitoring\Contracts\HealthCheckDescriptor;
use Larena\Monitoring\Contracts\HealthStatus;
use Larena\Monitoring\Contracts\IncidentRecord;
use Larena\Monitoring\Contracts\MetricDescriptor;
use Larena\Monitoring\Contracts\MetricSample;
use Larena\Monitoring\Contracts\RetentionPolicy;
use Larena\Monitoring\Enums\HealthState;
use Larena\Monitoring\Enums\IncidentState;
use Larena\Monitoring\Enums\MetricType;
use Larena\Monitoring\Enums\Severity;

$descriptor = new HealthCheckDescriptor('queue.health', 'larena/queue', 'queue.probe', 250, ['database']);
assert($descriptor->safeDiagnosticsOnly === true);

$status = new HealthStatus('queue.health', HealthState::Degraded, true, ['queue_lag'], ['check_workers']);
assert($status->canOpenIncident() === true);

$metric = new MetricDescriptor('queue.lag', 'larena/queue', MetricType::Gauge, ['queue'], ['warning' => 100], 'default');
$sample = new MetricSample($metric->id, 12, '2026-06-04T12:00:00Z', ['queue' => 'default']);
assert($sample->sanitized === true);

$retention = new RetentionPolicy(rawSampleDays: 7, aggregateDays: 30);
assert($retention->bounded() === true);

$incident = new IncidentRecord('inc-1', 'queue.health', 'larena/queue', Severity::Warning, IncidentState::Open);
assert($incident->isActive() === true);
