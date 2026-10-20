<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Larena\Monitoring\Enums\MetricType;

/**
 * The privacy and shape rules every record passes before it is buffered (the Federation telemetry rules):
 * a declared metric id of the right type, at most eight tags whose keys are common or declared and whose values
 * are short codes — never text, paths, e-mails or identifiers — and a record of at most 1 KB.
 * check() returns null for an admissible record and a reason code otherwise.
 */
final class MetricGuard
{
    /** Tag keys every metric may carry. */
    public const array COMMON_TAG_KEYS = ['package', 'subsystem', 'operation', 'outcome', 'reason'];

    public const array OUTCOMES = ['ok', 'error', 'rejected', 'timeout', 'skipped'];

    public const string TAG_VALUE_PATTERN = '/^[a-z][a-z0-9_.-]{0,59}$/';

    public const int MAX_TAGS = 8;

    public const int MAX_RECORD_BYTES = 1024;

    /** @var array<string, list<MetricType>> */
    private const array KIND_TYPES = [
        'count' => [MetricType::Counter],
        'timing' => [MetricType::Duration, MetricType::Histogram],
        'gauge' => [MetricType::Gauge, MetricType::State],
    ];

    public function __construct(private readonly MetricCatalog $catalog)
    {
    }

    /**
     * @param 'count'|'timing'|'gauge' $kind
     * @param array<mixed> $tags
     */
    public function check(string $id, string $kind, float $value, array $tags): ?string
    {
        if (preg_match(MetricCatalog::ID_PATTERN, $id) !== 1) {
            return 'id_invalid';
        }
        $descriptor = $this->catalog->find($id);
        if ($descriptor === null) {
            return 'id_undeclared';
        }
        if (!in_array($descriptor->type, self::KIND_TYPES[$kind], true)) {
            return 'type_mismatch';
        }
        if (!is_finite($value) || ($kind === 'count' && $value < 0)) {
            return 'value_invalid';
        }
        if (count($tags) > self::MAX_TAGS) {
            return 'too_many_tags';
        }
        foreach ($tags as $key => $tagValue) {
            if (!is_string($key) || preg_match(MetricCatalog::TAG_KEY_PATTERN, $key) !== 1) {
                return 'tag_key_invalid';
            }
            if (!in_array($key, self::COMMON_TAG_KEYS, true) && !in_array($key, $descriptor->tagKeys, true)) {
                return 'tag_key_undeclared';
            }
            if (!is_string($tagValue) || preg_match(self::TAG_VALUE_PATTERN, $tagValue) !== 1) {
                return 'tag_value_invalid';
            }
            if (self::looksLikeIdentifier($tagValue)) {
                return 'tag_value_identifier';
            }
            if ($key === 'outcome' && !in_array($tagValue, self::OUTCOMES, true)) {
                return 'outcome_invalid';
            }
        }
        $encoded = json_encode(['metric_id' => $id, 'type' => $descriptor->type->value, 'value' => $value, 'tags' => $tags]);
        if ($encoded === false || strlen($encoded) > self::MAX_RECORD_BYTES) {
            return 'record_too_large';
        }

        return null;
    }

    /**
     * Turns a name (a route name, a job type) into a tag value code: lowercase, every character outside
     * [a-z0-9_.-] replaced with '_', cut at 60 characters. A value that still is not a code — empty, not starting
     * with a letter or looking like an identifier — becomes $fallback.
     */
    public static function code(?string $value, string $fallback = 'unnamed'): string
    {
        if ($value === null) {
            return $fallback;
        }
        $code = substr((string) preg_replace('/[^a-z0-9_.-]/', '_', strtolower(trim($value))), 0, 60);

        return preg_match(self::TAG_VALUE_PATTERN, $code) === 1 && !self::looksLikeIdentifier($code) ? $code : $fallback;
    }

    /** Long digit runs and hex runs (ids, hashes, UUID parts) are identifiers, not codes. */
    private static function looksLikeIdentifier(string $value): bool
    {
        return preg_match('/[0-9]{5,}/', $value) === 1
            || preg_match('/(?=[0-9a-f]*[0-9])[0-9a-f]{8,}/', $value) === 1;
    }
}
