<?php

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\ApplicationService;
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
$mime = match ($extension) {
    'png' => 'image/png',
    'gif' => 'image/gif',
    default => 'image/jpeg',
};
$imageUrl = PathUtility::getPublicPath('api/download.php?image=' . rawurlencode($image) . '&inline=1');
$downloadUrl = PathUtility::getPublicPath('api/download.php?image=' . rawurlencode($image));
$languageService = LanguageService::getInstance();
$appTitle = ApplicationService::getInstance()->getTitle();
$startScreenTitle = trim((string) ($config['start_screen']['title'] ?? ''));
$viewerFallbackTitle = $startScreenTitle !== '' ? $startScreenTitle : 'TYS Photobooth';
$pageTitle = $appTitle . ' - ' . ($languageService->translate('viewer_photo_title') ?: 'Tải Ảnh Của Bạn');
$downloadLabel = $languageService->translate('download') ?: 'Tải Ảnh Về Điện Thoại';
$eventTitleLines = $config['event']['enabled']
    ? array_values(array_filter([
        $config['event']['textLeft'] ?? '',
        $config['event']['textRight'] ?? '',
    ], static fn ($line) => $line !== ''))
    : [$viewerFallbackTitle];

if ($eventTitleLines === []) {
    $eventTitleLines = [$viewerFallbackTitle];
}

