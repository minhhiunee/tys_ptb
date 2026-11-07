<?php

declare(strict_types=1);

use Photobooth\Service\PresetService;

$presetService = PresetService::getInstance();
$presets = $presetService->getEnabledPresets();
?>

<!-- Live Framed Time-Lapse GIF Overlay Viewer on Result Screen -->
<div id="result-gif-viewer" class="result-gif-viewer hidden" aria-hidden="true">
    <div class="result-gif-badge">
        <i class="fa fa-film"></i> <span>Ảnh Động Lồng Khung (Speedup 10s)</span>
        <button type="button" id="btn-close-gif-viewer" title="Quay lại xem ảnh tĩnh" style="background:rgba(255,255,255,0.2); border:none; color:#fff; border-radius:999px; padding:2px 10px; font-size:11px; font-weight:700; cursor:pointer; margin-left:8px;">
            ✕ Đóng
        </button>
    </div>
    <img id="result-gif-img" src="" alt="Framed Time-Lapse GIF">
</div>

<div id="desktop-preset-bar" class="desktop-preset-bar">
    <div class="preset-bar-inner">
        <!-- Preset Selector Buttons -->
        <div class="preset-group">
            <span class="preset-group-label"><i class="fa fa-sliders"></i> Presets:</span>
            <button type="button" class="preset-btn active" data-preset="original" title="Shortcut: 1">
                <span class="preset-btn-name">Original</span>
            </button>
            <?php $keyIdx = 2; foreach ($presets as $p): ?>
                <button type="button" class="preset-btn" data-preset="<?= htmlspecialchars($p->id) ?>" title="Shortcut: <?= $keyIdx ?>">
                    <span class="preset-btn-name"><?= htmlspecialchars($p->name) ?></span>
                </button>
            <?php $keyIdx++; endforeach; ?>
        </div>

        <!-- Amount Slider -->
        <div class="preset-amount-container">
            <label for="preset-amount-slider" class="preset-amount-label">Amount: <span id="preset-amount-val">100%</span></label>
            <input type="range" id="preset-amount-slider" min="0" max="100" value="100" class="preset-slider" title="Drag to adjust intensity">
        </div>

        <!-- Before / After Hold Button & Live Time-Lapse GIF Toggle -->
        <div class="preset-compare-container">
            <button type="button" id="btn-toggle-gif-preview" class="compare-btn" title="Xem Ảnh Động GIF tua nhanh quá trình từ lúc bắt đầu đến lúc chụp xong" style="background:rgba(14,165,233,0.2); border-color:rgba(56,189,248,0.5); color:#7dd3fc; font-weight:600;">
                <i class="fa fa-film"></i> <span>Ảnh Động (Tua nhanh)</span>
            </button>
            <button type="button" id="btn-hold-compare" class="compare-btn" title="Click & hold mouse button to view original image (or press \ )">
                <i class="fa fa-columns"></i> Hold: Before/After
            </button>
            <span id="preset-status" class="preset-status-pill">Ready</span>
        </div>

        <!-- Action Buttons: Retake, Direct QR & Confirm -->
        <div class="preset-actions-container">
            <button type="button" id="btn-preset-retake" class="preset-action-btn btn-retake" title="Chụp lại từ đầu nếu chưa ưng ý">
                <i class="fa fa-refresh"></i> <span>Chụp lại</span>
            </button>
            <button type="button" id="btn-preset-qr" class="preset-action-btn btn-qr-direct" title="Lưu màu hiện tại và hiện ngay Mã QR tải Ảnh & GIF (Cloud R2)">
                <i class="fa fa-qrcode"></i> <span>Mã QR</span>
            </button>
            <button type="button" id="btn-preset-confirm" class="preset-action-btn btn-confirm" title="Xác nhận ảnh ưng ý và hoàn tất lồng khung">
                <i class="fa fa-check-circle"></i> <span>Xác nhận lấy ảnh</span>
            </button>
        </div>
    </div>
</div>

