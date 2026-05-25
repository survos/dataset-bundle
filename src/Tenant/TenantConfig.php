<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Tenant;

final class TenantConfig
{
    public function __construct(
        public readonly string $code,
        public readonly string $database,
    ) {
    }
}
