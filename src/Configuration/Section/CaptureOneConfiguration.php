<?php

declare(strict_types=1);

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

final class CaptureOneConfiguration
{
    public static function getNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('capture_one');
        $node = $treeBuilder->getRootNode();

        $intNormalizer = static function (mixed $value): int {
            return (int) $value;
        };

        $node
            ->addDefaultsIfNotSet()
            ->ignoreExtraKeys()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Enable Capture One external image processing integration.')
                ->end()
                ->scalarNode('camera_mode')
                    ->defaultValue('photobooth_owns_camera')
                    ->info('Camera ownership strategy: photobooth_owns_camera (Hot Folder Bridge) or capture_one_owns_camera (Tethered Watch).')
                ->end()
                ->scalarNode('executable_path')
                    ->defaultValue('C:\\Program Files\\Capture One\\Capture One\\CaptureOne.exe')
                    ->info('Reference path to Capture One executable on the host system.')
                ->end()
                ->scalarNode('session_path')
                    ->defaultValue('data/c1_session/Photobooth.cosessiondb')
                    ->info('Capture One Session database path (.cosessiondb) or session folder.')
                ->end()
                ->scalarNode('input_folder')
                    ->defaultValue('data/c1_input')
                    ->info('Hot Folder input path where Photobooth drops captured poses for Capture One.')
                ->end()
                ->scalarNode('output_folder')
                    ->defaultValue('data/c1_output')
                    ->info('Output folder where Capture One Process Recipe exports processed JPEGs/TIFFs.')
                ->end()
                ->scalarNode('default_style')
                    ->defaultValue('clean-portrait')
                    ->info('Default Capture One Style (.costyle) or preset ID applied to captures.')
                ->end()
                ->scalarNode('process_recipe')
                    ->defaultValue('Photobooth_JPEG_sRGB')
                    ->info('Active Capture One Process Recipe name.')
                ->end()
                ->scalarNode('output_format')
                    ->defaultValue('jpeg')
                    ->info('Processed output image format: jpeg or tiff.')
                ->end()
                ->integerNode('jpeg_quality')
                    ->defaultValue(92)
                    ->min(50)
                    ->max(100)
                    ->beforeNormalization()->ifString()->then($intNormalizer)->end()
                    ->info('JPEG export quality (50-100).')
                ->end()
                ->integerNode('output_resolution')
                    ->defaultValue(2400)
                    ->min(600)
                    ->max(6000)
                    ->beforeNormalization()->ifString()->then($intNormalizer)->end()
                    ->info('Long-edge output resolution in pixels.')
                ->end()
                ->integerNode('processing_timeout')
                    ->defaultValue(25)
                    ->min(3)
                    ->max(120)
                    ->beforeNormalization()->ifString()->then($intNormalizer)->end()
                    ->info('Timeout in seconds to wait for Capture One to export a processed image.')
                ->end()
                ->integerNode('stability_wait_ms')
                    ->defaultValue(350)
                    ->min(100)
                    ->max(3000)
                    ->beforeNormalization()->ifString()->then($intNormalizer)->end()
                    ->info('Milliseconds between file-size stability checks before ingesting an exported file.')
                ->end()
                ->integerNode('retry_count')
                    ->defaultValue(2)
                    ->min(0)
                    ->max(5)
                    ->beforeNormalization()->ifString()->then($intNormalizer)->end()
                    ->info('Number of retry attempts before falling back.')
                ->end()
                ->booleanNode('fallback_to_local_preset')
                    ->defaultTrue()
                    ->info('Automatically fall back to Photobooth PresetService if Capture One times out or is offline.')
                ->end()
            ->end();

        return $node;
    }
}

