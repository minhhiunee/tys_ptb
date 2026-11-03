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

$title = (string) ($cloudConfig['event_title'] ?? 'TYS Photobooth');
$subtitle = (string) ($cloudConfig['event_subtitle'] ?? '');
$primary = (string) ($cloudConfig['primary_color'] ?? '#1e293b');
$bg = (string) ($cloudConfig['background_color'] ?? '#f8fafc');
$expireDays = (int) ($cloudConfig['expire_days'] ?? 14);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($title) ?> - Tải Ảnh Của Bạn</title>
    <?php if (!$fileReady): ?>
        <meta http-equiv="refresh" content="2">
    <?php endif; ?>
    <style>
        :root {
            --primary: <?= htmlspecialchars($primary) ?>;
            --bg: <?= htmlspecialchars($bg) ?>;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: #0f172a;
            display: grid;
            place-items: center;
            padding: 16px;
        }
        .card {
            width: min(560px, 100%);
            background: #ffffff;
            border-radius: 22px;
            padding: 22px 18px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 16px;
            text-align: center;
        }
        .title {
            font-family: "Georgia", serif;
            font-style: italic;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .subtitle {
            font-size: 13px;
            color: #64748b;
            margin: -8px 0 0;
        }
        .media-box {
            background: #f1f5f9;
            border-radius: 16px;
            padding: 12px;
            min-height: 280px;
            display: grid;
            place-items: center;
            border: 1px solid #e2e8f0;
        }
        .media-box img {
            max-width: 100%;
            max-height: 62vh;
            border-radius: 10px;
            display: block;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 50px;
            padding: 0 22px;
            border-radius: 999px;
            background: var(--primary);
            color: #ffffff;
            font-weight: 700;
            font-size: 15px;
            text-decoration: none;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.18);
        }
        .tabs {
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .tab-btn {
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
        }
        .tab-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
    <main class="card">
        <h1 class="title"><?= htmlspecialchars($title) ?></h1>
        <?php if ($subtitle !== ''): ?>
            <p class="subtitle"><?= htmlspecialchars($subtitle) ?></p>
        <?php endif; ?>

        <?php if (!$fileReady): ?>
            <div class="media-box">
                <div style="padding: 30px 15px;">
                    <div style="font-size: 36px; margin-bottom: 12px;">☁️⏳</div>
                    <strong style="font-size: 16px; display: block; margin-bottom: 6px;">Đang đồng bộ ảnh từ Booth lên Cloud...</strong>
                    <span style="font-size: 13px; color: #64748b;">Trang sẽ tự động hiển thị ảnh sau 2 giây. Vui lòng giữ màn hình này!</span>
                </div>
            </div>
        <?php else: ?>
            <?php if ($hasGif): ?>
                <div class="tabs">
                    <button type="button" id="tab-photo" class="tab-btn active" onclick="showTab('photo')">📸 Ảnh Ghép Khung</button>
                    <button type="button" id="tab-gif" class="tab-btn" onclick="showTab('gif')">🎞️ Ảnh Động GIF</button>
                </div>
            <?php endif; ?>

            <div class="media-box">
                <img id="img-photo" src="<?= htmlspecialchars($imageUrl) ?>" alt="Photobooth Image">
                <?php if ($hasGif): ?>
                    <img id="img-gif" src="<?= htmlspecialchars($gifInlineUrl) ?>" alt="Animated GIF" style="display:none;">
                <?php endif; ?>
            </div>

            <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:10px;">
                <a class="btn" href="<?= htmlspecialchars($downloadUrl) ?>" download="<?= htmlspecialchars($image) ?>">
                    ⬇️ Tải Ảnh Về Điện Thoại
                </a>
                <?php if ($hasGif): ?>
                    <a class="btn" href="<?= htmlspecialchars($gifDownloadUrl) ?>" download="<?= htmlspecialchars($gifFile) ?>" style="background:#0284c7;">
                        🎞️ Tải Ảnh Động GIF
                    </a>
                <?php endif; ?>
            </div>
            <small style="color:#64748b; font-size:12px;">
                💡 Mẹo: Nhấn giữ trực tiếp vào ảnh để chọn <b>"Lưu vào Ảnh" (Save to Photos)</b> trên iPhone & Android.
                <?php if ($expireDays > 0): ?>
                    <br>Lưu ý: Link tải ảnh có hiệu lực trong vòng <?= $expireDays ?> ngày.
                <?php endif; ?>
            </small>
        <?php endif; ?>
    </main>

    <?php if ($hasGif): ?>
    <script>
        function showTab(type) {
            var p = document.getElementById('img-photo');
            var g = document.getElementById('img-gif');
            var tp = document.getElementById('tab-photo');
            var tg = document.getElementById('tab-gif');
            if (type === 'gif') {
                p.style.display = 'none';
                g.style.display = 'block';
                tg.classList.add('active');
                tp.classList.remove('active');
            } else {
                p.style.display = 'block';
                g.style.display = 'none';
                tp.classList.add('active');
                tg.classList.remove('active');
            }
        }
    </script>
    <?php endif; ?>
</body>
</html>