<!-- Confirmation Success Modal -->
<div id="confirmSuccessModal" class="confirm-modal hidden" aria-hidden="true">
    <div class="confirm-modal-backdrop"></div>
    <div class="confirm-modal-card">
        <div class="confirm-modal-icon">
            <i class="fa fa-check-circle"></i>
        </div>
        <h2 class="confirm-modal-title">Ảnh Đã Được Lồng Khung Thành Công!</h2>
        <p class="confirm-modal-desc">Ảnh của bạn đã được áp dụng preset và ghép vào khung frame hoàn chỉnh. Hãy chọn thao tác bạn muốn thực hiện tiếp theo:</p>
        
        <div class="confirm-modal-buttons">
            <button type="button" class="confirm-action-btn btn-print-now" id="modal-btn-print">
                <span class="btn-icon" style="color:#2563eb; font-size:22px;"><i class="fa fa-print"></i></span>
                <span class="btn-text">
                    <strong style="display:block; font-size:14px; color:#0f172a;">In Ảnh Ngay</strong>
                    <small style="color:#64748b; font-size:11px;">In trực tiếp ra máy in</small>
                </span>
            </button>
            <button type="button" class="confirm-action-btn btn-qr-now" id="modal-btn-qr">
                <span class="btn-icon" style="color:#7c3aed; font-size:22px;"><i class="fa fa-qrcode"></i></span>
                <span class="btn-text">
                    <strong style="display:block; font-size:14px; color:#0f172a;">Quét Mã QR</strong>
                    <small style="color:#64748b; font-size:11px;">Tải ảnh về điện thoại</small>
                </span>
            </button>
            <button type="button" class="confirm-action-btn btn-gallery-now" id="modal-btn-gallery">
                <span class="btn-icon" style="color:#0284c7; font-size:22px;"><i class="fa fa-picture-o"></i></span>
                <span class="btn-text">
                    <strong style="display:block; font-size:14px; color:#0f172a;">Xem Gallery</strong>
                    <small style="color:#64748b; font-size:11px;">Xem bộ sưu tập ảnh</small>
                </span>
            </button>
            <button type="button" class="confirm-action-btn btn-home-now" id="modal-btn-home">
                <span class="btn-icon" style="color:#10b981; font-size:22px;"><i class="fa fa-home"></i></span>
                <span class="btn-text">
                    <strong style="display:block; font-size:14px; color:#0f172a;">Chụp Lượt Mới</strong>
                    <small style="color:#64748b; font-size:11px;">Về màn hình chính</small>
                </span>
            </button>
        </div>
        <button type="button" class="confirm-modal-close" id="modal-btn-close" title="Đóng để xem lại ảnh">
            <i class="fa fa-times"></i> Đóng lại xem ảnh
        </button>
    </div>
</div>

<!-- Pure Guest QR Delivery Modal (Shown immediately on Result / Preset Screen) -->
<div id="qrDeliveryModal" class="confirm-modal hidden" aria-hidden="true">
    <div class="confirm-modal-backdrop" id="qr-modal-backdrop"></div>
    <div class="confirm-modal-card qr-card">
        <div class="qr-modal-header">
            <span class="qr-badge cloud" id="qr-mode-badge"><i class="fa fa-cloud"></i> <span>Cloud R2 • Nhận Ảnh & GIF</span></span>
            <div class="qr-countdown-pill" id="qr-countdown-box" title="Thời gian tự động quay về màn hình chụp">
                <i class="fa fa-clock-o"></i> <span id="qr-countdown-val">30</span>s
            </div>
        </div>

        <h2 class="qr-modal-title">Quét Mã QR Để Tải Ảnh</h2>
        <p class="qr-modal-desc" id="qr-modal-instruction">Mở camera điện thoại (dùng 4G/5G hoặc bất kỳ WiFi nào) quét mã QR bên dưới để tải Ảnh gốc & Ảnh động GIF về máy.</p>
        
        <div class="qr-image-container">
            <div class="qr-image-frame">
                <img id="qr-modal-img" src="" alt="Photobooth QR Code" style="display:none;">
                <div class="qr-image-loading" id="qr-loading-spinner">
                    <i class="fa fa-spinner fa-spin"></i> Đang tạo mã QR...
                </div>
            </div>
        </div>

        <div class="qr-direct-link-container">
            <a href="#" target="_blank" id="qr-direct-link" class="qr-direct-link">
                <i class="fa fa-external-link"></i> Mở trang tải ảnh trực tiếp
            </a>
        </div>

        <div class="qr-modal-actions">
            <button type="button" class="qr-finish-btn" id="qr-btn-finish">
                <i class="fa fa-check"></i> <span>Đã Tải Xong • Chụp Lượt Mới</span>
            </button>
            <button type="button" class="confirm-modal-close" id="qr-btn-close">
                <i class="fa fa-times"></i> Đóng lại xem ảnh
            </button>
        </div>
    </div>
