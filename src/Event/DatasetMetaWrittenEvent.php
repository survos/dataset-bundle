<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Event;

/**
 * A provider has written `_meta/dataset.json` for a dataset — i.e. acquisition got far enough that
 * the dataset is describable.
 *
 * Dispatched so the registry can pick the dataset up immediately instead of waiting for someone to
 * remember `dataset:scan`. Scanning is a directory walk over every provider; registering the one
 * dataset a command just finished is a single upsert, and the command already knows which one it is.
 */
final readonly class DatasetMetaWrittenEvent
{
    public function __construct(
        public string $datasetKey,
        public string $metaJsonPath,
    ) {
    }
}
