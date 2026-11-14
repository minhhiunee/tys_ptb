<?php

declare(strict_types=1);

use Photobooth\Service\AssetService;
use Photobooth\Service\ColorWorkflowService;
use Photobooth\Service\PresetService;

// Mode 1 (default): base grade baked at capture => no preset controls on the result screen
// Mode 2 (admin opt-in `color_workflow.interactive_presets`): guests pick preset + intensity here
$interactivePresets = ColorWorkflowService::getInstance()->isInteractive();
$presetService = PresetService::getInstance();
$presets = $interactivePresets ? $presetService->getEnabledPresets() : [];
$assetService = AssetService::getInstance();
$evLogoUrl = $assetService->getUrl('resources/img/event/logo-96.png');
$evChaoUrl = $assetService->getUrl('resources/img/event/chao-tsv-2026-light-sm.png');
$evTitleUrl = $assetService->getUrl('resources/img/event/tram-tinh-ha-sm.png');
?>

<!-- Result Screen Event Branding Pill (Logo + Chào Tân Sinh Viên 2026 + TRẠM TINH HÀ) -->
<div class="result-event-brand" aria-label="Chào Tân Sinh Viên 2026 - TRẠM TINH HÀ">
    <img src="<?= htmlspecialchars($evLogoUrl, ENT_QUOTES) ?>" alt="Logo" class="result-event-brand__logo">
    <div class="result-event-brand__titles">
        <img src="<?= htmlspecialchars($evChaoUrl, ENT_QUOTES) ?>" alt="CHÀO TÂN SINH VIÊN 2026" class="result-event-brand__chao">
        <img src="<?= htmlspecialchars($evTitleUrl, ENT_QUOTES) ?>" alt="TRẠM TINH HÀ" class="result-event-brand__name">
    </div>
</div>

<!-- Live Framed Time-Lapse GIF Overlay Viewer on Result Screen -->
<div id="result-gif-viewer" class="result-gif-viewer hidden" aria-hidden="true">
    <div class="result-gif-badge">
        <i class="fa fa-film" aria-hidden="true"></i>
        <span>Ảnh động tua nhanh</span>
        <button type="button" id="btn-close-gif-viewer" class="result-gif-close-btn" title="Quay lại xem ảnh tĩnh">
            <i class="fa fa-times" aria-hidden="true"></i> Đóng
        </button>
    </div>
    <img id="result-gif-img" src="" alt="Framed Time-Lapse GIF">
</div>

