<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\DatasetPaths;
use Survos\ImportBundle\Command\ImportConvertCommand;
use Survos\JsonlBundle\Sqlite\SqlProfiler;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Bytes;

/**
 * Dataset-aware stage shortcuts.
 *
 * normalize/assemble preset the import stage and delegate to the conversion service
 * (ImportConvertCommand::convert), passing the same SymfonyStyle — so the work is
 * initiated from dataset-bundle, not import-bundle, while `import:convert` still works
 * as before.
 *
 * The target is resolved naturally from the <ref> argument and/or --provider:
 *   - --provider=smith     → every dataset registered for "smith" (fan-out)
 *   - mus/victoria         → that exact dataset key
 *   - victoria             → the dataset whose code is "victoria" (e.g. mus/victoria);
 *                            a bare token is a CODE, never a provider prefix
 *
 *   dataset:normalize mus/auur     (→ norm/)
 *   dataset:norm --provider=smith  (fan-out → norm/)
 *   dataset:assemble mus/auur      (→ _folio/)
 *
 * profile is the dataset-aware wrapper over the *scalable* SQL profiler
 * (SqlProfiler → <core>.jsonl.db sidecar), the same engine as `jsonl:profile`. It
 * deliberately does NOT regenerate the legacy <core>.profile.json blob, which inlines
 * sample rows and does not scale (a 118 MB core yields an ~89 MB profile.json).
 *
 *   dataset:profile mus/auur     ==  jsonl:profile <APP_DATA_DIR>/work/mus/auur/norm/obj.jsonl  (→ obj.jsonl.db)
 */
final class DatasetStageCommands
{
    public function __construct(
        private readonly DatasetInfoRepository $datasets,
        private readonly SqlProfiler $profiler,
        private readonly DataPaths $dataPaths,
        // Optional + last with a default: these stage commands drive import:convert (import-bundle).
        // Autowiring leaves it null when import-bundle isn't installed (optional arg → default used),
        // so a bare app that only reads/displays datasets still boots. convertStage() guards on null.
        private readonly ?ImportConvertCommand $convert = null,
    ) {}

    #[AsCommand('dataset:normalize', 'Normalize a dataset, code, or provider (→ norm/)', aliases: ['dataset:norm'])]
    public function normalize(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare code (e.g. "victoria")')] ?string $ref = null,
        #[Option('Fan out over every dataset for this provider (e.g. "smith")')] ?string $provider = null,
        #[Option('Normalize only this core stem (default: every core in _raw)')] ?string $core = null,
        #[Option('Convert every raw core for the dataset (default when --core is omitted)')] bool $allCores = false,
        #[Option('Max records to normalize (per dataset/core)')] ?int $limit = null,
    ): int {
        // Default to discovering every core in _raw; --core restricts to one (the exception).
        $allCores = $allCores || $core === null;

        return $this->convertStage($io, $ref, $provider, Stage::Normalize, $core ?? 'obj', $allCores, $limit);
    }

    #[AsCommand('dataset:assemble', 'Assemble the folio-input bundle (→ _folio/)')]
    public function assemble(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare code (e.g. "victoria")')] ?string $ref = null,
        #[Option('Fan out over every dataset for this provider (e.g. "smith")')] ?string $provider = null,
        #[Option('Core filename stem')] string $core = 'obj',
        #[Option('Convert every raw core for the dataset')] bool $allCores = false,
        #[Option('Max records to assemble (per dataset/core)')] ?int $limit = null,
    ): int {
        return $this->convertStage($io, $ref, $provider, Stage::Enrich, $core, $allCores, $limit);
    }

