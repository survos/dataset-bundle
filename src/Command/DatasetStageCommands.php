<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\DatasetPaths;
use Survos\ClaimsBundle\Service\ClaimsVaultWriter;
use Survos\ImportBundle\Command\ImportConvertCommand;
use Survos\JsonlBundle\Sqlite\SqlProfiler;
use Symfony\Component\Console\Attribute\Argument;
use Psr\EventDispatcher\EventDispatcherInterface;
use Survos\DatasetBundle\Event\BuildFolioRequestedEvent;
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
        // Decoupled folio (re)build for --folio: dispatch BuildFolioRequestedEvent; folio-bundle
        // listens. Optional so a folio-less app still boots. (Can't inject a folio service — that
        // would be a circular dependency, folio→dataset.)
        private readonly ?EventDispatcherInterface $dispatcher = null,
        // Optional claims:fetch service (claims-bundle). When present, dataset:assemble refreshes the
        // vault claims.jsonl from the central claims DB BEFORE enriching — so you can't forget the
        // claims:fetch step and silently enrich against a stale snapshot. The SAME service backs the
        // claims:fetch command (no duplicated write logic). Null when claims-bundle isn't installed
        // or no claims DB is wired → assemble falls back to the existing claims.jsonl.
        private readonly ?ClaimsVaultWriter $claimsWriter = null,
    ) {}

    #[AsCommand('dataset:normalize', 'Normalize a dataset, code, or provider (→ norm/)', aliases: ['dataset:norm'])]
    public function normalize(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare code (e.g. "victoria")')] ?string $ref = null,
        #[Option('Fan out over every dataset for this provider (e.g. "smith")')] ?string $provider = null,
        #[Option('Normalize only this core stem (default: every core in _raw)')] ?string $core = null,
        #[Option('Convert every raw core for the dataset (default when --core is omitted)')] bool $allCores = false,
        #[Option('Max collections to normalize in a fan-out (--provider, or a bare code matching many)')] ?int $limit = null,
        #[Option('Max records to normalize per collection/core')] ?int $rowLimit = null,
        #[Option('Also write the SQL profile sidecar (.db) — off by default; turn on when designing field maps')] bool $profile = false,
        #[Option('Run the full chain to a folio: normalize → enrich (claims:fetch + fold current AI) → build')] bool $folio = false,
    ): int {
        // Default to discovering every core in _raw; --core restricts to one (the exception).
        $allCores = $allCores || $core === null;

        // Plain normalize — just the one stage.
        if (!$folio) {
            return $this->convertStage(
                $io,
                ref: $ref,
                provider: $provider,
                stage: Stage::Normalize,
                core: $core ?? 'obj',
                allCores: $allCores,
                rowLimit: $rowLimit,
                profile: $profile,
                datasetLimit: $limit,
            );
        }

        // --folio is the full raw→folio chain: normalize → enrich (claims:fetch + fold whatever AI is
        // ready) → build the folio. Enrich is a near-passthrough when there are no claims yet, so it's
        // always safe to include — running it here means `data:norm <ds> --folio` reliably reflects the
        // current AI without remembering a separate `dataset:assemble`. The build happens after enrich
        // (folio:build consumes _folio), not after normalize.
        $rc = $this->convertStage(
            $io,
            ref: $ref,
            provider: $provider,
            stage: Stage::Normalize,
            core: $core ?? 'obj',
            allCores: $allCores,
            rowLimit: $rowLimit,
            profile: $profile,
            datasetLimit: $limit,
        );
        if ($rc !== Command::SUCCESS) {
            return $rc;
        }

        return $this->convertStage(
            $io,
            ref: $ref,
            provider: $provider,
            stage: Stage::Enrich,
            core: $core ?? 'obj',
            allCores: $allCores,
            rowLimit: $rowLimit,
            folio: true,
            fetchClaims: true,
            datasetLimit: $limit,
        );
    }

    #[AsCommand(
        'dataset:assemble',
        'Enrich normalized rows with claims → the folio-input bundle (work/<ds>/_folio/)',
        aliases: ['dataset:enrich'],
        help: <<<'HELP'
            The "enrich" stage of the dataset pipeline. Reads the normalized core
            (work/<ds>/norm/<core>.jsonl), folds claims for each row from the shared claims DB
            (AI results written by mediary, plus any modeled source claims) onto the row's fields —
            e.g. ai:denseSummary → denseSummary, dcterms:subject → subjects — and writes the
            enriched folio-input bundle to work/<ds>/_folio/<core>.jsonl.

            That bundle is what <info>folio:build</info> consumes (it prefers _folio over norm), so the usual
            order is:  produce claims (dataset:ai, or media:ensure + media:sync → mediary)  →
            <info>dataset:assemble</info>  →  <info>folio:build</info>.

              <info>dataset:assemble mus/fortepan</info>                 enrich one dataset's obj core
              <info>dataset:assemble mus/fortepan --folio</info>         …and rebuild the folio to view it inline
              <info>dataset:assemble --provider smith --all-cores</info> every core of every smith dataset

            Claims are folded from the vault claims.jsonl (populate it with claims:fetch/claims:export);
            with no claims file this is a near-passthrough of the normalized rows.
            HELP,
    )]
    public function assemble(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare code (e.g. "victoria")')] ?string $ref = null,
        #[Option('Fan out over every dataset for this provider (e.g. "smith")')] ?string $provider = null,
        #[Option('Core filename stem')] string $core = 'obj',
        #[Option('Convert every raw core for the dataset')] bool $allCores = false,
        #[Option('Max records to assemble (per dataset/core)')] ?int $limit = null,
        #[Option('After enriching, (re)build the folio so you can see current folio data inline')] bool $folio = false,
        #[Option('Skip the automatic claims:fetch (enrich against the existing vault claims.jsonl as-is)')] bool $skipClaimsFetch = false,
    ): int {
        return $this->convertStage(
            $io,
            ref: $ref,
            provider: $provider,
            stage: Stage::Enrich,
            core: $core,
            allCores: $allCores,
            rowLimit: $limit,
            folio: $folio,
            fetchClaims: !$skipClaimsFetch,
        );
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

    private function convertStage(SymfonyStyle $io, ?string $ref, ?string $provider, Stage $stage, string $core, bool $allCores, ?int $rowLimit = null, bool $profile = false, bool $folio = false, ?int $datasetLimit = null, bool $fetchClaims = false): int
    {
        if ($this->convert === null) {
            $io->error('This stage runs import:convert, which is unavailable (survos/import-bundle not installed).');
            return Command::FAILURE;
        }

        $keys = $this->resolveDatasetKeys($io, $ref, $provider);
        if ($keys === null) {
            return Command::FAILURE;
        }

        // A fan-out (--provider, or a bare code matching many keys) walks every registered
        // dataset, but a provider like DC catalogues ~1000 collections and only fetches the
        // larger ones. A dataset with no raw on disk hasn't been fetched yet — that's an
        // expected state, not a failure — so skip it quietly and summarise at the end rather
        // than emitting a per-dataset [ERROR] for each. A single explicit target (one key)
        // still runs convert unconditionally, so its missing-raw error stays loud.
        $fanOut = count($keys) > 1;

        $converted = 0;
        $skipped = 0;
        $failed = 0;
        $failedKeys = [];
        foreach ($keys as $datasetKey) {
            // --limit caps the number of collections actually normalized in a fan-out (datasets
            // skipped for no-raw don't count toward it), distinct from --row-limit which caps rows
            // per collection. Stop once we've normalized that many.
            if ($datasetLimit !== null && $converted >= $datasetLimit) {
                break;
            }

            if ($fanOut && !$this->hasRawCore($datasetKey)) {
                $skipped++;
                continue;
            }

            if ($fanOut) {
                $io->section($datasetKey);
            }
            // Enrich folds claims from the vault claims.jsonl (materialised by `claims:fetch`) so it
            // never queries the live claims DB per row. DataPaths owns the vault layout; import-bundle
            // just reads the file we hand it.
            $claimsFile = $stage === Stage::Enrich ? $this->dataPaths->claimsFile($datasetKey) : null;
            // Refresh the vault claims.jsonl from the central claims DB before folding it in, so a
            // forgotten `claims:fetch` can't make enrich silently use a stale snapshot. Delegates to
            // the same ClaimsVaultWriter the claims:fetch command uses. Best-effort: if claims-bundle/
            // the claims DB isn't wired, or the fetch fails, fall back to whatever claims.jsonl already
            // exists. --skip-claims-fetch turns this off.
            if ($fetchClaims && $stage === Stage::Enrich && $this->claimsWriter?->isAvailable()) {
                try {
                    $r = $this->claimsWriter->write($datasetKey);
                    $io->writeln(sprintf('  claims:fetch %s → %d claim(s), %d run(s)', $datasetKey, $r['claims'], $r['runs']));
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Inline claims:fetch failed for %s (%s); enriching against the existing claims.jsonl.', $datasetKey, $e->getMessage()));
                }
            }
            $result = $this->convert->convert(
                $io,
                limit: $rowLimit,
                dataset: $datasetKey,
                stage: $stage->value,
                core: $core,
                allCores: $allCores,
                profile: $profile,
                claimsFile: $claimsFile,
            );
            if ($result !== Command::SUCCESS) {
                $failed++;
                $failedKeys[] = $datasetKey;
                continue;
            }
            $converted++;

            // --folio: (re)build this dataset's folio inline, right after it normalizes, via the
            // decoupling event (folio-bundle listens and runs the full folio:build path: ingest →
            // inflate working folio → register the artifact). Convenient during testing; the
            // production path will move normalize + folio into an async workflow.
            if ($folio) {
                if ($this->dispatcher === null) {
                    $io->warning('--folio requested but no event dispatcher / folio-bundle is available; skipping folio build.');
                } else {
                    // Inline `--folio` runs right after this stage rewrote the data, so always force —
                    // otherwise the build's freshness check can skip the now-stale folio.
                    $this->dispatcher->dispatch(new BuildFolioRequestedEvent($datasetKey, $allCores ? null : $core, $io, force: true));
                }
            }
        }

        if ($fanOut) {
            $verb = $stage === Stage::Enrich ? 'Enriched' : 'Normalized';
            $summary = sprintf('%s %d · skipped %d (no raw yet) · failed %d', $verb, $converted, $skipped, $failed);
            if ($failed > 0) {
                $io->warning($summary);
                $io->listing(array_slice($failedKeys, 0, 20));
            } else {
                $io->success($summary);
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** Cheap "has this dataset been fetched?" probe: any *.jsonl(.gz) in its _raw dir. */
    private function hasRawCore(string $datasetKey): bool
    {
        $rawDir = (new DatasetPaths($this->dataPaths, $datasetKey))->rawDir;
        if (!is_dir($rawDir)) {
            return false;
        }

        foreach (['*.jsonl', '*.jsonl.gz'] as $pattern) {
            if (glob("{$rawDir}/{$pattern}")) {
                return true;
            }
        }

        return false;
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
