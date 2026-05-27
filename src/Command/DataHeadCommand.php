<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Service\DataPaths;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('dataset:head', 'Print first N JSONL lines from a dataset stage')]
final class DataHeadCommand
{
    public function __construct(
        private readonly DataPaths $paths,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,

        #[Argument('Dataset key (e.g. "larco")')]
        string $dataset,

        #[Argument('Stage alias (raw|normalize|profile|terms|...)')]
        ?string $stage = null,

        #[Option('Filename within stage (defaults to obj.jsonl)')]
        ?string $file = null,

        #[Option('Number of lines', name: 'limit')]
        int $lines = 5,
    ): int {
        if ($lines <= 0) {
            $io->warning('Line count must be > 0.');
            return Command::FAILURE;
        }

        $datasetRef = trim($dataset);
        $stage ??= 'normalize';
        $target = $this->paths->firstReadableStageFile($datasetRef, $stage, $file);

        if ($target === null) {
            $io->error(sprintf(
                'File not found. Tried: %s',
                implode(', ', $this->paths->stageFileCandidates($datasetRef, $stage, $file))
            ));
            return Command::FAILURE;
        }

        $count = 0;
        $handle = $this->openFile($target);
        if ($handle === null) {
            $io->error(sprintf('Unable to open file: %s', $target));
            return Command::FAILURE;
        }

        try {
            while ($count < $lines) {
                $line = $this->readLine($handle);
                if ($line === null) {
                    break;
                }
                $output->write($line);
                $count++;
            }
        } finally {
            $this->closeFile($handle);
        }

        return Command::SUCCESS;
    }

    private function openFile(string $path): mixed
    {
        if (str_ends_with($path, '.gz')) {
            return @gzopen($path, 'rb');
        }
        return @fopen($path, 'rb');
    }

    private function readLine(mixed $handle): ?string
    {
        if (get_resource_type($handle) === 'gzip') {
            $line = gzgets($handle);
        } else {
            $line = fgets($handle);
        }
        if ($line === false) {
            return null;
        }
        return $line;
    }

    private function closeFile(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        if (get_resource_type($handle) === 'gzip') {
            gzclose($handle);
        } else {
            fclose($handle);
        }
    }
}
