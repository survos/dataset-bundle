<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;

/**
 * Reset one dataset back to "fresh from raw": delete every generated work stage + the folio and its
 * archive, and reset the dataset_info workflow state — WITHOUT ever touching the vault.
 *
 * The reusable core (shared by dataset:purge and the app's agg:reset) so reset logic lives in ONE
 * place. "Never touch the vault" is structural: we delete every child of work/<p>/<c> EXCEPT the
 * names in {@see self::PRESERVE}. `_raw` is a tier portal (symlink → vault/<p>/<c>/_raw) for
 * migrated datasets and a real-but-precious dir for un-migrated ones (e.g. nara); excluding it by
 * name is safe in both. `_meta` is kept so the dataset metadata stub survives a reset.
 */
final class DatasetReset
{
    /** work/<p>/<c> children that are never deleted — the vault portal and the metadata stub. */
    public const array PRESERVE = ['_raw', '_meta'];

    public function __construct(
        private readonly DataPaths $paths,
        private readonly ManagerRegistry $registry,
        // Optional: a bare app without configured workflows has no workflow.registry; marking then
        // resets to null instead of the workflow's initial place.
        #[Autowire(service: 'workflow.registry')]
        private readonly ?WorkflowRegistry $workflows = null,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Every existing filesystem path a reset would remove for one dataset: all work-dir children
     * except _raw/_meta, plus (when $folios) the working folio and its .gz archive. Pure — computes,
     * deletes nothing — so callers can preview (dry-run) with the same logic that does the work.
     *
     * @return list<string>
     */
    public function purgePaths(string $datasetKey, bool $folios = true): array
    {
        $out = [];

        $workDir = $this->paths->datasetDir($datasetKey);
        if (is_dir($workDir)) {
            foreach (scandir($workDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || \in_array($entry, self::PRESERVE, true)) {
                    continue;
                }
                $out[] = $workDir . '/' . $entry;
            }
        }

        if ($folios) {
            foreach ([$this->paths->folioFile($datasetKey), $this->paths->folioArchiveFile($datasetKey)] as $folio) {
                if (is_file($folio)) {
                    $out[] = $folio;
                }
            }
        }

        return $out;
    }

    /**
     * Delete the generated artifacts and (when $resetState) reset the dataset_info row's workflow
     * state. Returns what happened so callers can report it.
     *
     * @return array{paths: list<string>, marking: ?string, stateReset: bool}
     */
    public function reset(string $datasetKey, bool $folios = true, bool $resetState = true): array
    {
        $paths = $this->purgePaths($datasetKey, $folios);
        if ($paths !== []) {
            $this->filesystem->remove($paths);
        }

        $marking = null;
        $stateReset = false;
        if ($resetState) {
            $marking = $this->resetDbState($datasetKey, $stateReset);
        }

        return ['paths' => $paths, 'marking' => $marking, 'stateReset' => $stateReset];
    }

    /**
     * REMOVE the dataset_info row, rather than rewinding its marking in place.
     *
     * Rewinding looked equivalent and was not: the workflow is started by
     * InitialPlaceKickoffListener, which fires on postPersist. Setting marking back to the initial
     * place is an UPDATE, so nothing was ever dispatched — a reset dataset sat at `new` with an
     * empty queue and no error, and the only way forward was to kick it by hand with
     * `state:iterate -m new -t raw`. That is the old manual kickstart, not part of the workflow.
     *
     * Deleting makes the next `dataset:scan` persist a genuinely new entity, so the ordinary
     * kickoff runs and the chain starts on its own. No second kickoff implementation lives here —
     * which is the whole point: InitialPlaceKickoffListener exists precisely because apps kept
     * hand-rolling that dispatch and getting it subtly wrong.
     *
     * Safe to delete because the row is derived, not authored: dataset:scan rebuilds it from
     * _meta/dataset.json plus a disk scan, and rediscovers artifacts from the folio files
     * themselves. Cascade removes this dataset's Artifact rows with it (orphanRemoval), which the
     * same scan repopulates. The primary key is the natural datasetKey, not an autoincrement id,
     * so identity survives the round trip and nothing can be left pointing at a stale id.
     *
     * @param-out bool $found whether a dataset_info row was removed
     */
    private function resetDbState(string $datasetKey, bool &$found): ?string
    {
        $em = $this->registry->getManagerForClass(DatasetInfo::class);
        if ($em === null) {
            return null;
        }

        $info = $em->getRepository(DatasetInfo::class)->findOneBy(['datasetKey' => $datasetKey]);
        if (!$info instanceof DatasetInfo) {
            return null;
        }

        // Read the initial place before removing, purely so the caller can report what the row
        // will come back as; nothing here writes it.
        $marking = $this->initialMarking($info);

        $em->remove($info);
        $em->flush();

        $found = true;

        return $marking;
    }

    private function initialMarking(object $info): ?string
    {
        if ($this->workflows === null) {
            return null;
        }

        try {
            $workflow = $this->workflows->get($info);
        } catch (\Throwable) {
            return null;
        }

        return $workflow->getDefinition()->getInitialPlaces()[0] ?? null;
    }
}