    /**
     * (Re-)profile a stage core into its scalable SQL sidecar (<core>.jsonl.db).
     *
     * Thin dataset-aware wrapper over SqlProfiler::profile() — resolves the target(s)
     * to the on-disk JSONL and streams each into its sidecar (field stats, top values,
     * facet attrs, pk→offset index). No legacy profile.json.
     */
    #[AsCommand('dataset:profile', 'Profile a stage core into its SQL sidecar (→ <core>.jsonl.db)', aliases: ['dataset:prof'])]
    public function profile(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare code (e.g. "victoria")')] ?string $ref = null,
        #[Option('Fan out over every dataset for this provider (e.g. "smith")')] ?string $provider = null,
        #[Option('Core filename stem (the JSONL to profile)')] string $core = 'obj',
        #[Option('Stage JSONL to profile: normalize, raw, enrich')] string $stage = 'normalize',
    ): int {
        $stageEnum = Stage::fromKey($stage);

        $keys = $this->resolveDatasetKeys($io, $ref, $provider);
        if ($keys === null) {
            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($keys as $datasetKey) {
            if (count($keys) > 1) {
                $io->section($datasetKey);
            }

            $jsonl = $this->stageCoreFile($datasetKey, $stageEnum, $core);
            if ($jsonl === null) {
                $io->warning(sprintf('No "%s" core JSONL in the %s stage for %s.', $core, $stageEnum->value, $datasetKey));
                $failed++;
                continue;
            }

            $result = $this->profiler->profile($jsonl);
            $sidecar = $jsonl . '.db';

            $io->success(sprintf(
                '%s — %d rows, %d fields, %d invalid → %s (%s)',
                $datasetKey,
                $result->rows,
                $result->fields,
                $result->invalid,
                $sidecar,
                is_file($sidecar) ? (string) Bytes::parse(filesize($sidecar))->humanize() : 'n/a',
            ));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function convertStage(SymfonyStyle $io, ?string $ref, ?string $provider, Stage $stage, string $core, bool $allCores, ?int $limit = null): int
    {
        if ($this->convert === null) {
            $io->error('This stage runs import:convert, which is unavailable (survos/import-bundle not installed).');
            return Command::FAILURE;
        }

        $keys = $this->resolveDatasetKeys($io, $ref, $provider);
        if ($keys === null) {
            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($keys as $datasetKey) {
            if (count($keys) > 1) {
                $io->section($datasetKey);
            }
            $result = $this->convert->convert(
                $io,
                limit: $limit,
                dataset: $datasetKey,
                stage: $stage->value,
                core: $core,
                allCores: $allCores,
            );
            if ($result !== Command::SUCCESS) {
                $failed++;
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolve the natural target spec to concrete dataset keys.
     *
     *   --provider=smith   → every "smith/*" key
     *   mus/victoria       → ['mus/victoria']
     *   victoria           → every "*\/victoria" key (a bare token is a code)
     *
     * Returns null (after warning/erroring) when nothing resolves.
     *
     * @return list<string>|null
     */
    private function resolveDatasetKeys(SymfonyStyle $io, ?string $ref, ?string $provider): ?array
    {
        $provider = $provider !== null ? strtolower(trim($provider)) : '';
        if ($provider !== '') {
            return $this->queryDatasetKeys($io, 'd.datasetKey LIKE :p', ['p' => $provider . '/%'], sprintf('provider "%s"', $provider));
        }

        $ref = trim((string) $ref);
        if ($ref === '') {
            $io->error('Specify a dataset (provider/code or a bare code) or --provider=<code>.');
            return null;
        }

        if (str_contains($ref, '/')) {
            return [strtolower($ref)];
        }

        // Bare token: treat as a dataset code, not a provider. Match "<anything>/<code>".
        $code = strtolower($ref);
        return $this->queryDatasetKeys($io, 'd.datasetKey LIKE :c', ['c' => '%/' . $code], sprintf('code "%s"', $code));
    }

    /**
     * @param array<string,string> $params
     * @return list<string>|null
     */
    private function queryDatasetKeys(SymfonyStyle $io, string $where, array $params, string $label): ?array
    {
        $qb = $this->datasets->createQueryBuilder('d')->andWhere($where)->orderBy('d.datasetKey', 'ASC');
        foreach ($params as $name => $value) {
            $qb->setParameter($name, $value);
        }

        $keys = array_values(array_map(
            static fn (DatasetInfo $d): string => $d->datasetKey,
            array_filter($qb->getQuery()->getResult(), static fn ($d): bool => $d instanceof DatasetInfo),
        ));

        if ($keys === []) {
            $io->warning(sprintf('No datasets registered for %s. Run dataset:scan first.', $label));
            return null;
        }

        return $keys;
    }

    /** Resolve the on-disk JSONL for a (dataset, stage, core), preferring plain over .gz. */
    private function stageCoreFile(string $datasetKey, Stage $stage, string $core): ?string
    {
        $dir = (new DatasetPaths($this->dataPaths, $datasetKey))->stageDir($stage);
        foreach (["{$dir}/{$core}.jsonl", "{$dir}/{$core}.jsonl.gz"] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
