<?php
declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Survos\DataBundle\Service\DataPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('dataset:diag', 'Diagnose APP_DATA_DIR layout and show file stats (datasets or aggregators).')]
final class DataDiagCommand
{
    public function __construct(
        private readonly DataPaths $paths,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Name of dataset (e.g. "dc/tb09jw350"). If omitted, you will be prompted.')]
        ?string $name = null,

        #[Option('Comma-separated unit codes for aggregator mode (e.g. "aaa,nmah"). Omit to scan all units under the aggregator directory.')]
        ?string $unit = null,
    ): int {
        $io->title('Data diagnostics');

        $root = $this->paths->root;
        $io->writeln('APP_DATA_DIR: ' . $root);

        $datasetsRoot = $this->paths->datasetsRoot;
        if (!is_dir($datasetsRoot)) {
            $io->warning(sprintf('Datasets root does not exist: %s', $datasetsRoot));
            return Command::SUCCESS;
        }

        if (!is_string($name) || trim($name) === '') {
            $this->showAvailableAggregators($io, $datasetsRoot);
            return Command::FAILURE;
        }

        $name = trim($name);
        if (!str_contains($name, '/')) {
            $providerDir = $datasetsRoot . '/' . strtolower($name);
            if (is_dir($providerDir)) {
                $this->showAvailableDatasetsForAggregator($io, strtolower($name), $providerDir);
                return Command::FAILURE;
            }
        }

        // Determine mode: aggregator if data/<name> contains per-unit dirs and/or has 05_raw under subdirs.
        $name = strtolower(trim($name));
        $datasetDir = $this->paths->datasetDir($name);

        if (is_dir($datasetDir)) {
            return $this->diagDataset($io, $name);
        }

        $io->error(sprintf('No dataset found for "%s". Looked for %s', $name, $datasetDir));
        return Command::FAILURE;
    }

    private function showAvailableAggregators(SymfonyStyle $io, string $datasetsRoot): void
    {
        $providers = $this->listImmediateSubdirs($datasetsRoot);
        sort($providers, SORT_NATURAL | SORT_FLAG_CASE);

        if ($providers === []) {
            $io->warning('No aggregators found under datasets root.');
            return;
        }

        $io->section('Available aggregator codes (from disk)');
        foreach ($providers as $provider) {
            $io->writeln(' - ' . $provider);
        }

        $io->note('Run: bin/console dataset:diag <aggregator/code> (example: dc/tb09jw350)');
    }

    private function showAvailableDatasetsForAggregator(SymfonyStyle $io, string $provider, string $providerDir): void
    {
        $codes = $this->listImmediateSubdirs($providerDir);
        sort($codes, SORT_NATURAL | SORT_FLAG_CASE);

        if ($codes === []) {
            $io->warning(sprintf('No dataset codes found under aggregator "%s".', $provider));
            return;
        }

        $io->section(sprintf('Available datasets for aggregator "%s"', $provider));
        foreach ($codes as $code) {
            $io->writeln(sprintf(' - %s/%s', $provider, $code));
        }

        $io->note(sprintf('Run: bin/console dataset:diag %s/<code>', $provider));
    }

    private function diagAggregator(SymfonyStyle $io, string $aggregator, ?string $unitCsv): int
    {
        $io->section(sprintf('Aggregator: %s', $aggregator));

        $units = $unitCsv
            ? array_values(array_filter(array_map('trim', explode(',', strtolower($unitCsv)))))
            : $this->listImmediateSubdirs($this->paths->aggregatorDir($aggregator));

        if (!$units) {
            $io->warning('No units found under aggregator directory.');
            return Command::SUCCESS;
        }

        sort($units, SORT_NATURAL | SORT_FLAG_CASE);

        $totals = [
            'units' => count($units),
            'raw_files' => 0,
            'raw_dirs' => 0,
            'raw_lines' => 0,
        ];

        $rows = [];
        foreach ($units as $u) {
            $rawDir = $this->paths->aggregatorRawDir($aggregator, $u);

            $raw = $this->countFilesAndDirs($rawDir);
            $rawLines = 0;

            if ($io->isVeryVerbose()) {
                $rawLines = $this->countJsonlLinesInDir($rawDir);
            }

            $totals['raw_files'] += $raw['files'];
            $totals['raw_dirs']  += $raw['dirs'];
            $totals['raw_lines'] += $rawLines;

            if ($io->isVerbose()) {
                $row = [
                    strtoupper($u),
                    sprintf('05_raw: %d files', $raw['files']),
                ];
                if ($io->isVeryVerbose()) {
                    $row[] = sprintf('%d lines', $rawLines);
                }
                $rows[] = $row;
            }
        }

        $io->writeln(sprintf('05_raw: %d dirs, %d files', $totals['raw_dirs'], $totals['raw_files']));
        if ($io->isVeryVerbose()) {
            $io->writeln(sprintf('05_raw: %d total lines (.jsonl/.jsonl.gz)', $totals['raw_lines']));
        }

        if ($io->isVerbose() && $rows) {
            $headers = ['Unit', 'Raw'];
            if ($io->isVeryVerbose()) {
                $headers[] = 'Lines';
            }
            $io->table($headers, $rows);
        }

        return Command::SUCCESS;
    }

