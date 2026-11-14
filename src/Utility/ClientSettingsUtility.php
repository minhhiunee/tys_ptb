<?php

namespace Photobooth\Utility;

use Photobooth\Environment;
use Photobooth\Service\ConfigurationService;

class ClientSettingsUtility
{
    public static function prepareClientConfig(array $config): array
    {
        // Override secret configuration we don't need access from javascript for
        $config['mail']['password'] = 'secret';
        $config['login']['username'] = 'secret';
        $config['login']['password'] = 'secret';
        $config['login']['pin'] = 'secret';
        $config['ftp']['username'] = 'secret';
        $config['ftp']['password'] = 'secret';

        $publicPathKeys = [
            ['logo', 'path'],
            ['ui', 'shutter_cheese_img'],
            ['picture', 'frame'],
            ['collage', 'background'],
            ['collage', 'frame'],
            ['background', 'defaults'],
            ['background', 'admin'],
            ['background', 'chroma'],
            ['screensaver', 'video_source'],
            ['screensaver', 'text_font'],
            ['fonts', 'start_screen_title'],
            ['fonts', 'event_text'],
            ['fonts', 'screensaver_text'],
            ['fonts', 'gallery_title'],
        ];

        foreach ($publicPathKeys as [$section, $key]) {
            if (!empty($config[$section][$key])) {
                $config[$section][$key] = PathUtility::getPublicPath($config[$section][$key]);
            }
        }

        if (!empty($config['screensaver']['image_source']) && ($config['screensaver']['mode'] ?? '') !== 'folder') {
            $config['screensaver']['image_source'] = PathUtility::getPublicPath($config['screensaver']['image_source']);
        }
        if (empty($config['screensaver']['text_position'])) {
            $config['screensaver']['text_position'] = 'center';
        }

        return $config;
    }

    public static function renderJavaScript(): string
    {
        $config = self::prepareClientConfig(ConfigurationService::getInstance()->getConfiguration());
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $csrfToken = $_SESSION['csrf'] ?? '';

        return 'const config = ' . json_encode($config, $flags) . ';'
            . 'const environment = ' . json_encode(new Environment(), $flags) . ';'
            . 'const csrf = { key: "csrf", token: ' . json_encode($csrfToken, $flags) . ' };';
    }
}

