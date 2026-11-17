<?php

declare(strict_types=1);

$cloudConfig = require __DIR__ . '/config.php';

$imageParam = $_GET['image'] ?? $_GET['session'] ?? '';
$image = basename(trim((string) $imageParam));
if ($image !== '' && !str_contains($image, '.')) {
    $image .= '.jpg';
}

if (
    $image === '' ||
    !preg_match('/^[A-Za-z0-9._-]+$/', $image) ||
    str_starts_with($image, 'meta_')
) {
    http_response_code(400);
    echo 'Đường dẫn ảnh không hợp lệ.';
    exit();
}

$storageDir = (string) $cloudConfig['storage_dir'];
$imagePath = $storageDir . DIRECTORY_SEPARATOR . $image;
$fileReady = is_file($imagePath);

$sessionId = pathinfo($image, PATHINFO_FILENAME);
$gifFile = $sessionId . '.gif';
$gifPath = $storageDir . DIRECTORY_SEPARATOR . $gifFile;
$hasGif = $fileReady && is_file($gifPath) && strtolower(pathinfo($image, PATHINFO_EXTENSION)) !== 'gif';

$imageUrl = 'api/download.php?image=' . rawurlencode($image) . '&inline=1';
$downloadUrl = 'api/download.php?image=' . rawurlencode($image);
$gifInlineUrl = $hasGif ? 'api/download.php?image=' . rawurlencode($gifFile) . '&inline=1' : '';
$gifDownloadUrl = $hasGif ? 'api/download.php?image=' . rawurlencode($gifFile) : '';

