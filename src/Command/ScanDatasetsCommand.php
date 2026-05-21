<?php
declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataBundle\Entity\Artifact;
use Survos\DataBundle\Entity\DatasetInfo;
use Survos\DataBundle\Entity\Provider;
use Survos\DataBundle\Repository\ArtifactRepository;
use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\DataBundle\Repository\ProviderRepository;
use Survos\DataBundle\Service\DatasetPaths;
use Survos\DataBundle\Service\ProviderSnapshotCodec;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scans APP_DATA_DIR for 00_meta/dataset.yaml files and populates DatasetInfo.
 *
 * Run once after fetching/normalizing data, then all registry lookups
 * use the DB — no more directory scanning at runtime.
 *
 * Usage:
 *   bin/console dataset:scan                    # all providers
 *   bin/console dataset:scan --provider=fortepan
 *   bin/console dataset:scan --provider=dc --limit=10
 */
#[AsCommand('dataset:scan',
    'Scan APP_DATA_DIR for 00_meta/dataset.json + folio DBs and populate DatasetInfo registry')]
final class ScanDatasetsCommand extends DataCommand
{
    /** @param list<string> $enabledProviders */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArtifactRepository $artifactRepository,
        private readonly ProviderRepository $providerRepo,
        private readonly DatasetInfoRepository $datasetRepository,
        private readonly ProviderSnapshotCodec $providerSnapshotCodec,
        private readonly array $enabledProviders = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only scan this provider (e.g. fortepan, dc, pp)')] ?string $provider = null,
        #[Option('Max datasets to process (0 = all)')] int $limit = 0,
        #[Option('Re-scan even if DatasetInfo already exists')] bool $force = false,
        #[Option('Only update status/counts, skip re-reading meta')] bool $statusOnly = false,
        #[Option('Folio DB directory (defaults to APP_DATA_DIR/folio)')] ?string $folioDir = null,
        #[Option('Open each folio SQLite and collect row counts + DTO class breakdown (--no-folio to skip)')] ?bool $folio = null,
        #[Option('Delete existing DatasetInfo rows before scanning (scoped to --provider if given)')] bool $reset = false,
    ): int {
        $io->title('Scanning datasets → DatasetInfo registry');

        $repo    = $this->datasetRepository;
        $created = $updated = $skipped = 0;
        $count   = 0;

        if ($reset) {
            $this->em->createQueryBuilder()->delete(Artifact::class, 'a')
                ->getQuery()->execute();
            $deleted = $this->em->createQueryBuilder()->delete(DatasetInfo::class, 'd')
                ->getQuery()->execute();
            $this->em->flush();
            $io->text(sprintf('Reset: deleted %d DatasetInfo row(s).', $deleted));
        }

        // ── Provider preflight: all provider dirs must have provider.json + DB row ──
        $root = $this->dataPaths->workRoot;
        $providerDirs = $this->listProviderDirs($root, $provider);
        if ($this->enabledProviders !== [] && $provider === null) {
            $io->text(sprintf('Provider allowlist from survos_data.providers: %s', implode(', ', $this->normalizedEnabledProviders())));
        }

        if ($providerDirs === []) {
            $io->warning(sprintf('No provider directories found in %s', $root));
        }

        $missingProviderJson = [];
        $invalidProviderJson = [];
        $providersByCode = [];

        foreach ($providerDirs as $providerCode => $providerDir) {
            $providerJsonFile = $providerDir . '/provider.json';
            if (!is_file($providerJsonFile)) {
                $missingProviderJson[] = $providerJsonFile;
                continue;
            }

            try {
                $snapshot = $this->providerSnapshotCodec->fromFile($providerJsonFile, $providerCode);
            } catch (\Throwable $e) {
                $invalidProviderJson[] = sprintf('%s (%s)', $providerJsonFile, $e->getMessage());
                continue;
            }

            if ($snapshot->code !== null && strtolower($snapshot->code) !== $providerCode) {
                $invalidProviderJson[] = sprintf(
                    '%s (code mismatch: file says "%s", dir is "%s")',
                    $providerJsonFile,
                    $snapshot->code,
                    $providerCode
                );
                continue;
            }

            $providerEntity = $this->providerRepo->findOneByCode($providerCode) ?? new Provider($providerCode);
            $this->providerSnapshotCodec->applyToProvider($snapshot, $providerEntity);
            $providerEntity->setSyncedAt(new \DateTime());
            $this->em->persist($providerEntity);
            $providersByCode[$providerCode] = $providerEntity;
        }

        if ($missingProviderJson !== [] || $invalidProviderJson !== []) {
            if ($missingProviderJson !== []) {
                $io->warning('Provider directories missing provider.json (skipped — run agg:sync to generate):');
                $io->listing($missingProviderJson);
            }
            if ($invalidProviderJson !== []) {
                $io->error('Invalid provider.json files:');
                $io->listing($invalidProviderJson);
                return Command::FAILURE;
            }
        }

        $this->em->flush();

        // ── Phase 1: scan each provider dir for dataset metadata JSON ─────────────
        $totalMetaFiles = 0;
        foreach ($providerDirs as $providerCode => $providerDir) {
            if (!isset($providersByCode[$providerCode])) {
                continue;
            }

            $files = glob($providerDir . '/*/00_meta/dataset.json', GLOB_NOSORT) ?: [];
            $totalMetaFiles += count($files);

            foreach ($files as $metaFile) {
                if ($limit > 0 && $count >= $limit) {
                    break 2;
                }

                $meta = json_decode(file_get_contents($metaFile), true, 512, JSON_THROW_ON_ERROR);
                $meta = $this->normalizeMeta($meta);
                $datasetKey = $meta['dataset_key'] ?? $meta['datasetKey'] ?? null;
                if (!$datasetKey) {
                    $code = basename(dirname(dirname($metaFile)));
                    $datasetKey = sprintf('%s/%s', $providerCode, $code);
                }

                if (!str_contains($datasetKey, '/') && str_starts_with($datasetKey, $providerCode . '-')) {
                    $datasetKey = $providerCode . '/' . substr($datasetKey, strlen($providerCode) + 1);
                }

                $datasetProvider = explode('/', $datasetKey, 2)[0] ?? '';
                if ($datasetProvider !== $providerCode) {
                    $io->warning(sprintf('Skipping %s (datasetKey provider "%s" != dir provider "%s")', $metaFile, $datasetProvider, $providerCode));
                    continue;
                }

                $existing = $repo->find($datasetKey);

                if ($existing && $existing->metaPath !== null && !$force && !$statusOnly) {
                    $existing->setProviderEntity($providersByCode[$providerCode]);
                    $updated++;
                    $count++;
                    continue;
                }

                $info = $existing ?? new DatasetInfo($datasetKey);
                $info->setProviderEntity($providersByCode[$providerCode]);

                if (!$statusOnly) {
                    $this->populateFromMeta($info, $meta, $metaFile);
                }

                if (!$existing) {
                    $this->em->persist($info);
                    $created++;
                } else {
                    $updated++;
                }

                $count++;

                if ($count % 100 === 0) {
                    $this->em->flush();
                    $io->text(sprintf('  %d processed...', $count));
                }
            }
        }

        $io->text(sprintf('Phase 1: %d provider(s), %d meta files in %s', count($providerDirs), $totalMetaFiles, $root));

        $this->em->flush();

        // ── Phase 2: Scan folio DB directory — upsert Artifact rows ──────────
        $folioPath   = rtrim($folioDir ?? ($this->dataPaths->root . '/folio'), '/');
        $folioFiles  = glob($folioPath . '/*/*.folio.sqlite') ?: [];
        $allowedProviders = $provider !== null && $provider !== ''
            ? [strtolower(trim($provider)) => true]
            : array_fill_keys($this->normalizedEnabledProviders(), true);

        $io->text(sprintf('Phase 2: Found %d folio .sqlite files in %s', count($folioFiles), $folioPath));

        $folioUpdated = 0;
        foreach ($folioFiles as $dbFile) {
            // Path is provider/dataset.folio.sqlite — strip base dir and extension to get dataset key.
            $relative   = substr($dbFile, strlen($folioPath) + 1);
            $datasetKey = preg_replace('/\.folio\.sqlite$/', '', $relative);
            $folioProviderCode = explode('/', $datasetKey, 2)[0] ?? '';
            if ($allowedProviders !== [] && !isset($allowedProviders[$folioProviderCode])) {
                continue;
            }

            $info = $repo->find($datasetKey);
            if (!$info) {
                $info = new DatasetInfo($datasetKey);
                $info->aggregator = $info->provider();
                $this->em->persist($info);
            }

            $providerCode = $info->provider();
            $providerEntity = $providersByCode[$providerCode]
                ?? $this->providerRepo->findOneByCode($providerCode)
                ?? new Provider($providerCode);

            if ($providerEntity->getCode() !== null) {
                $providerEntity->setSyncedAt(new \DateTime());
                $this->em->persist($providerEntity);
                $providersByCode[$providerCode] = $providerEntity;
                $info->setProviderEntity($providerEntity);
            }

            $folio ??= true;
            $summary = $folio ? $this->summarizeFolio($dbFile) : ['rowCount' => null, 'cores' => [], 'coreCounts' => [], 'dtoCounts' => null];
            $artifact = $this->artifactRepository->findOneBy([
                'dataset' => $info,
                'type' => Artifact::TYPE_FOLIO,
                'code' => Artifact::CODE_DEFAULT,
            ]) ?? new Artifact($info, Artifact::TYPE_FOLIO);

            $artifact->uri = $dbFile;
            $artifact->sizeBytes = is_file($dbFile) ? filesize($dbFile) : null;
            $artifact->rowCount  = $summary['rowCount'];
            $artifact->dtoCounts = $summary['dtoCounts'];
            $artifact->updatedAt = is_file($dbFile) ? (new \DateTimeImmutable())->setTimestamp((int) filemtime($dbFile)) : null;
            $artifact->discoveredAt = new \DateTimeImmutable();
            $artifact->metadata = [
                'relativePath' => $relative,
                'cores'        => $summary['cores'],
                'coreCounts'   => $summary['coreCounts'],
            ];

            $info->addArtifact($artifact);
            $this->em->persist($artifact);
            $folioUpdated++;
        }

        if ($folioUpdated > 0) {
            $this->em->flush();
        }

        // ── Phase 3: Update status for all scanned entries ────────────────────
        foreach ($repo->findAll() as $info) {
            $this->updateStatus($info);
            $this->ensureMaterializedDirectories($info);
        }
        $this->em->flush();

        // ── Phase 4: refresh provider dataset counts (cached on Provider) ─────
        $providerCountRows = [];
        foreach ($providersByCode as $providerCode => $providerEntity) {
            $datasetCount = $repo->count(['providerEntity' => $providerEntity]);
            $providerEntity->setDatasetCount($datasetCount);
            $providerEntity->setSyncedAt(new \DateTime());

            $providerCountRows[] = [$providerCode, (string) $datasetCount];
        }
        usort($providerCountRows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $this->em->flush();

        $io->success(sprintf(
            'Done — created: %d, updated: %d, skipped: %d, folio DBs matched: %d',
            $created, $updated, $skipped, $folioUpdated
        ));

        $io->section('Provider dataset counts');
        $io->table(['Provider', 'Dataset count'], $providerCountRows);

        // Show what has folio DBs
        $withDb = $repo->createQueryBuilder('d')
            ->join('d.artifacts', 'a')
            ->where('a.type = :type')
            ->setParameter('type', Artifact::TYPE_FOLIO)
            ->orderBy('d.aggregator')->addOrderBy('d.datasetKey')
            ->getQuery()->getResult();

        if ($withDb) {
            $io->section('Datasets with folio DB (ready to browse)');
            $rows = [];
            foreach ($withDb as $info) {
                $artifact = $info->primaryArtifact(Artifact::TYPE_FOLIO);
                $rows[] = [
                    $info->datasetKey,
                    $info->label ?? '-',
                    $artifact?->rowCount ? number_format($artifact->rowCount) : '?',
                    round(($artifact?->sizeBytes ?? 0) / 1024) . ' KB',
                    $info->status,
                ];
            }
            $io->table(['Dataset key', 'Label', 'Rows', 'DB size', 'Status'], $rows);
        }

        return 0;
    }

    /** @return array<string,string> providerCode => absolutePath */
    private function listProviderDirs(string $root, ?string $providerFilter): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $providerFilter = $providerFilter !== null ? strtolower(trim($providerFilter)) : null;
        $allowedProviders = $this->normalizedEnabledProviders();

        if ($providerFilter !== null && $providerFilter !== '' && $allowedProviders !== [] && !in_array($providerFilter, $allowedProviders, true)) {
            return [];
        }

        $providerDirs = [];

        foreach (new \DirectoryIterator($root) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $providerCode = strtolower($entry->getFilename());
            if ($allowedProviders !== [] && !in_array($providerCode, $allowedProviders, true)) {
                continue;
            }

            if ($providerFilter !== null && $providerFilter !== '' && $providerCode !== $providerFilter) {
                continue;
            }

            $providerDirs[$providerCode] = $entry->getPathname();
        }

        ksort($providerDirs);

        return $providerDirs;
    }

    /** @return list<string> */
    private function normalizedEnabledProviders(): array
    {
        $providers = [];
        foreach ($this->enabledProviders as $provider) {
            $provider = strtolower(trim((string) $provider));
            if ($provider !== '') {
                $providers[$provider] = $provider;
            }
        }

        ksort($providers);

        return array_values($providers);
    }

    /** @return array<string,mixed> */
    private function normalizeMeta(array $meta): array
    {
        if (isset($meta['dataset']) && is_array($meta['dataset'])) {
            $meta = $meta['dataset'];
        }

        if (isset($meta['datasetKey']) && !isset($meta['dataset_key'])) {
            $meta['dataset_key'] = $meta['datasetKey'];
        }

        return $meta;
    }


    private function populateFromMeta(DatasetInfo $info, array $meta, string $metaFile): void
    {
        $paths = new DatasetPaths($this->dataPaths, $info->datasetKey);

        $info->label        = $meta['label'] ?? null;
        $info->description  = $meta['description'] ?? null;
        $info->aggregator   = $meta['aggregator'] ?? $info->provider();
        $info->locale       = $meta['locale']['default'] ?? null;
        $info->country      = $meta['country']['iso2'] ?? null;
        $info->contactUrl   = $meta['contact']['url'] ?? null;
        $info->rightsUri    = $meta['rights']['default_uri'] ?? null;
        $info->objCount     = (int)($meta['extras']['obj_count'] ?? $meta['extras']['recordCount'] ?? 0);
        $info->meta         = $meta;
        $info->metaPath     = $metaFile;
        $info->lastScanned  = new \DateTimeImmutable();

        // Resolve paths — store absolute paths so no filesystem access needed later
        $rawFile = $paths->rawFile('obj.jsonl');
        $info->rawPath        = is_file($rawFile) ? $rawFile : null;

        $normFile = $paths->normalizeFile('obj.jsonl');
        $info->normalizedPath = is_file($normFile) ? $normFile : null;

        $profFile = $paths->profileFile('obj.profile.json');
        $info->profilePath    = is_file($profFile) ? $profFile : null;

        // Compile core list + field names from profile
        if ($info->profilePath && is_file($info->profilePath)) {
            $this->populateFromProfile($info, $info->profilePath);
        }

        if ($info->objCount === 0 && $info->rawPath && is_file($info->rawPath)) {
            $info->objCount = $this->countJsonlLines($info->rawPath);
        }
    }

    private function populateFromProfile(DatasetInfo $info, string $profilePath): void
    {
        try {
            $profile = json_decode(file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $info->normalizedCount = $profile['recordCount'] ?? null;
        if (($info->objCount ?? 0) === 0 && (int) ($info->normalizedCount ?? 0) > 0) {
            $info->objCount = (int) $info->normalizedCount;
        }

        // Profile is for the 'obj' core by default
        // Multi-core datasets will have multiple profile files (obj.profile.json, cat.profile.json, etc.)
        $coreName = basename(dirname(dirname($profilePath))); // derive from path if possible
        $coreName = 'obj'; // default — most datasets have a single 'obj' core

        if (!in_array($coreName, $info->cores, true)) {
            $info->cores[] = $coreName;
        }

        $info->fields[$coreName] = array_keys($profile['fields'] ?? []);
        $info->profileSummary = $this->buildProfileSummary($profile);

        if ($info->normalizedCount) {
            $info->lastNormalized = new \DateTimeImmutable();
        }
    }

    /** @param array<string,mixed> $profile */
    private function buildProfileSummary(array $profile): array
    {
        $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];

        $summary = [
            'recordCount' => (int) ($profile['recordCount'] ?? 0),
            'fieldCount' => count($fields),
            'fieldNames' => array_keys($fields),
            'uniqueFields' => array_values(array_filter($profile['uniqueFields'] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== '')),
            'candidates' => [
                'location' => $this->summarizeCandidateFields($fields, ['country', 'state', 'county', 'city']),
                'type' => $this->summarizeCandidateFields($fields, ['type', 'subtype', 'object_type', 'genre', 'format']),
            ],
        ];

        $locationDistinct = max(array_map(static fn(array $field): int => (int) ($field['distinct'] ?? 0), $summary['candidates']['location']) ?: [0]);
        $typeDistinct = max(array_map(static fn(array $field): int => (int) ($field['distinct'] ?? 0), $summary['candidates']['type']) ?: [0]);
        $summary['preferredHierarchy'] = ($locationDistinct <= 2 && $typeDistinct >= 3) ? 'type' : 'location';

        return $summary;
    }

    /**
     * @param array<string,mixed> $fields
     * @param string[] $names
     * @return array<string,array{distinct:int,nulls:int,types:array<int,string>}>
     */
    private function summarizeCandidateFields(array $fields, array $names): array
    {
        $summary = [];
        foreach ($names as $name) {
            $field = $fields[$name] ?? null;
            if (!is_array($field)) {
                continue;
            }
            $summary[$name] = [
                'distinct' => (int) ($field['distinct'] ?? 0),
                'nulls' => (int) ($field['nulls'] ?? 0),
                'types' => array_values(array_filter($field['types'] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== '')),
            ];
        }

        return $summary;
    }

    private function updateStatus(DatasetInfo $info): void
    {
        $info->status = match (true) {
            $info->meiliDocCount > 0    => 'indexed',
            $info->hasArtifact(Artifact::TYPE_FOLIO) => 'folio',
            $info->hasProfile()         => 'profiled',
            $info->hasNormalized()      => 'normalized',
            $info->hasRaw()             => 'raw',
            default                     => $info->status ?: 'discovered',
        };
    }

    private function ensureMaterializedDirectories(DatasetInfo $info): void
    {
        if ($info->status !== 'materialized') {
            return;
        }

        $this->dataPaths->filesystem()->mkdir($this->dataPaths->datasetDir($info->datasetKey));
        foreach ($this->dataPaths->stageMap as $stageDir) {
            $this->dataPaths->filesystem()->mkdir($this->dataPaths->datasetDir($info->datasetKey) . '/' . $stageDir);
        }
    }

    private function countJsonlLines(string $filename): int
    {
        $count = 0;
        $file = new \SplFileObject($filename, 'r');
        while (!$file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{rowCount:int|null, cores:list<array{code:string,label:?string,rowCount:int}>}
     */
    private function summarizeFolio(string $dbFile): array
    {
        $empty = ['rowCount' => null, 'cores' => [], 'coreCounts' => [], 'dtoCounts' => null];
        if (!is_file($dbFile)) {
            return $empty;
        }

        try {
            $pdo      = new \PDO('sqlite:' . $dbFile);
            $rowCount = (int) $pdo->query('SELECT COUNT(*) FROM item')->fetchColumn();

            $cores = $pdo->query('SELECT code, label, row_count AS rowCount FROM core ORDER BY code')
                ->fetchAll(\PDO::FETCH_ASSOC);

            // Counts by core code.
            $coreCounts = [];
            foreach ($cores as $core) {
                $coreCounts[(string) $core['code']] = (int) $core['rowCount'];
            }

            // Counts by DTO type, sorted descending.
            $dtoRows = $pdo->query(
                'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE dto_type IS NOT NULL GROUP BY dto_type ORDER BY cnt DESC'
            )->fetchAll(\PDO::FETCH_ASSOC);

            $dtoCounts = [];
            foreach ($dtoRows as $row) {
                $dtoCounts[(string) $row['dto_type']] = (int) $row['cnt'];
            }

            return [
                'rowCount'   => $rowCount,
                'cores'      => array_map(static fn(array $r): array => [
                    'code'     => (string) $r['code'],
                    'label'    => $r['label'] !== null ? (string) $r['label'] : null,
                    'rowCount' => (int) $r['rowCount'],
                ], $cores ?: []),
                'coreCounts' => $coreCounts,
                'dtoCounts'  => $dtoCounts ?: null,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
