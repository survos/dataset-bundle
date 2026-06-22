<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Event;

use Symfony\Component\Console\Style\SymfonyStyle;
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
        /**
         * The console style the dispatching command is writing to. When set, the folio-bundle
         * listener renders the build result onto it (rows + the browse link on the folio server),
         * so an inline `--folio` build isn't silent. Null when dispatched outside a command.
         */
        public readonly ?SymfonyStyle $io = null,
    ) {
    }
}
