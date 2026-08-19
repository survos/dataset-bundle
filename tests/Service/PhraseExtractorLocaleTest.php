<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\PhraseExtractor;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The source locale is not cosmetic: it names the phrases.<locale>.jsonl file, tags every phrase,
 * and feeds HashUtil::calcSourceKey, so it decides which hash Lingua stores a translation under.
 * When it was wrong, push reported success, Lingua translated en→en, and pull returned
 * "0 translated, 3056 missing" — for 1,250 musdig datasets whose registry locale was empty while
 * _meta/dataset.json held the real answer all along.
 *
 * PhraseExtractor's collaborators are final (DatasetInfoRepository), so the on-disk lookup that
 * fixes this is exercised directly rather than through a mock stack.
 */
final class PhraseExtractorLocaleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phrase-extractor-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function itReadsLocaleDefaultFromDatasetMeta(): void
    {
        $this->writeMeta('musdig/808', ['locale' => ['default' => 'de', 'targets' => ['en']]]);

        self::assertSame('de', $this->localeFromMeta('musdig/808'));
    }

    #[Test]
    public function itReturnsNullWhenTheMetaFileIsAbsent(): void
    {
        // No dataset.json at all — the caller must be able to tell "unknown" from "English",
        // which is the distinction the old `?: 'en'` destroyed.
        self::assertNull($this->localeFromMeta('musdig/does-not-exist'));
    }

    #[Test]
    public function itReturnsNullWhenTheMetaFileHasNoLocale(): void
    {
        $this->writeMeta('musdig/1', ['provider' => ['uri' => 'https://example.org']]);

        self::assertNull($this->localeFromMeta('musdig/1'));
    }

    #[Test]
    public function itReturnsNullOnAnEmptyLocaleRatherThanAnEmptyString(): void
    {
        $this->writeMeta('musdig/2', ['locale' => ['default' => '', 'targets' => []]]);

        self::assertNull($this->localeFromMeta('musdig/2'));
    }

    #[Test]
    public function itSurvivesMalformedJsonWithoutThrowing(): void
    {
        $dir = $this->metaDir('musdig/broken');
        (new Filesystem())->mkdir($dir);
        file_put_contents($dir . '/dataset.json', '{"locale": {"default": "de"');

        // A truncated file during a concurrent write must not take down the normalize listener.
        self::assertNull($this->localeFromMeta('musdig/broken'));
    }

    /** @param array<string, mixed> $meta */
    private function writeMeta(string $datasetKey, array $meta): void
    {
        $dir = $this->metaDir($datasetKey);
        (new Filesystem())->mkdir($dir);
        file_put_contents($dir . '/dataset.json', json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function metaDir(string $datasetKey): string
    {
        return $this->paths()->stageDir($datasetKey, Stage::Meta);
    }

    private function paths(): DataPaths
    {
        return new DataPaths($this->root);
    }

    /** Exercises the private on-disk lookup without booting a container. */
    private function localeFromMeta(string $datasetKey): ?string
    {
        $rc = new \ReflectionClass(PhraseExtractor::class);
        $extractor = $rc->newInstanceWithoutConstructor();

        $paths = $rc->getProperty('paths');
        $paths->setValue($extractor, $this->paths());

        $method = $rc->getMethod('localeFromMeta');

        return $method->invoke($extractor, $datasetKey);
    }
}
