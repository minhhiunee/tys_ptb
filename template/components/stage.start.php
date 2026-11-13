<?php
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\StartpageTextPosition;

$startpageTextPosition = StartpageTextPosition::resolve(
    $config['ui']['startpage_text_position'] ?? null,
    $config['logo']['position'] ?? null,
    $config['logo']['enabled'] ?? null
);
?>
<!-- Start Page -->
<div class="stage stage--start rotarygroup" data-stage="start">
    <?php include PathUtility::getAbsolutePath('template/components/start.logo.php'); ?>
    <!-- One-Time Cloud R2 Setup Button on Start Screen -->
    <button type="button" id="btn-start-qr-settings" class="start-qr-quick-btn" title="Thiết lập máy chủ nhận ảnh QR (Cloudflare R2)">
        <span class="start-qr-quick-btn__dot" aria-hidden="true"></span>
        <i class="fa fa-sliders" aria-hidden="true"></i>
        <span>Kết nối QR Cloud</span>
    </button>
    <style>
        .start-qr-quick-btn {
            position: fixed;
            top: 20px;
            right: 24px;
            z-index: 999;
            min-height: 46px;
            padding: 10px 18px;
            background: rgba(19, 23, 31, 0.88);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            color: #F3F1EC;
            border: 1px solid rgba(243, 241, 236, 0.18);
            border-radius: 12px;
            font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: -0.01em;
            cursor: pointer;
            touch-action: manipulation;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
            transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.15s ease;
        }
        .start-qr-quick-btn__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #1F9D6E;
            box-shadow: 0 0 0 3px rgba(31, 157, 110, 0.22);
        }
        .start-qr-quick-btn:hover {
            background: #1C222E;
            border-color: rgba(229, 154, 60, 0.65);
            color: #FFFFFF;
        }
        .start-qr-quick-btn:active {
            transform: scale(0.97);
        }
        .start-qr-quick-btn:focus-visible {
            outline: 2px solid #E59A3C;
            outline-offset: 2px;
        }
        .stage--start:not(.stage--active) .start-qr-quick-btn {
            display: none !important;
        }
    </style>
    <div class="stage-inner">
        <?php if ($config['event']['enabled'] || $config['start_screen']['title_visible'] || $config['start_screen']['subtitle_visible']): ?>
            <div class="stage-start__text stage-start__text--<?= $startpageTextPosition ?>">
                <div class="names<?= ($config['ui']['decore_lines']) ? ' names--decoration' : '' ?>">
                    <div class="names-inner">
                        <?php if ($config['event']['enabled']): ?>
                            <h1 class="event-text">
                                <?= $config['event']['textLeft'] ?>
                                <i class="fa <?= $config['event']['symbol'] ?>" aria-hidden="true"></i>
                                <?= $config['event']['textRight'] ?>
                            </h1>
                        <?php endif; ?>
                        <?php if ($config['start_screen']['title_visible']): ?>
                        <h1 class="start-text"><?= $config['start_screen']['title'] ?></h1>
                        <?php endif; ?>
                        <?php if ($config['start_screen']['subtitle_visible']): ?>
                        <h2 class="start-text"><?= $config['start_screen']['subtitle'] ?></h2>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="stage-start__actions">
            <?php
            if ($config['ui']['selfie_mode']) {
                include PathUtility::getAbsolutePath('template/components/selfieAction.php');
            } else {
                include PathUtility::getAbsolutePath('template/components/actionBtn.php');
                if ($config['collage']['enabled'] && $config['collage']['allow_selection']) {
                    include PathUtility::getAbsolutePath('template/components/collageSelection.php');
                }
            }
?>
        </div>
    </div>
    <?php
    $screensaverMode = $config['screensaver']['mode'] ?? 'image';
$screensaverImageSource = $config['screensaver']['image_source'] ?? '';
$screensaverVideoSource = $config['screensaver']['video_source'] ?? '';

$screensaverSource = '';
if ($screensaverMode === 'image' && $screensaverImageSource) {
    $screensaverSource = PathUtility::getPublicPath($screensaverImageSource);
} elseif ($screensaverMode === 'video' && $screensaverVideoSource) {
    $screensaverSource = PathUtility::getPublicPath($screensaverVideoSource);
}
?>
    <div
        id="screensaver-overlay"
        class="screensaver-overlay"
        data-mode="<?= $screensaverMode ?>"
        data-source="<?= $screensaverSource ?>"
        style="display: none;"
    >
        <div id="screensaver-text-top" class="screensaver-overlay__text screensaver-overlay__text--top"></div>
        <div id="screensaver-text-center" class="screensaver-overlay__text screensaver-overlay__text--center"></div>
        <img id="screensaver-image" class="screensaver-overlay__image" alt="screensaver">
        <video id="screensaver-video" loop muted playsinline></video>
        <div id="screensaver-text-bottom" class="screensaver-overlay__text screensaver-overlay__text--bottom"></div>
    </div>
    <?php include PathUtility::getAbsolutePath('template/components/github-corner.php'); ?>
</div>
