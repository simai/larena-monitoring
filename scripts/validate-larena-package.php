<?php

declare(strict_types=1);

$requiredFiles = [
    '.gitignore',
    '.env.example',
    '.github/workflows/larena-package-ci.yml',
    '.githooks/pre-commit',
    '.githooks/pre-push',
    'composer.json',
    'module.yaml',
    'phpstan.neon.dist',
    '.larena/spec-ref.json',
    '.larena/launch-context.json',
    'tools/larena-scope-check.php',
];
$errors = [];
foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $errors[] = "Missing required enforcement file: {$file}";
    }
}
$specRef = is_file('.larena/spec-ref.json')
    ? json_decode((string) file_get_contents('.larena/spec-ref.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
$launchContext = is_file('.larena/launch-context.json')
    ? json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
if (($specRef['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = '.larena/spec-ref.json must keep canonical_update_allowed=false';
}
if (($launchContext['package'] ?? null) !== 'larena/monitoring') {
    $errors[] = '.larena/launch-context.json package must be larena/monitoring';
}
if (!str_starts_with((string) ($launchContext['evidence_path'] ?? ''), 'docs/project-management/evidence/')) {
    $errors[] = 'launch-context evidence_path must start with docs/project-management/evidence/';
}
if (!str_starts_with((string) ($launchContext['graph_sync_proposal_path'] ?? ''), (string) ($launchContext['evidence_path'] ?? '__missing__'))) {
    $errors[] = 'graph_sync_proposal_path must be inside evidence_path';
}
$allowedStatuses = [
    'repository_prepared_pending_review',
    'coding_started',
    'contract_skeleton_review_passed',
];
if (!in_array((string) ($launchContext['status'] ?? ''), $allowedStatuses, true)) {
    $errors[] = 'launch-context status is not allowed for this package stage.';
}

$codingStarted = ($launchContext['coding_started'] ?? null) === true;
if (!$codingStarted) {
    foreach (['src', 'config', 'database', 'routes', 'resources', 'tests', 'lang'] as $runtimePath) {
        if (is_dir($runtimePath)) {
            $errors[] = "{$runtimePath}/ is not allowed before a coding launch record.";
        }
    }
}

$composer = is_file('composer.json')
    ? json_decode((string) file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
// Monitoring is a leaf: every package may report to it, so it must not depend on any other Larena package.
foreach (['require', 'require-dev'] as $section) {
    foreach (array_keys(is_array($composer[$section] ?? null) ? $composer[$section] : []) as $dependency) {
        if (is_string($dependency) && str_starts_with($dependency, 'larena/')) {
            $errors[] = "composer.json {$section} must not list Larena packages (monitoring stays a leaf): {$dependency}";
        }
    }
}

if ($codingStarted) {
    $acceptedLaunchRecords = [
        'docs/project-management/launch-records/monitoring-metrics.json',
    ];
    $launchRecord = (string) ($launchContext['launch_record_ref'] ?? '');
    if (!in_array($launchRecord, $acceptedLaunchRecords, true) || !is_file($launchRecord)) {
        $errors[] = 'coding_started requires an accepted monitoring launch record.';
    } else {
        $record = json_decode((string) file_get_contents($launchRecord), true, 512, JSON_THROW_ON_ERROR);
        if (($record['schema'] ?? null) !== 'larena.pre_coding_launch_record.v1' || ($record['target_package'] ?? null) !== 'larena/monitoring') {
            $errors[] = 'The launch record must be a larena.pre_coding_launch_record.v1 for larena/monitoring.';
        }
        $recordFeatures = is_array($record['selected_features'] ?? null) ? $record['selected_features'] : [];
        foreach (is_array($launchContext['selected_features'] ?? null) ? $launchContext['selected_features'] : [] as $feature) {
            if (!in_array($feature, $recordFeatures, true)) {
                $errors[] = "launch-context feature {$feature} is not selected by the launch record.";
            }
        }
    }

    $requiredContractFiles = [
        'src/Contracts/HealthCheckDescriptor.php',
        'src/Contracts/HealthStatus.php',
        'src/Contracts/IncidentRecord.php',
        'src/Contracts/MetricDescriptor.php',
        'src/Contracts/MetricSample.php',
        'src/Contracts/Metrics.php',
        'src/Contracts/MonitoringDashboardSnapshot.php',
        'src/Contracts/MonitoringGateway.php',
        'src/Contracts/RetentionPolicy.php',
        'src/Enums/HealthState.php',
        'src/Enums/IncidentState.php',
        'src/Enums/MetricType.php',
        'src/Enums/Severity.php',
        'tests/Unit/MonitoringContractTest.php',
        'tests/Unit/MonitoringFailsClosedTest.php',
        'config/larena-monitoring.php',
        'database/migrations/2026_10_20_000001_create_larena_monitoring_tables.php',
        'monitoring.yaml',
        'src/Providers/MonitoringServiceProvider.php',
        'src/Runtime/BufferedMetrics.php',
        'src/Runtime/MetricCatalog.php',
        'src/Runtime/MetricGuard.php',
        'src/Runtime/NullMetrics.php',
        'src/Runtime/SampleWriter.php',
        'src/Runtime/RollupBuilder.php',
        'src/Runtime/MetricVerdicts.php',
        'src/Runtime/MetricsReport.php',
    ];
    foreach ($requiredContractFiles as $file) {
        if (!is_file($file)) {
            $errors[] = "Missing required monitoring file: {$file}";
        }
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo $codingStarted
    ? "Larena Monitoring metrics launch context is valid.\n"
    : "Larena Monitoring clean pre-codegen baseline is valid.\n";