<!-- Studio Darkroom Loupe & Color Grading Deck (Mouse + Touch Ergonomic) -->
<div id="desktop-preset-bar" class="desktop-preset-bar" data-interactive-presets="<?= $interactivePresets ? '1' : '0' ?>" role="region" aria-label="Bảng chỉnh màu và nhận ảnh">
    <div class="preset-bar-inner">
        <?php if ($interactivePresets): ?>
        <!-- Zone 1: Film Preset Strip (Horizontally scrollable via Touch swipe or Mouse wheel) -->
        <div class="preset-strip-wrapper">
            <span class="preset-group-label">
                <i class="fa fa-sliders" aria-hidden="true"></i>
                <span>Màu ảnh</span>
            </span>
            <div class="preset-group" id="preset-scroll-strip">
                <button type="button" class="preset-btn active" data-preset="original" title="Màu gốc (Phím tắt: 1)">
                    <span class="preset-btn-key">1</span>
                    <span class="preset-btn-name">Gốc</span>
                </button>
                <?php $keyIdx = 2; foreach ($presets as $p): ?>
                    <button type="button" class="preset-btn" data-preset="<?= htmlspecialchars($p->id) ?>" title="<?= htmlspecialchars($p->name) ?> (Phím tắt: <?= $keyIdx ?>)">
                        <?php if ($keyIdx <= 9): ?>
                            <span class="preset-btn-key"><?= $keyIdx ?></span>
                        <?php endif; ?>
                        <span class="preset-btn-name"><?= htmlspecialchars($p->name) ?></span>
                    </button>
                <?php $keyIdx++; endforeach; ?>
            </div>
        </div>

        <!-- Zone 2: Tactile Intensity Dial (Mouse drag, Touch slide, or -/+ Stepper taps) -->
        <div class="preset-amount-container">
            <div class="preset-amount-header">
                <label for="preset-amount-slider" class="preset-amount-label">Độ đậm</label>
                <span id="preset-amount-val" class="preset-amount-readout">100%</span>
            </div>
            <div class="preset-slider-controls">
                <button type="button" id="btn-amount-dec" class="amount-step-btn" title="Giảm 10%" aria-label="Giảm độ đậm màu">−</button>
                <input type="range" id="preset-amount-slider" min="0" max="100" step="5" value="100" class="preset-slider" title="Kéo thanh trượt hoặc chạm để chỉnh độ đậm màu">
                <button type="button" id="btn-amount-inc" class="amount-step-btn" title="Tăng 10%" aria-label="Tăng độ đậm màu">+</button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Zone 3: Loupe Inspection Controls (GIF Preview & Before/After Hold) -->
        <div class="preset-compare-container">
            <button type="button" id="btn-toggle-gif-preview" class="compare-btn compare-btn--gif" title="Chạm hoặc click để xem ảnh động GIF tua nhanh">
                <i class="fa fa-film" aria-hidden="true"></i>
                <span>Xem GIF</span>
            </button>
            <?php if ($interactivePresets): ?>
            <button type="button" id="btn-hold-compare" class="compare-btn" title="Nhấn giữ chuột hoặc chạm giữ để đối chiếu ảnh gốc (Phím tắt: \)">
                <i class="fa fa-eye" aria-hidden="true"></i>
                <span>Giữ xem gốc</span>
            </button>
            <?php endif; ?>
            <span id="preset-status" class="preset-status-pill" aria-live="polite">Sẵn sàng</span>
        </div>

        <!-- Zone 4: Primary Decisions (Retake, Instant QR, Confirm & Print) -->
        <div class="preset-actions-container">
            <button type="button" id="btn-preset-retake" class="preset-action-btn btn-retake" title="Chụp lại từ đầu nếu chưa ưng ý">
                <i class="fa fa-refresh" aria-hidden="true"></i>
                <span>Chụp lại</span>
            </button>
            <button type="button" id="btn-preset-qr" class="preset-action-btn btn-qr-direct" title="Lưu màu hiện tại và mở mã QR nhận Ảnh & GIF">
                <i class="fa fa-qrcode" aria-hidden="true"></i>
                <span>Lấy mã QR</span>
            </button>
            <button type="button" id="btn-preset-confirm" class="preset-action-btn btn-confirm" title="Hoàn tất chọn màu để In ảnh hoặc Tải về">
                <i class="fa fa-check" aria-hidden="true"></i>
                <span>Hoàn tất & In</span>
            </button>
        </div>
    </div>
</div>

<!-- Confirmation & Print / Delivery Modal -->
<div id="confirmSuccessModal" class="confirm-modal hidden" aria-hidden="true" role="dialog" aria-labelledby="confirm-modal-heading">
    <div class="confirm-modal-backdrop"></div>
    <div class="confirm-modal-card">
        <div class="modal-top-bar">
            <span class="modal-status-tag">
                <span class="modal-status-dot"></span>
                Đã lưu màu & khung ảnh
            </span>
            <button type="button" class="modal-icon-close" id="modal-btn-close" title="Đóng để chỉnh lại màu" aria-label="Đóng">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <h2 class="confirm-modal-title" id="confirm-modal-heading">Bức ảnh của bạn đã sẵn sàng</h2>
        <p class="confirm-modal-desc">Chọn in ảnh lấy liền tại quầy hoặc quét mã QR để lưu bản gốc & ảnh động GIF về điện thoại.</p>

        <div class="confirm-modal-buttons">
            <button type="button" class="confirm-action-btn btn-print-now" id="modal-btn-print">
                <span class="btn-icon-box btn-icon-box--amber">
                    <i class="fa fa-print" aria-hidden="true"></i>
                </span>
                <span class="btn-text">
                    <strong>In ảnh ngay</strong>
                    <small>Nhận ảnh in nhiệt tại khay máy in</small>
                </span>
            </button>

            <button type="button" class="confirm-action-btn btn-qr-now" id="modal-btn-qr">
                <span class="btn-icon-box btn-icon-box--emerald">
                    <i class="fa fa-qrcode" aria-hidden="true"></i>
                </span>
                <span class="btn-text">
                    <strong>Quét mã QR tải ảnh</strong>
                    <small>Lấy cả Ảnh ghép khung & GIF bằng 4G/WiFi</small>
                </span>
            </button>

            <button type="button" class="confirm-action-btn btn-gallery-now" id="modal-btn-gallery">
                <span class="btn-icon-box btn-icon-box--slate">
                    <i class="fa fa-th-large" aria-hidden="true"></i>
                </span>
                <span class="btn-text">
                    <strong>Mở thư viện ảnh</strong>
                    <small>Xem lại các lượt chụp trước đó</small>
                </span>
            </button>

            <button type="button" class="confirm-action-btn btn-home-now" id="modal-btn-home">
                <span class="btn-icon-box btn-icon-box--slate">
                    <i class="fa fa-camera" aria-hidden="true"></i>
                </span>
                <span class="btn-text">
                    <strong>Chụp lượt tiếp theo</strong>
                    <small>Quay về màn hình bắt đầu</small>
                </span>
            </button>
        </div>

        <div class="modal-footer-row">
            <button type="button" class="confirm-modal-close" id="modal-btn-close-text">
                Quay lại chỉnh màu thêm
            </button>
        </div>
    </div>
