<?php
declare(strict_types=1);

namespace Survos\DatasetBundle;

use Survos\DatasetBundle\Command\DataBrowseCommand;
use Survos\DatasetBundle\Command\DataDiagCommand;
use Survos\DatasetBundle\Command\DataHeadCommand;
use Survos\DatasetBundle\Command\DataPathCommand;
use Survos\DatasetBundle\Command\DataPurgeCommand;
use Survos\DatasetBundle\Command\DatasetIterateCommand;
use Survos\DatasetBundle\Command\ScanDatasetsCommand;
use Survos\DatasetBundle\Event\DatasetIterateEvent;
use Survos\DatasetBundle\EventListener\SubjectImportListener;
use Survos\DatasetBundle\Context\DatasetContext;
use Survos\DatasetBundle\Context\DatasetResolver;
use Survos\DatasetBundle\Controller\ProviderController;
use Survos\DatasetBundle\Doctrine\SqliteWalMiddleware;
use Survos\DatasetBundle\EventListener\DatasetContextConsoleListener;
use Survos\DatasetBundle\Meta\DatasetMetadataConfiguration;
use Survos\DatasetBundle\Meta\DatasetMetadataEnsurer;
use Survos\DatasetBundle\Meta\DatasetMetadataLoader;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Survos\DatasetBundle\Repository\CandidateRepository;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Repository\ProviderRepository;
use Survos\DatasetBundle\Menu\DataMenuSubscriber;
use Survos\DatasetBundle\Twig\Components\ProviderListComponent;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\DatasetRegistryUpdater;
use Survos\DatasetBundle\Service\ProviderSnapshotCodec;
use Survos\DatasetBundle\Service\SurvosDatasetPathsFactory;
use Survos\ImportBundle\Contract\DatasetContextInterface;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class SurvosDatasetBundle extends AbstractBundle
{

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('data_dir')->defaultValue('%env(APP_DATA_DIR)%')->end()
                ->scalarNode('dataset_root')->defaultValue('work')->end()
                ->scalarNode('artifact_root')->defaultValue('artifacts')->end()
                ->scalarNode('runs_root')->defaultValue('runs')->end()
                ->scalarNode('cache_root')->defaultValue('cache')->end()
                ->scalarNode('zips_root')->defaultValue('%env(ZIPS_DIR)%')->end()
                ->scalarNode('default_object_filename')->defaultValue('obj.jsonl')->end()
                ->arrayNode('providers')
                    ->info('Optional application-level provider allowlist. When set, data:scan-datasets only scans these provider directories.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('tenant_database_prefix')->defaultValue('')->end()
                ->arrayNode('tenants')
                    ->useAttributeAsKey('code')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('database')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(DataPaths::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->args([
                '$dataDir' => $config['data_dir'],
                '$datasetRoot' => $config['dataset_root'],
                '$artifactRoot' => $config['artifact_root'],
                '$runsRoot' => $config['runs_root'],
                '$cacheRoot' => $config['cache_root'],
                '$zipsRoot' => $config['zips_root'],
                '$defaultObjectFilename' => $config['default_object_filename'],
            ]);

        $services->set(ProviderSnapshotCodec::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set(DatasetRegistryUpdater::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        foreach ([DatasetMetadataConfiguration::class, DatasetMetadataLoader::class, DatasetMetadataEnsurer::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        if (interface_exists(DatasetPathsFactoryInterface::class)) {
            $services->set(SurvosDatasetPathsFactory::class)
                ->autowire()
                ->autoconfigure()
                ->public();

            $services->alias(DatasetPathsFactoryInterface::class, SurvosDatasetPathsFactory::class)
                ->public();
        }

        $services->set(SqliteWalMiddleware::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.middleware');

        foreach ([DatasetContext::class, DatasetResolver::class, DatasetContextConsoleListener::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        if (interface_exists(DatasetContextInterface::class)) {
            $services->alias(DatasetContextInterface::class, DatasetContext::class)->public();
        }

        foreach ([DataDiagCommand::class, DataPathCommand::class, DataPurgeCommand::class, DataHeadCommand::class, DataBrowseCommand::class, DatasetIterateCommand::class] as $class) {
            $services->set($class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        $services->set(ScanDatasetsCommand::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->arg('$enabledProviders', $config['providers']);

        if (class_exists(\Survos\AiWorkflowBundle\Entity\Subject::class)) {
            $services->set(SubjectImportListener::class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        $services->set(DatasetInfoRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(ArtifactRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(CandidateRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(ProviderRepository::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('doctrine.repository_service');

        $services->set(ProviderListComponent::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->arg('$enabledProviders', $config['providers']);

        $services->set(ProviderController::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->arg('$enabledProviders', $config['providers']);

        if (class_exists(\Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber::class)) {
            $services->set(DataMenuSubscriber::class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        $services->set(Tenant\TenantRegistry::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->args([
                '$databasePrefix' => $config['tenant_database_prefix'],
                '$tenants' => $config['tenants'],
            ]);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $entityDir = dirname(__DIR__) . '/src/Entity';
        $templateDir = dirname(__DIR__) . '/templates';

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'SurvosDatasetBundle' => [
                            'is_bundle' => false,
                            'type' => 'attribute',
                            'dir' => $entityDir,
                            'prefix' => 'Survos\DatasetBundle\Entity',
                            'alias' => 'SurvosDatasetBundle',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('api_platform')) {
            $builder->prependExtensionConfig('api_platform', [
                'mapping' => [
                    'paths' => [$entityDir],
                ],
            ]);
        }

        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'paths' => [
                    $templateDir => 'SurvosDatasetBundle',
                ],
            ]);
        }

        if ($builder->hasExtension('twig_component')) {
            $builder->prependExtensionConfig('twig_component', [
                'defaults' => [
                    'Survos\\DatasetBundle\\Twig\\Components\\' => [
                        'template_directory' => '@SurvosDatasetBundle/components/',
                    ],
                ],
            ]);
        }
    }

    public function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(dirname(__DIR__) . '/src/Controller/', 'attribute');
    }

}
