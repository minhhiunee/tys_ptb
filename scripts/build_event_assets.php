<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);
$eventDir = $baseDir . '/resources/img/event';
$srcDir = $eventDir . '/_src';
$imgDir = $baseDir . '/resources/img';

if (!is_dir($eventDir)) {
    mkdir($eventDir, 0775, true);
}

echo "=== Building Event Assets (Trạm Tinh Hà - Chào Tân Sinh Viên 2026) ===\n";

/**
 * Resize and save PNG from trimmed source.
 */
function saveResizedPng(Imagick $src, string $destPath, int $size): void
{
    $im = clone $src;
    $im->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1, true);
    $canvas = new Imagick();
    $canvas->newImage($size, $size, new ImagickPixel('transparent'));
    $canvas->setImageFormat('png32');
    $x = (int) floor(($size - $im->getImageWidth()) / 2);
    $y = (int) floor(($size - $im->getImageHeight()) / 2);
    $canvas->compositeImage($im, Imagick::COMPOSITE_OVER, $x, $y);
    $canvas->stripImage();
    $canvas->writeImage($destPath);
    $canvas->destroy();
    $im->destroy();
}

// 1. Process Logo & Favicons
$logoSrcPath = $srcDir . '/logo.png';
$logoMaster = new Imagick($logoSrcPath);
$logoMaster->setImageFormat('png32');
$logoMaster->trimImage(0.01 * $logoMaster->getQuantum());
$logoMaster->setImagePage(0, 0, 0, 0);

// Add subtle 2% transparent margin so circular outline never clips
$lw = $logoMaster->getImageWidth();
$lh = $logoMaster->getImageHeight();
$pad = (int) round(max($lw, $lh) * 0.02);
$paddedLogo = new Imagick();
$paddedLogo->newImage($lw + $pad * 2, $lh + $pad * 2, new ImagickPixel('transparent'));
$paddedLogo->setImageFormat('png32');
$paddedLogo->compositeImage($logoMaster, Imagick::COMPOSITE_OVER, $pad, $pad);
$logoMaster->destroy();

saveResizedPng($paddedLogo, $eventDir . '/logo.png', 256);
saveResizedPng($paddedLogo, $eventDir . '/logo-96.png', 96);
saveResizedPng($paddedLogo, $eventDir . '/favicon-64.png', 64);

// Root favicons
saveResizedPng($paddedLogo, $imgDir . '/favicon-16x16.png', 16);
saveResizedPng($paddedLogo, $imgDir . '/favicon-32x32.png', 32);
saveResizedPng($paddedLogo, $imgDir . '/favicon-96x96.png', 96);
saveResizedPng($paddedLogo, $imgDir . '/android-chrome-192x192.png', 192);
saveResizedPng($paddedLogo, $imgDir . '/android-chrome-512x512.png', 512);
saveResizedPng($paddedLogo, $imgDir . '/mstile-150x150.png', 150);

// Apple Touch Icon (opaque dark cosmic rounded backdrop or full white backdrop — iOS strips transparency on black unless filled)
$appleIcon = new Imagick();
$appleIcon->newImage(180, 180, new ImagickPixel('#0A0724'));
$appleIcon->setImageFormat('png');
$logo156 = clone $paddedLogo;
$logo156->resizeImage(156, 156, Imagick::FILTER_LANCZOS, 1, true);
$appleIcon->compositeImage($logo156, Imagick::COMPOSITE_OVER, 12, 12);
$appleIcon->stripImage();
$appleIcon->writeImage($imgDir . '/apple-touch-icon.png');
$logo156->destroy();
$appleIcon->destroy();

// Multi-resolution favicon.ico (16, 32, 48)
$ico = new Imagick();
foreach ([16, 32, 48] as $s) {
    $frame = clone $paddedLogo;
    $frame->resizeImage($s, $s, Imagick::FILTER_LANCZOS, 1, true);
    $frame->setImageFormat('ico');
    $ico->addImage($frame);
    $frame->destroy();
}
$ico->writeImages($imgDir . '/favicon.ico', true);
$ico->destroy();
$paddedLogo->destroy();
echo "Saved logo & all favicons.\n";

