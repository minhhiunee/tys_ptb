<?php

declare(strict_types=1);

use Photobooth\Service\AssetService;

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
        <span>Ảnh động tua nhanh HD</span>
        <button type="button" id="btn-close-gif-viewer" class="result-gif-close-btn" title="Quay lại xem ảnh tĩnh">
            <i class="fa fa-times" aria-hidden="true"></i> Đóng
        </button>
    </div>
    <img id="result-gif-img" src="" alt="Framed Time-Lapse GIF">
</div>

<!-- Bottom Streamlined Kiosk Action Dock (Light Mode • Touch & Mouse Friendly) -->
<div id="desktop-preset-bar" class="desktop-preset-bar" role="region" aria-label="Bảng thao tác nhận ảnh và in">
    <div class="preset-bar-inner">
        <!-- Left: Quick View Controls & Print Pair Status -->
        <div class="dock-left-controls">
            <button type="button" id="btn-toggle-gif-preview" class="compare-btn compare-btn--gif" title="Chạm hoặc click để xem ảnh động GIF tua nhanh">
                <i class="fa fa-film" aria-hidden="true"></i>
                <span>Xem GIF</span>
            </button>
            <span id="pair-queue-pill" class="dock-status-pill pair-pill" title="Trạng thái ghép 2 dải ảnh để in Canon SELPHY CP1500">
                <i class="fa fa-print" aria-hidden="true"></i>
                <span id="pair-queue-text">In ghép: 0/2</span>
            </span>
            <span id="preset-status" class="dock-status-pill ready-pill" aria-live="polite">Sẵn sàng</span>
        </div>

        <!-- Right: Primary Actions (Retake, Instant QR, Confirm & Print) -->
        <div class="dock-right-actions">
            <button type="button" id="btn-preset-retake" class="preset-action-btn btn-retake" title="Chụp lại lượt này nếu chưa ưng ý">
                <i class="fa fa-refresh" aria-hidden="true"></i>
                <span>Chụp lại</span>
            </button>
            <button type="button" id="btn-preset-qr" class="preset-action-btn btn-qr-direct" title="Mở mã QR nhận Ảnh & GIF về điện thoại">
                <i class="fa fa-qrcode" aria-hidden="true"></i>
                <span>Lấy mã QR</span>
            </button>
            <button type="button" id="btn-preset-confirm" class="preset-action-btn btn-confirm" title="Mở bảng xác nhận In ảnh ghép hoặc Quét QR">
                <i class="fa fa-check" aria-hidden="true"></i>
                <span id="btn-preset-confirm-label">Hoàn tất & In</span>
            </button>
        </div>
    </div>
</div>

