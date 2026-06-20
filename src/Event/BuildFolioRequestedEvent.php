<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a dataset's stage completes when the user asked to (re)build the folio inline
 * (e.g. `dataset:normalize --folio`), so current folio data is visible mid-pipeline without a
 * separate `folio:build` run. folio-bundle listens and builds; an app without folio-bundle simply
 * has no listener, so the flag is a harmless no-op there.
 *
 * dataset-bundle must NOT depend on folio-bundle (folio→dataset is the dependency direction), so
 * this event is the decoupling seam.
 */
final class BuildFolioRequestedEvent extends Event
{
    public function __construct(
        public readonly string $datasetKey,
        public readonly ?string $core = null,
    ) {
    }
}
