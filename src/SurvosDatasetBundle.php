<?php
declare(strict_types=1);

namespace Survos\DatasetBundle;

use Survos\DatasetBundle\EventListener\SubjectImportListener;
use Survos\DatasetBundle\Service\DatasetIntlService;
use Survos\DatasetBundle\Service\PhraseExtractor;
use Survos\DatasetBundle\Context\DatasetContext;
use Survos\DatasetBundle\Context\DatasetResolver;
use Survos\DatasetBundle\Doctrine\SqliteWalMiddleware;
use Survos\DatasetBundle\EventListener\DatasetContextConsoleListener;
use Survos\DatasetBundle\EventListener\DatasetRegistryArtifactListener;
use Survos\DatasetBundle\EventListener\DatasetRegistryMetaListener;
use Survos\DatasetBundle\EventListener\DatasetRegistryImportConvertListener;
use Survos\DatasetBundle\Meta\DatasetMetadataConfiguration;
use Survos\DatasetBundle\Meta\DatasetMetadataEnsurer;
use Survos\DatasetBundle\Meta\DatasetMetadataLoader;
use Survos\DatasetBundle\Repository\ArtifactRepository;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Repository\ProviderRepository;
use Survos\DatasetBundle\Menu\DataMenuSubscriber;
use Survos\DataContracts\Path\DataPaths;

