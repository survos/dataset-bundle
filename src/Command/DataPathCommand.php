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

#[AsCommand('dataset:path', 'Resolve dataset stage path')]
final class DataPathCommand
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

        #[Argument('Stage alias (raw|meta|normalize|profile|terms|...)')]
        string $stage,

        #[Option('Filename within stage (defaults to obj.jsonl for data stages)')]
        ?string $file = null,
    ): int {
        $datasetRef = trim($dataset);
        $stageDir = $this->paths->stageDir($datasetRef, $stage);

        $target = $stageDir;
        if ($file !== null && $file !== '') {
            $target = rtrim($stageDir, '/') . '/' . ltrim($file, '/');
        } elseif ($stage !== 'meta' && $stage !== '00_meta') {
            $target = rtrim($stageDir, '/') . '/' . $this->paths->defaultObjectFilename;
        }

        $output->writeln($target);
        return Command::SUCCESS;
    }
}
