<?php

declare(strict_types=1);

use Larena\Monitoring\Contracts\HealthStatus;
use Larena\Monitoring\Contracts\IncidentRecord;
use Larena\Monitoring\Contracts\RetentionPolicy;
use Larena\Monitoring\Enums\HealthState;
use Larena\Monitoring\Enums\IncidentState;
use Larena\Monitoring\Enums\Severity;

$unsanitized = new HealthStatus('secret.probe', HealthState::Down, false, ['raw_payload_present']);
assert($unsanitized->canOpenIncident() === false);

$unknown = new HealthStatus('unknown.probe', HealthState::Unknown, true, ['probe_timeout']);
assert($unknown->canOpenIncident() === true);

$unbounded = new RetentionPolicy(rawSampleDays: 0, aggregateDays: 30);
assert($unbounded->bounded() === false);

$resolved = new IncidentRecord('inc-2', 'queue.health', 'larena/queue', Severity::Critical, IncidentState::Resolved);
assert($resolved->isActive() === false);
