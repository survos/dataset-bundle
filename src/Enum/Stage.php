<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Enum;

/*
 * BC shim — the real enum is Survos\DataContracts\Path\Stage (moved 2026-09-23).
 * See Survos\DatasetBundle\Service\DataPaths for why, and why this is a PSR-4 shim file.
 * Enum case identity is preserved: Stage::Raw here IS Path\Stage::Raw, same instance.
 */
// The bundle eagerly loads this alias so legacy parameter type checks work. PHP cannot
// distinguish that compatibility setup from an import, so loading it must not emit a warning.
// @deprecated Use Survos\DataContracts\Path\Stage instead.
class_alias(\Survos\DataContracts\Path\Stage::class, Stage::class);