</div>

<!-- Guest QR Delivery Modal -->
<div id="qrDeliveryModal" class="confirm-modal hidden" aria-hidden="true" role="dialog" aria-labelledby="qr-modal-heading">
    <div class="confirm-modal-backdrop" id="qr-modal-backdrop"></div>
    <div class="confirm-modal-card qr-card">
        <div class="qr-modal-header">
            <span class="qr-badge cloud" id="qr-mode-badge">
                <i class="fa fa-cloud" aria-hidden="true"></i>
                <span>Đang đồng bộ Ảnh & GIF</span>
            </span>
            <div style="display: inline-flex; align-items: center; gap: 8px;">
                <button type="button" class="modal-icon-close" id="btn-toggle-qr-settings" title="Cấu hình chế độ mạng QR (Cloud R2 / LAN)" aria-label="Cấu hình mạng QR" style="width: 34px; height: 34px; font-size: 14px;">
                    <i class="fa fa-cog" aria-hidden="true"></i>
                </button>
                <div class="qr-countdown-pill" id="qr-countdown-box" title="Thời gian tự động quay về màn hình bắt đầu">
                    <i class="fa fa-clock-o" aria-hidden="true"></i>
                    <span id="qr-countdown-val">45</span>s
                </div>
            </div>
        </div>

        <div class="qr-brand-strip" aria-hidden="true">
            <img src="<?= htmlspecialchars($evLogoUrl, ENT_QUOTES) ?>" alt="" class="qr-brand-strip__logo">
            <div class="qr-brand-strip__titles">
                <img src="<?= htmlspecialchars($evChaoUrl, ENT_QUOTES) ?>" alt="CHÀO TÂN SINH VIÊN 2026" class="qr-brand-strip__chao">
                <img src="<?= htmlspecialchars($evTitleUrl, ENT_QUOTES) ?>" alt="TRẠM TINH HÀ" class="qr-brand-strip__name">
            </div>
        </div>

        <h2 class="qr-modal-title" id="qr-modal-heading">Quét mã QR để lưu ảnh về máy</h2>
        <p class="qr-modal-desc" id="qr-modal-instruction">
            Mở camera điện thoại (dùng 4G/5G hoặc WiFi bất kỳ) hướng vào mã bên dưới để nhận Ảnh ghép khung & Ảnh động GIF.
        </p>

        <!-- High-contrast Baryta Paper Mount for instant camera focus in dim venues -->
        <div class="qr-image-container">
            <div class="qr-image-frame">
                <img id="qr-modal-img" src="" alt="Mã QR tải ảnh Photobooth" style="display:none;">
                <div class="qr-image-loading" id="qr-loading-spinner">
                    <i class="fa fa-circle-o-notch fa-spin" style="font-size:24px; color:#E59A3C;" aria-hidden="true"></i>
                    <span>Đang khởi tạo mã QR...</span>
                </div>
            </div>
        </div>

        <div class="qr-direct-link-container">
            <a href="#" target="_blank" rel="noopener" id="qr-direct-link" class="qr-direct-link">
                <i class="fa fa-external-link" aria-hidden="true"></i>
                <span>Mở trang tải ảnh trực tiếp trên trình duyệt</span>
            </a>
        </div>

        <div class="qr-modal-actions">
            <button type="button" class="qr-finish-btn" id="qr-btn-finish">
                <i class="fa fa-check" aria-hidden="true"></i>
                <span>Đã quét xong • Mời lượt chụp tiếp theo</span>
            </button>
            <button type="button" class="confirm-modal-close" id="qr-btn-close">
                Đóng mã QR để xem lại ảnh
            </button>
        </div>
    </div>
</div>

