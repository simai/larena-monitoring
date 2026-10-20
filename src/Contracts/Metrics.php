<?php

declare(strict_types=1);

namespace Larena\Monitoring\Contracts;

use Throwable;

/**
 * Runtime metrics for Larena packages. Every metric id must be declared in a package monitoring.yaml; tags carry
 * only short codes (no text, paths, e-mails or identifiers). Recording never throws and never breaks the caller:
 * a record that breaks the rules is dropped and counted as monitoring.dropped.
 */
interface Metrics
{
    /** @param array<string, string> $tags */
    public function count(string $id, int $n = 1, array $tags = []): void;

    /** @param array<string, string> $tags */
    public function timing(string $id, float $ms, array $tags = []): void;

    /** @param array<string, string> $tags */
    public function gauge(string $id, float $value, array $tags = []): void;

    /**
     * Times the operation and records it with outcome=ok, or with outcome=error and a reason code (derived from
     * the exception class) when it throws; the exception is rethrown unchanged.
     *
     * @template T
     *
     * @param callable(): T $operation
     * @param array<string, string> $tags
     *
     * @return T
     *
     * @throws Throwable
     */
    public function measure(string $id, callable $operation, array $tags = []): mixed;
}
