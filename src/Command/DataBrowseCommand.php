<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Meta\DatasetMetadataLoader;
use Survos\DatasetBundle\Service\DataPaths;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('dataset:browse', 'Browse datasets and show basic stats')]
final class DataBrowseCommand
{
    public function __construct(
        private readonly DataPaths $paths,
        private readonly DatasetMetadataLoader $metadataLoader,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Dataset key or provider prefix to browse (e.g. fortepan/hu or fortepan)')]
        ?string $dataset = null,

        #[Option('Filter dataset keys (substring or /regex/)')]
        ?string $pattern = null,

        #[Option('Filter by provider code (e.g. dc)')]
        ?string $providerFilter = null,

        #[Option('Limit rows')]
        ?int $limit = null,

        #[Option('Compute raw/normalized sizes even without -v')]
        bool $sizes = false,
    ): int {
        // dataset arg acts as a convenient shorthand for --pattern
        if ($dataset !== null && $dataset !== '') {
            $pattern ??= $dataset;
        }

        $root = $this->paths->datasetsRoot;
        if (!is_dir($root)) {
            $io->error(sprintf('Datasets root not found: %s', $root));
            return Command::FAILURE;
        }

        $withSizes = $sizes || $io->isVerbose();
        $dirSizes = $io->isVeryVerbose();
        if ($dirSizes) {
            $io->note('Size columns are directory totals at -vv.');
        }

        $rows = [];
        $count = 0;

        foreach (new \DirectoryIterator($root) as $providerDir) {
            if (!$providerDir->isDir() || $providerDir->isDot()) {
                continue;
            }
            $provider = $providerDir->getFilename();
            if ($providerFilter !== null && $providerFilter !== '' && $providerFilter !== $providerDir->getFilename()) {
                continue;
            }
            foreach (new \DirectoryIterator($providerDir->getPathname()) as $datasetDir) {
                if (!$datasetDir->isDir() || $datasetDir->isDot()) {
                    continue;
                }
                $code = $datasetDir->getFilename();
                $datasetRef = $provider . '/' . $code;
                if (!$this->matchesPattern($datasetRef, $pattern)) {
                    continue;
                }

                $meta = $this->loadMeta($datasetRef);
                $label = (string) ($meta['label'] ?? '');
                $locale = (string) (($meta['locale']['default'] ?? null) ?: ($meta['sourceLang'] ?? ''));
                $key = $this->paths->datasetKeyFromRef($datasetRef);

                $rawSize = '-';
                $normSize = '-';
                if ($withSizes) {
                    $rawSize = $this->formatSize($this->getStageSize($datasetRef, 'raw', $dirSizes));
                    $normSize = $this->formatSize($this->getStageSize($datasetRef, 'normalized', $dirSizes));
                }

                $rows[] = [$datasetRef, $key, $label, $locale, $rawSize, $normSize];
                $count++;

                if ($limit !== null && $count >= $limit) {
                    break 2;
                }
            }
        }

        $io->table(['ref', 'key', 'title', 'locale', 'rawSize', 'normalizedSize'], $rows);
        $io->writeln(sprintf('Total: %d', $count));
        return Command::SUCCESS;
    }

    private function loadMeta(string $datasetKey): array
    {
        $metaDir = $this->paths->stageDir($datasetKey, 'meta');
        $metaFile = rtrim($metaDir, '/') . '/dataset.yaml';
        if (!is_file($metaFile)) {
            return [];
        }
        try {
            return $this->metadataLoader->load($metaFile);
        } catch (\Throwable) {
            return [];
        }
    }

    private function getStageSize(string $datasetKey, string $stage, bool $dirSizes): int
    {
        $stageDir = $this->paths->stageDir($datasetKey, $stage);
        if ($dirSizes) {
            return $this->dirSize($stageDir);
        }

        $file = rtrim($stageDir, '/') . '/' . $this->paths->defaultObjectFilename;
        if (!is_file($file)) {
            $gz = $file . '.gz';
            if (is_file($gz)) {
                $file = $gz;
            } else {
                return 0;
            }
        }
        return (int) @filesize($file);
    }

    private function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $size = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return sprintf('%.1f%s', $size, $units[$i]);
    }

    private function matchesPattern(string $value, ?string $pattern): bool
    {
        if ($pattern === null || $pattern === '') {
            return true;
        }
        $pattern = trim($pattern);
        if ($pattern === '') {
            return true;
        }
        if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
            return (bool) @preg_match($pattern, $value);
        }
        return str_contains($value, $pattern);
    }
}
