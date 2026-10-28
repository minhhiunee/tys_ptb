<?php

declare(strict_types=1);

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

final class CameraConfiguration
{
    public static function getNode(): ArrayNodeDefinition
    {
        return (new TreeBuilder('camera'))->getRootNode()->addDefaultsIfNotSet()
            ->ignoreExtraKeys()
            ->children()
                ->scalarNode('provider')
                    ->defaultValue('auto')
                    ->info('Active camera provider: auto, webcam, sony, mock')
                ->end()
                ->scalarNode('sony_model')
                    ->defaultValue('ILCE-6400 / ZV-E10')
                    ->info('Target Sony Alpha model (e.g. ILCE-6400, ZV-E10)')
                ->end()
                ->booleanNode('auto_reconnect')
                    ->defaultTrue()
                    ->info('Automatically reset USB PTP and reconnect if Sony camera times out.')
                ->end()
            ->end();
    }
}