    private function diagDataset(SymfonyStyle $io, string $unit): int
    {
        $io->section(sprintf('Dataset: %s', $unit));

        $stages = [
            '05_raw' => $this->paths->stageDir($unit, '05_raw'),
            '10_extract' => $this->paths->stageDir($unit, '10_extract'),
            '20_normalize' => $this->paths->stageDir($unit, '20_normalize'),
            '21_profile' => $this->paths->stageDir($unit, '21_profile'),
            '30_terms' => $this->paths->stageDir($unit, '30_terms'),
        ];

        $rows = [];
        foreach ($stages as $label => $dir) {
            $counts = $this->countFilesAndDirs($dir);
            $lineCount = 0;

            if ($io->isVeryVerbose()) {
                $lineCount = $this->countJsonlLinesInDir($dir);
            }

            $summary = sprintf('%d dirs, %d files', $counts['dirs'], $counts['files']);
            $row = [$label, $summary, $dir];

            if ($io->isVeryVerbose()) {
                $row[] = sprintf('%d lines', $lineCount);
            }
            $rows[] = $row;
        }

        $headers = ['Stage', 'Counts', 'Path'];
        if ($io->isVeryVerbose()) {
            $headers[] = 'Lines';
        }

        $io->table($headers, $rows);

        // Also print a compact numeric summary like your example
        $extract = $this->countFilesAndDirs($this->paths->stageDir($unit, '10_extract'));
        $norm = $this->countFilesAndDirs($this->paths->stageDir($unit, '20_normalize'));
        $io->writeln(sprintf('10_extract: %d dirs, %d files', $extract['dirs'], $extract['files']));
        $io->writeln(sprintf('20_normalize: %d dirs, %d files', $norm['dirs'], $norm['files']));

        return Command::SUCCESS;
    }

    private function looksLikeAggregator(string $aggDir): bool
    {
        // Heuristic: if it has immediate subdirs that contain 05_raw, treat as aggregator.
        foreach ($this->listImmediateSubdirs($aggDir) as $unit) {
            if (is_dir($aggDir . '/' . $unit . '/05_raw')) {
                return true;
            }
        }
        return false;
    }

    private function listImmediateSubdirs(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        $dh = opendir($dir);
        if ($dh === false) {
            return [];
        }

        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $out[] = $entry;
            }
        }

        closedir($dh);
        return $out;
    }

    private function countFilesAndDirs(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['files' => 0, 'dirs' => 0];
        }

        $files = 0;
        $dirs = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $info) {
            if ($info->isDir()) {
                $dirs++;
            } else {
                $files++;
            }
        }

        return ['files' => $files, 'dirs' => $dirs];
    }

    private function countJsonlLinesInDir(string $dir): int
    {
        if (!is_dir($dir)) {
            return Command::SUCCESS;
        }

        $lines = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $info) {
            if (!$info->isFile()) {
                continue;
            }

            $path = $info->getPathname();
            if (str_ends_with($path, '.jsonl')) {
                $lines += $this->countLines($path);
            } elseif (str_ends_with($path, '.jsonl.gz')) {
                $lines += $this->countGzLines($path);
            }
        }

        return $lines;
    }

    private function countLines(string $file): int
    {
        $h = fopen($file, 'rb');
        if ($h === false) {
            return Command::SUCCESS;
        }

        $count = 0;
        while (!feof($h)) {
            $buf = fread($h, 1024 * 1024);
            if ($buf === false) {
                break;
            }
            $count += substr_count($buf, "\n");
        }
        fclose($h);

        return $count;
    }

    private function countGzLines(string $file): int
    {
        $h = gzopen($file, 'rb');
        if ($h === false) {
            return Command::SUCCESS;
        }

        $count = 0;
        while (!gzeof($h)) {
            $buf = gzread($h, 1024 * 1024);
            if ($buf === false) {
                break;
            }
            $count += substr_count($buf, "\n");
        }
        gzclose($h);

        return $count;
    }
}
