<?php
declare(strict_types=1);

namespace Survos\DataBundle\Meta;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

final class DatasetMetadataLoader
{
    public function __construct(
        private readonly DatasetMetadataConfiguration $configuration = new DatasetMetadataConfiguration(),
        private readonly Processor $processor = new Processor(),
    ) {
    }

    /**
     * Load and validate 00_meta/dataset.yaml.
     *
     * Accepts either:
     *  - rooted:   dataset: { dataset_key: ..., ... }
     *  - rootless: { dataset_key: ..., ... }
     *
     * @return array<string,mixed> validated/normalized metadata (root contents)
     */
    public function load(string $filename): array
    {
        if (!is_file($filename)) {
            throw new \RuntimeException("Dataset metadata file not found: $filename");
        }

        $raw = Yaml::parseFile($filename);
        if (!is_array($raw)) {
            throw new \RuntimeException("Invalid YAML in $filename (expected mapping)");
        }

        // IMPORTANT: processConfiguration expects the array *under* the root node ("dataset"),
        // not an extra wrapper containing "dataset" again.
        $data = (isset($raw['dataset']) && is_array($raw['dataset']))
            ? $raw['dataset']
            : $raw;

        return $this->processor->processConfiguration($this->configuration, [$data]);
    }
}