<!-- Confirmation & Print Pair Delivery Modal -->
<div id="confirmSuccessModal" class="confirm-modal hidden" aria-hidden="true" role="dialog" aria-labelledby="confirm-modal-heading">
    <div class="confirm-modal-backdrop"></div>
    <div class="confirm-modal-card">
        <div class="modal-top-bar">
            <span class="modal-status-tag">
                <span class="modal-status-dot"></span>
                Đã lưu ảnh chụp
            </span>
            <button type="button" class="modal-icon-close" id="modal-btn-close" title="Đóng" aria-label="Đóng">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <h2 class="confirm-modal-title" id="confirm-modal-heading">Bức ảnh của bạn đã sẵn sàng</h2>
        <p class="confirm-modal-desc" id="confirm-modal-desc-text">Quét mã QR để nhận ảnh gốc & GIF về điện thoại. Máy in Canon SELPHY CP1500 sẽ in khi đủ 2 dải ảnh PTB4.</p>

        <!-- 2-Strip Canon SELPHY CP1500 Print Queue Slot Banner -->
        <div id="pair-slots-banner" class="pair-slots-banner">
            <div class="pair-slot-item" id="pair-slot-left">
                <div class="pair-slot-thumb-box">
                    <img id="pair-slot-left-img" class="pair-slot-thumb-img hidden" src="" alt="Dải 1">
                    <div id="pair-slot-left-placeholder" class="pair-slot-thumb-ph">
                        <i class="fa fa-camera" aria-hidden="true"></i>
                    </div>
                </div>
                <div class="pair-slot-meta">
                    <span class="pair-slot-badge">Nửa Trái (Lượt 1)</span>
                    <strong id="pair-slot-left-name">Chưa có ảnh lượt 1</strong>
                    <span id="pair-slot-left-sub" class="pair-slot-sub">Chờ lượt chụp</span>
                </div>
            </div>

            <div class="pair-slot-divider">
                <i class="fa fa-plus" aria-hidden="true"></i>
            </div>

            <div class="pair-slot-item" id="pair-slot-right">
                <div class="pair-slot-thumb-box">
                    <img id="pair-slot-right-img" class="pair-slot-thumb-img hidden" src="" alt="Dải 2">
                    <div id="pair-slot-right-placeholder" class="pair-slot-thumb-ph">
                        <i class="fa fa-camera" aria-hidden="true"></i>
                    </div>
                </div>
                <div class="pair-slot-meta">
                    <span class="pair-slot-badge">Nửa Phải (Lượt 2)</span>
                    <strong id="pair-slot-right-name">Chờ chụp lượt 2...</strong>
                    <span id="pair-slot-right-sub" class="pair-slot-sub">Chờ lượt chụp</span>
                </div>
            </div>
        </div>

        <div id="pair-print-feedback-banner" class="pair-feedback-banner hidden" role="alert">
            <i class="fa fa-check-circle" aria-hidden="true"></i>
            <span id="pair-print-feedback-text">Đã gửi in thành công!</span>
        </div>

        <!-- 4 Action Choices -->
        <div class="confirm-modal-buttons">
            <button type="button" class="confirm-action-btn btn-print-now pair-locked" id="modal-btn-print" disabled>
                <span class="btn-icon-box btn-icon-box--amber">
                    <i class="fa fa-print" aria-hidden="true"></i>
                </span>
                <span class="btn-text">
                    <strong id="modal-print-title">Chờ đủ 2 dải ảnh để in (0/2)</strong>
                    <small id="modal-print-sub">Bấm "Chụp lượt tiếp theo" để đủ 2 tấm ghép in</small>
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

            <button type="button" class="confirm-action-btn btn-home-now highlight-action" id="modal-btn-home">
                <span class="btn-icon-box btn-icon-box--blue">
                    <i class="fa fa-camera" aria-hidden="true"></i>
                </span>
                <span class="btn-text">
                    <strong>Chụp lượt tiếp theo</strong>
                    <small>Quay về màn hình để chụp dải tiếp</small>
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

        <!-- High-contrast White Paper Mount for instant camera focus in all lighting -->
        <div class="qr-image-container">
            <div class="qr-image-frame">
                <img id="qr-modal-img" src="" alt="Mã QR tải ảnh Photobooth" style="display:none;">
                <div class="qr-image-loading" id="qr-loading-spinner">
                    <i class="fa fa-circle-o-notch fa-spin" style="font-size:24px; color:#2563EB;" aria-hidden="true"></i>
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

<!-- One-Time Cloud R2 Setup Modal -->
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
   TRẠM TINH HÀ • Clean Modern Light Mode Theme
   ========================================================================== */
:root {
    --km-bg: #F8FAFC;
    --km-surface: #FFFFFF;
    --km-elevated: #F1F5F9;
    --km-border: #E2E8F0;
    --km-border-focus: #3B82F6;
    --km-text-primary: #0F172A;
    --km-text-secondary: #475569;
    --km-text-muted: #64748B;
    --km-blue: #2563EB;
    --km-blue-soft: #EFF6FF;
    --km-emerald: #10B981;
    --km-emerald-soft: #ECFDF5;
    --km-amber: #D97706;
    --km-amber-soft: #FEF3C7;
    --km-coral: #DC2626;
    --km-coral-soft: #FEF2F2;
    --km-font-sans: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --km-font-mono: ui-monospace, "JetBrains Mono", "SF Mono", Consolas, monospace;
}

/* Result Screen Light Translucent Background Tint for High Clarity */
.stage[data-stage="result"] {
    background-color: #F8FAFC !important;
}

/* Event Branding Pill (Light Mode) */
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
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1.5px solid rgba(226, 232, 240, 0.9);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08), 0 1px 2px rgba(15, 23, 42, 0.04);
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
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
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
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.05));
}
.result-event-brand__name {
    height: 17px;
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 1px 3px rgba(37, 99, 235, 0.25));
}

.qr-brand-strip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin: 0 auto 12px;
    padding: 6px 14px 6px 8px;
    border-radius: 999px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
}
.qr-brand-strip__logo {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #FFFFFF;
    padding: 2px;
    object-fit: contain;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
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
    filter: drop-shadow(0 1px 3px rgba(37, 99, 235, 0.25));
}

