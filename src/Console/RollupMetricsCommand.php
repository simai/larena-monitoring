<?php

declare(strict_types=1);

namespace Larena\Monitoring\Console;

use Illuminate\Console\Command;
use Larena\Monitoring\Runtime\RollupBuilder;

/** Builds minute, hour and day rollups from raw samples; scheduled every minute. */
final class RollupMetricsCommand extends Command
{
    protected $signature = 'larena:monitoring:rollup';

    protected $description = 'Build minute, hour and day metric rollups from raw samples.';

    public function handle(RollupBuilder $builder): int
    {
        $written = $builder->run();
        $this->line(sprintf('Rollups written: minute %d, hour %d, day %d.', $written['m'], $written['h'], $written['d']));

        return self::SUCCESS;
    }
}
