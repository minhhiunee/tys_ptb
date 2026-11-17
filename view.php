<?php

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\ApplicationService;
use Photobooth\Service\AssetService;
use Photobooth\Service\LanguageService;
use Photobooth\Utility\PathUtility;

require_once __DIR__ . '/lib/boot.php';

$imageParam = $_GET['s'] ?? $_GET['image'] ?? $_GET['session'] ?? '';
$image = basename((string) $imageParam);
if ($image !== '' && !str_contains($image, '.')) {
    $image .= '.jpg';
}

if (
    $image === '' ||
    !preg_match('/^[A-Za-z0-9._-]+$/', $image) ||
    str_starts_with($image, 'meta_') ||
    str_starts_with($image, 'preview_') ||
    str_starts_with($image, 'orig_')
) {
    http_response_code(400);
    echo 'Invalid or restricted media identifier.';
    exit();
}

$imagesDir = FolderEnum::IMAGES->absolute();
$imagePath = $imagesDir . DIRECTORY_SEPARATOR . $image;
if (!is_file($imagePath)) {
    http_response_code(404);
    echo 'Image not found.';
    exit();
}

// Check link expiry if configured (e.g. $config['qr']['expire_hours'])
$expireHours = (int) ($config['qr']['expire_hours'] ?? 0);
if ($expireHours > 0) {
    $fileAgeSeconds = time() - (int) filemtime($imagePath);
    if ($fileAgeSeconds > ($expireHours * 3600)) {
        http_response_code(410);
        echo 'This download link has expired.';
        exit();
    }
}

$sessionId = pathinfo($image, PATHINFO_FILENAME);
$metaPath = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $image . '.json';
$sessionMeta = [];
if (is_file($metaPath)) {
    $decodedMeta = json_decode((string) file_get_contents($metaPath), true);
    if (is_array($decodedMeta)) {
        $sessionMeta = $decodedMeta;
    }
}

// Locate companion animated GIF if generated for this session
$gifFile = $sessionMeta['gif_photo'] ?? ($sessionId . '.gif');
$gifFile = basename((string) $gifFile);
$gifPath = $imagesDir . DIRECTORY_SEPARATOR . $gifFile;
$hasGif = is_file($gifPath) && strtolower(pathinfo($image, PATHINFO_EXTENSION)) !== 'gif';
$gifInlineUrl = $hasGif ? PathUtility::getPublicPath('api/download.php?image=' . rawurlencode($gifFile) . '&inline=1') : '';
$gifDownloadUrl = $hasGif ? PathUtility::getPublicPath('api/download.php?image=' . rawurlencode($gifFile)) : '';

$extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
$isVideo = in_array($extension, ['mp4', 'mov', 'webm'], true);
$imageUrl = PathUtility::getPublicPath('api/download.php?image=' . rawurlencode($image) . '&inline=1');
$downloadUrl = PathUtility::getPublicPath('api/download.php?image=' . rawurlencode($image));
$languageService = LanguageService::getInstance();
$appTitle = ApplicationService::getInstance()->getTitle();
$displayTitle = 'CHÀO TÂN SINH VIÊN 2026 • TRẠM TINH HÀ';
$pageTitle = 'TRẠM TINH HÀ • ' . ($languageService->translate('viewer_photo_title') ?: 'Lưu Ảnh Kỷ Niệm');

