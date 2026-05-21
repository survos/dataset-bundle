<?php
declare(strict_types=1);

namespace Survos\DataBundle\Meta;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class DatasetMetadataConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tb = new TreeBuilder('dataset');
        $root = $tb->getRootNode();

        $root
            ->children()
                // identity
                ->scalarNode('dataset_key')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('aggregator')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('source_id')->isRequired()->cannotBeEmpty()->end()

                // display
                ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('description')->defaultNull()->end()

                // institution / provider
                ->arrayNode('provider')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('uri')->defaultNull()->end()
                        ->arrayNode('labels')
                            ->useAttributeAsKey('lang')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()

                // geography
                ->arrayNode('country')
                    ->isRequired()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('iso2')->defaultNull()->end()
                    ->end()
                ->end()

                // contact info (often incomplete)
                ->arrayNode('contact')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('phone')->defaultNull()->end()
                        ->scalarNode('email')->defaultNull()->end()
                        ->scalarNode('url')->defaultNull()->end()
                    ->end()
                ->end()

                // dataset-level defaults / hints
                ->arrayNode('rights')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_uri')->defaultNull()->end()
                        ->scalarNode('statement')->defaultNull()->end()
                        ->enumNode('applies_to')
                            ->values(['media', 'metadata', 'both'])
                            ->defaultValue('media')
                        ->end()
                        ->booleanNode('inferred')->defaultFalse()->end()
                    ->end()
                ->end()

                // locale hints for translation/indexing
                ->arrayNode('locale')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default')->defaultValue('en')->end()
                        ->arrayNode('targets')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()

                // upstream bookkeeping (optional but useful)
                ->arrayNode('upstream')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('dataset_name')->defaultNull()->end()
                        ->scalarNode('organization_uri')->defaultNull()->end()
                    ->end()
                ->end()

                // escape hatch for future apps
                ->arrayNode('extras')
                    ->ignoreExtraKeys(false)
                    ->variablePrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ;

        return $tb;
    }
}
