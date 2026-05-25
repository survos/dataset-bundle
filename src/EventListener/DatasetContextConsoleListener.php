<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\EventListener;

use Survos\DatasetBundle\Context\DatasetContext;
use Survos\DatasetBundle\Context\DatasetResolver;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

use function sprintf;

final class DatasetContextConsoleListener
{
    public function __construct(
        private readonly DatasetContext $datasetContext,
        private readonly DatasetResolver $datasetResolver,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        try {
            $dataset = $this->datasetResolver->resolve($event->getInput());
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(sprintf('Invalid dataset key: %s', $e->getMessage()), 0, $e);
        }

        if ($dataset === null) {
            return;
        }

        $this->datasetContext->set($dataset);
    }
}
