<?php

declare(strict_types=1);

return [
    // Kill switch: LARENA_MONITORING=false (or 0/off) records nothing.
    'enabled' => filter_var(env('LARENA_MONITORING', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,

    // Share (0..1) of ok timings and gauges kept; counters and errors are always kept.
    'sample_rate' => (float) env('LARENA_MONITORING_SAMPLE_RATE', 1.0),

    // Raw samples and minute rollups are kept this many days; hour and day rollups rollup_days.
    'raw_days' => 7,
    'rollup_days' => 90,

    // At most this many records are buffered per request, command or job.
    'buffer_limit' => 500,

    // Database connection for the monitoring tables; null is the default connection.
    'connection' => null,

    // Package metric declarations: package name => path to its monitoring.yaml.
    'catalogs' => [],
];
