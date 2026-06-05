<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Survos\DatasetBundle\Enum\Stage;

/**
 * Dataset-scoped view over DataPaths.
 *
 * Provides property-hook accessors for the canonical stage layout and common files.
 */
final class DatasetPaths
{
    public function __construct(
        public readonly DataPaths $paths,
        public readonly string $datasetKey,
    ) {}

    public string $key { get => $this->paths->datasetKeyFromRef($this->datasetKey); }

    public string $dir { get => $this->paths->datasetDir($this->datasetKey); }

    // ---- Canonical stages (property hooks) ----

    public string $metaDir { get => $this->stageDir(Stage::Meta); }        // _meta
    public string $rawDir { get => $this->stageDir(Stage::Raw); }          // _raw (portal → vault)
    public string $extractDir { get => $this->stageDir(Stage::Extract); }  // extract
    public string $normalizeDir { get => $this->stageDir(Stage::Normalize); } // norm
    public string $termsDir  { get => $this->stageDir(Stage::Terms); }      // voc
    public string $aiDir     { get => $this->paths->aiDir($this->datasetKey); } // vault/<p>/<c>/ai
    public string $enrichDir { get => $this->stageDir(Stage::Enrich); }     // _folio (assembled)

    // ---- Common files ----

    public string $metaYaml { get => "{$this->metaDir}/dataset.yaml"; }
    public string $metaJson { get => "{$this->metaDir}/dataset.json"; }

    public function extractFile(?string $filename = null): string
    {
        return "{$this->extractDir}/" . ($filename ?? $this->paths->defaultObjectFilename);
    }

    public function normalizeFile(?string $filename = null): string
    {
        return "{$this->normalizeDir}/" . ($filename ?? $this->paths->defaultObjectFilename);
    }

    /** Profile is a sidecar to the normalize JSONL — same directory, same base name. */
    public function profileFile(string $filename = 'obj.profile.json'): string
    {
        return "{$this->normalizeDir}/{$filename}";
    }

    /**
     * Per-dataset extracted vocabulary terms.
     *
     * Split by type and language: genre.en.jsonl, medium.fr.jsonl, place.de.jsonl, …
     * Omit both args to get the terms directory itself.
     */
    public function termsFile(string $termType, string $lang): string
    {
        return "{$this->termsDir}/{$termType}.{$lang}.jsonl";
    }

    // ---- Stage resolver ----

    /**
     * Resolve a stage key (friendly) or a literal stage directory name.
     *
     * Examples:
     *  - stageDir('extract') => .../10_extract
     *  - stageDir('10_extract') => .../10_extract
     */
    public function stageDir(Stage|string $stage): string
    {
        $resolved = $stage instanceof Stage ? $stage : Stage::fromKey($stage); // unknown → throws

        return "{$this->dir}/{$resolved->dir()}";
    }

    // ---- Convenience: raw filename normalization ----

    public function normalizeRawFilename(string $filename): string
    {
        return $this->paths->normalizeRawFilename($filename);
    }

    public function rawFile(string $filename): string
    {
        return "{$this->rawDir}/{$this->normalizeRawFilename($filename)}";
    }

    public function rawFileFromUrl(string $url): string
    {
        return $this->paths->rawFileFromUrl($this->key, $url);
    }
}
