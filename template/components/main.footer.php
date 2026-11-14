<?php

use Photobooth\Service\AssetService;
use Photobooth\Utility\ClientSettingsUtility;

$assetService = AssetService::getInstance();

echo '<script>' . ClientSettingsUtility::renderJavaScript() . '</script>';
echo '<script src="' . $assetService->getUrl('resources/js/tools.js') . '"></script>';

if ($remoteBuzzer) {
    echo '<script src="' . $assetService->getUrl('node_modules/socket.io-client/dist/socket.io.min.js') . '"></script>';
    echo '<script src="' . $assetService->getUrl('resources/js/remotebuzzer-client.js') . '"></script>';
}

if ($photoswipe) {
    echo '<script src="' . $assetService->getUrl('node_modules/photoswipe/dist/umd/photoswipe.umd.min.js') . '"></script>';
    echo '<script src="' . $assetService->getUrl('node_modules/photoswipe/dist/umd/photoswipe-lightbox.umd.min.js') . '"></script>';
    echo '<script src="' . $assetService->getUrl('resources/js/photoswipe.js') . '"></script>';
}
