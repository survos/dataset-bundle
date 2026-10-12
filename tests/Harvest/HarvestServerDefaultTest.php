<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Harvest;

use PHPUnit\Framework\TestCase;
use Survos\DatasetBundle\SurvosDatasetBundle;
use Survos\FolioBundle\SurvosFolioBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

final class HarvestServerDefaultTest extends TestCase
{
    public function testHarvestSyncPointsFolioAtHarvestWithoutAppConfig(): void
    {
        $folio = $this->prepend(['harvest_sync' => ['scope' => 'all']]);

        self::assertSame('%env(default:survos_dataset.harvest_server:HARVEST_SERVER)%', $folio['folio_server']);
        self::assertTrue($folio['dataset_api']['enabled']);
        self::assertSame($folio['folio_server'], $folio['dataset_api']['server']);
        self::assertSame('%env(default:survos_dataset.harvest_read_token:HARVEST_READ_TOKEN)%', $folio['dataset_api']['token']);
    }

    public function testProducerWithoutHarvestSyncLeavesFolioAlone(): void
    {
        self::assertNull($this->prepend([]));
    }

    /** @return array<string, mixed>|null the survos_folio config dataset-bundle prepended */
    private function prepend(array $datasetConfig): ?array
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $dataset = (new SurvosDatasetBundle())->getContainerExtension();
        $builder->registerExtension($dataset);
        $builder->registerExtension((new SurvosFolioBundle())->getContainerExtension());
        $builder->loadFromExtension('survos_dataset', $datasetConfig);

        self::assertInstanceOf(PrependExtensionInterface::class, $dataset);
        $dataset->prepend($builder);

        self::assertSame(SurvosDatasetBundle::HARVEST_SERVER, $builder->getParameter('survos_dataset.harvest_server'));

        return $builder->getExtensionConfig('survos_folio')[0] ?? null;
    }
}
