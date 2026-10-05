<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\DataContracts\Path\Stage;
use Survos\DatasetBundle\Service\DataPaths as LegacyDataPaths;
use Survos\DatasetBundle\SurvosDatasetBundle;

final class DataPathsCompatibilityTest extends TestCase
{
    public function testLegacyPathsStillAcceptNamedArgumentsAndWarnOnConstruction(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity !== E_USER_DEPRECATED) {
                return false;
            }
            $deprecations[] = $message;

            return true;
        });
        try {
            $paths = new LegacyDataPaths('/srv/data', zipsRoot: 'custom-vault', worksRoot: 'custom-work');
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(DataPaths::class, $paths);
        self::assertSame('/srv/data/custom-work/dc/example', $paths->datasetDir('dc/example'));
        self::assertSame('/srv/data/custom-vault/dc/example', $paths->vaultDatasetDir('dc/example'));
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('Use Survos\\DataContracts\\Path\\DataPaths instead', $deprecations[0]);
    }

    public function testBundleBootstrapsLegacyEnumTypeHintsWithoutChangingCaseIdentity(): void
    {
        class_exists(SurvosDatasetBundle::class);
        $legacyConsumer = static fn (\Survos\DatasetBundle\Enum\Stage $stage): Stage => $stage;

        self::assertSame(Stage::Raw, $legacyConsumer(Stage::Raw));
        self::assertSame(Stage::Raw, \Survos\DatasetBundle\Enum\Stage::Raw);
    }
}
