<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
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
        private readonly DatasetInfoRepository $datasets,
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
        if ($isDataset) {
            return $this->convert->convert(
                $io,
                dataset: $ref,
                stage: $stage->value,
                core: $core,
                allCores: $allCores,
            );
        }

        $provider = strtolower(trim($ref));
        $datasetInfos = $this->datasets->createQueryBuilder('d')
            ->andWhere('d.datasetKey LIKE :prefix')
            ->setParameter('prefix', $provider . '/%')
            ->orderBy('d.datasetKey', 'ASC')
            ->getQuery()
            ->getResult();

        if ($datasetInfos === []) {
            $io->warning(sprintf('No datasets registered for provider "%s". Run dataset:scan first.', $provider));
            return 0;
        }

        $failed = 0;
        foreach ($datasetInfos as $datasetInfo) {
            if (!$datasetInfo instanceof DatasetInfo) {
                continue;
            }
            $io->section($datasetInfo->datasetKey);
            $result = $this->convert->convert(
                $io,
                dataset: $datasetInfo->datasetKey,
                stage: $stage->value,
                core: $core,
                allCores: $allCores,
            );
            if ($result !== 0) {
                $failed++;
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
