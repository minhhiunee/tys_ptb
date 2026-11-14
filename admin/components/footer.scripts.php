<?php

use Photobooth\Service\AssetService;
use Photobooth\Utility\ClientSettingsUtility;

$assetService = AssetService::getInstance();

echo '<script>' . ClientSettingsUtility::renderJavaScript() . '</script>';
echo '<script src="' . $assetService->getUrl('resources/js/main.admin.js') . '"></script>';
