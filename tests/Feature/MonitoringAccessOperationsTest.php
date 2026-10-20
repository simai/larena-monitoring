<?php

declare(strict_types=1);

namespace Larena\Monitoring\Tests\Feature;

use Larena\Monitoring\Access\AccessOperationRegistrar;
use Larena\Monitoring\Access\MonitoringOperations;
use Larena\Monitoring\Tests\TestCase;
use RuntimeException;

final class MonitoringAccessOperationsTest extends TestCase
{
    public function test_declares_the_dashboard_read_operation(): void
    {
        self::assertSame('monitoring.dashboard.read', MonitoringOperations::DASHBOARD_READ);
        self::assertSame([[
            'code' => 'monitoring.dashboard.read',
            'ownerPackage' => 'larena/monitoring',
            'labelKey' => 'larena-monitoring::operations.dashboard_read',
            'target' => 'monitoring.dashboard:system',
            'requiredGrant' => 'read',
            'risk' => 'high',
            'auditDenials' => true,
        ]], MonitoringOperations::definitions());
    }

    public function test_labels_exist_in_russian_and_english(): void
    {
        $translator = $this->app->make('translator');

        self::assertSame('Просмотр мониторинга', $translator->get('larena-monitoring::operations.dashboard_read', [], 'ru'));
        self::assertSame('View monitoring', $translator->get('larena-monitoring::operations.dashboard_read', [], 'en'));
    }

    public function test_registers_once_and_is_idempotent(): void
    {
        $registry = new FakeAccessOperationRegistry();
        $registrar = new AccessOperationRegistrar(FakeAccessOperationDescriptor::class);

        self::assertSame(1, $registrar->register($registry));
        self::assertSame(0, $registrar->register($registry));
        $descriptor = $registry->get(MonitoringOperations::DASHBOARD_READ);
        self::assertInstanceOf(FakeAccessOperationDescriptor::class, $descriptor);
        self::assertSame('larena/monitoring', $descriptor->ownerPackage);
        self::assertSame('read', $descriptor->requiredGrant);
    }

    public function test_a_different_descriptor_under_the_code_is_a_conflict(): void
    {
        $registry = new FakeAccessOperationRegistry();
        $registry->register(new FakeAccessOperationDescriptor(
            MonitoringOperations::DASHBOARD_READ, 'larena/other', 'larena-other::operations.x', 'other:system', 'manage',
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('monitoring_access_operation_conflict');
        (new AccessOperationRegistrar(FakeAccessOperationDescriptor::class))->register($registry);
    }

    public function test_without_larena_access_nothing_is_registered_and_boot_succeeds(): void
    {
        $registrar = $this->app->make(AccessOperationRegistrar::class);

        self::assertSame(class_exists(AccessOperationRegistrar::REGISTRY_CLASS), $registrar->available());
        self::assertFalse($this->app->bound(AccessOperationRegistrar::REGISTRY_CLASS));
        self::assertSame(0, (new AccessOperationRegistrar('Larena\Access\Missing\Descriptor'))->register(new FakeAccessOperationRegistry()));
    }
}

/** The shape of Larena\Access\ValueObjects\AccessOperationDescriptor, for tests without larena/access. */
final readonly class FakeAccessOperationDescriptor
{
    public function __construct(
        public string $code,
        public string $ownerPackage,
        public string $labelKey,
        public string $target,
        public string $requiredGrant,
        public string $risk = 'normal',
        public bool $auditDenials = true,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

/** The shape of Larena\Access\Runtime\AccessOperationRegistry, for tests without larena/access. */
final class FakeAccessOperationRegistry
{
    /** @var array<string, FakeAccessOperationDescriptor> */
    private array $operations = [];

    public function register(FakeAccessOperationDescriptor $descriptor): bool
    {
        if (isset($this->operations[$descriptor->code])) {
            return false;
        }
        $this->operations[$descriptor->code] = $descriptor;

        return true;
    }

    public function get(string $code): ?FakeAccessOperationDescriptor
    {
        return $this->operations[$code] ?? null;
    }
}
