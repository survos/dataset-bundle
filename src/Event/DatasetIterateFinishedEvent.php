<?php

declare(strict_types=1);

namespace Survos\DataBundle\Event;

final class DatasetIterateFinishedEvent extends DatasetIterateEvent
{
    public function __construct(
        string $dataset,
        string $stage,
        string $file,
        public readonly int $count,
        ?int   $limit = null,
    ) {
        parent::__construct($dataset, $stage, $file, $limit);
    }
}
