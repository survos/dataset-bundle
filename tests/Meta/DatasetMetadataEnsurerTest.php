<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Meta;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DatasetBundle\Configuration\DatasetConfiguration;
use Survos\DatasetBundle\Event\DatasetMetaWrittenEvent;
use Survos\DatasetBundle\Meta\DatasetMetadataEnsurer;
use Survos\DataContracts\Path\DataPaths;
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
    #[Test]
    public function vaultSurvivesWorkDeletionAndOverridesAreReversible(): void
    {
        $paths = new DatasetPaths(new DataPaths($this->root), 'cron-america/sn3');
        $ensurer = new DatasetMetadataEnsurer();
        $config = DatasetConfiguration::create($paths->datasetKey, 'cron-america')->withLabel('Generated title')
            ->withDescription('Source description')->withExtra('titleRecord', ['essay' => 'Source essay']);
        $ensurer->ensureJson($paths, $config, owner: 'loc.title');
        $vault = $paths->paths->vaultDatasetDir($paths->datasetKey).'/_meta';
        self::assertFileExists($vault.'/dataset.json');
        file_put_contents($vault.'/dataset.overrides.json', json_encode(['properties' => ['description' => 'Human correction']]));
        $fs = new Filesystem(); $fs->remove($paths->dir);
        $coverage = DatasetConfiguration::create($paths->datasetKey, 'cron-america')->withExtra('coverage', ['pages' => 200]);
        $resolved = $ensurer->ensureJson($paths, $coverage, owner: 'loc.coverage');
        self::assertSame('Human correction', $resolved->description);
        self::assertSame('Source essay', $resolved->extras['titleRecord']['essay']);
        self::assertSame(file_get_contents($vault.'/dataset.json'), file_get_contents($paths->metaJson));
        file_put_contents($vault.'/dataset.overrides.json', '{"properties":{}}');
        $resolved = $ensurer->ensureJson($paths, $coverage, owner: 'loc.coverage');
        self::assertSame('Source description', $resolved->description);
    }

    #[Test]
    public function malformedExistingMetadataIsNotOverwritten(): void
    {
        $paths = new DatasetPaths(new DataPaths($this->root), 'cron-america/sn4');
        (new Filesystem())->mkdir($paths->metaDir);
        file_put_contents($paths->metaJson, '{broken');
        try {
            (new DatasetMetadataEnsurer())->ensureJson($paths, DatasetConfiguration::create($paths->datasetKey, 'cron-america'));
            self::fail('Invalid JSON must fail');
        } catch (\JsonException) {
            self::assertSame('{broken', file_get_contents($paths->metaJson));
        }
    }

    #[Test]
    public function unknownOverrideObjectsKeepTheirJsonShape(): void
    {
        $paths = new DatasetPaths(new DataPaths($this->root), 'cron-america/sn5');
        $vault = $paths->paths->vaultDatasetDir($paths->datasetKey).'/_meta';
        (new Filesystem())->mkdir($vault);
        file_put_contents($vault.'/dataset.overrides.json', '{"properties":{"custom.object":{"empty":{},"list":[]}}}');
        $ensurer = new DatasetMetadataEnsurer();
        $config = DatasetConfiguration::create($paths->datasetKey, 'cron-america');
        $ensurer->ensureJson($paths, $config);
        $before = file_get_contents($vault.'/dataset.json');
        $ensurer->ensureJson($paths, $config);
        self::assertSame($before, file_get_contents($vault.'/dataset.json'));
        $value = json_decode($before)->dataset->extras->metadataProperties->{'custom.object'}->value;
        self::assertInstanceOf(\stdClass::class, $value->empty);
        self::assertSame([], $value->list);
    }

}