<!-- One-Time Cloud R2 Setup Modal (Opened from Start Screen) -->
<div id="cloudR2SetupModal" class="confirm-modal hidden" aria-hidden="true" role="dialog" aria-labelledby="r2-setup-heading">
    <div class="confirm-modal-backdrop" id="r2-setup-backdrop"></div>
    <div class="confirm-modal-card qr-card r2-setup-card">
        <div class="qr-modal-header">
            <span class="qr-badge cloud">
                <i class="fa fa-sliders" aria-hidden="true"></i>
                <span>Cấu hình máy chủ QR Cloud R2</span>
            </span>
            <span id="qr-net-save-msg" class="r2-save-feedback"></span>
        </div>

        <h2 class="qr-modal-title" id="r2-setup-heading">Cấu hình trả ảnh QR (Cloud R2 / LAN)</h2>
        <p class="qr-modal-desc">
            Chọn chế độ phát mã QR phù hợp với điều kiện mạng tại sự kiện. Mặc định ưu tiên Cloud R2 để khách quét bằng 4G/5G hoặc mọi Wi-Fi.
        </p>

        <div class="r2-form-group">
            <div class="r2-mode-switch" role="radiogroup" aria-label="Chế độ trả ảnh QR">
                <label class="r2-mode-option">
                    <input type="radio" name="qr_delivery_mode" value="cloud_web" checked>
                    <span><i class="fa fa-cloud" aria-hidden="true"></i> Hướng 2: Cloud R2 (4G/WiFi)</span>
                </label>
                <label class="r2-mode-option">
                    <input type="radio" name="qr_delivery_mode" value="venue_wifi">
                    <span><i class="fa fa-wifi" aria-hidden="true"></i> Hướng 1: Wi-Fi Nội Bộ (LAN)</span>
                </label>
            </div>

            <div id="qr-fields-cloud">
                <div class="r2-field" style="margin-bottom: 12px;">
                    <label for="input-cloud-url">Địa chỉ Cloudflare Worker (URL)</label>
                    <input type="url" id="input-cloud-url" value="https://tysptb.kocodau2006.workers.dev" placeholder="https://tysptb.kocodau2006.workers.dev" autocomplete="off" spellcheck="false">
                </div>
                <div class="r2-field">
                    <label for="input-cloud-key">Khóa bảo mật (PHOTOBOOTH_API_KEY)</label>
                    <input type="text" id="input-cloud-key" value="tys_secret_key_2026" placeholder="tys_secret_key_2026" autocomplete="off" spellcheck="false">
                </div>
            </div>

            <div id="qr-fields-venue" style="display: none;">
                <div class="r2-field">
                    <label for="input-venue-ip">Địa chỉ IP LAN của máy tính Kiosk (để trống = tự nhận diện)</label>
                    <input type="text" id="input-venue-ip" value="" placeholder="Ví dụ: 192.168.1.150" autocomplete="off" spellcheck="false">
                </div>
            </div>
        </div>

        <div class="r2-actions-stack">
            <div class="r2-actions-row">
                <button type="button" id="btn-test-cloud-r2" class="r2-btn-secondary">
                    <i class="fa fa-plug" aria-hidden="true"></i>
                    <span>Kiểm tra kết nối</span>
                </button>
                <button type="button" id="btn-save-qr-network" class="r2-btn-primary">
                    <i class="fa fa-check" aria-hidden="true"></i>
                    <span>Lưu cấu hình</span>
                </button>
            </div>
            <button type="button" id="btn-r2-test-qr-latest" class="r2-btn-preview">
                <i class="fa fa-qrcode" aria-hidden="true"></i>
                <span>Hiển thị thử mã QR của ảnh chụp gần nhất</span>
            </button>
            <button type="button" class="confirm-modal-close" id="r2-setup-btn-close">
                Đóng bảng cài đặt
            </button>
        </div>
    </div>
</div>

<style>
/* ==========================================================================
   TRẠM TINH HÀ • Cosmic Starlight Darkroom & Loupe Deck
   ========================================================================== */
:root {
    --loupe-obsidian: #08051A;
    --loupe-surface: rgba(16, 10, 44, 0.92);
    --loupe-elevated: #1A1147;
    --loupe-border: rgba(167, 139, 250, 0.24);
    --loupe-border-strong: rgba(125, 211, 252, 0.42);
    --loupe-paper: #F8FAFF;
    --loupe-text: #F8FAFF;
    --loupe-muted: #B6BCE0;
    --loupe-amber: #38BDF8;
    --loupe-amber-Soft: rgba(56, 189, 248, 0.18);
    --loupe-emerald: #10B981;
    --loupe-coral: #F43F5E;
    --loupe-font-sans: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --loupe-font-mono: ui-monospace, "JetBrains Mono", "SF Mono", Consolas, monospace;
}

