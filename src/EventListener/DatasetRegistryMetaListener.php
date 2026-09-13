<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\DatasetBundle\Event\DatasetMetaWrittenEvent;
use Survos\DatasetBundle\Service\DatasetRegistryUpdater;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Registers a dataset the moment its provider writes `_meta/dataset.json`, so acquisition commands
 * no longer have to be followed by `dataset:scan --provider=<x>`.
 */
final readonly class DatasetRegistryMetaListener
{
    public function __construct(
        private DatasetRegistryUpdater $registryUpdater,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[AsEventListener(event: DatasetMetaWrittenEvent::class)]
    public function __invoke(DatasetMetaWrittenEvent $event): void
    {
        try {
            $this->registryUpdater->syncFromMeta($event->datasetKey);
        } catch (\Throwable $e) {
            // Registration is a side effect of acquisition, not its purpose. A provider that has
            // just written gigabytes of raw must not fail because the registry row could not be
            // updated — the meta file is on disk, and dataset:scan remains the repair path.
            $this->logger->error('dataset registry: could not register {key} from meta: {err}', [
                'key' => $event->datasetKey,
                'err' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
