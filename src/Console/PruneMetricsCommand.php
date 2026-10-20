<?php

declare(strict_types=1);

namespace Larena\Monitoring\Console;

use Illuminate\Console\Command;
use Larena\Monitoring\Contracts\RetentionPolicy;
use Larena\Monitoring\Runtime\RetentionPruner;

/** Removes raw samples older than raw_days (7) and rollups older than rollup_days (90); scheduled daily. */
final class PruneMetricsCommand extends Command
{
    protected $signature = 'larena:monitoring:prune';

    protected $description = 'Remove raw metric samples and rollups older than the retention period.';

    public function handle(RetentionPruner $pruner, RetentionPolicy $policy): int
    {
        if (!$policy->bounded()) {
            $this->error('monitoring_retention_unbounded');

            return self::FAILURE;
        }
        $removed = $pruner->prune();
        $this->line(sprintf(
            'Removed %d raw sample(s) older than %d day(s) and %d rollup(s).',
            $removed['samples'],
            $policy->rawSampleDays,
            $removed['rollups'],
        ));

        return self::SUCCESS;
    }
}