// 2. Process "Chào Tân sinh viên 2026.png"
// Isolate the clean indigo letters ("CHÀO TÂN SINH VIÊN 2026") and remove rough outer swoosh extraction artifacts
$chaoGd = imagecreatefrompng($srcDir . '/chao-tsv.png');
imagesavealpha($chaoGd, true);
$cw = imagesx($chaoGd);
$ch = imagesy($chaoGd);

$minX = $cw;
$minY = $ch;
$maxX = 0;
$maxY = 0;
for ($y = 0; $y < $ch; $y++) {
    for ($x = 0; $x < $cw; $x++) {
        $rgba = imagecolorat($chaoGd, $x, $y);
        $a = ($rgba >> 24) & 0x7F; // 0 = opaque, 127 = transparent
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;
        // Identify solid purple/indigo glyph pixels (not white swooshes or black/grey halo dots)
        if ($a < 40 && $b > $g + 25 && $b > $r + 15 && $b > 40) {
            if ($x < $minX) $minX = $x;
            if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y;
            if ($y > $maxY) $maxY = $y;
        }
    }
}

$padX = 24;
$padY = 18;
$cropX = max(0, $minX - $padX);
$cropY = max(0, $minY - $padY);
$cropW = min($cw - $cropX, ($maxX - $minX + 1) + $padX * 2);
$cropH = min($ch - $cropY, ($maxY - $minY + 1) + $padY * 2);

echo "Detected 'Chao TSV 2026' glyph bounds: {$minX},{$minY} -> {$maxX},{$maxY} (crop {$cropW}x{$cropH})\n";

// Build ultra-clean dark-on-light version AND luminous white-lavender-on-dark version
$cleanDark = imagecreatetruecolor($cropW, $cropH);
imagealphablending($cleanDark, false);
imagesavealpha($cleanDark, true);
imagefill($cleanDark, 0, 0, imagecolorallocatealpha($cleanDark, 0, 0, 0, 127));

$cleanLight = imagecreatetruecolor($cropW, $cropH);
imagealphablending($cleanLight, false);
imagesavealpha($cleanLight, true);
imagefill($cleanLight, 0, 0, imagecolorallocatealpha($cleanLight, 0, 0, 0, 127));

for ($y = 0; $y < $cropH; $y++) {
    for ($x = 0; $x < $cropW; $x++) {
        $rgba = imagecolorat($chaoGd, $cropX + $x, $cropY + $y);
        $a = ($rgba >> 24) & 0x7F;
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;

        if ($a >= 95) {
            continue;
        }

        // Only keep crisp glyph strokes and immediate white rim; drop faint background compression haze
        $isPurpleGlyph = ($b > $g + 16) && ($b > 42) && ($a < 75);
        $isBrightRim = ($r > 200 && $g > 200 && $b > 210 && $a < 70);
        if (!$isPurpleGlyph && !$isBrightRim) {
            continue;
        }

        imagesetpixel($cleanDark, $x, $y, $rgba);

        if ($isPurpleGlyph) {
            // Luminous pearl-white glyph fill for dark cosmic backgrounds
            $invR = 248;
            $invG = 246;
            $invB = 255;
            $c = imagecolorallocatealpha($cleanLight, $invR, $invG, $invB, $a);
            imagesetpixel($cleanLight, $x, $y, $c);
        } else {
            // Subtle crisp violet-cyan outline on edges
            $c = imagecolorallocatealpha($cleanLight, 167, 139, 250, min(127, $a + 35));
            imagesetpixel($cleanLight, $x, $y, $c);
        }
    }
}
imagedestroy($chaoGd);

$tmpDarkPath = $eventDir . '/_tmp_chao_dark.png';
$tmpLightPath = $eventDir . '/_tmp_chao_light.png';
imagepng($cleanDark, $tmpDarkPath, 6);
imagepng($cleanLight, $tmpLightPath, 6);
imagedestroy($cleanDark);
imagedestroy($cleanLight);

// Scale cleanly with Imagick (pure transparent background, glow handled cleanly by CSS drop-shadow)
$imDark = new Imagick($tmpDarkPath);
$imDark->trimImage(0.01 * $imDark->getQuantum());
$imDark->setImagePage(0, 0, 0, 0);
$imDark->resizeImage(1200, 0, Imagick::FILTER_LANCZOS, 1);
$imDark->stripImage();
$imDark->writeImage($eventDir . '/chao-tsv-2026.png');
$imDark->destroy();

