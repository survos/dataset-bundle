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
     * @param array<string, string> $previousReceipts the receipts of the last successful sync
     */
    public function __construct(
        public readonly SymfonyStyle $io,
        public readonly array $entries,
        public readonly array $receipts,
        public readonly bool $changed,
        public readonly array $previousReceipts = [],
    ) {}

    /** Whether this entry is held locally (see HarvestSync::receiptKey()). */
    public function held(FolioCatalogEntry $entry): bool
    {
        return isset($this->receipts[self::key($entry)]);
    }

    /** Whether this entry's revision differs from the last successful sync. */
    public function revised(FolioCatalogEntry $entry): bool
    {
        $key = self::key($entry);

        return ($this->previousReceipts[$key] ?? null) !== ($this->receipts[$key] ?? $entry->revision);
    }

    private static function key(FolioCatalogEntry $entry): string
    {
        return \Survos\DatasetBundle\Harvest\HarvestSync::receiptKey($entry);
    }
}
