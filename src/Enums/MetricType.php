<?php

declare(strict_types=1);

namespace Larena\Monitoring\Enums;

enum MetricType: string
{
    case Gauge = 'gauge';
    case Counter = 'counter';
    case Histogram = 'histogram';
    case Duration = 'duration';
    case State = 'state';
}