/* Result Screen Event Brand Pill */
.result-event-brand {
    display: none;
    position: fixed;
    top: 14px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 55;
    align-items: center;
    gap: 10px;
    padding: 6px 16px 6px 8px;
    border-radius: 999px;
    background: rgba(14, 9, 40, 0.8);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1px solid rgba(167, 139, 250, 0.35);
    box-shadow: 0 10px 28px rgba(4, 2, 18, 0.65), inset 0 1px 0 rgba(255, 255, 255, 0.14);
    pointer-events: none;
}
.stage[data-stage="result"].stage--active .result-event-brand {
    display: inline-flex !important;
}
.result-event-brand__logo {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #FFFFFF;
    padding: 2px;
    object-fit: contain;
    flex-shrink: 0;
}
.result-event-brand__titles {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
}
.result-event-brand__chao {
    height: 10px;
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 0 6px rgba(196, 181, 253, 0.55));
}
.result-event-brand__name {
    height: 17px;
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.55));
}

.qr-brand-strip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin: 0 auto 12px;
    padding: 6px 14px 6px 8px;
    border-radius: 999px;
    background: rgba(25, 16, 68, 0.85);
    border: 1px solid rgba(167, 139, 250, 0.32);
}
.qr-brand-strip__logo {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #FFFFFF;
    padding: 2px;
    object-fit: contain;
}
.qr-brand-strip__titles {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
}
.qr-brand-strip__chao {
    height: 9.5px;
    width: auto;
    object-fit: contain;
}
.qr-brand-strip__name {
    height: 16px;
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.5));
}

/* GIF Overlay Viewer */
.result-gif-viewer {
    position: fixed;
    top: 66px;
    left: 50%;
    transform: translateX(-50%);
    height: calc(100dvh - 220px);
    width: 92vw;
    max-width: 92vw;
    z-index: 45;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: auto;
    font-family: var(--loupe-font-sans);
}
.result-gif-viewer.hidden {
    display: none !important;
}
.result-gif-viewer img {
    max-height: calc(100% - 48px);
    max-width: 100%;
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 10px;
    filter: drop-shadow(0 24px 56px rgba(0, 0, 0, 0.85));
}
.result-gif-badge {
    background: var(--loupe-surface);
    border: 1px solid rgba(229, 154, 60, 0.55);
    color: var(--loupe-text);
    padding: 6px 8px 6px 16px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 10px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.5);
}
.result-gif-badge i {
    color: var(--loupe-amber);
}
.result-gif-close-btn {
    background: rgba(243, 241, 236, 0.12);
    border: 1px solid rgba(243, 241, 236, 0.2);
    color: var(--loupe-text);
    border-radius: 999px;
    min-height: 32px;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    transition: background-color 0.15s ease;
}
.result-gif-close-btn:hover {
    background: var(--loupe-coral);
    border-color: var(--loupe-coral);
}

/* Bottom Color Grading & Action Dock */
.desktop-preset-bar {
    display: none;
    justify-content: center;
    width: 100%;
    margin-bottom: 5.2rem;
    padding: 0 12px;
    pointer-events: auto !important;
    touch-action: manipulation !important;
    z-index: 60;
    font-family: var(--loupe-font-sans);
    box-sizing: border-box;
}
.stage[data-stage="result"].stage--active .desktop-preset-bar {
    display: flex !important;
}
.stage[data-stage="result"]:not(.stage--active) .desktop-preset-bar {
    display: none !important;
}

.preset-bar-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px 14px;
    max-width: 98vw;
    background: var(--loupe-surface);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1px solid var(--loupe-border-strong);
    border-radius: 20px;
    padding: 10px 16px;
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.68), inset 0 1px 0 rgba(255, 255, 255, 0.08);
    color: var(--loupe-text);
    box-sizing: border-box;
}

