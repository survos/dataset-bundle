<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Harvest;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;

/**
 * An app's own answer to "which folios do I hold?" for survos_dataset.harvest_sync scope "app",
 * when neither survos_folio.folio_sets nor "all" expresses it (ink: the papers it has adopted).
 * Called once per sync with the catalog being applied.
 */
interface HarvestSelection
{
    /**
     * @param list<FolioCatalogEntry> $entries
     *
     * @return list<FolioCatalogEntry> the entries this app holds
     */
    public function select(array $entries): array;
}
