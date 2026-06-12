<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\DatasetPaths;

final class DataPathsTest extends TestCase
{
    #[Test]
    public function itNormalizesProviderDatasetReferences(): void
    {
        $paths = new DataPaths('/srv/app-data/');

        self::assertSame(
            ['provider' => 'dc', 'code' => 'tb09jw350', 'key' => 'dc-tb09jw350'],
            $paths->parseDatasetRef('DC/TB09JW350')
        );
        self::assertSame(
            ['provider' => 'smith', 'code' => 'objects', 'key' => 'smith-objects'],
            $paths->parseDatasetRef('smith-objects')
        );
        self::assertSame(
            ['provider' => 'fortepan', 'code' => 'fortepan', 'key' => 'fortepan-fortepan'],
            $paths->parseDatasetRef('fortepan')
        );
    }

    #[Test]
    public function itResolvesProviderDatasetAndArtifactPaths(): void
    {
        $paths = new DataPaths('/srv/app-data/', zipsRoot: 'vault');

        self::assertSame('/srv/app-data/work/dc/tb09jw350', $paths->datasetDir('dc/tb09jw350'));
        self::assertSame('/srv/app-data/work/dc', $paths->providerRoot('dc'));
        self::assertSame('/srv/app-data/vault/dc/tb09jw350', $paths->vaultDatasetDir('dc/tb09jw350'));
        self::assertSame('/srv/app-data/vault/dc/tb09jw350/obj.jsonl.gz', $paths->providerRawFile('dc/tb09jw350'));
        self::assertSame('/srv/app-data/folio/dc/tb09jw350.folio', $paths->folioFile('dc/tb09jw350'));
        self::assertSame('/srv/app-data/folio-archive/dc/tb09jw350.folio.gz', $paths->folioArchiveFile('dc/tb09jw350'));
    }

    #[Test]
    public function datasetPathsResolveStageDirectoriesAndFiles(): void
    {
        $paths = new DataPaths('/srv/app-data');
        $dataset = new DatasetPaths($paths, 'dc/tb09jw350');

        self::assertSame('dc-tb09jw350', $dataset->key);
        self::assertSame('/srv/app-data/work/dc/tb09jw350', $dataset->dir);
        self::assertSame('/srv/app-data/work/dc/tb09jw350/_meta', $dataset->metaDir);
        self::assertSame('/srv/app-data/work/dc/tb09jw350/_raw', $dataset->rawDir);
        self::assertSame('/srv/app-data/work/dc/tb09jw350/extract', $dataset->extractDir);
        self::assertSame('/srv/app-data/work/dc/tb09jw350/norm', $dataset->normalizeDir);
        self::assertSame('/srv/app-data/work/dc/tb09jw350/voc', $dataset->termsDir);
        self::assertSame('/srv/app-data/work/dc/tb09jw350/_folio', $dataset->enrichDir);
        self::assertSame('/srv/app-data/work/dc/tb09jw350/extract/obj.jsonl', $dataset->extractFile());
        self::assertSame('/srv/app-data/work/dc/tb09jw350/norm/obj.profile.json', $dataset->profileFile());
        self::assertSame('/srv/app-data/work/dc/tb09jw350/voc/genre.en.jsonl', $dataset->termsFile('genre', 'en'));
        self::assertSame('/srv/app-data/work/dc/tb09jw350/extract', $dataset->stageDir(Stage::Extract));
        self::assertSame('/srv/app-data/work/dc/tb09jw350/extract', $dataset->stageDir('extract'));
    }

    #[Test]
    public function itRejectsUnsafeArchivePaths(): void
    {
        $paths = new DataPaths('/srv/app-data');

        $this->expectException(\InvalidArgumentException::class);
        $paths->providerArchiveFile('dc', '../escape.jsonl');
    }
}