/* Zone 1: Film Preset Strip */
.preset-strip-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 100%;
}
.preset-group-label {
    font-weight: 600;
    font-size: 13px;
    color: var(--loupe-muted);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.preset-group-label i {
    color: var(--loupe-amber);
}
.preset-group {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
    max-width: min(46vw, 620px);
    padding: 2px 2px 4px;
    scrollbar-width: thin;
    scrollbar-color: rgba(243, 241, 236, 0.25) transparent;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-x;
}
.preset-group::-webkit-scrollbar {
    height: 4px;
}
.preset-group::-webkit-scrollbar-thumb {
    background: rgba(243, 241, 236, 0.25);
    border-radius: 999px;
}

.preset-btn {
    min-height: 44px;
    padding: 8px 15px;
    background: var(--loupe-elevated);
    border: 1px solid var(--loupe-border);
    border-radius: 12px;
    color: var(--loupe-text);
    font-family: var(--loupe-font-sans);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
}
.preset-btn-key {
    font-family: var(--loupe-font-mono);
    font-size: 11px;
    font-weight: 600;
    padding: 2px 5px;
    border-radius: 5px;
    background: rgba(255, 255, 255, 0.08);
    color: var(--loupe-muted);
    line-height: 1;
}
.preset-btn:hover {
    background: #272F40;
    border-color: var(--loupe-border-strong);
}
.preset-btn:active {
    transform: scale(0.96);
}
.preset-btn.active {
    background: var(--loupe-amber);
    border-color: #F4B35E;
    color: #0D1015;
    box-shadow: 0 4px 16px rgba(229, 154, 60, 0.35);
}
.preset-btn.active .preset-btn-key {
    background: rgba(13, 16, 21, 0.18);
    color: #0D1015;
}

/* Zone 2: Tactile Amount Slider + Stepper */
.preset-amount-container {
    display: flex;
    align-items: center;
    gap: 10px;
    border-left: 1px solid var(--loupe-border);
    border-right: 1px solid var(--loupe-border);
    padding: 0 14px;
}
.preset-amount-header {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    min-width: 56px;
}
.preset-amount-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--loupe-muted);
    line-height: 1.1;
}
.preset-amount-readout {
    font-family: var(--loupe-font-mono);
    font-variant-numeric: tabular-nums;
    font-size: 14px;
    font-weight: 700;
    color: var(--loupe-amber);
    line-height: 1.25;
}
.preset-slider-controls {
    display: flex;
    align-items: center;
    gap: 6px;
}
.amount-step-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid var(--loupe-border);
    background: var(--loupe-elevated);
    color: var(--loupe-text);
    font-family: var(--loupe-font-mono);
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
}
.amount-step-btn:hover {
    background: #272F40;
    border-color: var(--loupe-amber);
    color: var(--loupe-amber);
}
.amount-step-btn:active {
    transform: scale(0.92);
}

.preset-slider {
    -webkit-appearance: none;
    appearance: none;
    width: 115px;
    height: 10px;
    background: rgba(243, 241, 236, 0.2);
    border-radius: 999px;
    outline: none;
    cursor: pointer;
    pointer-events: auto !important;
    touch-action: pan-x !important;
}
.preset-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--loupe-amber);
    border: 3px solid var(--loupe-paper);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.55);
    cursor: grab;
    transition: transform 0.12s ease;
}
.preset-slider::-webkit-slider-thumb:hover,
.preset-slider::-webkit-slider-thumb:active {
    transform: scale(1.15);
    cursor: grabbing;
}
.preset-slider::-moz-range-thumb {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--loupe-amber);
    border: 3px solid var(--loupe-paper);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.55);
    cursor: grab;
}

/* Zone 3: Loupe Compare & GIF Toggle */
.preset-compare-container {
    display: flex;
    align-items: center;
    gap: 8px;
}
.compare-btn {
    min-height: 44px;
    padding: 8px 14px;
    background: var(--loupe-elevated);
    border: 1px solid var(--loupe-border);
    border-radius: 12px;
    color: var(--loupe-text);
    font-family: var(--loupe-font-sans);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
}
.compare-btn:hover {
    background: #272F40;
    border-color: var(--loupe-border-strong);
}
.compare-btn:active,
.compare-btn.comparing {
    background: var(--loupe-paper);
    border-color: var(--loupe-paper);
    color: var(--loupe-obsidian);
}
.compare-btn.active {
    background: var(--loupe-amber-Soft) !important;
    border-color: var(--loupe-amber) !important;
    color: var(--loupe-amber) !important;
}

.preset-status-pill {
    font-family: var(--loupe-font-mono);
    font-size: 11.5px;
    font-weight: 600;
    padding: 5px 10px;
    border-radius: 8px;
    background: rgba(243, 241, 236, 0.07);
    color: var(--loupe-muted);
    white-space: nowrap;
}
.preset-status-pill.loading {
    background: var(--loupe-amber-Soft);
    color: var(--loupe-amber);
}