/* GIF Overlay Viewer */
.result-gif-viewer {
    position: fixed;
    top: 52px;
    left: 50%;
    transform: translateX(-50%);
    height: calc(100dvh - 135px);
    width: 96vw;
    max-width: 96vw;
    z-index: 48;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: auto;
    font-family: var(--km-font-sans);
}
.result-gif-viewer.hidden {
    display: none !important;
}
.result-gif-viewer img {
    height: 100% !important;
    max-height: 100% !important;
    width: auto !important;
    max-width: 96vw !important;
    object-fit: contain;
    border-radius: 12px;
    box-shadow: 0 24px 56px rgba(15, 23, 42, 0.28);
}
.result-gif-badge {
    position: absolute;
    top: 8px;
    right: 16px;
    z-index: 5;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid #CBD5E1;
    color: var(--km-text-primary);
    padding: 6px 10px 6px 14px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.1);
}
.result-gif-badge i {
    color: var(--km-blue);
}
.result-gif-close-btn {
    background: #F1F5F9;
    border: 1px solid #CBD5E1;
    color: var(--km-text-primary);
    border-radius: 999px;
    min-height: 28px;
    padding: 2px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    transition: background-color 0.15s ease;
}
.result-gif-close-btn:hover {
    background: var(--km-coral-soft);
    color: var(--km-coral);
    border-color: #FCA5A5;
}

/* Bottom Streamlined Action Dock (Light Mode) */
.desktop-preset-bar {
    display: none;
    justify-content: center;
    width: 100%;
    margin-bottom: 2.5rem;
    padding: 0 16px;
    pointer-events: auto !important;
    touch-action: manipulation !important;
    z-index: 60;
    font-family: var(--km-font-sans);
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
    gap: 12px 18px;
    max-width: 960px;
    width: 100%;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1.5px solid rgba(226, 232, 240, 0.95);
    border-radius: 20px;
    padding: 10px 18px;
    box-shadow: 0 16px 40px -10px rgba(15, 23, 42, 0.12), 0 2px 6px rgba(15, 23, 42, 0.04);
    color: var(--km-text-primary);
    box-sizing: border-box;
}

.dock-left-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.dock-right-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.compare-btn {
    min-height: 44px;
    padding: 8px 16px;
    background: var(--km-elevated);
    border: 1.5px solid var(--km-border);
    border-radius: 12px;
    color: var(--km-text-primary);
    font-family: var(--km-font-sans);
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.15s ease;
}
.compare-btn:hover {
    background: #E2E8F0;
    border-color: #CBD5E1;
}
.compare-btn.active {
    background: var(--km-blue-soft) !important;
    border-color: var(--km-blue) !important;
    color: var(--km-blue) !important;
}

