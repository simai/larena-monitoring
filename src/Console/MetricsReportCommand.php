<?php

declare(strict_types=1);

namespace Larena\Monitoring\Console;

use Illuminate\Console\Command;
use Larena\Monitoring\Runtime\MetricsReport;

/** Shows the verdict of every declared metric for the last N days. */
final class MetricsReportCommand extends Command
{
    protected $signature = 'larena:monitoring:report
        {--days=7 : Report window in days}
        {--package= : Only metrics declared by this package}
        {--json : Print the report as JSON}';

    protected $description = 'Report declared metrics with a verdict: working, degraded, unused or no data.';

    public function handle(MetricsReport $report): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
        if ($days === false) {
            $this->error('monitoring_report_days_invalid');

            return self::FAILURE;
        }
        $package = $this->option('package');
        $package = is_string($package) && $package !== '' ? $package : null;
        $data = $report->build($days, $package);

        if ($this->option('json') === true) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows = [];
        foreach (is_array($data['metrics']) ? $data['metrics'] : [] as $metric) {
            if (!is_array($metric)) {
                continue;
            }
            $rows[] = [
                $metric['id'],
                $metric['verdict'],
                $metric['count'],
                $metric['error_share'] === null ? '-' : round(100 * (float) $metric['error_share'], 1).'%',
                $metric['p95'] ?? '-',
                implode(', ', is_array($metric['reasons']) ? $metric['reasons'] : []),
            ];
        }
        $this->line(sprintf('Metrics for the last %d day(s)%s:', $days, $package !== null ? ' ('.$package.')' : ''));
        $this->table(['Metric', 'Verdict', 'Events', 'Errors', 'p95', 'Reasons'], $rows);

        return self::SUCCESS;
    }
}
