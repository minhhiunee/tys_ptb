<?php

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * Color workflow:
 *  - interactive_presets = false (default, "Mode 1"): a base color grade is baked into every shot right after
 *    it is captured. The result screen has no preset/amount controls, only Retake / GIF / QR / Print.
 *  - interactive_presets = true ("Mode 2"): shots stay pristine, guests can pick a preset + amount on the
 *    result screen and the grade is baked on demand (with per-shot background warm-up).
 */
class ColorWorkflowConfiguration
{
    public static function getNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('color_workflow');
        $node = $treeBuilder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->ignoreExtraKeys()
            ->children()
                ->booleanNode('interactive_presets')
                    ->defaultFalse()
                    ->info('Let guests choose a color preset on the result screen (Mode 2). Off = base grade is baked at capture time (Mode 1).')
                ->end()
                ->scalarNode('base_preset')
                    ->defaultValue('clean-portrait')
                    ->info('Preset ID baked into every shot at capture time in Mode 1 ("original" = no grading).')
                ->end()
                ->integerNode('base_amount')
                    ->defaultValue(100)
                    ->min(0)
                    ->max(100)
                    ->info('Intensity (0-100) of the base preset in Mode 1.')
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function (string $value): int {
                            return intval($value);
                        })
                    ->end()
                ->end()
            ->end();

        return $node;
    }
}

