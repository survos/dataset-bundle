<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Survos\DataContracts\Path\DataPaths as ContractDataPaths;

/**
 * BC subclass. The real class is {@see ContractDataPaths} (moved 2026-09-23).
 *
 * DataPaths resolves where a dataset's files live — knowledge a READING app needs, since
 * folio-bundle builds every folio path through it. The rest of this bundle (the Doctrine registry,
 * its second entity manager, the API Platform resources) is production machinery an app that only
 * displays folios should never install. Keeping them in one package is why folio-bundle required
 * this bundle, and why fotostory and mastheads each carried a registry to read one field.
 *
 * A SUBCLASS, not a class_alias, and that distinction is load-bearing: `instanceof` and parameter
 * type checks do NOT autoload, so an alias only exists once something else has happened to load
 * this file. Harvest hit exactly that — DatasetResolver::__construct() type-hints the old name,
 * was handed the data-contracts instance, and PHP rejected it without ever consulting the
 * autoloader ("must be of type Survos\DatasetBundle\Service\DataPaths, Survos\DataContracts\Path\
 * DataPaths given"). Inheritance is checked on the object's real class chain instead, so it holds
 * no matter what has been loaded.
 *
 * The bundle registers this as a separate deprecated service. Canonical consumers receive
 * ContractDataPaths directly; old consumers still receive this narrower type.
 *
 * @deprecated Use ContractDataPaths instead.
 */
class DataPaths extends ContractDataPaths
{
    #[\Deprecated(message: 'Use Survos\\DataContracts\\Path\\DataPaths instead.', since: '2.32')]
    public function __construct(
        string $dataDir,
        string $worksRoot = 'work',
        string $datasetRoot = 'work',
        string $artifactRoot = 'artifacts',
        string $runsRoot = 'runs',
        string $cacheRoot = 'cache',
        string $zipsRoot = 'vault',
        ?string $captureRoot = null,
        string $defaultObjectFilename = 'obj.jsonl',
    ) {
        parent::__construct($dataDir, $worksRoot, $datasetRoot, $artifactRoot, $runsRoot, $cacheRoot, $zipsRoot, $captureRoot, $defaultObjectFilename);
    }
}
