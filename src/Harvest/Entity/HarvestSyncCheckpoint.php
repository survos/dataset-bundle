<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Harvest\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The last successful Harvest synchronization: feed cursor, dataset map and per-folio receipts.
 * One row, written only after a sync's local work succeeded (see SyncState). Mapped into the
 * app's default entity manager; the app's own migration creates the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'harvest_sync_checkpoint')]
class HarvestSyncCheckpoint
{
    #[ORM\Column(type: Types::JSON)]
    public array $checkpoint = [];

    public function __construct(
        #[ORM\Id, ORM\Column(length: 32)] public readonly string $id = 'harvest',
    ) {}
}
