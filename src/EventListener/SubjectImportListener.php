<?php

declare(strict_types=1);

namespace Survos\DataBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Entity\Subject;
use Survos\AiWorkflowBundle\Repository\SubjectRepository;
use Survos\AiWorkflowBundle\Task\Observation\ObserveTask;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\DataBundle\Event\DatasetIterateFinishedEvent;
use Survos\DataBundle\Event\DatasetIterateRowEvent;
use Survos\DataBundle\Event\DatasetIterateStartedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Generic dataset-row → Subject importer.
 *
 * Only registered when survos/ai-workflow-bundle is installed.
 * See SurvosDataBundle::loadExtension() for the class_exists guard.
 *
 * subjectType = provider prefix  (e.g. "fortepan" from "fortepan/hu")
 * subjectId   = row['id']
 * scope       = full dataset key (e.g. "fortepan/hu")
 */
final class SubjectImportListener
{
    private const FLUSH_BATCH = 500;

    /** @var array<string, true> subjectId → true */
    private array $existing = [];
    private int   $count    = 0;

    public function __construct(
        private readonly SubjectRepository $subjectRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[AsEventListener]
    public function onStarted(DatasetIterateStartedEvent $event): void
    {
        $this->existing = $this->subjectRepository->existingIdMap(
            $this->subjectType($event->dataset),
            $event->dataset,
        );
        $this->count = 0;
    }

    #[AsEventListener]
    public function onRow(DatasetIterateRowEvent $event): void
    {
        if ($event->row === null) {
            throw new \UnexpectedValueException(sprintf('Null row at index %d in dataset "%s".', $event->index ?? -1, $event->dataset));
        }

        $id = (string) ($event->row['id'] ?? '');
        if ($id === '') {
            throw new \UnexpectedValueException(sprintf('Row at index %d in dataset "%s" has no "id" field.', $event->index ?? -1, $event->dataset));
        }

        if (isset($this->existing[$id])) {
            return;
        }

        $subjectType = $this->subjectType($event->dataset);
        $subject          = new Subject($subjectType, $id, $event->dataset);
        $subject->data    = $event->row;
        $subject->marking = SubjectFlow::PLACE_PREPARED;
        $subject->addPendingStep(ObserveTask::TASK, SubjectFlow::TRANSITION_OBSERVE);
        $this->em->persist($subject);

        $this->existing[$id] = true;
        $this->count++;

        if ($this->count % self::FLUSH_BATCH === 0) {
            $this->em->flush();
            $this->em->clear();
        }
    }

    #[AsEventListener]
    public function onFinished(DatasetIterateFinishedEvent $event): void
    {
        $this->em->flush();
    }

    private function subjectType(string $dataset): string
    {
        return explode('/', $dataset, 2)[0];
    }
}
