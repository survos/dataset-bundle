<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Harvest;

use Survos\DatasetBundle\Event\HarvestSyncedEvent;
use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Command\FolioPullCommand;
use Survos\FolioBundle\Command\FolioSetsSyncCommand;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Set\FolioSetResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Keeps a consuming app current with Harvest: a full catalog fetch (--full) or a replay of the
 * change feed, then the folios this app selects, then HarvestSyncedEvent for the app's own step.
 *
 * folio-bundle owns the protocol (FolioCatalogClient::synchronize()) and acquiring one folio
 * (FolioPullCommand::pullEntry()). This owns the checkpoint, the lock and the selection:
 * scope "folio_sets" holds the members of survos_folio.folio_sets, scope "all" every dataset.
 */
final readonly class HarvestSync
{
    public function __construct(
        private FolioCatalogClient $catalog,
        private FolioPullCommand $pull,
        private FolioService $folios,
        private SyncState $state,
        private EventDispatcherInterface $events,
        private string $scope,
        private bool $localPassthrough,
        private ?FolioSetResolver $sets = null,
        private ?FolioSetsSyncCommand $recordSets = null,
    ) {}

    #[AsCommand('harvest:sync', 'Synchronize this app\'s folios with Harvest; record the feed checkpoint on success')]
    public function sync(SymfonyStyle $io, #[Option('Reconcile the full Harvest catalog before replaying changes')] bool $full = false): int
    {
        try {
            $result = $this->state->locked(function () use ($io, $full): array {
                $saved = $this->state->load();
                if ($saved !== null && $saved[Field::SOURCE] !== $this->catalog->source()) {
                    $saved = null;
                }
                $previous = $saved[Field::RECEIPTS] ?? [];
                $receipts = [];
                $result = $this->catalog->synchronize($saved, $full, function (array $entries) use ($io, $full, $saved, $previous, &$receipts): void {
                    // Resolved against the catalog being applied, not the one on disk.
                    $membership = $this->membership();
                    $wanted = $membership === null ? null : self::wanted($membership);
                    foreach (self::select($entries, $wanted, $previous) as [$entry, $changed]) {
                        if ($this->acquire($io, $entry, $changed)) {
                            $receipts[self::receiptKey($entry)] = $entry->revision;
                        }
                    }
                    $changed = $full || $saved === null || $receipts !== $previous || $membership !== $this->recordedMembership();
                    if ($changed && $membership !== null) {
                        $this->recordSets?->__invoke($io);
                    }
                    if (!$changed) {
                        $io->text('No folio or membership changes.');
                    }
                    $this->events->dispatch(new HarvestSyncedEvent($io, $entries, $receipts, $changed));
                });
                $result[Field::RECEIPTS] = $receipts;
                $this->state->save($result);

                return $result;
            });
            $io->success(sprintf('Synchronized with Harvest at %s (%d folios held).', $result[Field::LAST_SUCCESSFUL_SYNC_AT], count($result[Field::RECEIPTS])));

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error('Sync incomplete; checkpoint unchanged. '.$error->getMessage());

            return Command::FAILURE;
        }
    }

    #[AsCommand('harvest:sync:status', 'Show the last successful Harvest synchronization')]
    public function status(SymfonyStyle $io): int
    {
        $state = $this->state->load();
        if ($state === null) {
            $io->note('No completed Harvest sync. Run harvest:sync --full.');

            return Command::SUCCESS;
        }
        $io->definitionList(
            ['Source' => $state[Field::SOURCE]],
            ['Last synced' => $state[Field::LAST_SUCCESSFUL_SYNC_AT]],
            ['Cursor' => $state[Field::CURSOR]],
            ['Datasets known' => count($state[Field::ITEMS])],
            ['Folios held' => count($state[Field::RECEIPTS] ?? [])],
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, list<string>> $membership
     *
     * @return array<string, true>
     */
    public static function wanted(array $membership): array
    {
        return array_fill_keys(array_merge([], ...array_values($membership)), true);
    }

    /**
     * The wanted entries (all of them when $wanted is null), translated variants included, each
     * with whether its revision differs from the last successful sync (which forces a re-pull).
     *
     * @param list<FolioCatalogEntry> $entries
     * @param array<string, true>|null $wanted
     * @param array<string, string> $previous receipt key → revision
     *
     * @return list<array{0: FolioCatalogEntry, 1: bool}>
     */
    public static function select(array $entries, ?array $wanted, array $previous): array
    {
        $selected = [];
        foreach ($entries as $entry) {
            if ($wanted === null || isset($wanted[$entry->datasetKey])) {
                $selected[] = [$entry, ($previous[self::receiptKey($entry)] ?? null) !== $entry->revision];
            }
        }

        return $selected;
    }

    public static function receiptKey(FolioCatalogEntry $entry): string
    {
        return $entry->locale === null ? $entry->datasetKey : $entry->datasetKey.'#'.$entry->locale;
    }

    /** Whether the entry's folio is present at its published revision once this returns. */
    private function acquire(SymfonyStyle $io, FolioCatalogEntry $entry, bool $changed): bool
    {
        // Shared volume: the builder owns the files and has already replaced them. No archive
        // (an uncompressed folio Harvest only registered): nothing to download yet. Either way,
        // the file on disk is the answer.
        if ($this->localPassthrough || $entry->downloadUrl === null || !$entry->compressed) {
            if ($this->folios->exists($entry->datasetKey, $entry->locale)) {
                return true;
            }
            // Not repeated on every five-minute poll unless asked (-v).
            if ($io->isVerbose()) {
                $io->text(sprintf('%s: not on disk and %s.', self::receiptKey($entry),
                    $this->localPassthrough ? 'local_passthrough leaves acquisition to the folio owner' : 'Harvest publishes no archive yet'));
            }

            return false;
        }
        if ($this->pull->pullEntry($io, $entry, force: $changed) !== Command::SUCCESS) {
            throw new \RuntimeException('Folio synchronization failed: '.self::receiptKey($entry));
        }

        return true;
    }

    /** @return array<string, list<string>>|null set code → member dataset keys resolved now; null for scope "all" */
    private function membership(): ?array
    {
        if ($this->scope !== 'folio_sets' || $this->sets === null) {
            return null;
        }
        $membership = [];
        foreach (array_keys($this->sets->sets()) as $code) {
            $membership[$code] = array_column($this->sets->resolve($code), 'datasetKey');
        }

        return $membership;
    }

    /** @return array<string, list<string>|null>|null set code → keys recorded by the last refresh */
    private function recordedMembership(): ?array
    {
        if ($this->scope !== 'folio_sets' || $this->sets === null) {
            return null;
        }
        $membership = [];
        foreach (array_keys($this->sets->sets()) as $code) {
            $recorded = $this->sets->recorded($code);
            $membership[$code] = $recorded === null ? null : array_column($recorded['members'], 'datasetKey');
        }

        return $membership;
    }
}
