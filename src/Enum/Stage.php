<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Enum;

/*
 * BC shim — the real enum is Survos\DataContracts\Path\Stage (moved 2026-09-23).
 * See Survos\DatasetBundle\Service\DataPaths for why, and why this is a PSR-4 shim file.
 * Enum case identity is preserved: Stage::Raw here IS Path\Stage::Raw, same instance.
 */
trigger_deprecation(
    'survos/dataset-bundle',
    '2.32',
    'Importing %s is deprecated, use %s instead.',
    Stage::class,
    \Survos\DataContracts\Path\Stage::class,
);

class_alias(\Survos\DataContracts\Path\Stage::class, Stage::class);
