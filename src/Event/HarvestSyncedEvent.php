<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Event;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dispatched by harvest:sync after the app's selected folios are applied, before the checkpoint
 * is saved: a listener that throws leaves the checkpoint unchanged and the next run retries.
 * $changed is false when neither a folio revision nor folio-set membership moved.
 */
final class HarvestSyncedEvent
{
    /**
     * @param list<FolioCatalogEntry> $entries every published entry in the applied catalog
     * @param array<string, string> $receipts receipt key → revision held locally
     */
    public function __construct(
        public readonly SymfonyStyle $io,
        public readonly array $entries,
        public readonly array $receipts,
        public readonly bool $changed,
    ) {}
}