$assetService = AssetService::getInstance();
$favicon64Url = $assetService->getUrl('resources/img/event/favicon-64.png');
$faviconIcoUrl = $assetService->getUrl('resources/img/favicon.ico');
$appleIconUrl = $assetService->getUrl('resources/img/apple-touch-icon.png');
$logoUrl = $assetService->getUrl('resources/img/event/logo-96.png');
$chaoTsvUrl = $assetService->getUrl('resources/img/event/chao-tsv-2026-light-sm.png');
$tramTinhHaUrl = $assetService->getUrl('resources/img/event/tram-tinh-ha-sm.png');
$bgPortraitUrl = $assetService->getUrl('resources/img/event/bg-portrait-1.jpg');
$bgLandscapeUrl = $assetService->getUrl('resources/img/event/bg-landscape-1.jpg');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover" />
    <meta name="theme-color" content="#09051D" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?= htmlspecialchars($favicon64Url, ENT_QUOTES) ?>" />
    <link rel="shortcut icon" href="<?= htmlspecialchars($faviconIcoUrl, ENT_QUOTES) ?>" />
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($appleIconUrl, ENT_QUOTES) ?>" />
    <style>
        :root {
            --bg-deep: #07041A;
            --glass-bg: rgba(13, 8, 40, 0.78);
            --glass-bg-hover: rgba(24, 15, 68, 0.9);
            --glass-border: rgba(167, 139, 250, 0.28);
            --glass-border-strong: rgba(125, 211, 252, 0.45);
            --text-pure: #F8FAFF;
            --text-muted: #B6BCE0;
            --emerald-1: #10B981;
            --emerald-2: #059669;
            --cyan-1: #38BDF8;
            --violet-1: #818CF8;
            --font-main: -apple-system, BlinkMacSystemFont, "Plus Jakarta Sans", "SF Pro Display", Roboto, "Segoe UI", sans-serif;
            --font-mono: ui-monospace, "SF Mono", "JetBrains Mono", Consolas, monospace;
            --top-pad: max(8px, env(safe-area-inset-top));
            --bottom-pad: max(8px, env(safe-area-inset-bottom));
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            height: 100%;
            height: 100dvh;
            width: 100%;
            overflow: hidden;
            background-color: var(--bg-deep);
            color: var(--text-pure);
            font-family: var(--font-main);
            position: fixed;
            top: 0;
            left: 0;
            user-select: none;
            -webkit-user-select: none;
        }

        /* Cosmic Event Background */
        .cosmos-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image: url('<?= htmlspecialchars($bgPortraitUrl, ENT_QUOTES) ?>');
            background-position: center center;
            background-size: cover;
            background-repeat: no-repeat;
            pointer-events: none;
        }

        @media (orientation: landscape) and (min-width: 768px) {
            .cosmos-bg {
                background-image: url('<?= htmlspecialchars($bgLandscapeUrl, ENT_QUOTES) ?>');
            }
        }

        .cosmos-vignette {
            position: fixed;
            inset: 0;
            z-index: 1;
            background:
                radial-gradient(circle at 50% 48%, rgba(8, 4, 26, 0.34) 0%, rgba(6, 3, 20, 0.78) 100%),
                linear-gradient(180deg, rgba(10, 5, 34, 0.52) 0%, rgba(8, 4, 26, 0.14) 45%, rgba(6, 3, 20, 0.72) 100%);
            pointer-events: none;
        }

        /* Viewport Shell: Maximizes Hero Stage Space */
        .viewer-shell {
            position: relative;
            z-index: 2;
            height: 100%;
            height: 100dvh;
            width: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: var(--top-pad) 10px var(--bottom-pad);
            gap: 6px;
        }

        /* 1. Compact Cosmic Header (Logo + Chào Tân Sinh Viên 2026 + TRẠM TINH HÀ underneath) */
        .viewer-top-bar {
            flex-shrink: 0;
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 5px 10px 5px 7px;
            min-height: 46px;
            background: var(--glass-bg);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(3, 1, 16, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.12);
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .brand-cluster {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
            flex: 1;
        }

        .brand-logo-badge {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #FFFFFF;
            padding: 1.5px;
            flex-shrink: 0;
            box-shadow: 0 0 12px rgba(125, 211, 252, 0.45);
            object-fit: contain;
        }

        .brand-titles {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            gap: 2px;
            min-width: 0;
            flex: 1;
        }

        .brand-chao-img {
            display: block;
            height: 10px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            filter: drop-shadow(0 0 6px rgba(196, 181, 253, 0.65));
        }

        .brand-title-img {
            display: block;
            height: 17px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.55));
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .session-badge {
            font-family: var(--font-mono);
            font-size: 10px;
            font-variant-numeric: tabular-nums;
            color: #CBD5E1;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(125, 211, 252, 0.28);
            padding: 3px 7px;
            border-radius: 7px;
            max-width: 112px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .icon-btn {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            border: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-pure);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.12s ease;
        }

        .icon-btn:hover {
            background: rgba(167, 139, 250, 0.2);
        }

        .icon-btn:active {
            transform: scale(0.92);
        }

        /* 2. Full-Screen Photo & GIF Hero Stage */
        .photo-stage {
            position: relative;
            flex: 1 1 0%;
            min-height: 0;
            min-width: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 2px;
            cursor: zoom-in;
            background: transparent !important;
            background-color: transparent !important;
        }

        .image-wrapper {
            position: relative;
            max-width: 100%;
            max-height: 100%;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            transform-origin: center center;
            transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
            background: transparent !important;
            background-color: transparent !important;
        }

        .image-wrapper.zoomed {
            transform: scale(2.0);
            cursor: zoom-out;
        }

        /* Stretch both static photo AND animated GIF prominently with no surrounding white box */
        .media-display {
            display: none;
            width: auto !important;
            height: auto !important;
            max-width: min(100%, 96vw) !important;
            max-height: min(100%, 88vh, calc(100dvh - 105px)) !important;
            object-fit: contain;
            border-radius: 8px;
            filter: drop-shadow(0 16px 36px rgba(2, 1, 14, 0.92));
            transition: opacity 0.2s ease, filter 0.2s ease;
            pointer-events: auto;
            background: transparent !important;
            background-color: transparent !important;
        }

        .media-display.active {
            display: block;
        }

        video.media-display {
            background: #000;
        }

        /* 3. Cosmic Bottom Control Island */
        .viewer-dock {
            flex-shrink: 0;
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            z-index: 20;
            display: flex;
            flex-direction: column;
            gap: 5px;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .media-switcher {
            display: flex;
            align-self: center;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 3px;
            border-radius: 999px;
            gap: 4px;
            box-shadow: 0 8px 20px rgba(3, 1, 16, 0.5);
        }

        .switch-pill {
            display: flex;
            align-items: center;
            gap: 5px;
            min-height: 29px;
            padding: 0 13px;
            border-radius: 999px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-muted);
            font-family: var(--font-main);
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.16s ease, color 0.16s ease, transform 0.12s ease;
        }

        .switch-pill:hover {
            color: var(--text-pure);
        }

        .switch-pill:active {
            transform: scale(0.96);
        }

        .switch-pill.active {
            background: linear-gradient(135deg, var(--cyan-1), var(--violet-1));
            color: #07041A;
            box-shadow: 0 3px 14px rgba(56, 189, 248, 0.4);
        }

        .action-row {
            display: flex;
            align-items: center;
            gap: 6px;
            width: 100%;
        }

        .btn-primary {
            flex: 1;
            min-height: 42px;
            padding: 8px 14px;
            border-radius: 12px;
            border: 1px solid rgba(110, 231, 183, 0.35);
            background: linear-gradient(135deg, var(--emerald-1), var(--emerald-2));
            color: #FFFFFF;
            font-family: var(--font-main);
            font-size: 13.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
            transition: transform 0.12s ease, filter 0.15s ease;
        }

        .btn-primary:active {
            transform: scale(0.97);
            filter: brightness(0.92);
        }

        .btn-secondary {
            min-height: 42px;
            padding: 8px 12px;
            border-radius: 12px;
            border: 1px solid rgba(125, 211, 252, 0.42);
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            color: var(--cyan-1);
            font-family: var(--font-main);
            font-size: 12.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: 0 4px 14px rgba(3, 1, 16, 0.45);
            transition: transform 0.12s ease, background-color 0.15s ease;
        }

        .btn-secondary:hover {
            background: rgba(56, 189, 248, 0.16);
        }

        .btn-secondary:active {
            transform: scale(0.96);
        }

        .btn-icon-share {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            border-radius: 12px;
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            color: var(--text-pure);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.12s ease;
            box-shadow: 0 4px 14px rgba(3, 1, 16, 0.45);
        }

        .btn-icon-share:hover {
            background: var(--glass-bg-hover);
        }

        .btn-icon-share:active {
            transform: scale(0.92);
        }

        /* 4. Micro Guidance at the very bottom */
        .bottom-guidance {
            text-align: center;
            padding: 1px 4px 0;
            line-height: 1.3;
        }

        .guide-text {
            font-size: 10px;
            color: #CBD5E1;
            font-weight: 500;
        }

        .meta-sub {
            font-size: 9.5px;
            color: #94A3B8;
            margin-top: 1px;
        }

        /* Zen / Clean Immersion Mode: Tapping Photo Hides Chrome */
        body.zen-mode .viewer-top-bar {
            opacity: 0;
            max-height: 0;
            margin-top: -60px;
            padding: 0;
            pointer-events: none;
            overflow: hidden;
        }

        body.zen-mode .viewer-dock {
            opacity: 0;
            max-height: 0;
            margin-bottom: -160px;
            padding: 0;
            pointer-events: none;
            overflow: hidden;
        }

        /* Toast feedback */
        .toast {
            position: fixed;
            top: calc(var(--top-pad) + 56px);
            left: 50%;
            transform: translateX(-50%) translateY(-10px);
            background: rgba(16, 185, 129, 0.95);
            color: #FFFFFF;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 13.5px;
            font-weight: 700;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
            z-index: 100;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .toast.visible {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        @media (max-width: 390px) {
            .session-badge {
                display: none;
            }
        }

        @media (min-width: 768px) {
            .viewer-top-bar,
            .viewer-dock {
                max-width: 540px;
            }
            .brand-logo-badge {
                width: 38px;
                height: 38px;
            }
            .brand-chao-img {
                height: 11px;
            }
            .brand-title-img {
                height: 19px;
            }
        }

        @media (max-height: 540px) and (orientation: landscape) {
            .viewer-top-bar {
                min-height: 38px;
                padding: 4px 10px;
            }
            .brand-logo-badge {
                width: 28px;
                height: 28px;
            }
            .brand-chao-img {
                height: 8.5px;
            }
            .brand-title-img {
                height: 14px;
            }
            .btn-primary, .btn-secondary {
                min-height: 36px;
                font-size: 12px;
            }
            .btn-icon-share {
                width: 36px;
                height: 36px;
            }
            .bottom-guidance {
                display: none;
            }
        }
    </style>
</head>
<body class="<?= $hasGif ? '' : 'no-gif' ?>">
    <!-- Cosmic Starlight Backdrop -->
    <div class="cosmos-bg" aria-hidden="true"></div>
    <div class="cosmos-vignette" aria-hidden="true"></div>

    <!-- Viewport Container -->
    <main class="viewer-shell" id="viewer-shell">

        <!-- 1. Compact Header: Logo + Chào Tân Sinh Viên 2026 + TRẠM TINH HÀ underneath -->
        <header class="viewer-top-bar" id="viewer-top-bar">
            <div class="brand-cluster">
                <img
                    src="<?= htmlspecialchars($logoUrl, ENT_QUOTES) ?>"
                    alt="Logo Hội Sinh Viên Đất Cầu Vồng"
                    class="brand-logo-badge"
                />
                <div class="brand-titles">
                    <img
                        src="<?= htmlspecialchars($chaoTsvUrl, ENT_QUOTES) ?>"
                        alt="CHÀO TÂN SINH VIÊN 2026"
                        class="brand-chao-img"
                    />
                    <img
                        src="<?= htmlspecialchars($tramTinhHaUrl, ENT_QUOTES) ?>"
                        alt="TRẠM TINH HÀ"
                        class="brand-title-img"
                    />
                </div>
            </div>
            <div class="header-actions">
                <span class="session-badge" title="Mã phiên chụp">#<?= htmlspecialchars($sessionId) ?></span>
                <button type="button" class="icon-btn" id="btn-fullscreen" title="Toàn màn hình" aria-label="Toàn màn hình">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- 2. Hero Photo & GIF Stage (Maximized Identically) -->
        <section class="photo-stage" id="photo-stage" aria-label="Xem ảnh">
            <div class="image-wrapper" id="image-wrapper">
                <?php if ($isVideo): ?>
                    <video id="viewer-video" class="media-display active" src="<?= htmlspecialchars($imageUrl) ?>" controls playsinline controlsList="nodownload"></video>
                <?php else: ?>
                    <img id="viewer-image" class="media-display active" src="<?= htmlspecialchars($imageUrl) ?>" alt="Ảnh Photobooth" draggable="false" />
                    <?php if ($hasGif): ?>
                        <img id="viewer-gif" class="media-display" src="<?= htmlspecialchars($gifInlineUrl) ?>" alt="Ảnh động GIF" draggable="false" />
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- 3. Floating Bottom Control Island -->
        <footer class="viewer-dock" id="viewer-dock">
            <?php if ($hasGif): ?>
                <div class="media-switcher" role="tablist">
                    <button type="button" id="tab-photo" class="switch-pill active" onclick="switchMedia('photo')" role="tab" aria-selected="true">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                        </svg>
                        <span>Ảnh Khung (HD)</span>
                    </button>
                    <button type="button" id="tab-gif" class="switch-pill" onclick="switchMedia('gif')" role="tab" aria-selected="false">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <polygon points="5 3 19 12 5 21 5 3"/>
                        </svg>
                        <span>Ảnh Động GIF</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="action-row">
                <button type="button" class="btn-primary" id="btn-save-primary" onclick="handlePrimarySave()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <span id="btn-dl-label">Lưu Ảnh Về Máy</span>
                </button>

                <?php if ($hasGif): ?>
                    <a class="btn-secondary" id="btn-dl-gif" href="<?= htmlspecialchars($gifDownloadUrl) ?>" download="<?= htmlspecialchars($gifFile) ?>" title="Tải file GIF động">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <span>Tải GIF</span>
                    </a>
                <?php endif; ?>

                <button type="button" class="btn-icon-share" id="btn-share" onclick="handleNativeShare()" title="Chia sẻ ảnh" aria-label="Chia sẻ">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                    </svg>
                </button>
            </div>

            <div class="bottom-guidance">
                <div class="guide-text">💡 Giữ ảnh để Lưu vào Album • Chạm 2 lần để phóng to • Tự động lưu 7 ngày</div>
                <div class="meta-sub">Phiên: <?= htmlspecialchars($sessionId) ?><?= !empty($sessionMeta['preset']) ? ' • Preset: ' . htmlspecialchars($sessionMeta['preset']) : '' ?></div>
            </div>
        </footer>

        <a id="hidden-dl-photo" href="<?= htmlspecialchars($downloadUrl) ?>" download="<?= htmlspecialchars($image) ?>" style="display:none;"></a>
        <?php if ($hasGif): ?>
            <a id="hidden-dl-gif" href="<?= htmlspecialchars($gifDownloadUrl) ?>" download="<?= htmlspecialchars($gifFile) ?>" style="display:none;"></a>
        <?php endif; ?>
    </main>

    <div class="toast" id="toast">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="toast-text">Đang tải xuống...</span>
    </div>

    <script>
        let currentMode = 'photo';
        const photoUrl = <?= json_encode($imageUrl) ?>;
        const photoDlUrl = <?= json_encode($downloadUrl) ?>;
        const photoName = <?= json_encode($image) ?>;
        const hasGif = <?= $hasGif ? 'true' : 'false' ?>;
        const gifUrl = <?= json_encode($gifInlineUrl) ?>;
        const gifDlUrl = <?= json_encode($gifDownloadUrl) ?>;
        const gifName = <?= json_encode($gifFile) ?>;

        function showToast(msg) {
            const toast = document.getElementById('toast');
            const toastText = document.getElementById('toast-text');
            if (!toast || !toastText) return;
            toastText.textContent = msg;
            toast.classList.add('visible');
            setTimeout(() => {
                toast.classList.remove('visible');
            }, 2600);
        }

        function switchMedia(mode) {
            if (!hasGif) return;
            currentMode = mode;
            const imgPhoto = document.getElementById('viewer-image');
            const imgGif = document.getElementById('viewer-gif');
            const tabPhoto = document.getElementById('tab-photo');
            const tabGif = document.getElementById('tab-gif');
            const btnLabel = document.getElementById('btn-dl-label');
            const imgWrapper = document.getElementById('image-wrapper');

            if (imgWrapper) imgWrapper.classList.remove('zoomed');

            if (mode === 'gif') {
                if (imgPhoto) imgPhoto.classList.remove('active');
                if (imgGif) imgGif.classList.add('active');
                if (tabPhoto) { tabPhoto.classList.remove('active'); tabPhoto.setAttribute('aria-selected', 'false'); }
                if (tabGif) { tabGif.classList.add('active'); tabGif.setAttribute('aria-selected', 'true'); }
                if (btnLabel) btnLabel.textContent = 'Lưu Ảnh Động (GIF)';
            } else {
                if (imgGif) imgGif.classList.remove('active');
                if (imgPhoto) imgPhoto.classList.add('active');
                if (tabGif) { tabGif.classList.remove('active'); tabGif.setAttribute('aria-selected', 'false'); }
                if (tabPhoto) { tabPhoto.classList.add('active'); tabPhoto.setAttribute('aria-selected', 'true'); }
                if (btnLabel) btnLabel.textContent = 'Lưu Ảnh Về Máy';
            }
        }

        (function initInteraction() {
            const stage = document.getElementById('photo-stage');
            const wrapper = document.getElementById('image-wrapper');
            let lastTap = 0;
            let tapTimer = null;

            // Open straight to GIF tab if ?tab=gif is supplied
            try {
                const urlParams = new URLSearchParams(window.location.search);
                if (hasGif && urlParams.get('tab') === 'gif') {
                    switchMedia('gif');
                }
            } catch (_) {}

            if (stage && wrapper) {
                stage.addEventListener('click', function (e) {
                    if (e.target.tagName === 'VIDEO') return;

                    const now = Date.now();
                    const diff = now - lastTap;
                    lastTap = now;

                    if (diff < 300) {
                        clearTimeout(tapTimer);
                        wrapper.classList.toggle('zoomed');
                    } else {
                        tapTimer = setTimeout(function () {
                            if (!wrapper.classList.contains('zoomed')) {
                                document.body.classList.toggle('zen-mode');
                            }
                        }, 250);
                    }
                });
            }

            if (hasGif && stage) {
                let startX = 0;
                stage.addEventListener('touchstart', function (e) {
                    if (e.touches && e.touches.length === 1) {
                        startX = e.touches[0].clientX;
                    }
                }, { passive: true });

                stage.addEventListener('touchend', function (e) {
                    if (e.changedTouches && e.changedTouches.length === 1) {
                        const dx = e.changedTouches[0].clientX - startX;
                        if (Math.abs(dx) > 60) {
                            switchMedia(dx < 0 ? 'gif' : 'photo');
                        }
                    }
                }, { passive: true });
            }

            const fsBtn = document.getElementById('btn-fullscreen');
            if (fsBtn) {
                fsBtn.addEventListener('click', function () {
                    if (!document.fullscreenElement) {
                        document.documentElement.requestFullscreen().catch(() => {
                            document.body.classList.toggle('zen-mode');
                        });
                    } else {
                        document.exitFullscreen().catch(() => {});
                    }
                });
            }

            document.addEventListener('keydown', function (e) {
                if (e.key === ' ' || e.key === 'f') {
                    document.body.classList.toggle('zen-mode');
                } else if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                    if (hasGif) switchMedia(currentMode === 'photo' ? 'gif' : 'photo');
                } else if (e.key === 'd') {
                    handlePrimarySave();
                }
            });
        })();

        async function handlePrimarySave() {
            const isGif = currentMode === 'gif' && hasGif;
            const targetUrl = isGif ? gifUrl : photoUrl;
            const targetDl = isGif ? gifDlUrl : photoDlUrl;
            const targetName = isGif ? gifName : photoName;
            const mime = isGif ? 'image/gif' : 'image/jpeg';

            if (navigator.share && navigator.canShare && window.File && window.fetch) {
                try {
                    showToast('Đang chuẩn bị file...');
                    const res = await fetch(targetUrl);
                    const blob = await res.blob();
                    const file = new File([blob], targetName, { type: mime });
                    if (navigator.canShare({ files: [file] })) {
                        await navigator.share({
                            files: [file],
                            title: 'TRẠM TINH HÀ • Chào Tân Sinh Viên 2026',
                        });
                        showToast('Đã mở chia sẻ thành công!');
                        return;
                    }
                } catch (err) {
                    if (err.name === 'AbortError') return;
                }
            }

            showToast('Đang tải ảnh về máy...');
            const a = isGif ? document.getElementById('hidden-dl-gif') : document.getElementById('hidden-dl-photo');
            if (a) {
                a.click();
            } else {
                window.location.href = targetDl;
            }
        }

        async function handleNativeShare() {
            const isGif = currentMode === 'gif' && hasGif;
            const targetUrl = isGif ? gifUrl : photoUrl;
            const targetName = isGif ? gifName : photoName;
            const mime = isGif ? 'image/gif' : 'image/jpeg';

            if (navigator.share) {
                try {
                    if (window.fetch && window.File && navigator.canShare) {
                        const res = await fetch(targetUrl);
                        const blob = await res.blob();
                        const file = new File([blob], targetName, { type: mime });
                        if (navigator.canShare({ files: [file] })) {
                            await navigator.share({
                                files: [file],
                                title: <?= json_encode($displayTitle) ?>,
                                text: 'Ảnh kỷ niệm TRẠM TINH HÀ - Chào Tân Sinh Viên 2026!'
                            });
                            return;
                        }
                    }
                    await navigator.share({
                        title: <?= json_encode($displayTitle) ?>,
                        text: 'Ảnh kỷ niệm TRẠM TINH HÀ - Chào Tân Sinh Viên 2026!',
                        url: window.location.href
                    });
                } catch (e) {
                    if (e.name !== 'AbortError') {
                        copyLinkFallback();
                    }
                }
            } else {
                copyLinkFallback();
            }
        }

        function copyLinkFallback() {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(window.location.href).then(() => {
                    showToast('Đã sao chép liên kết ảnh!');
                }).catch(() => {
                    handlePrimarySave();
                });
            } else {
                handlePrimarySave();
            }
        }
    </script>
</body>
</html>
