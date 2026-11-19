<?php

use Photobooth\Service\LanguageService;
use Photobooth\Utility\PathUtility;

$languageService = LanguageService::getInstance();
$previewFlipClass = $config['preview']['flip'];
$previewStyleClass = $config['preview']['style'];
$previewShowPictureFrame = $config['preview']['showFrame'] && !empty($config['picture']['frame']);
$previewShowCollageFrame = $config['preview']['showFrame'] && !empty($config['collage']['frame']);
$extendPreviewAsPicture = $config['preview']['showFrame'] && $config['picture']['extend_by_frame'] && $config['preview']['extend_by_frame'];
$l = $t = $r = $b = 0;
$x = !empty($config['preview']['videoWidth']) ? $config['preview']['videoWidth'] : 2160;
$y = !empty($config['preview']['videoHeight']) ? $config['preview']['videoHeight'] : 1440;
$comp = $prev_ratio = $x / $y;
$prev_l = $prev_t = 0;
$prev_h = $prev_w = 100;

if ($extendPreviewAsPicture) {
    $l = $config['picture']['frame_left_percentage'] / 100;
    $t = $config['picture']['frame_top_percentage'] / 100;
    $r = $config['picture']['frame_right_percentage'] / 100;
    $b = $config['picture']['frame_bottom_percentage'] / 100;

    $hori_ratio = 1 - ($l + $r);
    $vert_ratio = 1 - ($t + $b);

    $prev_ratio = ($hori_ratio * $x) / ($vert_ratio * $y);

    $prev_h = $vert_ratio * 100;
    $prev_w = $hori_ratio * 100;

    $prev_l = 100 * ($previewFlipClass === 'flip-vertical' ? $r : $l);
    $prev_t = 100 * ($previewFlipClass === 'flip-horizontal' ? $b : $t);
}

$composed_style = '
	top:' . $prev_t . '%;
	left:' . $prev_l . '%;
	height:' . $prev_h . '%;
	width:' . $prev_w . '%;
	aspect-ratio:' . $prev_ratio;

echo '<div class="preview">';
echo '<div id="preview-container" style="aspect-ratio: ' . $comp . '">';
echo '<div id="preview-wrapper" style="aspect-ratio:' . $comp . '">';
echo '<video id="preview--video" style="' . $composed_style . '" class="' . $previewFlipClass . ' ' . $previewStyleClass . '" autoplay playsinline></video>';
echo '<div id="preview--ipcam" style="' . $composed_style . '" class="' . $previewFlipClass . ' ' . $previewStyleClass . '"></div>';
echo '<div id="preview--none">' . $languageService->translate('no_preview') . '</div>';
echo '</div></div>';

if ($previewShowPictureFrame) {
    echo '<img id="previewframe--picture" class="' . $previewFlipClass . '" src="' . PathUtility::getPublicPath($config['picture']['frame']) . '" alt="pictureFrame" />';
}
if ($previewShowCollageFrame) {
    echo '<img id="previewframe--collage" class="' . $previewFlipClass . '" src="' . PathUtility::getPublicPath($config['collage']['frame']) . '" alt="collageFrame" />';
}

echo '</div>';
?>

<style>
/* ==========================================================================
   TRẠM TINH HÀ • Maximized 3:2 Live Viewfinder & Capture Frame
   ========================================================================== */
:root {
    --camera-aspect: 1.5;
}

.preview {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100dvh;
    pointer-events: none;
    z-index: 1 !important;
    display: flex;
    align-items: center;
    justify-content: center;
}

#preview-container {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    width: min(98vw, calc(97dvh * 1.5)) !important;
    height: min(97dvh, calc(98vw / 1.5)) !important;
    max-width: 98vw !important;
    max-height: 97dvh !important;
    aspect-ratio: 3 / 2 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    border-radius: 20px !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    pointer-events: none !important;
    transition: width 0.25s ease, height 0.25s ease !important;
}

#preview-wrapper {
    position: relative !important;
    width: 100% !important;
    height: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    aspect-ratio: 3 / 2 !important;
    background: transparent !important;
}

#preview--video,
#preview--ipcam {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    width: 100% !important;
    height: 100% !important;
    max-width: 100% !important;
    max-height: 100% !important;
    object-fit: cover !important;
    border-radius: 18px !important;
    background: #000000 !important;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.8), 
                0 0 0 2px rgba(167, 139, 250, 0.45), 
                0 0 60px rgba(99, 102, 241, 0.3) !important;
}

#preview--none {
    position: absolute !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    color: rgba(255, 255, 255, 0.7) !important;
    font-size: 1.5rem !important;
    font-weight: 600 !important;
    letter-spacing: 0.05em !important;
}

#previewframe--picture,
#previewframe--collage {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    width: min(98vw, calc(97dvh * 1.5)) !important;
    height: min(97dvh, calc(98vw / 1.5)) !important;
    max-width: 98vw !important;
    max-height: 97dvh !important;
    aspect-ratio: 3 / 2 !important;
    object-fit: contain !important;
    pointer-events: none !important;
    z-index: 5 !important;
}

/* Ensure Stage Loader and its elements sit ON TOP of preview */
.stage--loader {
    z-index: 10 !important;
    background: transparent !important;
}

.stage--loader .stage-inner {
    width: 100vw !important;
    height: 100dvh !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    position: relative !important;
    z-index: 11 !important;
}

.stage--loader .stage-image {
    width: min(98vw, calc(97dvh * 1.5)) !important;
    height: min(97dvh, calc(98vw / 1.5)) !important;
    max-width: 98vw !important;
    max-height: 97dvh !important;
    aspect-ratio: 3 / 2 !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    background-size: cover !important;
    background-position: center center !important;
    background-repeat: no-repeat !important;
    border-radius: 18px !important;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.8), 0 0 0 2px rgba(167, 139, 250, 0.45) !important;
    z-index: 12 !important;
}

.stage--loader .stage-message {
    z-index: 25 !important;
}

.stage--loader .buttonbar--bottom {
    z-index: 30 !important;
}

/* Ensure countdown & cheese digits pop brightly above everything */
.countdown,
.cheese {
    z-index: 1500 !important;
    pointer-events: none !important;
}
.countdown-number,
.cheese-label {
    font-size: clamp(5rem, 15vw, 10rem) !important;
    font-weight: 800 !important;
    color: #38BDF8 !important;
    text-shadow: 0 12px 40px rgba(0, 0, 0, 0.95), 0 0 60px rgba(56, 189, 248, 0.75) !important;
}
</style>

<script>
// Enforce strict 3:2 aspect ratio (1.5) for camera canvas across Home & Capture screens
(function() {
    function enforce3to2Aspect() {
        document.documentElement.style.setProperty('--camera-aspect', '1.5');
        var container = document.getElementById('preview-container');
        if (container) {
            container.style.aspectRatio = '3 / 2';
        }
    }
    enforce3to2Aspect();
    window.addEventListener('DOMContentLoaded', enforce3to2Aspect);
})();
</script>
