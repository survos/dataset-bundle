<?php

declare(strict_types=1);

namespace Survos\DataBundle\Event;

final class DatasetIterateRowEvent extends DatasetIterateEvent
{
    /** @param array<string,mixed>|null $row */
    public function __construct(
        string  $dataset,
        string  $stage,
        string  $file,
        public readonly ?array $row,
        public readonly ?int   $index = null,
        ?int    $limit = null,
    ) {
        parent::__construct($dataset, $stage, $file, $limit);
    }
}
