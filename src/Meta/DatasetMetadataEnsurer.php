<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Meta;

use Survos\DatasetBundle\Configuration\DatasetConfiguration;
use Survos\DatasetBundle\Service\DatasetPaths;
use Survos\DatasetBundle\Event\DatasetMetaWrittenEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

use function array_key_exists;
use function file_put_contents;
use function is_array;
use function is_file;
use function json_encode;
use function sprintf;

final class DatasetMetadataEnsurer
{
    public function __construct(
        private readonly DatasetMetadataConfiguration $configuration = new DatasetMetadataConfiguration(),
        private readonly Processor $processor = new Processor(),
        // Optional so the ensurer stays newable in tests and in apps without a dispatcher; when it
        // is present, writing dataset.json announces the dataset instead of waiting for a scan.
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * Ensure dataset metadata exists with required keys and defaults.
     *
     * @param array<string,mixed> $seed
     * @return array<string,mixed>
     */
    public function ensure(DatasetPaths $paths, array $seed, bool $write = true): array
    {
        throw new \RuntimeException('This method is deprecated. Use ensureJson() instead.');
        $metaFile = $paths->metaYaml;
        $existing = [];
        $rooted = false;

        if (is_file($metaFile)) {
            $raw = Yaml::parseFile($metaFile);
            if (!is_array($raw)) {
                throw new \RuntimeException(sprintf('Invalid YAML in %s (expected mapping)', $metaFile));
            }

            $rooted = isset($raw['dataset']) && is_array($raw['dataset']);
            $existing = $rooted ? $raw['dataset'] : $raw;
        }

        $seed = $this->fillMissing($seed, ['dataset_key' => $paths->key]);
        $merged = $this->fillMissing($existing, $seed);

        $processed = $this->processor->processConfiguration($this->configuration, [$merged]);

        if ($write) {
            $payload = $rooted ? ['dataset' => $processed] : $processed;
            $existingPayload = $rooted ? ['dataset' => $existing] : $existing;

            if (!is_file($metaFile) || $payload !== $existingPayload) {
                $paths->paths->filesystem()->mkdir($paths->metaDir);
                file_put_contents($metaFile, Yaml::dump($payload, inline: 6, indent: 2));
            }
        }

        return $processed;
    }

    /**
     * Write dataset configuration as JSON.
     * Replaces the YAML-based ensure() method.
     */
    public function ensureJson(DatasetPaths $paths, DatasetConfiguration $config, bool $write = true, ?string $owner = null, array $provenance = []): DatasetConfiguration
    {
        $workFile = $paths->metaJson;
        $vaultDir = $paths->paths->vaultDatasetDir($paths->datasetKey).'/_meta';
        $file = $vaultDir.'/dataset.json';
        $filesystem = $paths->paths->filesystem();
        if ($write) { $filesystem->mkdir([$vaultDir, $paths->metaDir]); }
        // Serialize read/merge/replace, not just the final write: concurrent producers must not
        // overwrite each other's snapshots. Dry runs never create a directory or lock file.
        $lock = $write ? fopen($file.'.lock', 'c') : null;
        if ($write && ($lock === false || !flock($lock, LOCK_EX))) {
            throw new \RuntimeException('Cannot lock dataset metadata: '.$file);
        }
        try {
            $read = static function (string $path): array {
                if (!is_file($path)) { return []; }
                $json = (string) file_get_contents($path);
                $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
                if (!$object instanceof \stdClass) { throw new \UnexpectedValueException('Expected JSON object: '.$path); }
                $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                // Configuration maps are arrays, but arbitrary property values must retain JSON
                // objects (especially {} versus []) through a read/write cycle.
                foreach ($object->properties ?? [] as $key => $value) { $data['properties'][$key] = $value; }
                foreach ($object->_metadata->fields ?? [] as $key => $entry) { $data['_metadata']['fields'][$key]['value'] = $entry->value; }
                foreach ($object->dataset->extras->metadataProperties ?? [] as $key => $entry) { $data['dataset']['extras']['metadataProperties'][$key]['value'] = $entry->value; }
                return $data;
            };
            $existing = $read($file);
            if (!isset($existing['_metadata'])) {
                $work = $read($workFile);
                $newerWork = is_file($workFile) && (!is_file($file) || filemtime($workFile) >= filemtime($file));
                $existing = \Survos\DataContracts\Metadata\DatasetDocument::mergeLegacy(
                    $newerWork ? $work : $existing, $newerWork ? $existing : $work,
                );
            }
            $overrideFile = $vaultDir.'/dataset.overrides.json';
            $workOverrides = $paths->metaDir.'/dataset.overrides.json';
            $overrideDocument = $read(is_file($overrideFile) ? $overrideFile : $workOverrides);
            if ($overrideDocument !== [] && (!isset($overrideDocument['properties']) || !is_array($overrideDocument['properties']))) {
                throw new \UnexpectedValueException('Overrides must contain a properties object.');
            }
            if ($write && !is_file($overrideFile) && is_file($workOverrides)) {
                $filesystem->dumpFile($overrideFile, (string) file_get_contents($workOverrides));
            }
            $payload = \Survos\DataContracts\Metadata\DatasetDocument::update(
                $existing, $config->toArray(), $owner ?? 'dataset:'.$config->aggregator,
                $overrideDocument['properties'] ?? [], $provenance,
            );
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $changed = !is_file($file) || file_get_contents($file) !== $encoded
                || !is_file($workFile) || file_get_contents($workFile) !== $encoded;
            if ($write && $changed) {
                // The vault is authoritative. A failed work projection is repaired on retry.
                $filesystem->dumpFile($file, $encoded);
                $filesystem->dumpFile($workFile, $encoded);
            }
            $resolved = DatasetConfiguration::fromArray($payload['dataset']);
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
        if ($write && $changed) {
            $this->eventDispatcher?->dispatch(new DatasetMetaWrittenEvent($paths->datasetKey, $workFile));
        }
        return $resolved;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $add
     * @return array<string,mixed>
     */
    private function fillMissing(array $base, array $add): array
    {
        foreach ($add as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $base[$key] = $value;
                continue;
            }

            if (is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->fillMissing($base[$key], $value);
            }
        }

        return $base;
    }
}
