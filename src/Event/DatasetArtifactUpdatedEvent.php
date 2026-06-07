<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Event;

use Survos\DatasetBundle\Entity\Artifact;

final readonly class DatasetArtifactUpdatedEvent
{
    /**
     * @param array<string,int>|null $dtoCounts
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $datasetKey,
        public string $type,
        public string $uri,
        public ?int $rowCount = null,
        public ?array $dtoCounts = null,
        public array $metadata = [],
        public string $code = Artifact::CODE_DEFAULT,
    ) {
    }
}
