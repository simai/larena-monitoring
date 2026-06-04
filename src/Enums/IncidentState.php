<?php

declare(strict_types=1);

namespace Larena\Monitoring\Enums;

enum IncidentState: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Mitigated = 'mitigated';
    case Resolved = 'resolved';
    case Suppressed = 'suppressed';
}
