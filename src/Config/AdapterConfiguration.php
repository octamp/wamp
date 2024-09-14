<?php

namespace Octamp\Wamp\Config;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class AdapterConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('adapter');
        $this->configure($treeBuilder->getRootNode());

        return $treeBuilder;
    }

    protected function configure(NodeDefinition|ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->enumNode('type')
                    ->values(['redis'])
                    ->defaultValue('redis')
                ->end()
                ->scalarNode('host')
                    ->defaultValue('0.0.0.0')
                ->end()
                ->integerNode('port')
                    ->defaultValue(6379)
                ->end()
                ->arrayNode('auth')
                    ->children()
                        ->scalarNode('username')->end()
                        ->scalarNode('password')->end()
                    ->end()
                ->end()
                ->arrayNode('options')
                    ->ignoreExtraKeys(false)
                    ->children()
                        ->integerNode('database')->defaultValue(0)->end()
                    ->end()
                ->end()
            ->end();
    }
}