<?php

declare(strict_types=1);

namespace Larena\Monitoring\Access;

use RuntimeException;

/**
 * Registers MonitoringOperations into larena/access's operation registry when that package is installed. Monitoring
 * must not depend on any Larena package, so the registry and descriptor classes are named, not imported: without
 * larena/access nothing is registered and nothing fails. Registration is idempotent; a different descriptor already
 * registered under a monitoring code is a conflict and fails loudly, as in the other packages.
 */
final class AccessOperationRegistrar
{
    public const string REGISTRY_CLASS = 'Larena\Access\Runtime\AccessOperationRegistry';

    public const string DESCRIPTOR_CLASS = 'Larena\Access\ValueObjects\AccessOperationDescriptor';

    /** @param string $descriptorClass a class taking the MonitoringOperations::definitions() fields as named arguments */
    public function __construct(private readonly string $descriptorClass = self::DESCRIPTOR_CLASS)
    {
    }

    public function available(): bool
    {
        return class_exists(self::REGISTRY_CLASS) && class_exists($this->descriptorClass);
    }

    /**
     * @param object $registry an object with register(descriptor): bool and get(code): ?descriptor
     * @return int the number of operations newly registered
     */
    public function register(object $registry): int
    {
        if (!class_exists($this->descriptorClass) || !method_exists($registry, 'register') || !method_exists($registry, 'get')) {
            return 0;
        }

        $registered = 0;
        foreach (MonitoringOperations::definitions() as $definition) {
            $descriptor = new ($this->descriptorClass)(...$definition);
            $existing = $registry->get($definition['code']);
            if ($existing === null) {
                if ($registry->register($descriptor) !== true) {
                    throw new RuntimeException('monitoring_access_operation_registration_failed');
                }
                $registered++;
            } elseif (!self::same($existing, $descriptor)) {
                throw new RuntimeException('monitoring_access_operation_conflict');
            }
        }

        return $registered;
    }

    private static function same(mixed $existing, object $descriptor): bool
    {
        if (!is_object($existing) || !method_exists($existing, 'toArray') || !method_exists($descriptor, 'toArray')) {
            return $existing == $descriptor;
        }

        return $existing->toArray() === $descriptor->toArray();
    }
}
