<?php
declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\ImportBundle\Model\DatasetPaths;

/**
 * Canonical dataset path resolver for Museado-style staged datasets.
 *
 * Stages:
 *  - 05_raw        : raw object jsonl (obj.jsonl)
 *  - 20_normalize  : normalized jsonl
 *  - 21_profile    : profile / rules
 *  - 30_terms      : extracted terms / termsets
 */
final class SurvosDatasetPathsFactory implements DatasetPathsFactoryInterface
{
    public function __construct(
        private readonly DataPaths $dataPaths,
    ) {}

    public function for(string $datasetKey): DatasetPaths
    {
        $datasetRoot = $this->dataPaths->datasetDir($datasetKey);

        $rawDir        = $this->dataPaths->stageDir($datasetKey, '05_raw');
        $normalizeDir  = $this->dataPaths->stageDir($datasetKey, '20_normalize');
        $profileDir    = $this->dataPaths->stageDir($datasetKey, '21_profile');
        $termsDir      = $this->dataPaths->stageDir($datasetKey, '30_terms');

        $objFilename = $this->dataPaths->defaultObjectFilename;

        return new DatasetPaths(
            datasetKey: $datasetKey,
            datasetRoot: $datasetRoot,

            rawDir: $rawDir,
            rawObjectPath: $rawDir . '/' . $objFilename,

            normalizedDir: $normalizeDir,
            normalizedObjectPath: $normalizeDir . '/' . $objFilename,

            termsDir: $termsDir,

            profileDir: $profileDir,
        );
    }
}
