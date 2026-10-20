<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Larena\Monitoring\Contracts\RetentionPolicy;

/**
 * Applies the retention policy: raw samples and minute rollups are kept rawSampleDays (7 by default), hour and day
 * rollups aggregateDays (90 by default). Rows are removed in small batches so a large table is not locked at once.
 */
final class RetentionPruner
{
    private const int BATCH = 5000;

    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly RetentionPolicy $policy,
        private readonly ?string $connection = null,
    ) {
    }

    /** @return array{samples: int, rollups: int} */
    public function prune(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now('UTC'))->getTimestamp();
        $rawCutoff = RollupBuilder::format($now - $this->policy->rawSampleDays * 86400);
        $rollupCutoff = RollupBuilder::format($now - $this->policy->aggregateDays * 86400);

        $samples = $this->deleteBatched(SampleWriter::TABLE, static fn ($query) => $query->where('recorded_at', '<', $rawCutoff));
        $rollups = $this->deleteBatched(RollupBuilder::TABLE, static fn ($query) => $query->where('period', 'm')->where('bucket_start', '<', $rawCutoff))
            + $this->deleteBatched(RollupBuilder::TABLE, static fn ($query) => $query->whereIn('period', ['h', 'd'])->where('bucket_start', '<', $rollupCutoff));

        return ['samples' => $samples, 'rollups' => $rollups];
    }

    /** @param callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder $scope */
    private function deleteBatched(string $table, callable $scope): int
    {
        $db = $this->database->connection($this->connection);
        $removed = 0;
        do {
            $ids = $scope($db->table($table))->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $removed += $db->table($table)->whereIn('id', $ids)->delete();
        } while (count($ids) === self::BATCH);

        return $removed;
    }
}
