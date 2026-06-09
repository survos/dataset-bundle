<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Survos\DatasetBundle\Enum\Stage;
use Survos\JsonlBundle\Sqlite\SidecarDb;
use Zenstruck\Bytes;

/**
 * Read-only, on-disk inventory of a dataset's pipeline stages.
 *
 * Answers the "what do we actually have here?" question for the dataset dashboard:
 * for each stage it lists the files present *on this machine*, grouped by core
 * (obj, creator, …), and — where a scalable SQL sidecar (<core>.jsonl.db) exists —
 * surfaces its field/facet/record counts. Nothing is read from the legacy, non-scaling
 * <core>.profile.json blob; the sidecar is the source of truth.
 *
 * Pure filesystem + SQLite reads, so it is safe to call at render time and returns
 * empty stages on a machine that does not hold the data.
 */
final class DatasetStageInventory
{
    /** Stages shown on the dashboard, in pipeline order. */
    private const STAGES = [Stage::Raw, Stage::Extract, Stage::Normalize, Stage::Intl, Stage::Terms, Stage::Enrich];

    public function __construct(
        private readonly DataPaths $dataPaths,
    ) {}

    /**
     * @return list<array{
     *   stage:string, dir:string, path:string, exists:bool,
     *   fileCount:int, totalBytes:int, totalSize:string,
     *   cores:list<array<string,mixed>>
     * }>
     */
    public function forDatasetKey(string $datasetKey): array
    {
        $paths = new DatasetPaths($this->dataPaths, $datasetKey);

        $stages = [];
        foreach (self::STAGES as $stage) {
            $dir = $paths->stageDir($stage);
            $stages[] = $this->inventoryStage($stage, $dir);
        }

        return $stages;
    }

    /** @return array<string,mixed> */
    private function inventoryStage(Stage $stage, string $dir): array
    {
        $row = [
            'stage' => $stage->value,
            'dir' => $stage->dir(),
            'path' => $dir,
            'exists' => is_dir($dir),
            'fileCount' => 0,
            'totalBytes' => 0,
            'totalSize' => (string) Bytes::parse(0)->humanize(),
            'cores' => [],
        ];

        if (!$row['exists']) {
            return $row;
        }

        /** @var array<string,array<string,mixed>> $cores keyed by core name */
        $cores = [];
        $totalBytes = 0;
        $fileCount = 0;

        foreach (new \DirectoryIterator($dir) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $name = $entry->getFilename();
            [$core, $kind] = $this->classify($name);
            if ($core === null) {
                continue; // sqlite -wal/-shm and other noise
            }

            $size = (int) $entry->getSize();
            $totalBytes += $size;
            $fileCount++;

            $cores[$core] ??= ['core' => $core, 'files' => [], 'bytes' => 0];
            $cores[$core]['files'][] = [
                'name' => $name,
                'kind' => $kind,
                'bytes' => $size,
                'size' => (string) Bytes::parse($size)->humanize(),
                'mtime' => $entry->getMTime(),
            ];
            $cores[$core]['bytes'] += $size;
        }

        // Enrich each core with sidecar stats + tidy file ordering.
        foreach ($cores as $coreName => &$core) {
            usort($core['files'], fn (array $a, array $b): int => $this->kindRank($a['kind']) <=> $this->kindRank($b['kind']));
            $core['size'] = (string) Bytes::parse($core['bytes'])->humanize();
            $core += $this->sidecarStats($dir, $coreName);
        }
        unset($core);

        uksort($cores, $this->coreSort());

        $row['cores'] = array_values($cores);
        $row['fileCount'] = $fileCount;
        $row['totalBytes'] = $totalBytes;
        $row['totalSize'] = (string) Bytes::parse($totalBytes)->humanize();

        return $row;
    }

    /**
     * Map a filename to [coreName, kind]. Returns [null, null] for files to ignore.
     *
     * @return array{0:?string,1:?string}
     */
    private function classify(string $name): array
    {
        return match (true) {
            (bool) preg_match('/^(.+)\.profile\.json$/', $name, $m)        => [$m[1], 'profile'],
            (bool) preg_match('/^(.+)\.jsonl\.db$/', $name, $m)            => [$m[1], 'sidecar'],
            str_ends_with($name, '.jsonl.db-wal'),
            str_ends_with($name, '.jsonl.db-shm')                          => [null, null],
            (bool) preg_match('/^(.+)\.jsonl\.idx\.json$/', $name, $m)     => [$m[1], 'index'],
            (bool) preg_match('/^(.+)\.idx\.json$/', $name, $m)            => [$m[1], 'index'],
            (bool) preg_match('/^(.+)\.jsonl\.sidecar\.json$/', $name, $m) => [$m[1], 'meta'],
            (bool) preg_match('/^(.+)\.jsonl(\.gz)?$/', $name, $m)         => [$m[1], 'data'],
            default                                                        => [pathinfo($name, PATHINFO_FILENAME), 'other'],
        };
    }

    private function kindRank(string $kind): int
    {
        return ['data' => 0, 'sidecar' => 1, 'index' => 2, 'profile' => 3, 'meta' => 4, 'other' => 5][$kind] ?? 9;
    }

    /** obj first, then natural order. */
    private function coreSort(): callable
    {
        return static function (string $a, string $b): int {
            if ($a === 'obj' || $b === 'obj') {
                return $a === $b ? 0 : ($a === 'obj' ? -1 : 1);
            }
            return strnatcasecmp($a, $b);
        };
    }

    /**
     * Stats pulled from the scalable <core>.jsonl.db sidecar, if present.
     *
     * @return array{hasSidecar:bool, recordCount:?int, indexedKeys:?int, fieldCount:?int, facetFields:list<string>}
     */
    private function sidecarStats(string $dir, string $core): array
    {
        $empty = ['hasSidecar' => false, 'recordCount' => null, 'indexedKeys' => null, 'fieldCount' => null, 'facetFields' => []];

        $dbPath = "{$dir}/{$core}.jsonl.db";
        if (!is_file($dbPath)) {
            return $empty;
        }

        try {
            $sidecar = new SidecarDb($dbPath);
            $fieldStats = $sidecar->loadFieldStats();
            // The most-present field's `present` count is the row total.
            $recordCount = $fieldStats === [] ? null : max(array_map(static fn (array $s): int => (int) ($s['present'] ?? 0), $fieldStats));

            return [
                'hasSidecar' => true,
                'recordCount' => $recordCount,
                'indexedKeys' => $sidecar->keyCount() ?: null,
                'fieldCount' => count($fieldStats),
                'facetFields' => $sidecar->facetFields(),
            ];
        } catch (\Throwable) {
            return ['hasSidecar' => true, 'recordCount' => null, 'indexedKeys' => null, 'fieldCount' => null, 'facetFields' => []];
        }
    }
}
