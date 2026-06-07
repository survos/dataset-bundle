<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\EventListener;

use Survos\DatasetBundle\Service\DatasetRegistryUpdater;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class DatasetRegistryImportConvertListener
{
    public function __construct(
        private DatasetRegistryUpdater $registryUpdater,
    ) {
    }

    #[AsEventListener(event: ImportConvertFinishedEvent::class)]
    public function __invoke(ImportConvertFinishedEvent $event): void
    {
        if ($event->dataset === '') {
            return;
        }

        $this->registryUpdater->updateNormalized(
            $event->dataset,
            $event->jsonlPath,
            $event->profilePath,
            $event->recordCount,
        );
    }
}