</div>

<!-- One-Time Cloud R2 Setup Modal (Opened only from Start Screen) -->
<div id="cloudR2SetupModal" class="confirm-modal hidden" aria-hidden="true">
    <div class="confirm-modal-backdrop" id="r2-setup-backdrop"></div>
    <div class="confirm-modal-card qr-card" style="max-width:460px !important; text-align:left;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <span class="qr-badge cloud"><i class="fa fa-cloud-upload"></i> <span>Cấu hình Cloudflare R2 (1 Lần)</span></span>
            <span id="qr-net-save-msg" style="font-size:12px; color:#10b981; font-weight:700;"></span>
        </div>
        <h2 style="font-size:19px; font-weight:800; color:#0f172a; margin:0 0 6px;">Thiết Lập Máy Chủ Nhận Ảnh QR</h2>
        <p style="font-size:12.5px; color:#64748b; margin:0 0 16px; line-height:1.45;">
            Cấu hình 1 lần tại màn hình chờ. Sau khi lưu, ở màn hình chụp/áp preset khi bấm nút <b>Mã QR</b> hệ thống sẽ tự động đẩy Ảnh + GIF lên R2 và hiện thẳng mã QR cho khách quét.
        </p>

        <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:18px;">
            <div>
                <label style="display:block; font-weight:700; font-size:12.5px; color:#334155; margin-bottom:4px;">Địa chỉ Cloudflare R2 Worker (URL):</label>
                <input type="text" id="input-cloud-url" value="https://tysptb.kocodau2006.workers.dev" placeholder="VD: https://tysptb.kocodau2006.workers.dev" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid #cbd5e1; font-size:13px; color:#0f172a; font-weight:600;">
            </div>
            <div>
                <label style="display:block; font-weight:700; font-size:12.5px; color:#334155; margin-bottom:4px;">Cloud API Key bảo mật (PHOTOBOOTH_API_KEY):</label>
                <input type="text" id="input-cloud-key" value="tys_secret_key_2026" placeholder="tys_secret_key_2026" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid #cbd5e1; font-size:13px; color:#0f172a;">
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:8px;">
            <div style="display:flex; gap:8px;">
                <button type="button" id="btn-test-cloud-r2" style="flex:1; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; border-radius:12px; padding:11px 12px; font-weight:700; font-size:13px; cursor:pointer;">
                    <i class="fa fa-plug"></i> Kiểm tra kết nối R2
                </button>
                <button type="button" id="btn-save-qr-network" style="flex:1; background:linear-gradient(135deg, #2563eb, #0ea5e9); color:#ffffff; border:none; border-radius:12px; padding:11px 14px; font-weight:700; font-size:13px; cursor:pointer;">
                    <i class="fa fa-check"></i> Lưu cấu hình
                </button>
            </div>
            <button type="button" id="btn-r2-test-qr-latest" style="width:100%; background:#f8fafc; color:#2563eb; border:1px dashed #93c5fd; border-radius:12px; padding:9px 12px; font-weight:700; font-size:12.5px; cursor:pointer;">
                <i class="fa fa-qrcode"></i> Xem thử Mã QR của ảnh chụp gần nhất
            </button>
            <button type="button" class="confirm-modal-close" id="r2-setup-btn-close" style="width:100%; text-align:center; margin-top:4px;">
                <i class="fa fa-times"></i> Đóng bảng cài đặt
            </button>
        </div>
    </div>
</div>

