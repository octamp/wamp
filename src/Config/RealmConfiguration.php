<?php

namespace Octamp\Wamp\Config;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class RealmConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('realms');
        $this->configure($treeBuilder->getRootNode());

        return $treeBuilder;
    }

    protected function configure(NodeDefinition|ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->arrayPrototype()
                ->children()
                    ->scalarNode('name')->isRequired()->end()
                ->end()
            ->end();
    }
}