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
     * Reset the dataset_info row to its workflow's initial place, with no docs and no pending steps.
     * The initial place is read from the registered workflow (so we don't hard-code an app-defined
     * place name); null when the dataset isn't registered or no workflow applies.
     *
     * @param-out bool $found whether a dataset_info row was updated
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

        $marking = $this->initialMarking($info);
        $info->marking = $marking;
        $info->pendingSteps = [];
        $info->meiliDocCount = null;
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
