<?php

declare(strict_types=1);

namespace Larena\Monitoring\Enums;

enum HealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Down = 'down';
    case Unknown = 'unknown';
    case Muted = 'muted';

    public function operationalProblem(): bool
    {
        return $this === self::Degraded || $this === self::Down || $this === self::Unknown;
    }
}
