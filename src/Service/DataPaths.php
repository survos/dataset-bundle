<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Survos\DataContracts\Path\DataPaths as ContractDataPaths;

trigger_deprecation(
    'survos/dataset-bundle',
    '2.32',
    'Type-hinting %s is deprecated, use %s instead. The path vocabulary moved to survos/data-contracts so a reading app can resolve folio paths without installing the production registry.',
    DataPaths::class,
    ContractDataPaths::class,
);

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
 * Because the old name is the narrower type, THIS is the class the container must instantiate:
 * SurvosDatasetBundle registers it and aliases the data-contracts id to it, so a service
 * type-hinting either name gets something valid. Code should migrate to the contracts class; the
 * deprecation above marks every remaining old-name import.
 */
class DataPaths extends ContractDataPaths
{
}
