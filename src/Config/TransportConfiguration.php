<?php

namespace Octamp\Wamp\Config;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class TransportConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('transport');
        $this->configure($treeBuilder->getRootNode());

        return $treeBuilder;
    }

    protected function configure(NodeDefinition|ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->scalarNode('host')->isRequired()->end()
                ->integerNode('port')->isRequired()->end()
                ->integerNode('workerNum')->defaultValue(1)->end()
                ->arrayNode('realms')->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->isRequired()->end()
                        ->end()
                    ->end()
                    ->isRequired()
                    ->validate()
                        ->ifEmpty()->thenInvalid('Realms should not empty')
                    ->end()
                ->end()
                ->arrayNode('auths')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('method')->values(['anonymous', 'ticket', 'wampcra'])->isRequired()->end()
                            ->enumNode('type')->values(['static', 'dynamic'])->isRequired()->end()
                            ->scalarNode('authenticator')->end()
                            ->scalarNode('authenticatorRealm')->end()
                            ->arrayNode('realms')
                                ->scalarPrototype()->end()
                                ->validate()
                                    ->ifEmpty()->thenInvalid('Realms should not empty')
                                ->end()
                            ->end()
                            ->arrayNode('users')
                                ->arrayPrototype()->ignoreExtraKeys(false)->end()
                                ->validate()
                                    ->ifEmpty()->thenInvalid('Users should not empty')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->isRequired()
                    ->validate()
                        ->ifEmpty()->thenInvalid('Auths should have one auth method')
                    ->end()
                ->end()
            ->end();
    }
}