.dock-status-pill {
    font-family: var(--km-font-mono);
    font-size: 12.5px;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.dock-status-pill.ready-pill {
    background: #F1F5F9;
    border: 1px solid #CBD5E1;
    color: var(--km-text-secondary);
}
.dock-status-pill.pair-pill {
    background: var(--km-blue-soft);
    border: 1.5px solid #BFDBFE;
    color: var(--km-blue);
}
.dock-status-pill.pair-pill.ready {
    background: var(--km-emerald-soft);
    border-color: #A7F3D0;
    color: var(--km-emerald);
}

.preset-action-btn {
    min-height: 44px;
    padding: 9px 18px;
    border-radius: 12px;
    font-family: var(--km-font-sans);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
}
.preset-action-btn:active {
    transform: scale(0.96);
}
.preset-action-btn.btn-retake {
    background: var(--km-coral-soft);
    border: 1.5px solid #FECACA;
    color: var(--km-coral);
}
.preset-action-btn.btn-retake:hover {
    background: var(--km-coral);
    border-color: var(--km-coral);
    color: #FFFFFF;
}
.preset-action-btn.btn-qr-direct {
    background: var(--km-emerald-soft);
    border: 1.5px solid #A7F3D0;
    color: #059669;
}
.preset-action-btn.btn-qr-direct:hover {
    background: var(--km-emerald);
    border-color: var(--km-emerald);
    color: #FFFFFF;
}
.preset-action-btn.btn-confirm {
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
    border: 1.5px solid #1D4ED8;
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28);
}
.preset-action-btn.btn-confirm:hover {
    background: linear-gradient(135deg, #1D4ED8, #1E40AF);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.38);
}

/* ==========================================================================
   Light Mode Modals (Confirm & Print, QR Delivery, Cloud R2 Setup)
   ========================================================================== */
.confirm-modal {
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    font-family: var(--km-font-sans);
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
    background: rgba(15, 23, 42, 0.35);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}
.confirm-modal-card {
    position: relative;
    max-width: 580px;
    width: 100%;
    background: linear-gradient(175deg, #FFFFFF 0%, #F8FAFC 100%);
    color: var(--km-text-primary);
    border-radius: 24px;
    padding: 26px 28px 22px;
    box-shadow: 0 28px 64px -12px rgba(15, 23, 42, 0.18), 0 2px 8px rgba(15, 23, 42, 0.06);
    border: 1.5px solid #E2E8F0;
    text-align: left;
    animation: kmModalReveal 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes kmModalReveal {
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
    background: var(--km-emerald-soft);
    border: 1px solid #A7F3D0;
    color: #065F46;
    font-size: 13px;
    font-weight: 700;
}
.modal-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--km-emerald);
}
.modal-icon-close {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1.5px solid #CBD5E1;
    background: #FFFFFF;
    color: var(--km-text-muted);
    font-size: 15px;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.modal-icon-close:hover {
    background: var(--km-coral-soft);
    color: var(--km-coral);
    border-color: #FECACA;
}

.confirm-modal-title,
.qr-modal-title {
    font-size: 23px;
    font-weight: 800;
    color: var(--km-text-primary);
    margin: 0 0 6px;
    letter-spacing: -0.02em;
}
.confirm-modal-desc,
.qr-modal-desc {
    font-size: 14px;
    color: var(--km-text-secondary);
    margin: 0 0 18px;
    line-height: 1.5;
}

/* Pair Slots Banner with Photo Strip Thumbnails */
.pair-slots-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 0 0 16px;
    padding: 12px;
    border-radius: 16px;
    background: #F8FAFC;
    border: 1.5px solid #E2E8F0;
}
.pair-slot-item {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 12px;
    background: #FFFFFF;
    border: 2px dashed #CBD5E1;
    transition: all 0.2s ease;
}
.pair-slot-item.filled {
    background: #F0FDF4;
    border: 1.5px solid #86EFAC;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.1);
}
.pair-slot-thumb-box {
    width: 44px;
    height: 64px;
    border-radius: 8px;
    background: #F1F5F9;
    border: 1px solid #CBD5E1;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.pair-slot-thumb-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.pair-slot-thumb-ph {
    color: #94A3B8;
    font-size: 18px;
}
.pair-slot-meta {
    display: flex;
    flex-direction: column;
    gap: 2px;
    overflow: hidden;
}
.pair-slot-badge {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #64748B;
}
.pair-slot-item.filled .pair-slot-badge {
    color: #059669;
}
.pair-slot-item strong {
    font-size: 13.5px;
    color: var(--km-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pair-slot-sub {
    font-size: 11.5px;
    color: var(--km-text-muted);
}
.pair-slot-divider {
    font-size: 16px;
    font-weight: 800;
    color: #3B82F6;
    padding: 0 2px;
}

.pair-feedback-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 10px;
    background: var(--km-emerald-soft);
    border: 1.5px solid #A7F3D0;
    color: #065F46;
    font-size: 13.5px;
    font-weight: 700;
    margin-bottom: 14px;
}

/* Modal Actions Grid */
.confirm-modal-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 8px;
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
    border: 1.5px solid #E2E8F0;
    background: #FFFFFF;
    color: var(--km-text-primary);
    cursor: pointer;
    touch-action: manipulation;
    text-align: left;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
    transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
}
.confirm-action-btn:hover {
    background: #F8FAFC;
    border-color: #2563EB;
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.12);
    transform: translateY(-2px);
}
.confirm-action-btn:active {
    transform: scale(0.98);
}
.confirm-action-btn.highlight-action {
    background: #EFF6FF;
    border-color: #93C5FD;
}
.confirm-action-btn.highlight-action:hover {
    background: #DBEAFE;
}
.confirm-action-btn.pair-locked {
    opacity: 0.55;
    cursor: not-allowed !important;
    background: #F1F5F9 !important;
    border-color: #E2E8F0 !important;
    box-shadow: none !important;
    transform: none !important;
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
    background: var(--km-amber-soft);
    color: var(--km-amber);
}
.btn-icon-box--emerald {
    background: var(--km-emerald-soft);
    color: #059669;
}
.btn-icon-box--blue {
    background: var(--km-blue-soft);
    color: var(--km-blue);
}
.btn-icon-box--slate {
    background: #F1F5F9;
    color: var(--km-text-secondary);
}
.confirm-action-btn .btn-text strong {
    display: block;
    font-size: 14.5px;
    font-weight: 700;
    color: var(--km-text-primary);
    margin-bottom: 2px;
}
.confirm-action-btn .btn-text small {
    display: block;
    font-size: 12px;
    color: var(--km-text-secondary);
    line-height: 1.3;
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
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
}
.qr-badge.cloud {
    background: var(--km-blue-soft);
    border: 1px solid #BFDBFE;
    color: var(--km-blue);
}
.qr-badge.local {
    background: var(--km-emerald-soft);
    border: 1px solid #A7F3D0;
    color: #065F46;
}
.qr-countdown-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #F1F5F9;
    color: var(--km-text-primary);
    border: 1px solid #CBD5E1;
    padding: 5px 11px;
    border-radius: 8px;
    font-family: var(--km-font-mono);
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
    background: #FFFFFF;
    border-radius: 16px;
    border: 3px solid var(--km-blue);
    box-shadow: 0 12px 28px rgba(37, 99, 235, 0.15);
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
    color: var(--km-text-secondary);
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
    color: var(--km-blue);
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    background: var(--km-blue-soft);
    transition: opacity 0.15s ease;
}
.qr-direct-link:hover {
    text-decoration: underline;
}