/* Zone 4: Primary Action Buttons */
.preset-actions-container {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-left: 12px;
    border-left: 1px solid var(--loupe-border);
}
.preset-action-btn {
    min-height: 44px;
    padding: 8px 16px;
    border-radius: 12px;
    font-family: var(--loupe-font-sans);
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
}
.preset-action-btn:active {
    transform: scale(0.96);
}
.preset-action-btn.btn-retake {
    background: rgba(224, 82, 82, 0.14);
    border: 1px solid rgba(224, 82, 82, 0.4);
    color: #FCA5A5;
}
.preset-action-btn.btn-retake:hover {
    background: var(--loupe-coral);
    border-color: var(--loupe-coral);
    color: #FFFFFF;
}
.preset-action-btn.btn-qr-direct {
    background: var(--loupe-elevated);
    border: 1px solid rgba(229, 154, 60, 0.55);
    color: var(--loupe-amber);
}
.preset-action-btn.btn-qr-direct:hover {
    background: var(--loupe-amber);
    border-color: var(--loupe-amber);
    color: #0D1015;
}
.preset-action-btn.btn-confirm {
    background: var(--loupe-emerald);
    border: 1px solid #28B882;
    color: #FFFFFF;
    box-shadow: 0 6px 18px rgba(31, 157, 110, 0.35);
}
.preset-action-btn.btn-confirm:hover {
    background: #24B27D;
}

@media (max-width: 1180px) {
    .desktop-preset-bar {
        margin-bottom: 5.5rem;
    }
    .preset-bar-inner {
        padding: 10px 12px;
        gap: 8px 10px;
    }
    .preset-group {
        max-width: 40vw;
    }
    .preset-slider {
        width: 90px;
    }
}

/* ==========================================================================
   Darkroom Modal System (Confirm, QR Delivery, Cloud R2 Setup)
   ========================================================================== */
.confirm-modal {
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    font-family: var(--loupe-font-sans);
    opacity: 1;
    pointer-events: auto;
    transition: opacity 0.2s ease;
}
.confirm-modal.hidden {
    display: none !important;
    opacity: 0;
    pointer-events: none;
}
.confirm-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(9, 11, 16, 0.82);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.confirm-modal-card {
    position: relative;
    max-width: 540px;
    width: 100%;
    background: linear-gradient(160deg, rgba(20, 12, 58, 0.96), rgba(9, 5, 28, 0.98));
    color: var(--loupe-text);
    border-radius: 22px;
    padding: 26px 26px 22px;
    box-shadow: 0 28px 64px rgba(2, 1, 16, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.14);
    border: 1.5px solid var(--loupe-border-strong);
    text-align: left;
    animation: loupeModalReveal 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes loupeModalReveal {
    0% { transform: translateY(10px) scale(0.97); opacity: 0; }
    100% { transform: translateY(0) scale(1); opacity: 1; }
}

.modal-top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.modal-status-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 12px;
    border-radius: 8px;
    background: rgba(31, 157, 110, 0.15);
    border: 1px solid rgba(31, 157, 110, 0.38);
    color: #5EEAD4;
    font-size: 12.5px;
    font-weight: 600;
}
.modal-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #1F9D6E;
}
.modal-icon-close {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    border: 1px solid var(--loupe-border);
    background: var(--loupe-elevated);
    color: var(--loupe-muted);
    font-size: 16px;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.modal-icon-close:hover {
    background: #272F40;
    color: var(--loupe-text);
}

.confirm-modal-title,
.qr-modal-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--loupe-text);
    margin: 0 0 6px;
    letter-spacing: -0.02em;
}
.confirm-modal-desc,
.qr-modal-desc {
    font-size: 14px;
    color: var(--loupe-muted);
    margin: 0 0 20px;
    line-height: 1.5;
}

.confirm-modal-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}
@media (max-width: 520px) {
    .confirm-modal-buttons {
        grid-template-columns: 1fr;
    }
}
.confirm-action-btn {
    min-height: 68px;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border-radius: 14px;
    border: 1px solid var(--loupe-border);
    background: var(--loupe-elevated);
    color: var(--loupe-text);
    cursor: pointer;
    touch-action: manipulation;
    text-align: left;
    transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
}
.confirm-action-btn:hover {
    background: #252D3D;
    border-color: var(--loupe-amber);
}
.confirm-action-btn:active {
    transform: scale(0.98);
}
.btn-icon-box {
    width: 44px;
    height: 44px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.btn-icon-box--amber {
    background: var(--loupe-amber-Soft);
    color: var(--loupe-amber);
}
.btn-icon-box--emerald {
    background: rgba(31, 157, 110, 0.18);
    color: #34D399;
}
.btn-icon-box--slate {
    background: rgba(243, 241, 236, 0.08);
    color: var(--loupe-text);
}
.confirm-action-btn .btn-text strong {
    display: block;
    font-size: 15px;
    font-weight: 700;
    color: var(--loupe-text);
    margin-bottom: 2px;
}
.confirm-action-btn .btn-text small {
    display: block;
    font-size: 12px;
    color: var(--loupe-muted);
    line-height: 1.3;
}

.modal-footer-row {
    display: flex;
    justify-content: center;
}
.confirm-modal-close {
    min-height: 42px;
    background: transparent;
    border: 1px solid transparent;
    color: var(--loupe-muted);
    font-family: var(--loupe-font-sans);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    padding: 8px 18px;
    border-radius: 10px;
    transition: color 0.15s ease, background-color 0.15s ease;
}
.confirm-modal-close:hover {
    color: var(--loupe-text);
    background: rgba(243, 241, 236, 0.06);
}

/* QR Delivery Modal Specifics */
.qr-card {
    max-width: 460px !important;
    text-align: center;
}
.qr-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.qr-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: var(--loupe-elevated);
    border: 1px solid var(--loupe-border);
    color: var(--loupe-text);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
}
.qr-badge.cloud {
    background: var(--loupe-amber-Soft);
    border-color: rgba(229, 154, 60, 0.45);
    color: var(--loupe-amber);
}
.qr-badge.local {
    background: rgba(31, 157, 110, 0.18);
    border-color: rgba(31, 157, 110, 0.45);
    color: #34D399;
}
.qr-countdown-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(243, 241, 236, 0.08);
    color: var(--loupe-text);
    border: 1px solid var(--loupe-border);
    padding: 5px 11px;
    border-radius: 8px;
    font-family: var(--loupe-font-mono);
    font-variant-numeric: tabular-nums;
    font-size: 13px;
    font-weight: 700;
}

