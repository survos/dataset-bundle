<?php

declare(strict_types=1);

namespace Survos\DataBundle\Event;

abstract class DatasetIterateEvent
{
    public function __construct(
        public readonly string  $dataset,
        public readonly string  $stage,
        public readonly string  $file,
        public readonly ?int    $limit = null,
    ) {}
}
