<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Harvest;

use PHPUnit\Framework\TestCase;
use Survos\DatasetBundle\Event\HarvestSyncedEvent;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class HarvestSyncedEventTest extends TestCase
{
    public function testListenersCanTellWhichEntriesAreHeldAndRevised(): void
    {
        $event = new HarvestSyncedEvent(
            new SymfonyStyle(new ArrayInput([]), new NullOutput()),
            [],
            receipts: ['loc/covid' => 'r1', 'loc/covid#es' => 'r2'],
            changed: true,
            previousReceipts: ['loc/covid' => 'r1', 'loc/covid#es' => 'r1'],
        );

        self::assertTrue($event->held(self::entry('loc/covid', 'r1')));
        self::assertFalse($event->held(self::entry('mus/fpus', 'r1')));
        self::assertFalse($event->revised(self::entry('loc/covid', 'r1')));
        self::assertTrue($event->revised(self::entry('loc/covid', 'r2', 'es')));
    }

    private static function entry(string $key, string $revision, ?string $locale = null): FolioCatalogEntry
    {
        [$provider, $code] = explode('/', $key);

        return new FolioCatalogEntry($key, $provider, $code, $key, locale: $locale, revision: $revision);
    }
}