.qr-image-container {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 12px 0 16px;
}
.qr-image-frame {
    width: 248px;
    height: 248px;
    padding: 14px;
    background: var(--loupe-paper);
    border-radius: 16px;
    border: 3px solid var(--loupe-amber);
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.qr-image-frame img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 6px;
}
.qr-image-loading {
    color: #1E2430;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.qr-direct-link-container {
    margin-bottom: 16px;
}
.qr-direct-link {
    font-size: 13px;
    color: var(--loupe-amber);
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    background: var(--loupe-amber-Soft);
    transition: opacity 0.15s ease;
}
.qr-direct-link:hover {
    opacity: 0.88;
    text-decoration: underline;
}

.qr-modal-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.qr-finish-btn {
    width: 100%;
    min-height: 50px;
    background: var(--loupe-emerald);
    color: #FFFFFF;
    border: 1px solid #28B882;
    padding: 12px 20px;
    border-radius: 12px;
    font-family: var(--loupe-font-sans);
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 8px 20px rgba(31, 157, 110, 0.32);
    transition: background-color 0.15s ease, transform 0.12s ease;
}
.qr-finish-btn:hover {
    background: #24B27D;
}
.qr-finish-btn:active {
    transform: scale(0.98);
}

/* One-time Cloud R2 Setup Modal */
.r2-setup-card {
    text-align: left;
}
.r2-save-feedback {
    font-size: 12.5px;
    font-weight: 600;
    color: #34D399;
}
.r2-form-group {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-bottom: 20px;
}
.r2-mode-switch {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.r2-mode-option {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid var(--loupe-border-strong);
    background: var(--loupe-elevated);
    color: var(--loupe-text);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
}
.r2-mode-option input[type="radio"] {
    accent-color: var(--loupe-amber);
    width: 16px;
    height: 16px;
    margin: 0;
    flex-shrink: 0;
}
.r2-field label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: var(--loupe-text);
    margin-bottom: 6px;
}
.r2-field input {
    width: 100%;
    min-height: 46px;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--loupe-border-strong);
    background: var(--loupe-obsidian);
    color: var(--loupe-text);
    font-family: var(--loupe-font-mono);
    font-size: 13px;
    box-sizing: border-box;
}
.r2-field input:focus {
    outline: none;
    border-color: var(--loupe-amber);
}
.r2-actions-stack {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.r2-actions-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.r2-btn-secondary,
.r2-btn-primary,
.r2-btn-preview {
    min-height: 46px;
    border-radius: 11px;
    font-family: var(--loupe-font-sans);
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 14px;
    transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
}
.r2-btn-secondary {
    background: var(--loupe-elevated);
    color: var(--loupe-text);
    border: 1px solid var(--loupe-border-strong);
}
.r2-btn-secondary:hover {
    background: #272F40;
}
.r2-btn-primary {
    background: var(--loupe-amber);
    color: #0D1015;
    border: 1px solid #F4B35E;
}
.r2-btn-primary:hover {
    background: #EDA448;
}
.r2-btn-preview {
    width: 100%;
    background: rgba(243, 241, 236, 0.05);
    color: var(--loupe-text);
    border: 1px dashed var(--loupe-border-strong);
}
.r2-btn-preview:hover {
    border-color: var(--loupe-amber);
    color: var(--loupe-amber);
}
</style>
