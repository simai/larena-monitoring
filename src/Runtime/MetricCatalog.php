<?php

declare(strict_types=1);

namespace Larena\Monitoring\Runtime;

use Larena\Monitoring\Contracts\MetricDescriptor;
use Larena\Monitoring\Contracts\RetentionPolicy;
use Larena\Monitoring\Enums\MetricType;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The declared metrics: the `metrics:` section of each registered package monitoring.yaml.
 *
 *     metrics:
 *       storage.query:
 *         type: duration            # counter | gauge | duration | histogram | state
 *         tags: [driver]            # declared tag keys besides the common ones
 *         thresholds: { p95_ms: 250, error_rate: 0.05 }
 *         retention: { raw: 7d, aggregate: 90d }
 *
 * Packages register their file with register('larena/storage', $path) or through the
 * `larena-monitoring.catalogs` config list. Files are read lazily, once per process; an unreadable file or an
 * invalid entry is skipped and listed in problems() — it never throws into the caller.
 */
final class MetricCatalog
{
    public const string ID_PATTERN = '/^[a-z][a-z0-9_]{0,39}(\.[a-z0-9_]{1,40}){0,3}$/';

    public const string TAG_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /** @var array<string, string> package => yaml path */
    private array $sources = [];

    /** @var array<string, MetricDescriptor>|null */
    private ?array $descriptors = null;

    /** @var array<string, RetentionPolicy> */
    private array $retention = [];

    /** @var list<string> */
    private array $problems = [];

    public function __construct(private readonly RetentionPolicy $defaultRetention = new RetentionPolicy(7, 90))
    {
    }

    public function register(string $package, string $yamlPath): void
    {
        $this->sources[$package] = $yamlPath;
        $this->descriptors = null;
    }

    /** @return array<string, string> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function find(string $id): ?MetricDescriptor
    {
        return $this->all()[$id] ?? null;
    }

    /** @return array<string, MetricDescriptor> */
    public function all(): array
    {
        if ($this->descriptors === null) {
            $this->load();
        }

        return $this->descriptors ?? [];
    }

    public function retention(string $id): RetentionPolicy
    {
        $this->all();

        return $this->retention[$id] ?? $this->defaultRetention;
    }

    /** @return list<string> problem codes in the form `package:code` or `package:metric_id:code` */
    public function problems(): array
    {
        $this->all();

        return $this->problems;
    }

    private function load(): void
    {
        $this->descriptors = [];
        $this->retention = [];
        $this->problems = [];
        foreach ($this->sources as $package => $path) {
            try {
                $parsed = is_file($path) ? Yaml::parseFile($path) : null;
            } catch (Throwable) {
                $parsed = null;
            }
            if (!is_array($parsed)) {
                $this->problems[] = $package.':catalog_unreadable';

                continue;
            }
            $metrics = $parsed['metrics'] ?? [];
            if (!is_array($metrics)) {
                $this->problems[] = $package.':metrics_section_invalid';

                continue;
            }
            foreach ($metrics as $id => $definition) {
                $this->add($package, (string) $id, $definition);
            }
        }
    }

    private function add(string $package, string $id, mixed $definition): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            $this->problems[] = $package.':metric_id_invalid';

            return;
        }
        if (!is_array($definition)) {
            $this->problems[] = $package.':'.$id.':definition_invalid';

            return;
        }
        if (isset($this->descriptors[$id])) {
            $this->problems[] = $package.':'.$id.':declared_twice';

            return;
        }
        $type = MetricType::tryFrom(is_string($definition['type'] ?? null) ? $definition['type'] : '');
        if ($type === null) {
            $this->problems[] = $package.':'.$id.':type_invalid';

            return;
        }
        $tagKeys = [];
        foreach (is_array($definition['tags'] ?? null) ? $definition['tags'] : [] as $key) {
            if (!is_string($key) || preg_match(self::TAG_KEY_PATTERN, $key) !== 1) {
                $this->problems[] = $package.':'.$id.':tag_key_invalid';

                return;
            }
            $tagKeys[] = $key;
        }
        $thresholds = [];
        foreach (['p95_ms', 'error_rate'] as $name) {
            $value = is_array($definition['thresholds'] ?? null) ? ($definition['thresholds'][$name] ?? null) : null;
            if (is_int($value) || is_float($value)) {
                $thresholds[$name] = (float) $value;
            }
        }
        $retention = is_array($definition['retention'] ?? null) ? $definition['retention'] : [];
        $policy = new RetentionPolicy(
            $this->days($retention['raw'] ?? null) ?? $this->defaultRetention->rawSampleDays,
            $this->days($retention['aggregate'] ?? null) ?? $this->defaultRetention->aggregateDays,
        );
        if (!$policy->bounded()) {
            $this->problems[] = $package.':'.$id.':retention_unbounded';
            $policy = $this->defaultRetention;
        }
        $this->retention[$id] = $policy;
        $this->descriptors[$id] = new MetricDescriptor(
            $id,
            $package,
            $type,
            $tagKeys,
            $thresholds,
            'raw:'.$policy->rawSampleDays.'d;aggregate:'.$policy->aggregateDays.'d',
        );
    }

    private function days(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^([1-9][0-9]{0,3})d$/', $value, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }
}
