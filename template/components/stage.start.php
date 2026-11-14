<?php
use Photobooth\Service\AssetService;
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\StartpageTextPosition;

$assetService = AssetService::getInstance();
$startpageTextPosition = StartpageTextPosition::resolve(
    $config['ui']['startpage_text_position'] ?? null,
    $config['logo']['position'] ?? null,
    $config['logo']['enabled'] ?? null
);
$galaxyBgUrl = $assetService->getUrl('resources/img/event/bg-galaxy.jpg');
$landscapeBgUrl = $assetService->getUrl('resources/img/event/bg-landscape-1.jpg');
$eventLogoUrl = $assetService->getUrl('resources/img/event/logo.png');
$chaoTsvUrl = $assetService->getUrl('resources/img/event/chao-tsv-2026-light.png');
$tramTinhHaUrl = $assetService->getUrl('resources/img/event/tram-tinh-ha.png');
?>
<!-- Start Page -->
<div class="stage stage--start rotarygroup" data-stage="start">
    <div class="tramtinhha-cosmos-backdrop" aria-hidden="true">
        <div class="tramtinhha-cosmos-vignette"></div>
        <div class="tramtinhha-cosmos-stars"></div>
    </div>

    <?php include PathUtility::getAbsolutePath('template/components/start.logo.php'); ?>
    <!-- One-Time Cloud R2 Setup Button on Start Screen -->
    <button type="button" id="btn-start-qr-settings" class="start-qr-quick-btn" title="Thiết lập máy chủ nhận ảnh QR (Cloudflare R2)">
        <span class="start-qr-quick-btn__dot" aria-hidden="true"></span>
        <i class="fa fa-sliders" aria-hidden="true"></i>
        <span>Kết nối QR Cloud</span>
    </button>
    <style>
        /* ==========================================================================
           TRẠM TINH HÀ • Chào Tân Sinh Viên 2026 — Cosmic Observatory Start Screen
           ========================================================================== */
        .stage--start {
            position: relative;
            overflow: hidden;
            background-color: #07041A !important;
            background-image: url('<?= htmlspecialchars($galaxyBgUrl, ENT_QUOTES) ?>') !important;
            background-position: center center !important;
            background-size: cover !important;
        }

        .stage--start .github-corner {
            display: none !important;
        }

        .tramtinhha-cosmos-backdrop {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .tramtinhha-cosmos-vignette {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 72% 58% at 50% 44%, rgba(16, 10, 48, 0.42) 0%, rgba(7, 4, 26, 0.78) 72%, rgba(5, 3, 18, 0.92) 100%),
                linear-gradient(180deg, rgba(10, 5, 34, 0.48) 0%, rgba(10, 5, 34, 0.16) 45%, rgba(6, 3, 20, 0.84) 100%);
        }

        .tramtinhha-cosmos-stars {
            position: absolute;
            inset: -20%;
            background-image:
                radial-gradient(1.6px 1.6px at 14% 22%, rgba(255, 255, 255, 0.85), transparent 65%),
                radial-gradient(1.4px 1.4px at 31% 16%, rgba(196, 181, 253, 0.9), transparent 65%),
                radial-gradient(2px 2px at 78% 19%, rgba(125, 211, 252, 0.9), transparent 65%),
                radial-gradient(1.4px 1.4px at 86% 64%, rgba(255, 255, 255, 0.75), transparent 65%),
                radial-gradient(1.8px 1.8px at 22% 76%, rgba(167, 139, 250, 0.85), transparent 65%),
                radial-gradient(1.5px 1.5px at 64% 82%, rgba(56, 189, 248, 0.85), transparent 65%);
            background-size: 520px 520px;
            opacity: 0.75;
            animation: tramtinhhaStarDrift 48s linear infinite;
        }

        @keyframes tramtinhhaStarDrift {
            0% { transform: translate3d(0, 0, 0); }
            50% { transform: translate3d(-18px, 12px, 0); }
            100% { transform: translate3d(0, 0, 0); }
        }

        .start-qr-quick-btn {
            position: fixed;
            top: 20px;
            right: 24px;
            z-index: 999;
            min-height: 44px;
            padding: 9px 18px;
            background: rgba(16, 10, 48, 0.78);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            color: #F5F3FF;
            border: 1px solid rgba(167, 139, 250, 0.34);
            border-radius: 999px;
            font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            letter-spacing: -0.01em;
            cursor: pointer;
            touch-action: manipulation;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            box-shadow: 0 10px 28px rgba(4, 2, 18, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.14);
            transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.15s ease, box-shadow 0.18s ease;
        }
        .start-qr-quick-btn__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #38BDF8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25), 0 0 10px rgba(56, 189, 248, 0.8);
        }
        .start-qr-quick-btn:hover {
            background: rgba(29, 17, 79, 0.92);
            border-color: rgba(56, 189, 248, 0.7);
            color: #FFFFFF;
            box-shadow: 0 12px 30px rgba(56, 189, 248, 0.22);
        }
        .start-qr-quick-btn:active {
            transform: scale(0.97);
        }
        .stage--start:not(.stage--active) .start-qr-quick-btn {
            display: none !important;
        }

        .stage--start .stage-inner {
            position: relative;
            z-index: 2;
            justify-content: center !important;
            align-items: center !important;
            gap: clamp(1.4rem, 3.4vh, 2.8rem) !important;
            padding: clamp(1.5rem, 3vh, 3rem) 1.5rem !important;
        }

        /* Hero Lockup: Logo -> Chào Tân Sinh Viên 2026 -> TRẠM TINH HÀ */
        .tramtinhha-hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            max-width: min(92vw, 820px);
            padding: clamp(18px, 2.8vh, 30px) clamp(20px, 4vw, 48px);
            border-radius: 32px;
            background: radial-gradient(ellipse at center, rgba(24, 15, 68, 0.56) 0%, rgba(11, 7, 36, 0.34) 100%);
            border: 1px solid rgba(196, 181, 253, 0.2);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow:
                0 28px 64px rgba(3, 1, 16, 0.68),
                inset 0 1px 0 rgba(255, 255, 255, 0.14);
        }

        .tramtinhha-hero__logo-wrap {
            position: relative;
            width: clamp(84px, 11vw, 122px);
            height: clamp(84px, 11vw, 122px);
            margin-bottom: clamp(12px, 1.8vh, 18px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .tramtinhha-hero__orbit {
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 1.5px solid rgba(125, 211, 252, 0.45);
            box-shadow: 0 0 28px rgba(124, 58, 237, 0.45), inset 0 0 18px rgba(56, 189, 248, 0.25);
            animation: tramtinhhaPulse 4s ease-in-out infinite;
        }

        @keyframes tramtinhhaPulse {
            0%, 100% { transform: scale(1); opacity: 0.78; }
            50% { transform: scale(1.06); opacity: 1; }
        }

        .tramtinhha-hero__logo {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
            background: #FFFFFF;
            padding: 3px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.55);
            position: relative;
            z-index: 2;
        }

        .tramtinhha-hero__chao-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            width: 100%;
            margin-bottom: clamp(8px, 1.3vh, 14px);
        }

        .tramtinhha-hero__line {
            flex: 1;
            max-width: 72px;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(196, 181, 253, 0.75), transparent);
        }

        .tramtinhha-hero__chao-img {
            display: block;
            height: clamp(22px, 3.1vw, 34px);
            width: auto;
            max-width: min(70vw, 520px);
            object-fit: contain;
            filter: drop-shadow(0 0 12px rgba(196, 181, 253, 0.65)) drop-shadow(0 2px 6px rgba(0, 0, 0, 0.85));
        }

        .tramtinhha-hero__title-img {
            display: block;
            width: min(78vw, 640px);
            max-height: clamp(60px, 9.5vw, 118px);
            height: auto;
            object-fit: contain;
            margin: 2px auto clamp(10px, 1.4vh, 16px);
            filter:
                drop-shadow(0 0 22px rgba(56, 189, 248, 0.45))
                drop-shadow(0 6px 24px rgba(15, 6, 48, 0.95));
        }

        .tramtinhha-hero__tagline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 18px;
            border-radius: 999px;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(125, 211, 252, 0.34);
            color: #E0F2FE;
            font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: clamp(11px, 1.15vw, 13.5px);
            font-weight: 700;
            letter-spacing: 0.11em;
            text-transform: uppercase;
            text-shadow: 0 1px 6px rgba(0, 0, 0, 0.6);
        }

        .stage--start .stage-start__actions {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .stage--start .stage-start__actions .buttonbar {
            display: flex !important;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 16px !important;
            padding: 12px 16px;
            border-radius: 28px;
            background: rgba(12, 8, 38, 0.62);
            border: 1px solid rgba(167, 139, 250, 0.25);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            box-shadow: 0 22px 48px rgba(2, 1, 12, 0.65);
        }

        .stage--start .stage-start__actions .button {
            min-width: clamp(140px, 15vw, 178px) !important;
            height: clamp(104px, 12vh, 124px) !important;
            width: auto !important;
            padding: 16px 24px !important;
            border-radius: 20px !important;
            display: inline-flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 10px !important;
            background: linear-gradient(155deg, rgba(46, 26, 112, 0.86), rgba(18, 12, 54, 0.92)) !important;
            border: 1.5px solid rgba(167, 139, 250, 0.45) !important;
            color: #F8FAFF !important;
            box-shadow: 0 12px 26px rgba(4, 2, 20, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.16) !important;
            transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.18s ease, box-shadow 0.2s ease, background 0.2s ease !important;
        }

        .stage--start .stage-start__actions .button.takePic,
        .stage--start .stage-start__actions .button.takeCollage,
        .stage--start .stage-start__actions .button.takeCustom {
            background: linear-gradient(135deg, rgba(109, 40, 217, 0.9), rgba(14, 116, 144, 0.92)) !important;
            border-color: rgba(125, 211, 252, 0.65) !important;
            box-shadow: 0 14px 32px rgba(56, 189, 248, 0.24), 0 0 0 1px rgba(167, 139, 250, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.24) !important;
        }

        .stage--start .stage-start__actions .button .button--icon {
            font-size: clamp(1.75rem, 2.4vw, 2.25rem) !important;
            color: #E0F2FE !important;
            filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.5));
        }

        .stage--start .stage-start__actions .button .button--label {
            font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
            font-size: clamp(0.9rem, 1.1vw, 1.05rem) !important;
            font-weight: 700 !important;
            letter-spacing: 0.02em !important;
            color: #FFFFFF !important;
        }

        .stage--start .stage-start__actions .button:hover,
        .stage--start .stage-start__actions .button.focused {
            transform: translateY(-3px) scale(1.03) !important;
            border-color: #7DD3FC !important;
            box-shadow: 0 18px 36px rgba(56, 189, 248, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.3) !important;
        }

        .stage--start .stage-start__actions .button:active {
            transform: scale(0.97) !important;
        }

        @media (max-height: 700px) {
            .tramtinhha-hero {
                padding: 14px 24px;
            }
            .tramtinhha-hero__logo-wrap {
                width: 72px;
                height: 72px;
                margin-bottom: 10px;
            }
            .stage--start .stage-start__actions .button {
                height: 88px !important;
                min-width: 126px !important;
            }
        }
    </style>
    <div class="stage-inner">
        <div class="tramtinhha-hero" role="banner" aria-label="Chào Tân Sinh Viên 2026 - TRẠM TINH HÀ">
            <div class="tramtinhha-hero__logo-wrap">
                <div class="tramtinhha-hero__orbit" aria-hidden="true"></div>
                <img
                    src="<?= htmlspecialchars($eventLogoUrl, ENT_QUOTES) ?>"
                    alt="Logo Hội Sinh Viên Đất Cầu Vồng tại Hà Nội"
                    class="tramtinhha-hero__logo"
                />
            </div>

            <div class="tramtinhha-hero__chao-row">
                <span class="tramtinhha-hero__line" aria-hidden="true"></span>
                <img
                    src="<?= htmlspecialchars($chaoTsvUrl, ENT_QUOTES) ?>"
                    alt="CHÀO TÂN SINH VIÊN 2026"
                    class="tramtinhha-hero__chao-img"
                />
                <span class="tramtinhha-hero__line" aria-hidden="true"></span>
            </div>

            <img
                src="<?= htmlspecialchars($tramTinhHaUrl, ENT_QUOTES) ?>"
                alt="TRẠM TINH HÀ"
                class="tramtinhha-hero__title-img"
            />

            <div class="tramtinhha-hero__tagline">
                <span>✦ Photobooth Kỷ Niệm • Trạm Tinh Hà 2026 ✦</span>
            </div>
        </div>

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
