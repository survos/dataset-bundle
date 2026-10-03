<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Meta;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DatasetBundle\Configuration\DatasetConfiguration;
use Survos\DatasetBundle\Event\DatasetMetaWrittenEvent;
use Survos\DatasetBundle\Meta\DatasetMetadataEnsurer;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\DatasetPaths;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

final class DatasetMetadataEnsurerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/dataset-ensurer-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function itAnnouncesADatasetOnlyWhenItsMetadataChanges(): void
    {
        $announced = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(DatasetMetaWrittenEvent::class, static function (DatasetMetaWrittenEvent $event) use (&$announced): void {
            $announced[] = $event->datasetKey;
        });
        $ensurer = new DatasetMetadataEnsurer(eventDispatcher: $dispatcher);
        $paths = new DatasetPaths(new DataPaths($this->root), 'cron-america/sn1');
        $config = fn (string $label): DatasetConfiguration => DatasetConfiguration::create('cron-america/sn1', 'cron-america')->withLabel($label);

        $ensurer->ensureJson($paths, $config('The Gazette'));
        self::assertCount(1, $announced, 'the first write declares the dataset');
        self::assertFileExists($paths->metaJson);

        $before = filemtime($paths->metaJson);
        $ensurer->ensureJson($paths, $config('The Gazette'));
        $ensurer->ensureJson($paths, $config('The Gazette'));
        self::assertCount(1, $announced, 'the same declaration is not announced again');
        self::assertSame($before, filemtime($paths->metaJson), 'and the file is left alone');

        $ensurer->ensureJson($paths, $config('The Daily Gazette'));
        self::assertCount(2, $announced, 'a changed declaration is announced');
        self::assertStringContainsString('The Daily Gazette', (string) file_get_contents($paths->metaJson));
    }

    #[Test]
    public function writeFalseComputesWithoutWritingOrAnnouncing(): void
    {
        $announced = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(DatasetMetaWrittenEvent::class, static function () use (&$announced): void { ++$announced; });
        $paths = new DatasetPaths(new DataPaths($this->root), 'cron-america/sn2');

        (new DatasetMetadataEnsurer(eventDispatcher: $dispatcher))
            ->ensureJson($paths, DatasetConfiguration::create('cron-america/sn2', 'cron-america'), write: false);

        self::assertSame(0, $announced);
        self::assertFileDoesNotExist($paths->metaJson);
    }
}