.qr-modal-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.qr-finish-btn {
    width: 100%;
    min-height: 48px;
    background: linear-gradient(135deg, #10B981, #059669);
    color: #FFFFFF;
    border: none;
    padding: 12px 20px;
    border-radius: 12px;
    font-family: var(--km-font-sans);
    font-size: 14.5px;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.28);
    transition: all 0.15s ease;
}
.qr-finish-btn:hover {
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.38);
    transform: translateY(-1px);
}
.confirm-modal-close {
    min-height: 40px;
    background: transparent;
    border: none;
    color: var(--km-text-muted);
    font-family: var(--km-font-sans);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    padding: 8px 18px;
    border-radius: 10px;
    transition: all 0.15s ease;
}
.confirm-modal-close:hover {
    color: var(--km-text-primary);
    background: #F1F5F9;
}

/* One-time Cloud R2 Setup Modal (Light Mode) */
.r2-setup-card {
    text-align: left;
}
.r2-save-feedback {
    font-size: 12.5px;
    font-weight: 700;
    color: #059669;
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
    border: 1.5px solid var(--km-border);
    background: #FFFFFF;
    color: var(--km-text-primary);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
}
.r2-mode-option input[type="radio"] {
    accent-color: var(--km-blue);
    width: 16px;
    height: 16px;
    margin: 0;
    flex-shrink: 0;
}
.r2-field label {
    display: block;
    font-weight: 700;
    font-size: 13px;
    color: var(--km-text-primary);
    margin-bottom: 6px;
}
.r2-field input {
    width: 100%;
    min-height: 44px;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--km-border);
    background: #F8FAFC;
    color: var(--km-text-primary);
    font-family: var(--km-font-mono);
    font-size: 13px;
    box-sizing: border-box;
}
.r2-field input:focus {
    outline: none;
    border-color: var(--km-blue);
    background: #FFFFFF;
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
    min-height: 44px;
    border-radius: 11px;
    font-family: var(--km-font-sans);
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 14px;
    transition: all 0.15s ease;
}
.r2-btn-secondary {
    background: #FFFFFF;
    color: var(--km-text-primary);
    border: 1.5px solid #CBD5E1;
}
.r2-btn-secondary:hover {
    background: #F1F5F9;
}
.r2-btn-primary {
    background: var(--km-blue);
    color: #FFFFFF;
    border: 1.5px solid var(--km-blue);
}
.r2-btn-primary:hover {
    background: #1D4ED8;
}
.r2-btn-preview {
    width: 100%;
    background: #F8FAFC;
    color: var(--km-text-secondary);
    border: 1.5px dashed #CBD5E1;
}
.r2-btn-preview:hover {
    border-color: var(--km-blue);
    color: var(--km-blue);
}
</style>