// Public event hosts may expose this viewer and api/download.php, but still 404
// the usual Photobooth assets under /resources, /node_modules, /private and
// api/settings.php. Keep this page self-contained unless those asset paths are
// available again. As a consequence, shared JS/CSS, Font Awesome icons and the
// actual configured custom font files are not used here.
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($config['ui']['language'] ?? 'vi') ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#0D1015">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" href='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="16" fill="%230D1015"/><circle cx="32" cy="32" r="14" fill="%23F5F2EB"/><circle cx="32" cy="32" r="6" fill="%23E59A3C"/></svg>'>
    <style>
        :root {
            --bg: #0D1015;
            --surface: #151A23;
            --elevated: #1E2532;
            --paper: #F5F2EB;
            --border: rgba(243, 241, 236, 0.14);
            --border-strong: rgba(243, 241, 236, 0.26);
            --text: #F3F1EC;
            --muted: #9BA3B2;
            --amber: #E59A3C;
            --amber-soft: rgba(229, 154, 60, 0.16);
            --emerald: #1F9D6E;
            --font-sans: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-mono: ui-monospace, "JetBrains Mono", "SF Mono", Consolas, monospace;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            min-height: 100dvh;
            font-family: var(--font-sans);
            color: var(--text);
            background-color: var(--bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 18px 16px 40px;
        }

        .viewer {
            width: 100%;
            max-width: 520px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .viewer__header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            padding: 4px 2px 8px;
            border-bottom: 1px solid var(--border);
        }

        .viewer__title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text);
        }

        .viewer__subtitle {
            font-size: 13px;
            color: var(--muted);
            text-align: right;
        }

        .viewer__tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: var(--surface);
            border: 1px solid var(--border-strong);
            padding: 5px;
            border-radius: 14px;
            gap: 6px;
        }

        .viewer__tab-btn {
            min-height: 46px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            padding: 10px 14px;
            border-radius: 10px;
            font-family: var(--font-sans);
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            touch-action: manipulation;
            user-select: none;
            transition: background-color 0.16s ease, color 0.16s ease, transform 0.12s ease;
        }

        .viewer__tab-btn:hover {
            color: var(--text);
            background: rgba(243, 241, 236, 0.05);
        }

        .viewer__tab-btn:active {
            transform: scale(0.98);
        }

        .viewer__tab-btn.active {
            background: var(--amber);
            color: #0D1015;
            border-color: #F4B35E;
            box-shadow: 0 4px 14px rgba(229, 154, 60, 0.3);
        }

        .viewer__stage {
            background: var(--surface);
            border: 1px solid var(--border-strong);
            border-radius: 18px;
            padding: 14px;
            box-shadow: 0 24px 54px rgba(0, 0, 0, 0.65);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .viewer__print-mount {
            width: 100%;
            background: var(--paper);
            padding: 8px 8px 10px;
            border-radius: 10px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: zoom-in;
            transition: padding 0.2s ease;
        }

        .viewer__print-mount.zoomed {
            padding: 2px;
            cursor: zoom-out;
        }

        .viewer__print-mount img,
        .viewer__print-mount video {
            display: block;
            width: 100%;
            max-height: 66dvh;
            object-fit: contain;
            border-radius: 6px;
            background: #0D1015;
            transition: max-height 0.2s ease;
        }

        .viewer__print-mount.zoomed img {
            max-height: 82dvh;
        }

        .viewer__meta {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
            padding: 2px 4px 0;
            font-family: var(--font-mono);
            font-variant-numeric: tabular-nums;
            font-size: 12px;
            color: var(--muted);
        }

        .viewer__preset-tag {
            background: var(--amber-soft);
            border: 1px solid rgba(229, 154, 60, 0.4);
            padding: 3px 10px;
            border-radius: 6px;
            color: var(--amber);
            font-weight: 600;
        }

        .viewer__actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .viewer__download {
            width: 100%;
            min-height: 52px;
            padding: 13px 18px;
            border-radius: 14px;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            touch-action: manipulation;
            user-select: none;
            transition: background-color 0.15s ease, transform 0.12s ease;
        }

        .viewer__download:active {
            transform: scale(0.98);
        }

        .viewer__download--primary {
            background: var(--emerald);
            border: 1px solid #28B882;
            color: #FFFFFF;
            box-shadow: 0 8px 22px rgba(31, 157, 110, 0.32);
        }

        .viewer__download--primary:hover {
            background: #24B27D;
        }

        .viewer__download--secondary {
            background: var(--elevated);
            border: 1px solid rgba(229, 154, 60, 0.5);
            color: var(--amber);
        }

        .viewer__download--secondary:hover {
            background: var(--amber);
            color: #0D1015;
        }

        .viewer__tip {
            text-align: center;
            font-size: 12.5px;
            color: var(--muted);
            line-height: 1.45;
            padding: 6px 10px;
            background: rgba(243, 241, 236, 0.04);
            border-radius: 10px;
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <main class="viewer">
        <header class="viewer__header">
            <h1 class="viewer__title"><?= htmlspecialchars(implode(' • ', $eventTitleLines)) ?></h1>
            <p class="viewer__subtitle">Lưu ảnh kỷ niệm</p>
        </header>

        <?php if ($hasGif): ?>
            <div class="viewer__tabs" role="tablist">
                <button type="button" id="tab-photo" class="viewer__tab-btn active" onclick="switchMedia('photo')" role="tab" aria-selected="true">
                    Ảnh ghép khung
                </button>
                <button type="button" id="tab-gif" class="viewer__tab-btn" onclick="switchMedia('gif')" role="tab" aria-selected="false">
                    Ảnh động GIF (x3)
                </button>
            </div>
        <?php endif; ?>

        <div class="viewer__stage" id="viewer-touch-surface" aria-label="Captured media preview">
            <div class="viewer__print-mount" id="viewer-mount" title="Chạm hoặc click để phóng to/thu nhỏ">
                <?php if ($isVideo): ?>
                    <video src="<?= htmlspecialchars($imageUrl) ?>" controls playsinline controlsList="nodownload">
                        <?= htmlspecialchars($languageService->translate('viewer_video_fallback')) ?>
                    </video>
                <?php else: ?>
                    <img id="viewer-image" src="<?= htmlspecialchars($imageUrl) ?>" alt="Captured photo">
                    <?php if ($hasGif): ?>
                        <img id="viewer-gif" src="<?= htmlspecialchars($gifInlineUrl) ?>" alt="Animated GIF" style="display:none;">
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="viewer__meta">
                <span>#<?= htmlspecialchars($sessionId) ?></span>
                <span class="viewer__preset-tag">Màu: <?= htmlspecialchars((string) ($sessionMeta['preset'] ?? 'Original')) ?></span>
            </div>
        </div>

        <div class="viewer__actions">
            <a class="viewer__download viewer__download--primary" href="<?= htmlspecialchars($downloadUrl) ?>" download="<?= htmlspecialchars($image) ?>">
                <span><?= htmlspecialchars($downloadLabel) ?> (JPG)</span>
            </a>
            <?php if ($hasGif): ?>
                <a class="viewer__download viewer__download--secondary" href="<?= htmlspecialchars($gifDownloadUrl) ?>" download="<?= htmlspecialchars($gifFile) ?>">
                    <span>Tải ảnh động tua nhanh (GIF)</span>
                </a>
            <?php endif; ?>
            <div class="viewer__tip">
                Mẹo nhỏ: Nhấn giữ trực tiếp vào bức ảnh để chọn <b>"Lưu vào Ảnh"</b> trên iPhone & Android, hoặc chạm nhẹ vào ảnh để phóng lớn.
            </div>
        </div>
    </main>
    <script>
        (function () {
            const mount = document.getElementById('viewer-mount');
            if (mount) {
                mount.addEventListener('click', function () {
                    mount.classList.toggle('zoomed');
                });
            }
        })();
        <?php if ($hasGif): ?>
        function switchMedia(mode) {
            const imgPhoto = document.getElementById('viewer-image');
            const imgGif = document.getElementById('viewer-gif');
            const tabPhoto = document.getElementById('tab-photo');
            const tabGif = document.getElementById('tab-gif');
            if (!imgPhoto || !imgGif) {
                return;
            }
            if (mode === 'gif') {
                imgPhoto.style.display = 'none';
                imgGif.style.display = 'block';
                tabGif.classList.add('active');
                tabGif.setAttribute('aria-selected', 'true');
                tabPhoto.classList.remove('active');
                tabPhoto.setAttribute('aria-selected', 'false');
            } else {
                imgPhoto.style.display = 'block';
                imgGif.style.display = 'none';
                tabPhoto.classList.add('active');
                tabPhoto.setAttribute('aria-selected', 'true');
                tabGif.classList.remove('active');
                tabGif.setAttribute('aria-selected', 'false');
            }
        }
        (function () {
            const surface = document.getElementById('viewer-touch-surface');
            let startX = 0;
            if (!surface) {
                return;
            }
            surface.addEventListener('touchstart', function (e) {
                if (e.touches && e.touches.length === 1) {
                    startX = e.touches[0].clientX;
                }
            }, { passive: true });
            surface.addEventListener('touchend', function (e) {
                if (e.changedTouches && e.changedTouches.length === 1) {
                    const dx = e.changedTouches[0].clientX - startX;
                    if (Math.abs(dx) > 55) {
                        switchMedia(dx < 0 ? 'gif' : 'photo');
                    }
                }
            }, { passive: true });
        })();
        <?php endif; ?>
    </script>
</body>
</html>