<style>
.result-gif-viewer {
    position: fixed;
    top: 16px;
    left: 50%;
    transform: translateX(-50%);
    height: calc(100dvh - 165px);
    max-width: 92vw;
    z-index: 45;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: auto;
}
.result-gif-viewer.hidden {
    display: none !important;
}
.result-gif-viewer img {
    max-height: calc(100% - 40px);
    max-width: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.65);
    border: 2px solid rgba(56, 189, 248, 0.6);
    background: #0f172a;
}
.result-gif-badge {
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(56, 189, 248, 0.5);
    color: #38bdf8;
    padding: 5px 14px;
    border-radius: 999px;
    font-size: 12.5px;
    font-weight: 700;
    margin-bottom: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
}
.desktop-preset-bar {
    display: none;
    justify-content: center;
    width: 100%;
    margin-bottom: 5.5rem;
    pointer-events: auto !important;
    touch-action: auto !important;
    z-index: 60;
    transition: opacity 0.25s ease, transform 0.25s ease;
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
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px 14px;
    max-width: 96vw;
    background: rgba(18, 24, 38, 0.94);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 40px;
    padding: 8px 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6), 0 0 1px 1px rgba(255, 255, 255, 0.15);
    color: #f1f5f9;
    font-family: inherit;
    font-size: 14px;
    box-sizing: border-box;
}
@media (max-width: 1024px) {
    .desktop-preset-bar {
        margin-bottom: 5.8rem;
    }
    .preset-bar-inner {
        padding: 6px 14px;
        gap: 8px 10px;
        border-radius: 20px;
    }
    .preset-amount-container {
        padding: 0 10px !important;
    }
    .preset-slider {
        width: 80px !important;
    }
    .preset-btn {
        padding: 5px 10px !important;
        font-size: 12px !important;
    }
    .preset-action-btn {
        padding: 6px 12px !important;
        font-size: 12px !important;
    }
}
.preset-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.preset-group-label {
    font-weight: 600;
    font-size: 13px;
    color: #94a3b8;
    margin-right: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.preset-btn {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.16);
    color: #e2e8f0;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    user-select: none;
}
.preset-btn:hover {
    background: rgba(255, 255, 255, 0.22);
    border-color: rgba(255, 255, 255, 0.35);
    color: #ffffff;
    transform: translateY(-1px);
}
.preset-btn.active {
    background: linear-gradient(135deg, #0ea5e9, #2563eb);
    border-color: #38bdf8;
    color: #ffffff;
    box-shadow: 0 2px 10px rgba(14, 165, 233, 0.5);
    font-weight: 600;
}
.preset-amount-container {
    display: flex;
    align-items: center;
    gap: 10px;
    border-left: 1px solid rgba(255, 255, 255, 0.15);
    border-right: 1px solid rgba(255, 255, 255, 0.15);
    padding: 0 16px;
}
.preset-amount-label {
    font-size: 12px;
    font-weight: 600;
    color: #cbd5e1;
    min-width: 90px;
}
.preset-slider {
    -webkit-appearance: none;
    appearance: none;
    width: 120px;
    height: 8px;
    background: rgba(255, 255, 255, 0.28);
    border-radius: 4px;
    outline: none;
    cursor: pointer;
    pointer-events: auto !important;
    touch-action: pan-x !important;
    user-select: auto !important;
    transition: background 0.2s;
}
.preset-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #38bdf8;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
    cursor: pointer;
    transition: transform 0.15s, background 0.15s;
}
.preset-slider::-webkit-slider-thumb:hover {
    transform: scale(1.2);
    background: #0284c7;
}
.preset-compare-container {
    display: flex;
    align-items: center;
    gap: 10px;
}
.compare-btn {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: #e2e8f0;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    user-select: none;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.compare-btn:hover {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}
.compare-btn:active, .compare-btn.comparing {
    background: #eab308;
    border-color: #fde047;
    color: #0f172a;
    font-weight: 700;
}
.compare-btn.active {
    background: linear-gradient(135deg, #0284c7, #2563eb) !important;
    border-color: #38bdf8 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 0 14px rgba(56, 189, 248, 0.6);
}
.preset-status-pill {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
    color: #94a3b8;
}
.preset-status-pill.loading {
    background: rgba(14, 165, 233, 0.25);
    color: #38bdf8;
}

/* Preset Action Buttons (Retake & Confirm) */
.preset-actions-container {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-left: 6px;
    padding-left: 14px;
    border-left: 1px solid rgba(255, 255, 255, 0.18);
}
.preset-action-btn {
    border: none;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    user-select: none;
}
.preset-action-btn.btn-retake {
    background: rgba(239, 68, 68, 0.18);
    border: 1px solid rgba(239, 68, 68, 0.45);
    color: #fca5a5;
}
.preset-action-btn.btn-retake:hover {
    background: #ef4444;
    border-color: #ef4444;
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(239, 68, 68, 0.4);
    transform: translateY(-1px);
}
.preset-action-btn.btn-qr-direct {
    background: linear-gradient(135deg, #6366f1, #0ea5e9);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(14, 165, 233, 0.4);
}
.preset-action-btn.btn-qr-direct:hover {
    background: linear-gradient(135deg, #818cf8, #38bdf8);
    box-shadow: 0 6px 18px rgba(56, 189, 248, 0.55);
    transform: translateY(-1px);
}
.preset-action-btn.btn-confirm {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
}
.preset-action-btn.btn-confirm:hover {
    background: linear-gradient(135deg, #34d399, #059669);
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.55);
    transform: translateY(-1px);
}

/* Confirmation Success Modal */
.confirm-modal {
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 1;
    pointer-events: auto;
    transition: opacity 0.25s ease;
}
.confirm-modal.hidden {
    display: none !important;
    opacity: 0;
    pointer-events: none;
}
.confirm-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}
.confirm-modal-card {
    position: relative;
    max-width: 540px;
    width: 90%;
    background: #ffffff;
    border-radius: 28px;
    padding: 34px 30px 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    text-align: center;
    border: 1px solid #e2e8f0;
    animation: confirmCardPop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes confirmCardPop {
    0% { transform: scale(0.92); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
.confirm-modal-icon {
    font-size: 54px;
    color: #10b981;
    line-height: 1;
    margin-bottom: 12px;
}
.confirm-modal-title {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 8px;
    letter-spacing: -0.02em;
}
.confirm-modal-desc {
    font-size: 13.5px;
    color: #64748b;
    margin: 0 0 24px;
    line-height: 1.5;
}
.confirm-modal-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}
.confirm-action-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 16px;
    border: 2px solid #e2e8f0;
    background: #f8fafc;
    color: #1e293b;
    cursor: pointer;
    text-align: left;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.confirm-action-btn:hover {
    border-color: #3b82f6;
    background: #eff6ff;
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(59, 130, 246, 0.16);
}
.confirm-modal-close {
    background: transparent;
    border: none;
    color: #94a3b8;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    padding: 8px 16px;
    border-radius: 8px;
    transition: color 0.2s;
}
.confirm-modal-close:hover {
    color: #334155;
}

/* Dedicated QR Delivery Modal Styles */
.qr-card {
    max-width: 480px !important;
    padding: 26px 26px 20px !important;
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
    gap: 6px;
    background: #f1f5f9;
    color: #475569;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.qr-badge.cloud {
    background: #eff6ff;
    color: #2563eb;
}
.qr-badge.local {
    background: #f0fdf4;
    color: #16a34a;
}
.qr-countdown-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fef2f2;
    color: #ef4444;
    border: 1px solid #fee2e2;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.qr-modal-title {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 6px;
}
.qr-modal-desc {
    font-size: 13px;
    color: #64748b;
    margin: 0 0 16px;
    line-height: 1.45;
}
.qr-image-container {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 10px 0 14px;
}
.qr-image-frame {
    width: 230px;
    height: 230px;
    padding: 12px;
    background: #ffffff;
    border-radius: 20px;
    border: 2px solid #e2e8f0;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.qr-image-frame img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 8px;
}
.qr-image-loading {
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.qr-direct-link-container {
    margin-bottom: 18px;
}
.qr-direct-link {
    font-size: 12.5px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 8px;
    background: #eff6ff;
    transition: background 0.2s;
}
.qr-direct-link:hover {
    background: #dbeafe;
    text-decoration: underline;
}
.qr-modal-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.qr-finish-btn {
    width: 100%;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    border: none;
    padding: 13px 20px;
    border-radius: 16px;
    font-size: 14.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.qr-finish-btn:hover {
    background: linear-gradient(135deg, #34d399, #059669);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.5);
}
</style>
