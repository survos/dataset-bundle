<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Entity\Provider;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\FolioService;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Repository\ProviderRepository;
use Survos\DatasetBundle\Service\DatasetPaths;
use Survos\DatasetBundle\Service\ProviderSnapshotCodec;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        // The registry lives in the bundle's private sqlite EM, not the app default.
        #[Autowire(service: 'doctrine.orm.dataset_entity_manager')]
        private readonly EntityManagerInterface $em,
        private readonly ArtifactRepository $artifactRepository,
        private readonly ProviderRepository $providerRepo,
        private readonly DatasetInfoRepository $datasetRepository,
        private readonly ProviderSnapshotCodec $providerSnapshotCodec,
        private readonly array $enabledProviders = [],
        private readonly ?FolioService $folioService = null,
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
        $this->ensureRegistrySchema();

        $repo    = $this->datasetRepository;
        $created = $updated = $skipped = 0;
        $count   = 0;

        // ── Provider preflight: all provider dirs must have provider.json + DB row ──
        $root = $this->dataPaths->workRoot;
        $providerDirs = $this->listProviderDirs($root, $provider);
        if ($this->enabledProviders !== [] && $provider === null) {
            $io->text(sprintf('Provider allowlist from survos_dataset.providers: %s', implode(', ', $this->normalizedEnabledProviders())));
        }

        if ($reset) {
            $resetProviderCodes = $provider !== null && trim($provider) !== '' ? array_keys($providerDirs) : [];
            [$deletedDatasets, $deletedProviders] = $this->resetRegistryCache($resetProviderCodes);
            $io->text(sprintf(
                'Reset: deleted %d DatasetInfo row(s) and %d Provider row(s)%s.',
                $deletedDatasets,
                $deletedProviders,
                $resetProviderCodes === [] ? '' : ' for ' . implode(', ', $resetProviderCodes)
            ));
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

            $files = glob($providerDir . '/*/_meta/dataset.json', GLOB_NOSORT) ?: [];
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
        $folioPath   = rtrim($folioDir ?? $this->dataPaths->folioRootDir, '/');
        $folioFiles  = glob($folioPath . '/*/*.folio') ?: [];
        $folioArchives = glob($folioPath . '/*/*.folio.gz') ?: [];
        $allowedProviders = $provider !== null && $provider !== ''
            ? [strtolower(trim($provider)) => true]
            : array_fill_keys($this->normalizedEnabledProviders(), true);

        $io->text(sprintf('Phase 2: Found %d folio files and %d archives in %s', count($folioFiles), count($folioArchives), $folioPath));

        $folioUpdated = 0;
        foreach ($folioFiles as $dbFile) {
            // Path is provider/dataset.folio - strip extension to get dataset key.
            $relative   = substr($dbFile, strlen($folioPath) + 1);
            $datasetKey = substr($relative, 0, -strlen('.folio'));
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

            // Read label from the folio via FolioService; row counts stay core-scoped.
            if ($this->folioService) {
                $ctx = $this->folioService->context($datasetKey);
                $folioEntity = $ctx->em->find(Folio::class, $datasetKey);
                $info->label ??= $folioEntity->label;
            }

            $summary = $this->summarizeFolio($dbFile);
            $info->objCount = (int) ($info->getCoreCounts()['obj'] ?? $summary['coreCounts']['obj'] ?? $info->objCount);
            $artifact = $this->artifactRepository->findOneBy([
                'dataset' => $info,
                'type' => Artifact::TYPE_FOLIO,
                'code' => Artifact::CODE_DEFAULT,
            ]) ?? new Artifact($info, Artifact::TYPE_FOLIO);

            $artifact->uri = $dbFile;
            $artifact->sizeBytes = filesize($dbFile) ?: null;
            $artifact->rowCount  = $summary['rowCount'];
            $artifact->dtoCounts = $summary['dtoCounts'];
            $artifact->updatedAt = (new \DateTimeImmutable())->setTimestamp((int) filemtime($dbFile));
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


        foreach ($folioArchives as $archiveFile) {
            $relative = substr($archiveFile, strlen($folioPath) + 1);
            $datasetKey = substr($relative, 0, -strlen('.folio'));
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

            $artifact = $this->artifactRepository->findOneBy([
                'dataset' => $info,
                'type' => Artifact::TYPE_FOLIO_ARCHIVE,
                'code' => Artifact::CODE_DEFAULT,
            ]) ?? new Artifact($info, Artifact::TYPE_FOLIO_ARCHIVE);

            $artifact->uri = $archiveFile;
            $artifact->sizeBytes = is_file($archiveFile) ? filesize($archiveFile) : null;
            $artifact->updatedAt = is_file($archiveFile) ? (new \DateTimeImmutable())->setTimestamp((int) filemtime($archiveFile)) : null;
            $artifact->discoveredAt = new \DateTimeImmutable();
            $artifact->metadata = ['relativePath' => $relative, 'compressed' => true];

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
                \assert($info instanceof DatasetInfo);
                $artifact = $info->artifact(Artifact::TYPE_FOLIO);
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

    private function ensureRegistrySchema(): void
    {
        $connection = $this->em->getConnection();
        $params = $connection->getParams();
        $path = $params['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        if ($metadata === []) {
            return;
        }

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->updateSchema($metadata, true);
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

    /**
     * @param list<string> $providerCodes Empty list means reset the whole app-local registry cache.
     * @return array{0:int, 1:int}
     */
    private function resetRegistryCache(array $providerCodes): array
    {
        $providerCodes = array_values(array_filter(array_unique(array_map(
            static fn(mixed $code): string => strtolower(trim((string) $code)),
            $providerCodes
        ))));

        $deletedDatasets = 0;
        $datasets = $providerCodes === []
            ? $this->datasetRepository->findAll()
            : array_merge(...array_map(
                fn(string $providerCode): array => $this->datasetRepository->findBy(['aggregator' => $providerCode]),
                $providerCodes
            ));

        foreach ($datasets as $datasetInfo) {
                $this->em->remove($datasetInfo);
                $deletedDatasets++;
        }
        $this->em->flush();

        $deletedProviders = 0;
        $providers = $providerCodes === []
            ? $this->providerRepo->findAll()
            : array_values(array_filter(array_map(
                fn(string $providerCode) => $this->providerRepo->findOneByCode($providerCode),
                $providerCodes
            )));

        foreach ($providers as $providerEntity) {
                $this->em->remove($providerEntity);
                $deletedProviders++;
        }
        $this->em->flush();

        return [$deletedDatasets, $deletedProviders];
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

        $info->cores = [];
        $info->fields = [];
        $info->meta['coreCounts'] = [];
        foreach ($this->profileFiles($paths) as $coreName => $profileFile) {
            $this->populateFromProfile($info, $profileFile, $coreName);
        }

        if ($info->objCount === 0 && $info->rawPath && is_file($info->rawPath)) {
            $info->objCount = $this->countJsonlLines($info->rawPath);
            $info->meta['coreCounts']['obj'] ??= $info->objCount;
        }
    }

    /**
     * @return array<string,string> core => profile path
     */
    private function profileFiles(DatasetPaths $paths): array
    {
        $dir = $paths->normalizeDir;
        if (!is_dir($dir)) {
            return [];
        }

        $profiles = [];
        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            if (preg_match('/^([A-Za-z0-9_-]+)\.profile\.json$/', $name, $matches) !== 1) {
                continue;
            }

            $profiles[$matches[1]] = $file->getPathname();
        }

        uksort($profiles, static function (string $left, string $right): int {
            if ($left === 'obj') {
                return $right === 'obj' ? 0 : -1;
            }
            if ($right === 'obj') {
                return 1;
            }

            return $left <=> $right;
        });

        return $profiles;
    }

    private function populateFromProfile(DatasetInfo $info, string $profilePath, string $coreName): void
    {
        try {
            $profile = json_decode(file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        if (!in_array($coreName, $info->cores, true)) {
            $info->cores[] = $coreName;
        }

        $recordCount = (int) ($profile['recordCount'] ?? 0);
        $info->meta['coreCounts'][$coreName] = $recordCount;
        $info->fields[$coreName] = array_keys($profile['fields'] ?? []);

        if ($recordCount > 0) {
            $info->lastNormalized = new \DateTimeImmutable();
        }

        if ($coreName !== 'obj') {
            return;
        }

        $info->normalizedCount = $recordCount;
        if (($info->objCount ?? 0) === 0 && (int) ($info->normalizedCount ?? 0) > 0) {
            $info->objCount = (int) $info->normalizedCount;
        }

        $info->profileSummary = $this->buildProfileSummary($profile);

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
