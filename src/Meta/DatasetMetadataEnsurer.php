<?php
declare(strict_types=1);

namespace Survos\DataBundle\Meta;

use Survos\DataBundle\Configuration\DatasetConfiguration;
use Survos\DataBundle\Service\DatasetPaths;
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
    public function ensureJson(DatasetPaths $paths, DatasetConfiguration $config, bool $write = true): DatasetConfiguration
    {
        $metaJsonFile = $paths->metaJson;

        $existing = null;
        if (is_file($metaJsonFile)) {
            $content = file_get_contents($metaJsonFile);
            if ($content !== false) {
                $existing = json_decode($content, true);
            }
        }

        $configArray = $config->toArray();

        if ($write) {
            $payload = ['dataset' => $configArray];
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $paths->paths->filesystem()->mkdir($paths->metaDir);
            file_put_contents($metaJsonFile, $encoded);
        }

        return $config;
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