$imLight = new Imagick($tmpLightPath);
$imLight->trimImage(0.01 * $imLight->getQuantum());
$imLight->setImagePage(0, 0, 0, 0);

$imLightHero = clone $imLight;
$imLightHero->resizeImage(1200, 0, Imagick::FILTER_LANCZOS, 1);
$imLightHero->stripImage();
$imLightHero->writeImage($eventDir . '/chao-tsv-2026-light.png');
$imLightHero->destroy();

$imLightSm = clone $imLight;
$imLightSm->resizeImage(460, 0, Imagick::FILTER_LANCZOS, 1);
$imLightSm->stripImage();
$imLightSm->writeImage($eventDir . '/chao-tsv-2026-light-sm.png');
$imLightSm->destroy();

$imLight->destroy();
@unlink($tmpDarkPath);
@unlink($tmpLightPath);
echo "Saved trimmed & luminous 'Chào Tân Sinh Viên 2026' variants.\n";

// 3. Process "Tên chương trình.png" (TRẠM TINH HÀ)
$titleIm = new Imagick($srcDir . '/tram-tinh-ha.png');
$titleIm->setImageFormat('png32');
$titleIm->trimImage(0.02 * $titleIm->getQuantum());
$titleIm->setImagePage(0, 0, 0, 0);

$titleHero = clone $titleIm;
$titleHero->resizeImage(1200, 0, Imagick::FILTER_LANCZOS, 1);
$titleHero->stripImage();
$titleHero->writeImage($eventDir . '/tram-tinh-ha.png');
$titleHero->destroy();

$titleSm = clone $titleIm;
$titleSm->resizeImage(460, 0, Imagick::FILTER_LANCZOS, 1);
$titleSm->stripImage();
$titleSm->writeImage($eventDir . '/tram-tinh-ha-sm.png');
$titleSm->destroy();
$titleIm->destroy();
echo "Saved trimmed 'TRẠM TINH HÀ' variants.\n";

// 4. Optimize Backgrounds
// Galaxy (Nền 5.png -> 1600x1600 high-quality JPEG for rapid load)
$galaxy = new Imagick($srcDir . '/nen5.png');
$galaxy->setImageBackgroundColor(new ImagickPixel('#07041A'));
$galaxy = $galaxy->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
$galaxy->resizeImage(1600, 1600, Imagick::FILTER_LANCZOS, 1, true);
$galaxy->setImageFormat('jpeg');
$galaxy->setImageCompressionQuality(88);
$galaxy->stripImage();
$galaxy->writeImage($eventDir . '/bg-galaxy.jpg');
$galaxy->destroy();

copy($srcDir . '/nen1.jpg', $eventDir . '/bg-landscape-1.jpg');
copy($srcDir . '/nen2.jpg', $eventDir . '/bg-landscape-2.jpg');
copy($srcDir . '/nen3.jpg', $eventDir . '/bg-portrait-1.jpg');
copy($srcDir . '/nen4.jpg', $eventDir . '/bg-portrait-2.jpg');
echo "Saved background images.\n";

// 5. Build lightweight base64 manifest for standalone Cloudflare Worker HTML/SVG assets
$manifest = [
    'favicon_b64' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($eventDir . '/favicon-64.png')),
    'logo_b64' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($eventDir . '/logo-96.png')),
    'chao_tsv_sm_b64' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($eventDir . '/chao-tsv-2026-light-sm.png')),
    'tram_tinh_ha_sm_b64' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($eventDir . '/tram-tinh-ha-sm.png')),
    'bg_portrait_b64' => 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($eventDir . '/bg-portrait-1.jpg')),
    'bg_landscape_b64' => 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($eventDir . '/bg-landscape-1.jpg')),
];
file_put_contents($eventDir . '/worker-assets.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

foreach ($manifest as $k => $v) {
    echo "Manifest {$k}: " . round(strlen($v) / 1024, 1) . " KB\n";
}
echo "Done!\n";
