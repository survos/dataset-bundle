<?php
declare(strict_types=1);

namespace Survos\DatasetBundle;

use Survos\DatasetBundle\Event\DatasetArtifactUpdatedEvent;
use Survos\DatasetBundle\EventListener\SubjectImportListener;
use Survos\DatasetBundle\Service\DatasetIntlService;
use Survos\DatasetBundle\Service\PhraseExtractor;
use Survos\DatasetBundle\Context\DatasetContext;
use Survos\DatasetBundle\Context\DatasetResolver;
use Survos\DatasetBundle\Doctrine\SqliteWalMiddleware;
use Survos\DatasetBundle\EventListener\DatasetContextConsoleListener;
use Survos\DatasetBundle\EventListener\DatasetRegistryArtifactListener;
use Survos\DatasetBundle\EventListener\DatasetRegistryImportConvertListener;
use Survos\DatasetBundle\Meta\DatasetMetadataConfiguration;
use Survos\DatasetBundle\Meta\DatasetMetadataEnsurer;
use Survos\DatasetBundle\Meta\DatasetMetadataLoader;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Survos\DatasetBundle\Repository\CandidateRepository;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Repository\ProviderRepository;
use Survos\DatasetBundle\Menu\DataMenuSubscriber;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\HfHubClient;
use Survos\DatasetBundle\Service\DatasetStageInventory;
use Survos\DatasetBundle\Service\DatasetRegistryUpdater;
use Survos\DatasetBundle\Service\ProviderSnapshotCodec;
use Survos\DatasetBundle\Service\SurvosDatasetPathsFactory;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Contract\DatasetContextInterface;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\MeiliBundle\SurvosMeiliBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

#[RequiredBundle(SurvosMeiliBundle::class, ignoreOnInvalid: true)]
final class SurvosDatasetBundle extends AbstractBundle
{
    use HasConfigurableRoutes;


    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('routes_enabled')->defaultTrue()
                    ->info('Set false to disable automatic bundle route registration.')
                ->end()
                ->scalarNode('route_prefix')->defaultValue('')
                    ->info('URL prefix applied to all routes from this bundle.')
                ->end()
                ->scalarNode('data_dir')->defaultValue('%env(APP_DATA_DIR)%')->end()
                ->scalarNode('registry_database_path')->defaultValue('%env(APP_DATA_DIR)%/datasets.db')
                    ->info('SQLite database path for the shared dataset registry cache.')
                ->end()
                ->scalarNode('dataset_root')->defaultValue('work')->end()
                ->scalarNode('artifact_root')->defaultValue('artifacts')->end()
                ->scalarNode('runs_root')->defaultValue('runs')->end()
                ->scalarNode('cache_root')->defaultValue('cache')->end()
                ->scalarNode('zips_root')->defaultValue('vault')->end()
                ->scalarNode('default_object_filename')->defaultValue('obj.jsonl')->end()
                ->integerNode('normalized_row_limit')->defaultValue(0)
                    ->info('Cap records per core when the workflow normalizes (raw→normalized). 0 = all. Bind to an env var (e.g. DATASET_NORMALIZED_ROW_LIMIT) to throttle for smoke tests in .env.local.')
                ->end()
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

