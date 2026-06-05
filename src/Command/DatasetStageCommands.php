<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Enum\Stage;
use Survos\ImportBundle\Command\ImportConvertCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dataset-aware stage shortcuts.
 *
 * These preset the import stage and delegate to the conversion service
 * (ImportConvertCommand::convert), passing the same SymfonyStyle — so the work is
 * initiated from dataset-bundle, not import-bundle, while `import:convert` still works
 * as before. <ref> is a dataset key (provider/code) or a bare provider (fan-out).
 *
 *   dataset:normalize mus/auur   ==  import:convert --dataset=mus/auur --stage=normalize  (→ norm/)
 *   dataset:norm dc              ==  import:convert --provider=dc      --stage=normalize
 *   dataset:assemble mus/auur    ==  import:convert --dataset=mus/auur --stage=enrich      (→ _folio/)
 */
final class DatasetStageCommands
{
    public function __construct(
        private readonly ImportConvertCommand $convert,
    ) {}

    #[AsCommand('dataset:normalize', 'Normalize a dataset or provider (→ norm/)', aliases: ['dataset:norm'])]
    public function normalize(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare provider')] string $ref,
        #[Option('Core filename stem')] string $core = 'obj',
        #[Option('Convert every raw core for the dataset')] bool $allCores = false,
    ): int {
        return $this->run($io, $ref, Stage::Normalize, $core, $allCores);
    }

    #[AsCommand('dataset:assemble', 'Assemble the folio-input bundle (→ _folio/)')]
    public function assemble(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare provider')] string $ref,
        #[Option('Core filename stem')] string $core = 'obj',
        #[Option('Convert every raw core for the dataset')] bool $allCores = false,
    ): int {
        return $this->run($io, $ref, Stage::Enrich, $core, $allCores);
    }

    private function run(SymfonyStyle $io, string $ref, Stage $stage, string $core, bool $allCores): int
    {
        $isDataset = str_contains($ref, '/');

        return $this->convert->convert(
            $io,
            dataset: $isDataset ? $ref : null,
            stage: $stage->value,
            core: $core,
            allCores: $allCores,
            provider: $isDataset ? null : $ref,
        );
    }
}
