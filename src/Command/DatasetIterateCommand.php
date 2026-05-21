<?php

declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Survos\DataBundle\Event\DatasetIterateFinishedEvent;
use Survos\DataBundle\Event\DatasetIterateRowEvent;
use Survos\DataBundle\Event\DatasetIterateStartedEvent;
use Survos\DataBundle\Service\DataPaths;
use Survos\DataBundle\Service\DatasetPaths;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsCommand('dataset:iterate', 'Iterate over a dataset stage JSONL and dispatch one event per row')]
final class DatasetIterateCommand
{
    public function __construct(
        private readonly DataPaths $dataPaths,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dataset key (e.g. fortepan/hu)')] string $dataset,
        #[Option('Stage to read: normalize, extract, raw, …')] string $stage = 'normalize',
        #[Option('Core filename stem')] string $core = 'obj',
        #[Option('Max rows to dispatch (0 = all)')] int $limit = 0,
    ): int {
        $paths = new DatasetPaths($this->dataPaths, $dataset);
        $file  = $paths->stageDir($stage) . "/{$core}.jsonl";

        if (!is_file($file)) {
            $io->error(sprintf('File not found: %s', $file));
            return Command::FAILURE;
        }

        $effectiveLimit = $limit > 0 ? $limit : null;

        $this->dispatcher->dispatch(new DatasetIterateStartedEvent($dataset, $stage, $file, $effectiveLimit));
        $io->text(sprintf('Iterating %s [%s] → %s', $dataset, $stage, $file));

        $reader = new JsonlReader($file);
        $count  = 0;

        foreach ($reader as $index => $row) {
            if ($effectiveLimit !== null && $count >= $effectiveLimit) {
                break;
            }
            $this->dispatcher->dispatch(new DatasetIterateRowEvent($dataset, $stage, $file, $row, $index, $effectiveLimit));
            $count++;
        }

        $this->dispatcher->dispatch(new DatasetIterateFinishedEvent($dataset, $stage, $file, $count, $effectiveLimit));
        $io->success(sprintf('%d row(s) dispatched.', $count));

        return Command::SUCCESS;
    }
}
