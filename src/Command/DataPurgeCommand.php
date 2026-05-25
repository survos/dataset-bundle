<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Service\DataPaths;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[AsCommand('dataset:purge', 'Delete generated dataset artifacts')]
final class DataPurgeCommand
{
    private const array GENERATED_STAGES = ['normalize', 'enriched', 'enrich_profile'];

    public function __construct(
        private readonly DataPaths $paths,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dataset key, e.g. mus/aust. Omit with --provider to purge all provider datasets.')]
        ?string $dataset = null,
        #[Option('Only purge datasets for this provider, e.g. mus')]
        ?string $provider = null,
        #[Option('Also delete folio SQLite files for selected datasets')]
        bool $folios = false,
        #[Option('Delete files instead of showing what would be removed')]
        bool $force = false,
    ): int {
        $targets = $this->datasetTargets($dataset, $provider);
        if ($targets === []) {
            $io->warning('No dataset directories matched.');
            return Command::SUCCESS;
        }

        $paths = [];
        foreach ($targets as $datasetKey) {
            foreach (self::GENERATED_STAGES as $stage) {
                $dir = $this->paths->stageDir($datasetKey, $stage);
                if (is_dir($dir)) {
                    $paths[] = $dir;
                }
            }

            if ($folios) {
                $folio = $this->folioPath($datasetKey);
                if (is_file($folio)) {
                    $paths[] = $folio;
                }
            }
        }

        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            $io->warning('No generated artifacts found.');
            return Command::SUCCESS;
        }

        $io->title($force ? 'Purging generated artifacts' : 'Generated artifacts purge dry-run');
        $io->listing($paths);

        if (!$force) {
            $io->note('Re-run with --force to delete these paths.');
            return Command::SUCCESS;
        }

        $this->filesystem->remove($paths);
        $io->success(sprintf('Deleted %d path(s).', count($paths)));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function datasetTargets(?string $dataset, ?string $provider): array
    {
        if ($dataset !== null && trim($dataset) !== '') {
            return [$dataset];
        }

        if ($provider === null || trim($provider) === '') {
            throw new \InvalidArgumentException('Pass a dataset key or --provider.');
        }

        $providerRoot = $this->paths->providerRoot($provider);
        if (!is_dir($providerRoot)) {
            return [];
        }

        $targets = [];
        $finder = (new Finder())->directories()->depth('== 0')->in($providerRoot)->sortByName();
        foreach ($finder as $dir) {
            $targets[] = trim($provider) . '/' . $dir->getBasename();
        }

        return $targets;
    }

    private function folioPath(string $datasetKey): string
    {
        $parsed = $this->paths->parseDatasetRef($datasetKey);

        return sprintf(
            '%s/folio/%s/%s.folio.sqlite',
            $this->paths->root,
            $parsed['provider'],
            $parsed['code'],
        );
    }
}
