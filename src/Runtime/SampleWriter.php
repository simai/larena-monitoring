<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Writes the buffered records in one bulk insert, normally once when the request, command or job ends. A write
 * never breaks the caller: any failure is swallowed, the records are given up, and the failure is counted as
 * monitoring.write_failed with the exception code — written with the next successful flush of this process.
 */
final class SampleWriter
{
    public const string TABLE = 'larena_monitoring_samples';

    public const string WRITE_FAILED = 'monitoring.write_failed';

    /** @var array<string, int> reason code => failed flushes not yet recorded */
    private array $failures = [];

    public function __construct(
        private readonly BufferedMetrics $buffer,
        private readonly ConnectionResolverInterface $database,
        private readonly ?string $connection = null,
    ) {
    }

    /** @return int records written */
    public function flush(): int
    {
        try {
            foreach ($this->failures as $reason => $count) {
                $this->buffer->internal(self::WRITE_FAILED, $count, $reason);
            }
            $carried = $this->failures;
            $this->failures = [];
            $rows = $this->buffer->drain();
            if ($rows === []) {
                return 0;
            }
        } catch (Throwable) {
            return 0;
        }

        try {
            $table = $this->database->connection($this->connection)->table(self::TABLE);
            foreach (array_chunk($rows, 500) as $chunk) {
                $table->insert($chunk);
            }

            return count($rows);
        } catch (Throwable $error) {
            $this->failures = $carried;
            $reason = BufferedMetrics::reasonCode($error);
            $this->failures[$reason] = ($this->failures[$reason] ?? 0) + 1;

            return 0;
        }
    }

    /** @return array<string, int> */
    public function pendingFailures(): array
    {
        return $this->failures;
    }
}
