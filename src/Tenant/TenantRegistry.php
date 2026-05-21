<?php

declare(strict_types=1);

namespace Survos\DataBundle\Tenant;

use InvalidArgumentException;

final class TenantRegistry
{
    /** @var array<string, array{database: string|null}> */
    private array $tenants;
    private string $databasePrefix;

    /** @param array<string, array{database?: string|null}> $tenants */
    public function __construct(string $databasePrefix = '', array $tenants = [])
    {
        $this->databasePrefix = $databasePrefix;
        $this->tenants = $tenants;
    }

    public function has(string $tenantCode): bool
    {
        return isset($this->tenants[$tenantCode]);
    }

    public function get(string $tenantCode): TenantConfig
    {
        if (!$this->has($tenantCode)) {
            throw new InvalidArgumentException(sprintf('Unknown tenant code "%s".', $tenantCode));
        }

        $tenant = $this->tenants[$tenantCode];

        return new TenantConfig(
            $tenantCode,
            (string) ($tenant['database'] ?? ''),
        );
    }

    public function resolveDatabaseName(string $tenantCode): string
    {
        $tenant = $this->tenants[$tenantCode] ?? null;
        if (is_array($tenant) && ($tenant['database'] ?? '') !== '') {
            return (string) $tenant['database'];
        }

        if ($this->databasePrefix !== '') {
            return $this->databasePrefix . $tenantCode;
        }

        return $tenantCode;
    }

    /** @return iterable<string, TenantConfig> */
    public function all(): iterable
    {
        foreach ($this->tenants as $code => $tenant) {
            yield $code => new TenantConfig(
                (string) $code,
                (string) ($tenant['database'] ?? ''),
            );
        }
    }
}
