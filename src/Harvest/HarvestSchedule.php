<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Harvest;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/** Polls Harvest's change feed; consumed by `messenger:consume scheduler_harvest`. */
#[AsSchedule('harvest')]
final readonly class HarvestSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('5 minutes', new RunCommandMessage('harvest:sync --no-interaction')))
            ->stateful($this->cache)
            // One feed replay catches up every missed publication after downtime.
            ->processOnlyLastMissedRun(true);
    }
}