// Stage moved to Survos\DataContracts\Path\Stage (2026-09-23). An enum cannot be subclassed the
// way DataPaths is, so the old name is a class_alias in src/Enum/Stage.php — and `instanceof` and
// parameter type checks do NOT autoload, so that alias has to exist BEFORE anything type-hints the
// old name. Loading it here means it does, for every app that enables this bundle: this file is
// read at kernel boot, long before any service is constructed. Static uses (Stage::Raw) autoload
// on their own; a bare `Stage $stage` parameter is the case that would otherwise fail.
class_exists(\Survos\DatasetBundle\Enum\Stage::class);
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
use Survos\GeonamesBundle\Service\GeoService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
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
                ->scalarNode('route_prefix')->defaultValue('/data')
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
                ->scalarNode('capture_root')->defaultNull()
                    ->info('Root for provider capture dirs (vault/<provider>/_capture) when they must live somewhere other than zips_root. Null/empty = same as zips_root. Absolute paths are used as-is, relative ones resolve under data_dir. Exists because capture and raw have opposite access patterns: capture holds write-once/read-once archives (nara ships a single 174 GB zip) while raw is re-read on every normalize, so a cached S3 mount that is right for raw is actively wrong for capture.')
                ->end()
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
                ->arrayNode('harvest_sync')
                    ->info('A consuming app\'s harvest:sync (full fetch or change-feed replay from folio-bundle\'s dataset_api) and its five-minute schedule. Off by default: Harvest, the producer, uses this bundle too. The app\'s own migration creates harvest_sync_checkpoint.')
                    ->canBeEnabled()
                    ->children()
                        ->enumNode('scope')->values(['folio_sets', 'all', 'app'])->defaultValue('folio_sets')
                            ->info('folio_sets: hold the members of survos_folio.folio_sets. all: every published dataset. app: what the app\'s Harvest\\HarvestSelection service returns.')
                        ->end()
                        ->scalarNode('lock_factory')->defaultValue('lock.factory')
                            ->info('LockFactory service id shared by manual and scheduled runs; use a PostgreSQL advisory store in production (a flock only excludes within one container).')
                        ->end()
                        ->booleanNode('schedule')->defaultTrue()
                            ->info('Register the "harvest" schedule (every 5 minutes), consumed by messenger:consume scheduler_harvest.')
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
                '$captureRoot' => $config['capture_root'],
                '$defaultObjectFilename' => $config['default_object_filename'],
            ]);

        // Preserve old constructor type hints without making every contract consumer use
        // the deprecated subclass. Inherit the configured paths, but instantiate the old type.
        $services->set(\Survos\DatasetBundle\Service\DataPaths::class)
            ->parent(DataPaths::class)
            ->class(\Survos\DatasetBundle\Service\DataPaths::class)
            ->public()
            ->deprecate('survos/dataset-bundle', '2.32', 'The "%service_id%" service is deprecated, use "Survos\\DataContracts\\Path\\DataPaths" instead.');

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

        if (!class_exists(\Survos\FolioBundle\Twig\FolioCoreTwig::class)) {
            $services->set(\Survos\DatasetBundle\Twig\FolioFallbackTwig::class)->tag('twig.extension');
        }

        if (class_exists(\Survos\AiWorkflowBundle\Entity\Subject::class)) {
            $services->set(SubjectImportListener::class)
                ->autowire()
                ->autoconfigure()
                ->public();
        }

        // No explicit kernel.event_listener tag on either of these: autoconfigure() already reads
        // their #[AsEventListener] attribute, and adding the tag as well registered them TWICE —
        // DatasetRegistryArtifactListener has been running the registry updater twice per artifact.
        $services->set(DatasetRegistryArtifactListener::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        // Makes `dataset:scan` unnecessary after an acquisition command: every provider's meta step
        // goes through DatasetMetadataEnsurer::ensureJson(), which announces the dataset here.
        $services->set(DatasetRegistryMetaListener::class)
            ->autowire()
            ->autoconfigure()
            ->public();

        // Scopes Artifact's /folio-archives collection to type=folio_archive -- see the class
        // docblock. autoconfigure() picks up API Platform's own registerForAutoconfiguration()
        // tag for QueryCollectionExtensionInterface, no explicit tag needed here.
        $services->set(\Survos\DatasetBundle\Doctrine\FolioArchiveTypeExtension::class)
            ->autowire()
            ->autoconfigure()
            ->public();

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
            // GeoService is optional (NULL_ON_INVALID_REFERENCE): dataset:intl:push/pull work with
            // Lingua alone even when survos/geonames-bundle isn't installed — geonames resolution
            // for place-name phrases is a bonus DatasetIntlService::resolvePlace() skips
            // gracefully when null, not a hard requirement for the whole command to exist.
            $services->set(DatasetIntlService::class)
                ->autowire()
                ->autoconfigure()
                ->public()
                ->arg('$geoService', new Reference(GeoService::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));
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

        // A consumer's Harvest sync, outside src/Command so Harvest itself never registers it.
        if ($config['harvest_sync']['enabled'] && class_exists(\Survos\FolioBundle\Catalog\FolioCatalogClient::class)) {
            $services->set(Harvest\SyncState::class)
                ->autowire()
                ->arg('$em', new Reference('doctrine.orm.default_entity_manager'))
                ->arg('$locks', new Reference($config['harvest_sync']['lock_factory']));
            $services->set(Harvest\HarvestSync::class)
                ->autowire()
                ->autoconfigure()
                ->args([
                    '$scope' => $config['harvest_sync']['scope'],
                    '$localPassthrough' => '%survos_folio.local_passthrough%',
                    '$sets' => new Reference(\Survos\FolioBundle\Set\FolioSetResolver::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$recordSets' => new Reference(\Survos\FolioBundle\Command\FolioSetsSyncCommand::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$selection' => new Reference(Harvest\HarvestSelection::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ]);
            if ($config['harvest_sync']['schedule'] && class_exists(\Symfony\Component\Scheduler\Attribute\AsSchedule::class)) {
                $services->set(Harvest\HarvestSchedule::class)->autowire()->autoconfigure();
            }
        }
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

            // The Harvest checkpoint lives in the app's own database, like Str/Tr: mapped into the
            // default entity manager, its table created by the app's migration.
            $harvestSync = false;
            foreach ($builder->getExtensionConfig('survos_dataset') as $extensionConfig) {
                $value = $extensionConfig['harvest_sync'] ?? null;
                if ($value === true || (is_array($value) && ($value['enabled'] ?? true))) {
                    $harvestSync = true;
                }
            }
            if ($harvestSync) {
                $defaultEm = 'default';
                foreach ($builder->getExtensionConfig('doctrine') as $doctrineConfig) {
                    $defaultEm = $doctrineConfig['orm']['default_entity_manager'] ?? $defaultEm;
                }
                $builder->prependExtensionConfig('doctrine', [
                    'orm' => [
                        'entity_managers' => [
                            $defaultEm => [
                                'mappings' => [
                                    'SurvosDatasetHarvest' => [
                                        'is_bundle' => false,
                                        'type' => 'attribute',
                                        'dir' => dirname(__DIR__) . '/src/Harvest/Entity',
                                        'prefix' => 'Survos\DatasetBundle\Harvest\Entity',
                                        'alias' => 'SurvosDatasetHarvest',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
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
