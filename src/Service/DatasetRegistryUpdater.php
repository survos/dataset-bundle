<?php
declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataBundle\Entity\Artifact;
use Survos\DataBundle\Entity\DatasetInfo;
use Survos\DataBundle\Entity\Provider;
use Survos\DataBundle\Repository\ArtifactRepository;
use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\DataBundle\Repository\ProviderRepository;
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
        $this->attachProvider($info);
        $this->updateStatus($info);
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

        $info->normalizedPath = is_file($jsonlPath) ? $jsonlPath : null;
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
    public function updateFolioArtifact(
        string $datasetKey,
        string $dbFile,
        ?int $rowCount,
        ?array $dtoCounts = null,
        array $metadata = [],
        string $code = Artifact::CODE_DEFAULT,
    ): Artifact {
        $info = $this->requireDataset($datasetKey);

        $artifact = $this->artifactRepository->findOneBy([
            'dataset' => $info,
            'type' => Artifact::TYPE_FOLIO,
            'code' => $code,
        ]) ?? new Artifact($info, Artifact::TYPE_FOLIO, $code);

        $artifact->uri = $dbFile;
        $artifact->sizeBytes = is_file($dbFile) ? filesize($dbFile) : null;
        $artifact->rowCount = $rowCount;
        $artifact->dtoCounts = $dtoCounts;
        $artifact->updatedAt = is_file($dbFile) ? (new \DateTimeImmutable())->setTimestamp((int) filemtime($dbFile)) : new \DateTimeImmutable();
        $artifact->discoveredAt = new \DateTimeImmutable();
        $artifact->metadata = $metadata;

        $info->addArtifact($artifact);
        $this->entityManager->persist($artifact);
        $this->updateStatus($info);
        $this->entityManager->flush();

        return $artifact;
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

        $normFile = $paths->normalizeFile('obj.jsonl');
        $info->normalizedPath = is_file($normFile) ? $normFile : null;

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

    private function attachProvider(DatasetInfo $info): void
    {
        $provider = $this->providerRepository->findOneByCode($info->provider()) ?? new Provider($info->provider());
        $provider->setSyncedAt(new \DateTime());
        $this->entityManager->persist($provider);
        $info->setProviderEntity($provider);
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
