<?php

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class LightroomConfiguration
{
    public static function getNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('lightroom');
        $node = $treeBuilder->getRootNode();
        
        $node
            ->addDefaultsIfNotSet()
            ->ignoreExtraKeys()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Enable Lightroom Bridge Mode (Hot-folder workflow).')
                ->end()
                ->integerNode('poll_timeout')
                    ->defaultValue(60)
                    ->info('Timeout in seconds to wait for Lightroom to export the image.')
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function (string $value): int {
                            return intval($value);
                        })
                    ->end()
                ->end()
                ->scalarNode('executable_path')

                    ->defaultValue('D:\\Adobe\\Lightroom 2024\\Adobe Lightroom Classic\\Lightroom.exe')
                    ->info('Path to Lightroom Classic executable on the host system.')
                ->end()
                ->scalarNode('preset_folder')
                    ->defaultValue('TYS TEST')
                    ->info('Lightroom preset group/folder name.')
                ->end()
            ->end();


        return $node;
    }
}
