<?php

declare(strict_types=1);

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

final class SyncConfiguration
{
    public static function getNode(): ArrayNodeDefinition
    {
        return (new TreeBuilder('sync'))->getRootNode()->addDefaultsIfNotSet()
            ->ignoreExtraKeys()
            ->children()
                ->scalarNode('environment')
                    ->defaultValue('local')
                    ->info('Active deployment profile: local, staging, production')
                ->end()
                ->booleanNode('auto_sync')
                    ->defaultFalse()
                    ->info('Automatically push completed sessions to remote sync endpoint.')
                ->end()
                ->scalarNode('endpoint')
                    ->defaultValue('')
                    ->info('Default remote syncReceive.php URL')
                ->end()
                ->scalarNode('api_key')
                    ->defaultValue('')
                    ->info('Shared secret key for authenticating kiosk uploads')
                ->end()
                ->integerNode('max_file_size_mb')
                    ->defaultValue(25)
                ->end()
                ->arrayNode('endpoints')
                    ->addDefaultsIfNotSet()
                    ->ignoreExtraKeys()
                    ->children()
                        ->scalarNode('local')->defaultValue('')->end()
                        ->scalarNode('staging')->defaultValue('')->end()
                        ->scalarNode('production')->defaultValue('')->end()
                    ->end()
                ->end()
            ->end();
    }
}