    public function build(ContainerBuilder $container): void
    {
        $this->addRouteLoaderCompilerPass($container);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->captureRouteConfig($config);
        $container->parameters()->set('survos_dataset.registry_database_path', $config['registry_database_path']);
        $container->parameters()->set('survos_dataset.providers', $config['providers']);
        $container->parameters()->set('survos_dataset.normalized_row_limit', $config['normalized_row_limit']);
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

        // HuggingFace archive sync client (hf:pull / hf:push). Autowires from the HTTP client +
        // %env(default::HF_TOKEN)%; HfCommand treats it as optional.
        $services->set(HfHubClient::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        $services->set(DatasetRegistryUpdater::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->arg('$entityManager', new \Symfony\Component\DependencyInjection\Reference('doctrine.orm.dataset_entity_manager'));

        // Shared reset core (dataset:purge + the app's agg:reset). The optional workflow.registry is
        // pulled in via #[Autowire] on the constructor.
        $services->set(\Survos\DatasetBundle\Service\DatasetReset::class)
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

        // Auto-register the attribute-tagged classes in src/Command, src/Controller and
        // src/Twig/Components — the same PSR-4 + autoconfigure mechanism as
        // Survos\Kit\AbstractSurvosBundle::loadExtension(): drop a class in the dir and it wires
        // itself up. The provider allowlist is the only cross-cutting constructor arg, so it is
        // bound once here; the dataset entity manager (the one genuinely special dependency — a
        // private sqlite registry) is pulled in via an #[Autowire] attribute where needed.
        $autoload = $services->defaults()->autowire()->autoconfigure()
            ->bind('$enabledProviders', '%survos_dataset.providers%');

        foreach (['Command', 'Controller', 'Twig\\Components'] as $sub) {
            $dir = $this->getPath() . '/src/' . str_replace('\\', '/', $sub) . '/';
            if (is_dir($dir)) {
                $autoload->load('Survos\\DatasetBundle\\' . $sub . '\\', $dir);
            }
        }

        if (class_exists(\Survos\AiWorkflowBundle\Entity\Subject::class)) {
            $services->set(SubjectImportListener::class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        $services->set(DatasetRegistryArtifactListener::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('kernel.event_listener', ['event' => DatasetArtifactUpdatedEvent::class]);

        if (class_exists(\Survos\ImportBundle\Event\ImportConvertFinishedEvent::class)) {
            $services->set(DatasetRegistryImportConvertListener::class)
                ->autowire()
                ->autoconfigure()
                ->public()
                ->tag('kernel.event_listener', ['event' => ImportConvertFinishedEvent::class]);
        }

        if (class_exists(\Survos\ImportBundle\Event\ImportConvertRowEvent::class)) {
            $services->set(PhraseExtractor::class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        if (class_exists(\Survos\LinguaBundle\Service\LinguaClient::class)) {
            $services->set(DatasetIntlService::class)
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

        $services->set(DatasetStageInventory::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        if ($config['routes_enabled'] && class_exists(\Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber::class)) {
            $services->set(DataMenuSubscriber::class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }
        $this->registerRouteLoader($builder);


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
        $registryDatabasePath = '%env(APP_DATA_DIR)%/datasets.db';
        foreach ($builder->getExtensionConfig('survos_dataset') as $extensionConfig) {
            if (isset($extensionConfig['registry_database_path']) && is_string($extensionConfig['registry_database_path']) && $extensionConfig['registry_database_path'] !== '') {
                $registryDatabasePath = $extensionConfig['registry_database_path'];
            }
        }
        $builder->setParameter('survos_dataset.registry_database_path', $registryDatabasePath);

        $entityDir = dirname(__DIR__) . '/src/Entity';
        $templateDir = dirname(__DIR__) . '/templates';

        if ($builder->hasExtension('doctrine')) {
            // This bundle adds a SECOND Doctrine connection ('dataset', pdo_sqlite) and
            // entity manager. Once a second connection exists, Doctrine no longer infers
            // the default from the dbal `url` shorthand — it falls back to the first
            // declared connection, which would silently make this sqlite registry the
            // app's default connection/EM. Rather than guess, fail loud: require the app
            // to pin the default explicitly. (Prepended config is lowest priority, so we
            // cannot safely set it for them without risking the wrong choice.)
            $hasDefaultConnection = false;
            $hasDefaultEm = false;
            foreach ($builder->getExtensionConfig('doctrine') as $doctrineConfig) {
                if (!empty($doctrineConfig['dbal']['default_connection'])) {
                    $hasDefaultConnection = true;
                }
                if (!empty($doctrineConfig['orm']['default_entity_manager'])) {
                    $hasDefaultEm = true;
                }
            }

            if (!$hasDefaultConnection || !$hasDefaultEm) {
                throw new \LogicException(
                    "SurvosDatasetBundle registers a second Doctrine connection ('dataset', pdo_sqlite) and "
                    . "entity manager, which makes the default ambiguous. Pin them explicitly in "
                    . "config/packages/doctrine.yaml so your app DB stays the default:\n\n"
                    . "    doctrine:\n"
                    . "        dbal:\n"
                    . "            default_connection: default\n"
                    . "            connections:\n"
                    . "                default:\n"
                    . "                    url: '%env(resolve:DATABASE_URL)%'\n"
                    . "        orm:\n"
                    . "            default_entity_manager: default\n"
                    . "            entity_managers:\n"
                    . "                default:\n"
                    . "                    connection: default\n"
                    . "                    mappings: { App: { ... } }\n\n"
                    . "Without this the 'dataset' sqlite connection silently becomes the app default."
                );
            }

            $builder->prependExtensionConfig('doctrine', [
                'dbal' => [
                    'connections' => [
                        'dataset' => [
                            'driver' => 'pdo_sqlite',
                            'path' => '%survos_dataset.registry_database_path%',
                            'logging' => false,
                        ],
                    ],
                ],
                'orm' => [
                    'entity_managers' => [
                        'dataset' => [
                            'connection' => 'dataset',
                            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
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

}
