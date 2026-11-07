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
    <button type="button" id="btn-start-qr-settings" class="start-qr-quick-btn" title="Thiết lập thông tin Cloudflare R2 nhận ảnh QR">
        <i class="fa fa-cloud-upload"></i>
        <span>Cài đặt Cloud R2</span>
    </button>
    <style>
        .start-qr-quick-btn {
            position: fixed;
            top: 18px;
            right: 22px;
            z-index: 999;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.45);
            border-radius: 999px;
            padding: 9px 18px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            transition: all 0.2s ease;
        }
        .start-qr-quick-btn:hover {
            background: #0284c7;
            color: #ffffff;
            border-color: #38bdf8;
            transform: translateY(-1px);
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
