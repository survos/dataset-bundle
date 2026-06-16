<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Entity\Provider;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Repository\ProviderRepository;
use Survos\JsonlBundle\Service\JsonlCountService;

final class DatasetRegistryUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DatasetInfoRepository $datasetRepository,
        private readonly ArtifactRepository $artifactRepository,
        private readonly ProviderRepository $providerRepository,
        private readonly DataPaths $dataPaths,
        private readonly JsonlCountService $jsonlCount,
    ) {
    }

    public function ensureFromMetaIfExists(string $datasetKey): ?DatasetInfo
    {
        $datasetKey = $this->canonicalDatasetKey($datasetKey);
        $info = $this->datasetRepository->find($datasetKey);
        if ($info instanceof DatasetInfo) {
            return $info;
        }

        $paths = new DatasetPaths($this->dataPaths, $datasetKey);
        if (!is_file($paths->metaJson)) {
            return null;
        }

        $meta = $this->loadMeta($paths->metaJson);
        $info = new DatasetInfo($datasetKey);
        $this->entityManager->persist($info);
        $this->populateFromMeta($info, $meta, $paths->metaJson);
        $provider = $this->attachProvider($info);
        $this->updateStatus($info);
        $this->entityManager->flush();

        // Keep the provider/dataset tables in sync without a separate dataset:scan: the
        // new DatasetInfo now exists, so recompute the provider's cached dataset count.
        $this->refreshProviderCount($provider);
        $this->entityManager->flush();

        return $info;
    }

    public function updateNormalized(
        string $datasetKey,
        string $jsonlPath,
        string $profilePath,
        int $recordCount,
    ): DatasetInfo {
        $info = $this->requireDataset($datasetKey);

        $info->profilePath = is_file($profilePath) ? $profilePath : null;
        $info->normalizedCount = $recordCount;
        $info->lastNormalized = new \DateTimeImmutable();

        if ($info->profilePath !== null) {
            $this->populateFromProfile($info, $info->profilePath);
        }

        $this->updateStatus($info);
        $this->entityManager->flush();

        return $info;
    }

    /**
     * @param array<string,int>|null $dtoCounts
     * @param array<string,mixed> $metadata
     */
    /**
     * @param array<string,int>|null $dtoCounts
     * @param array<string,mixed> $metadata
     */
    public function updateArtifact(
        string $datasetKey,
        string $type,
        string $uri,
        ?int $rowCount = null,
        ?array $dtoCounts = null,
        array $metadata = [],
        string $code = Artifact::CODE_DEFAULT,
    ): Artifact {
        // Self-registering: if the dataset isn't in the DB yet but its _meta/dataset.json
        // exists, register it from meta first (no separate dataset:scan needed). Falls back
        // to requireDataset() — which throws — only when there's no meta to register from.
        $info = $this->ensureFromMetaIfExists($datasetKey) ?? $this->requireDataset($datasetKey);

        $artifact = $this->artifactRepository->findOneBy([
            'dataset' => $info,
            'type' => $type,
            'code' => $code,
        ]) ?? new Artifact($info, $type, $code);

        $artifact->uri = $uri;
        $artifact->sizeBytes = is_file($uri) ? filesize($uri) : null;
        $artifact->rowCount = $rowCount;
        $artifact->dtoCounts = $dtoCounts;
        $artifact->updatedAt = is_file($uri) ? (new \DateTimeImmutable())->setTimestamp((int) filemtime($uri)) : new \DateTimeImmutable();
        $artifact->discoveredAt = new \DateTimeImmutable();
        $artifact->metadata = $metadata;

        $info->addArtifact($artifact);
        $this->entityManager->persist($artifact);
        $this->updateStatus($info);
        $this->entityManager->flush();

        return $artifact;
    }

    /**
     * @param array<string,int>|null $dtoCounts
     * @param array<string,mixed> $metadata
     */
    public function updateFolioArtifact(
        string $datasetKey,
        string $dbFile,
        ?int $rowCount,
        ?array $dtoCounts = null,
        array $metadata = [],
        string $code = Artifact::CODE_DEFAULT,
    ): Artifact {
        return $this->updateArtifact(
            datasetKey: $datasetKey,
            type: Artifact::TYPE_FOLIO,
            uri: $dbFile,
            rowCount: $rowCount,
            dtoCounts: $dtoCounts,
            metadata: $metadata,
            code: $code,
        );
    }

    public function requireDataset(string $datasetKey): DatasetInfo
    {
        $info = $this->ensureFromMetaIfExists($datasetKey);
        if (!$info instanceof DatasetInfo) {
            throw new \RuntimeException(sprintf(
                'Dataset "%s" is not registered and no 00_meta/dataset.json exists. Create detailed metadata first, then run data:scan-datasets.',
                $datasetKey,
            ));
        }

        return $info;
    }

    private function canonicalDatasetKey(string $datasetKey): string
    {
        $ref = $this->dataPaths->parseDatasetRef($datasetKey);

        return $ref['provider'] . '/' . $ref['code'];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadMeta(string $metaFile): array
    {
        $meta = json_decode((string) file_get_contents($metaFile), true, 512, JSON_THROW_ON_ERROR);
        if (isset($meta['dataset']) && is_array($meta['dataset'])) {
            $meta = $meta['dataset'];
        }
        if (isset($meta['datasetKey']) && !isset($meta['dataset_key'])) {
            $meta['dataset_key'] = $meta['datasetKey'];
        }

        return $meta;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function populateFromMeta(DatasetInfo $info, array $meta, string $metaFile): void
    {
        $paths = new DatasetPaths($this->dataPaths, $info->datasetKey);

        $info->label = $meta['label'] ?? null;
        $info->description = $meta['description'] ?? null;
        $info->aggregator = $meta['aggregator'] ?? $info->provider();
        $info->locale = $meta['locale']['default'] ?? null;
        $info->country = $meta['country']['iso2'] ?? null;
        $info->contactUrl = $meta['contact']['url'] ?? null;
        $info->rightsUri = $meta['rights']['default_uri'] ?? $meta['rights']['defaultUri'] ?? null;
        $info->objCount = (int) ($meta['extras']['obj_count'] ?? $meta['extras']['recordCount'] ?? 0);
        $info->meta = $meta;
        $info->metaPath = $metaFile;
        $info->lastScanned = new \DateTimeImmutable();

        $rawFile = $paths->rawFile('obj.jsonl');
        $info->rawPath = is_file($rawFile) ? $rawFile : null;

        $profileFile = $paths->profileFile('obj.profile.json');
        $info->profilePath = is_file($profileFile) ? $profileFile : null;

        if ($info->profilePath !== null) {
            $this->populateFromProfile($info, $info->profilePath);
        }

        if ($info->objCount === 0 && $info->rawPath !== null) {
            $info->objCount = $this->jsonlCount->rows($info->rawPath);
        }
    }

    private function populateFromProfile(DatasetInfo $info, string $profilePath): void
    {
        try {
            $profile = json_decode((string) file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $info->normalizedCount = (int) ($profile['recordCount'] ?? 0);
        if ($info->objCount === 0 && $info->normalizedCount > 0) {
            $info->objCount = $info->normalizedCount;
        }

        if (!in_array('obj', $info->cores, true)) {
            $info->cores[] = 'obj';
        }

        $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
        $info->fields['obj'] = array_keys($fields);
        $info->profileSummary = $this->buildProfileSummary($profile);
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function buildProfileSummary(array $profile): array
    {
        $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];

        return [
            'recordCount' => (int) ($profile['recordCount'] ?? 0),
            'fieldCount' => count($fields),
            'fieldNames' => array_keys($fields),
            'uniqueFields' => array_values(array_filter($profile['uniqueFields'] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== '')),
            'candidates' => [
                'location' => $this->summarizeCandidateFields($fields, ['country', 'state', 'county', 'city']),
                'type' => $this->summarizeCandidateFields($fields, ['type', 'subtype', 'object_type', 'genre', 'format']),
            ],
        ];
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

    private function attachProvider(DatasetInfo $info): Provider
    {
        $provider = $this->providerRepository->findOneByCode($info->provider()) ?? new Provider($info->provider());
        $provider->setSyncedAt(new \DateTime());
        $this->entityManager->persist($provider);
        $info->setProviderEntity($provider);

        return $provider;
    }

    /**
     * Recompute the provider's cached dataset count from the registry — mirrors
     * dataset:scan Phase 4, so an import that creates a dataset keeps the count current.
     */
    private function refreshProviderCount(Provider $provider): void
    {
        $provider->setDatasetCount($this->datasetRepository->count(['providerEntity' => $provider]));
        $provider->setSyncedAt(new \DateTime());
    }

    private function updateStatus(DatasetInfo $info): void
    {
        $info->status = match (true) {
            $info->meiliDocCount > 0 => 'indexed',
            $info->hasArtifact(Artifact::TYPE_FOLIO) => 'folio',
            $info->hasProfile() => 'profiled',
            $info->hasNormalized() => 'normalized',
            $info->hasRaw() => 'raw',
            default => $info->status ?: 'discovered',
        };
    }

}
