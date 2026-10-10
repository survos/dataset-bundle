<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Harvest;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Harvest\Entity\HarvestSyncCheckpoint;
use Symfony\Component\Lock\LockFactory;

/** The durable Harvest checkpoint and the lock that manual and scheduled syncs share. */
final readonly class SyncState
{
    public function __construct(
        private EntityManagerInterface $em,
        private LockFactory $locks,
    ) {}

    public function load(): ?array
    {
        $row = $this->em->find(HarvestSyncCheckpoint::class, 'harvest');
        if ($row === null) {
            return null;
        }
        // A long-running worker must see a checkpoint written by a manual run.
        $this->em->refresh($row);

        return $row->checkpoint;
    }

    public function save(array $state): void
    {
        $row = $this->em->find(HarvestSyncCheckpoint::class, 'harvest') ?? new HarvestSyncCheckpoint();
        $row->checkpoint = $state;
        $this->em->persist($row);
        $this->em->flush();
    }

    public function locked(callable $work): mixed
    {
        $lock = $this->locks->createLock('survos_dataset.harvest-sync', ttl: null);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another Harvest sync is running.');
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
