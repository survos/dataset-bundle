<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\EventListener;

use Survos\DatasetBundle\Event\DatasetArtifactUpdatedEvent;
use Survos\DatasetBundle\Service\DatasetRegistryUpdater;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class DatasetRegistryArtifactListener
{
    public function __construct(
        private DatasetRegistryUpdater $registryUpdater,
    ) {
    }

    #[AsEventListener(event: DatasetArtifactUpdatedEvent::class)]
    public function __invoke(DatasetArtifactUpdatedEvent $event): void
    {
        $this->registryUpdater->updateArtifact(
            datasetKey: $event->datasetKey,
            type: $event->type,
            uri: $event->uri,
            rowCount: $event->rowCount,
            dtoCounts: $event->dtoCounts,
            metadata: $event->metadata,
            code: $event->code,
        );
    }
}
