<?php

declare(strict_types=1);

namespace Larena\Monitoring\Access;

/**
 * The access operations larena/monitoring owns. Other packages (the admin monitoring page) check these codes; the
 * codes are registered into larena/access only when it is installed (see AccessOperationRegistrar), so monitoring
 * stays a leaf package without a Larena dependency.
 */
final class MonitoringOperations
{
    /** Reading the monitoring dashboard: metric verdicts, counts, percentiles and error shares. */
    public const string DASHBOARD_READ = 'monitoring.dashboard.read';

    public const string OWNER_PACKAGE = 'larena/monitoring';

    /**
     * Descriptor fields in the shape of Larena\Access\ValueObjects\AccessOperationDescriptor's constructor.
     *
     * @return list<array{code: string, ownerPackage: string, labelKey: string, target: string, requiredGrant: string, risk: string, auditDenials: bool}>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => self::DASHBOARD_READ,
                'ownerPackage' => self::OWNER_PACKAGE,
                'labelKey' => 'larena-monitoring::operations.dashboard_read',
                'target' => 'monitoring.dashboard:system',
                'requiredGrant' => 'read',
                'risk' => 'high',
                'auditDenials' => true,
            ],
        ];
    }
}