$title = (string) ($cloudConfig['event_title'] ?? 'TRẠM TINH HÀ');
$expireDays = (int) ($cloudConfig['expire_days'] ?? 14);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#09051D">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>TRẠM TINH HÀ • Lưu Ảnh Kỷ Niệm</title>
    <link rel="icon" type="image/png" sizes="64x64" href="assets/favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
    <?php if (!$fileReady): ?>
        <meta http-equiv="refresh" content="2">
    <?php endif; ?>
    <style>
        :root {
            --bg-deep: #07041A;
            --glass-bg: rgba(13, 8, 40, 0.78);
            --glass-border: rgba(167, 139, 250, 0.28);
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

        .cosmos-bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image: url('assets/bg-portrait.jpg');
            background-position: center center;
            background-size: cover;
            background-repeat: no-repeat;
            pointer-events: none;
        }

        @media (orientation: landscape) and (min-width: 768px) {
            .cosmos-bg {
                background-image: url('assets/bg-landscape.jpg');
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
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.16s ease, color 0.16s ease;
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
            font-size: 13.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
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
            font-size: 12.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }

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

        body.zen-mode .viewer-top-bar,
        body.zen-mode .viewer-dock {
            opacity: 0;
            pointer-events: none;
            transform: translateY(10px);
        }

        .sync-wait-card {
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            border: 1px solid var(--glass-border);
            padding: 24px 20px;
            border-radius: 18px;
            text-align: center;
            max-width: 320px;
        }

        @media (max-width: 390px) {
            .session-badge {
                display: none;
            }
        }
        @media (min-width: 768px) {
            .viewer-top-bar, .viewer-dock { max-width: 540px; }
        }
    </style>
</head>
<body>
    <div class="cosmos-bg" aria-hidden="true"></div>
    <div class="cosmos-vignette" aria-hidden="true"></div>

    <main class="viewer-shell">
        <header class="viewer-top-bar">
            <div class="brand-cluster">
                <img src="assets/logo.png" alt="Logo Hội Sinh Viên Đất Cầu Vồng" class="brand-logo-badge">
                <div class="brand-titles">
                    <img src="assets/chao-tsv.png" alt="CHÀO TÂN SINH VIÊN 2026" class="brand-chao-img">
                    <img src="assets/tram-tinh-ha.png" alt="TRẠM TINH HÀ" class="brand-title-img">
                </div>
            </div>
            <div class="header-actions">
                <span class="session-badge">#<?= htmlspecialchars($sessionId) ?></span>
            </div>
        </header>

        <section class="photo-stage" id="photo-stage">
            <?php if (!$fileReady): ?>
                <div class="sync-wait-card">
                    <div style="font-size: 38px; margin-bottom: 12px;">☁️⏳</div>
                    <strong style="font-size: 16px; display: block; margin-bottom: 6px;">Đang đồng bộ ảnh lên Cloud...</strong>
                    <span style="font-size: 13px; color: var(--text-muted);">Trang sẽ tự động cập nhật sau 2 giây. Vui lòng giữ màn hình!</span>
                </div>
            <?php else: ?>
                <div class="image-wrapper" id="image-wrapper">
                    <img id="img-photo" class="media-display active" src="<?= htmlspecialchars($imageUrl) ?>" alt="Ảnh Photobooth">
                    <?php if ($hasGif): ?>
                        <img id="img-gif" class="media-display" src="<?= htmlspecialchars($gifInlineUrl) ?>" alt="Ảnh động GIF">
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($fileReady): ?>
            <footer class="viewer-dock">
                <?php if ($hasGif): ?>
                    <div class="media-switcher">
                        <button type="button" id="tab-photo" class="switch-pill active" onclick="switchMedia('photo')">Ảnh Khung (HD)</button>
                        <button type="button" id="tab-gif" class="switch-pill" onclick="switchMedia('gif')">Ảnh Động GIF</button>
                    </div>
                <?php endif; ?>

                <div class="action-row">
                    <a class="btn-primary" href="<?= htmlspecialchars($downloadUrl) ?>" download="<?= htmlspecialchars($image) ?>">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <span>Lưu Ảnh Về Máy</span>
                    </a>
                    <?php if ($hasGif): ?>
                        <a class="btn-secondary" href="<?= htmlspecialchars($gifDownloadUrl) ?>" download="<?= htmlspecialchars($gifFile) ?>">Tải GIF</a>
                    <?php endif; ?>
                </div>

                <div class="bottom-guidance">
                    <div class="guide-text">💡 Giữ ảnh để Lưu vào Album • Chạm 2 lần để phóng to • Tự động lưu <?= $expireDays ?> ngày</div>
                    <div class="meta-sub">Phiên: <?= htmlspecialchars($sessionId) ?></div>
                </div>
            </footer>
        <?php endif; ?>
    </main>

    <script>
        function switchMedia(mode) {
            const p = document.getElementById('img-photo');
            const g = document.getElementById('img-gif');
            const tp = document.getElementById('tab-photo');
            const tg = document.getElementById('tab-gif');
            if (mode === 'gif') {
                if (p) p.classList.remove('active');
                if (g) g.classList.add('active');
                if (tp) tp.classList.remove('active');
                if (tg) tg.classList.add('active');
            } else {
                if (g) g.classList.remove('active');
                if (p) p.classList.add('active');
                if (tg) tg.classList.remove('active');
                if (tp) tp.classList.add('active');
            }
        }

        if (new URLSearchParams(window.location.search).get('tab') === 'gif') {
            switchMedia('gif');
        }

        const stage = document.getElementById('photo-stage');
        const wrapper = document.getElementById('image-wrapper');
        let lastTap = 0;
        let tapTimer = null;
        if (stage && wrapper) {
            stage.addEventListener('click', function () {
                const now = Date.now();
                if (now - lastTap < 300) {
                    clearTimeout(tapTimer);
                    wrapper.classList.toggle('zoomed');
                } else {
                    tapTimer = setTimeout(() => {
                        if (!wrapper.classList.contains('zoomed')) {
                            document.body.classList.toggle('zen-mode');
                        }
                    }, 250);
                }
                lastTap = now;
            });
        }
    </script>
</body>
</html>
