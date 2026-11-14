<?php

use Photobooth\Service\AssetService;
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\ThemeUtility;

$assetService = AssetService::getInstance();

include PathUtility::getAbsolutePath('template/components/main.defaults.php');

?>
<!DOCTYPE html>
<html
    data-ui-theme="<?php echo $config['ui']['style'] ?? 'default'; ?>"
    data-ui-button="<?php echo $config['ui']['button'] ?? 'default'; ?>"
>
<head>

    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="msapplication-TileColor" content="#09051D">
    <meta name="theme-color" content="#09051D">

    <title><?= $pageTitle ?></title>

    <!-- Favicon + Android/iPhone Icons (Trạm Tinh Hà Logo) -->
    <link rel="icon" type="image/png" sizes="64x64" href="<?=$assetService->getUrl('resources/img/event/favicon-64.png')?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?=$assetService->getUrl('resources/img/favicon-32x32.png')?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?=$assetService->getUrl('resources/img/favicon-16x16.png')?>">
    <link rel="shortcut icon" href="<?=$assetService->getUrl('resources/img/favicon.ico')?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?=$assetService->getUrl('resources/img/apple-touch-icon.png')?>">
    <link rel="manifest" href="<?=$assetService->getUrl('resources/img/site.webmanifest')?>">
    <link rel="mask-icon" href="<?=$assetService->getUrl('resources/img/safari-pinned-tab.svg')?>" color="#38BDF8">

    <!-- Fullscreen Mode on old iOS-Devices when starting photobooth from homescreen -->
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />

    <link rel="stylesheet" href="<?=$assetService->getUrl('node_modules/normalize.css/normalize.css')?>" />
    <link rel="stylesheet" href="<?=$assetService->getUrl('node_modules/@fortawesome/fontawesome-free/css/all.min.css')?>" />
    <link rel="stylesheet" href="<?=$assetService->getUrl('node_modules/material-icons/iconfont/material-icons.css')?>">
    <link rel="stylesheet" href="<?=$assetService->getUrl('node_modules/material-icons/css/material-icons.css')?>">
    <?= $photoswipe ? '<link rel="stylesheet" href="' . $assetService->getUrl('node_modules/photoswipe/dist/photoswipe.css') . '" />' : ''?>
    <link rel="stylesheet" href="<?=$assetService->getUrl('resources/css/fonts.css')?>" />
    <link rel="stylesheet" href="<?=$assetService->getUrl('resources/css/framework.css')?>" />
    <?= $photoswipe && $config['gallery']['bottom_bar'] ? '<link rel="stylesheet" href="' . $assetService->getUrl('resources/css/photoswipe-bottom.css') . '"/>' : '' ?>
    <?= ThemeUtility::renderCustomUserStyle($config); ?>
    <?= is_file(PathUtility::getAbsolutePath('private/overrides.css')) ? '<link rel="stylesheet" href="' . $assetService->getUrl('private/overrides.css') . '"/>' : '' ?>
    <script src="<?=$assetService->getUrl('node_modules/jquery/dist/jquery.min.js')?>"></script>
</head>
