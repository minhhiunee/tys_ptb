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
            <i class="fa fa-times" aria-hidden="true"></i>
            <span>Đóng xem ảnh tua nhanh</span>
        </button>
    </div>
    <div id="result-gif-loading" class="result-gif-loading hidden">
        <div class="result-gif-spinner"></div>
        <p class="result-gif-loading-text">Đang tạo ảnh động tua nhanh HD...</p>
        <span class="result-gif-loading-sub">Hệ thống đang ghép khung và nén ảnh GIF (khoảng 3-5 giây)</span>
    </div>
    <img id="result-gif-img" src="" alt="Framed Time-Lapse GIF" class="hidden">
</div>

<!-- Right Vertical Streamlined Kiosk Action Dock (Touch & Mouse Friendly) -->
<div id="desktop-preset-bar" class="desktop-preset-bar" role="region" aria-label="Bảng thao tác nhận ảnh và in">
    <div class="preset-bar-inner">
        <!-- Top Group: Quick View GIF Toggle & Print Queue Status -->
        <div class="dock-left-controls">
            <button type="button" id="btn-toggle-gif-preview" class="compare-btn compare-btn--gif" title="Chạm hoặc click để xem ảnh động GIF tua nhanh">
                <i class="fa fa-film" aria-hidden="true"></i>
                <span>Xem ảnh động GIF</span>
            </button>
            <span id="pair-queue-pill" class="dock-status-pill pair-pill" title="Trạng thái ghép 2 dải ảnh để in Canon SELPHY CP1500">
                <i class="fa fa-print" aria-hidden="true"></i>
                <span id="pair-queue-text">In ghép: 0/2</span>
            </span>
            <button type="button" id="btn-dock-banquet" class="dock-status-pill banquet-dock-pill" title="Quản lý hàng đợi in thêm lúc tiệc & hệ thống 6 thư mục lưu trữ tuần tự">
                <i class="fa fa-folder-open" aria-hidden="true"></i>
                <span id="dock-banquet-text">In tiệc: 0 dải</span>
            </button>
            <span id="preset-status" class="dock-status-pill ready-pill" aria-live="polite">Sẵn sàng</span>
        </div>

        <!-- Bottom Group: Primary Actions (Confirm & Print, Instant QR, Retake) -->
        <div class="dock-right-actions">
            <button type="button" id="btn-preset-confirm" class="preset-action-btn btn-confirm" title="Mở bảng xác nhận In ảnh ghép hoặc Quét QR">
                <i class="fa fa-check" aria-hidden="true"></i>
                <span id="btn-preset-confirm-label">Hoàn tất &amp; In</span>
            </button>
            <button type="button" id="btn-preset-qr" class="preset-action-btn btn-qr-direct" title="Mở mã QR nhận Ảnh & GIF về điện thoại">
                <i class="fa fa-qrcode" aria-hidden="true"></i>
                <span>Lấy mã QR tải ảnh</span>
            </button>
            <button type="button" id="btn-preset-retake" class="preset-action-btn btn-retake" title="Chụp lại lượt này nếu chưa ưng ý">
                <i class="fa fa-refresh" aria-hidden="true"></i>
                <span>Chụp lại lượt này</span>
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
            <div style="display: inline-flex; align-items: center; gap: 8px;">
                <button type="button" class="banquet-queue-trigger-btn" id="btn-open-banquet-modal" title="Quản lý kho ảnh chờ in tiệc & hệ thống 6 thư mục tuần tự">
                    <i class="fa fa-folder-open" aria-hidden="true"></i>
                    <span>Kho In Tiệc: <strong id="banquet-pending-badge">0</strong> dải</span>
                </button>
                <button type="button" class="modal-icon-close" id="modal-btn-close" title="Đóng" aria-label="Đóng">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>
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

        <!-- Split Print Queue: Choose Total Copies (1 Instant + N-1 Banquet Deferred) -->
        <div id="split-print-box" class="split-print-box">
            <div class="split-print-header">
                <div class="split-print-title-wrap">
                    <i class="fa fa-clone" aria-hidden="true"></i>
                    <span>Số lượng bản in muốn lấy:</span>
                </div>
                <span class="split-print-hint">1 bản vào hàng đợi in ngay • Bản thêm lưu in lúc tiệc</span>
            </div>

            <div class="split-qty-row" role="group" aria-label="Chọn số lượng bản in">
                <button type="button" class="split-qty-chip active" data-copies="1">
                    <strong>1 Bản</strong>
                    <small>In ngay</small>
                </button>
                <button type="button" class="split-qty-chip" data-copies="2">
                    <strong>2 Bản</strong>
                    <small>1 ngay + 1 tiệc</small>
                </button>
                <button type="button" class="split-qty-chip" data-copies="3">
                    <strong>3 Bản</strong>
                    <small>1 ngay + 2 tiệc</small>
                </button>
                <button type="button" class="split-qty-chip" data-copies="4">
                    <strong>4 Bản</strong>
                    <small>1 ngay + 3 tiệc</small>
                </button>
                <div class="split-qty-stepper" title="Tùy chỉnh số lượng bản in">
                    <button type="button" id="btn-qty-minus" class="qty-step-btn" aria-label="Giảm số bản">-</button>
                    <span id="split-qty-custom-val" class="qty-step-val">1</span>
                    <button type="button" id="btn-qty-plus" class="qty-step-btn" aria-label="Tăng số bản">+</button>
                </div>
            </div>

            <!-- Shown when copies > 1: Customer Name input to rename file into 05_print_banquet_pending -->
            <div id="banquet-extra-form" class="banquet-extra-form hidden">
                <label for="banquet-customer-name-input" class="banquet-extra-label">
                    <i class="fa fa-tag" aria-hidden="true"></i>
                    <span>Ghi tên khách (để đánh dấu <strong id="banquet-extra-count-text">3</strong> bản in sau trong thư mục chờ in tiệc):</span>
                </label>
                <div class="banquet-input-row">
                    <input
                        type="text"
                        id="banquet-customer-name-input"
                        class="banquet-name-input"
                        placeholder="Nhập tên của bạn (VD: Lan Anh - Bàn 5)"
                        maxlength="40"
                        autocomplete="off"
                    >
                    <button type="button" id="btn-save-banquet-split" class="banquet-save-btn">
                        <i class="fa fa-check-circle" aria-hidden="true"></i>
                        <span>Đánh dấu &amp; Lưu <b id="btn-banquet-extra-num">3</b> bản</span>
                    </button>
                </div>
                <div class="banquet-file-preview-bar">
                    <span>File lưu tại <code>05_print_banquet_pending</code>:</span>
                    <code id="banquet-filename-preview" class="banquet-filename-code">session_KhachHang_3x.jpg</code>
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
                    <i class="fa fa-clock" aria-hidden="true"></i>
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
                    <i class="fa fa-circle-notch fa-spin" style="font-size:24px; color:#2563EB;" aria-hidden="true"></i>
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

<!-- Banquet Batch Print & Sequential Folder Pipeline Manager Modal -->
<div id="banquetManagerModal" class="confirm-modal hidden" aria-hidden="true" role="dialog" aria-labelledby="banquet-modal-heading">
    <div class="confirm-modal-backdrop" id="banquet-modal-backdrop"></div>
    <div class="confirm-modal-card banquet-manager-card">
        <div class="modal-top-bar">
            <span class="modal-status-tag">
                <span class="modal-status-dot"></span>
                Hệ Thống Quản Lý File Tuần Tự &amp; In Tiệc
            </span>
            <div style="display: inline-flex; align-items: center; gap: 8px;">
                <button type="button" class="banquet-refresh-btn" id="btn-refresh-banquet" title="Làm mới danh sách thư mục">
                    <i class="fa fa-refresh" aria-hidden="true"></i>
                    <span>Làm mới</span>
                </button>
                <button type="button" class="modal-icon-close" id="banquet-modal-btn-close" title="Đóng" aria-label="Đóng">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <h2 class="confirm-modal-title" id="banquet-modal-heading">Quản Lý Thư Mục Tuần Tự &amp; Hàng Đợi In Thêm Lúc Tiệc</h2>
        <p class="confirm-modal-desc">
            Ảnh chụp đi qua 6 thư mục tuần tự. Các bản in thêm được đánh dấu theo <code>{session}_{TênKhách}_{SốLượng}x.jpg</code> tại <strong>05_print_banquet_pending</strong>, ghép tuần tự 2 ảnh/lượt trên Canon CP1500 và tự động chuyển sang <strong>06_printed</strong> sau khi in xong.
        </p>

        <!-- 6 Sequential Pipeline Folders Grid -->
        <div class="pipeline-folders-grid" id="pipeline-folders-grid">
            <div class="pipeline-folder-card" data-stage="01">
                <span class="pf-stage-num">01</span>
                <div class="pf-info">
                    <strong>01_raw_captures</strong>
                    <small>Ảnh chụp gốc từ máy ảnh</small>
                </div>
                <span class="pf-count" id="pf-count-01">0</span>
            </div>
            <div class="pipeline-folder-card" data-stage="02">
                <span class="pf-stage-num">02</span>
                <div class="pf-info">
                    <strong>02_pending_frame</strong>
                    <small>Ảnh pose chờ lồng frame</small>
                </div>
                <span class="pf-count" id="pf-count-02">0</span>
            </div>
            <div class="pipeline-folder-card" data-stage="03">
                <span class="pf-stage-num">03</span>
                <div class="pf-info">
                    <strong>03_framed</strong>
                    <small>Ảnh đã lồng frame PTB4</small>
                </div>
                <span class="pf-count" id="pf-count-03">0</span>
            </div>
            <div class="pipeline-folder-card" data-stage="04">
                <span class="pf-stage-num">04</span>
                <div class="pf-info">
                    <strong>04_print_instant</strong>
                    <small>Hàng đợi 2 ảnh in luôn</small>
                </div>
                <span class="pf-count" id="pf-count-04">0</span>
            </div>
            <div class="pipeline-folder-card pf-highlight" data-stage="05">
                <span class="pf-stage-num">05</span>
                <div class="pf-info">
                    <strong>05_print_banquet_pending</strong>
                    <small>Đợi in thêm lúc tiệc ({Tên}_{N}x)</small>
                </div>
                <span class="pf-count" id="pf-count-05">0</span>
            </div>
            <div class="pipeline-folder-card pf-done" data-stage="06">
                <span class="pf-stage-num">06</span>
                <div class="pf-info">
                    <strong>06_printed</strong>
                    <small>Đã in xong (tránh nhầm lẫn)</small>
                </div>
                <span class="pf-count" id="pf-count-06">0</span>
            </div>
        </div>

        <!-- Banquet Print Summary & Controls -->
        <div class="banquet-control-panel">
            <div class="banquet-stats-bar">
                <div class="b-stat-item">
                    <span>Khách chờ in thêm:</span>
                    <strong id="b-stat-customers">0 khách</strong>
                </div>
                <div class="b-stat-item">
                    <span>Tổng số dải chờ in:</span>
                    <strong id="b-stat-strips">0 dải</strong>
                </div>
                <div class="b-stat-item">
                    <span>Số lượt ghép in (2 dải/tờ 4x6"):</span>
                    <strong id="b-stat-sheets">0 tờ</strong>
                </div>
            </div>

            <div class="banquet-mode-bar">
                <label class="banquet-odd-option">
                    <input type="radio" name="banquet_odd_mode" value="duplicate" checked>
                    <span>In hết toàn bộ (Nếu lẻ 1 dải cuối sẽ tự nhân đôi ở nửa phải để in luôn)</span>
                </label>
                <label class="banquet-odd-option">
                    <input type="radio" name="banquet_odd_mode" value="wait">
                    <span>Chỉ in các cặp chẵn 2 dải (Giữ lại 1 dải lẻ chờ ghép thêm)</span>
                </label>
            </div>

            <button type="button" id="btn-run-banquet-batch" class="banquet-run-batch-btn" disabled>
                <i class="fa fa-print" aria-hidden="true"></i>
                <span id="btn-run-banquet-label">Chưa có ảnh chờ in tiệc (0 dải)</span>
            </button>
        </div>

        <div id="banquet-batch-feedback" class="pair-feedback-banner hidden" role="alert">
            <i class="fa fa-check-circle" aria-hidden="true"></i>
            <span id="banquet-batch-feedback-text"></span>
        </div>

        <!-- Pending Banquet Items List (05_print_banquet_pending) -->
        <div class="banquet-lists-container">
            <div class="banquet-list-section">
                <h3 class="banquet-section-heading">
                    <i class="fa fa-clock" aria-hidden="true"></i>
                    <span>Danh sách file trong <code>05_print_banquet_pending</code></span>
                </h3>
                <div id="banquet-pending-list" class="banquet-items-list">
                    <div class="banquet-empty-msg">Chưa có file ảnh nào chờ in thêm lúc tiệc.</div>
                </div>
            </div>

            <div class="banquet-list-section">
                <h3 class="banquet-section-heading">
                    <i class="fa fa-check-square" aria-hidden="true"></i>
                    <span>Lịch sử đã in &amp; chuyển sang <code>06_printed</code> gần đây</span>
                </h3>
                <div id="banquet-printed-list" class="banquet-items-list banquet-printed-history">
                    <div class="banquet-empty-msg">Chưa có lịch sử in tiệc.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ==========================================================================
   TRẠM TINH HÀ • Cosmic Dark Starlight Theme
   ========================================================================== */
:root {
    --km-bg: #09051D;
    --km-surface: #120A38;
    --km-elevated: #1A0E4C;
    --km-border: rgba(167, 139, 250, 0.32);
    --km-border-focus: #38BDF8;
    --km-text-primary: #F8FAFC;
    --km-text-secondary: #C4B5FD;
    --km-text-muted: #94A3B8;
    --km-blue: #38BDF8;
    --km-blue-soft: rgba(56, 189, 248, 0.18);
    --km-emerald: #10B981;
    --km-emerald-soft: rgba(16, 185, 129, 0.18);
    --km-amber: #F59E0B;
    --km-amber-soft: rgba(245, 158, 11, 0.18);
    --km-coral: #EF4444;
    --km-coral-soft: rgba(239, 68, 68, 0.18);
    --km-font-sans: 'Be Vietnam Pro', 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
    --km-font-heading: 'Be Vietnam Pro', 'Segoe UI', system-ui, -apple-system, sans-serif;
    --km-font-mono: 'Be Vietnam Pro', ui-monospace, 'JetBrains Mono', Consolas, monospace;
}

/* Result Screen Cosmic Dark Translucent Tint */
.stage[data-stage="result"] {
    background-color: transparent !important;
}
.stage[data-stage="result"].is-viewing-gif::after {
    opacity: 0 !important;
}

/* Event Branding Pill (Dark Cosmic Mode) */
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
    background: rgba(13, 7, 36, 0.92);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1.5px solid rgba(167, 139, 250, 0.35);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5), 0 0 16px rgba(139, 92, 246, 0.2);
    pointer-events: none;
}
.stage[data-stage="result"].stage--active .result-event-brand {
    display: inline-flex !important;
}
.result-event-brand__logo {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(167, 139, 250, 0.4);
    padding: 2px;
    object-fit: contain;
    flex-shrink: 0;
    box-shadow: 0 0 12px rgba(56, 189, 248, 0.35);
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
    filter: drop-shadow(0 0 6px rgba(56, 189, 248, 0.6));
}
.result-event-brand__name {
    height: 17px;
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.7));
}

.qr-brand-strip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin: 0 auto 12px;
    padding: 6px 14px 6px 8px;
    border-radius: 999px;
    background: rgba(22, 14, 58, 0.85);
    border: 1px solid rgba(167, 139, 250, 0.3);
}
.qr-brand-strip__logo {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    padding: 2px;
    object-fit: contain;
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.3);
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
    filter: drop-shadow(0 0 4px rgba(56, 189, 248, 0.5));
}
.qr-brand-strip__name {
    height: 16px;
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.6));
}

/* GIF Overlay Viewer — Identical 88dvh height, centered position & sharp corners matching static photo */
.result-gif-viewer {
    position: fixed;
    inset: 0;
    top: 0;
    left: 0;
    transform: none;
    height: 100dvh;
    width: 100dvw;
    max-width: 100dvw;
    z-index: 48;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    font-family: var(--km-font-sans);
}
.result-gif-viewer.hidden {
    display: none !important;
}
.result-gif-viewer img {
    height: 88dvh !important;
    max-height: 88dvh !important;
    width: auto !important;
    max-width: 96vw !important;
    object-fit: contain;
    border-radius: 0 !important;
    box-shadow: 0 24px 56px rgba(0, 0, 0, 0.45);
    pointer-events: auto;
}
.result-gif-viewer img.hidden {
    display: none !important;
}
.result-gif-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(18, 10, 56, 0.94);
    border: 1.5px solid rgba(129, 140, 248, 0.35);
    border-radius: 16px;
    padding: 36px 48px;
    box-shadow: 0 20px 48px rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(12px);
    text-align: center;
    color: #F8FAFC;
    pointer-events: auto;
}
.result-gif-loading.hidden {
    display: none !important;
}
.result-gif-spinner {
    width: 44px;
    height: 44px;
    border: 4px solid rgba(255, 255, 255, 0.15);
    border-top-color: #38BDF8;
    border-radius: 50%;
    animation: gifSpin 0.9s linear infinite;
    margin-bottom: 16px;
}
@keyframes gifSpin {
    to { transform: rotate(360deg); }
}
.result-gif-loading-text {
    font-size: 17px;
    font-weight: 700;
    color: #FFFFFF;
    margin: 0 0 6px 0;
}
.result-gif-loading-sub {
    font-size: 13px;
    color: #94A3B8;
    max-width: 340px;
}
.result-gif-retry-btn {
    margin-top: 14px;
    padding: 8px 20px;
    background: #38BDF8;
    color: #09051D;
    border: none;
    border-radius: 999px;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: transform 0.15s ease, background 0.15s ease;
}
.result-gif-retry-btn:hover {
    background: #7DD3FC;
    transform: scale(1.04);
}
.result-gif-badge {
    position: fixed;
    left: 24px;
    bottom: 28px;
    top: auto;
    right: auto;
    z-index: 66;
    background: rgba(13, 7, 36, 0.94);
    border: 1.5px solid rgba(56, 189, 248, 0.45);
    color: #F8FAFC;
    padding: 10px 12px 10px 18px;
    border-radius: 18px;
    font-size: 15px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 14px 36px rgba(0, 0, 0, 0.65), 0 0 20px rgba(56, 189, 248, 0.2);
    backdrop-filter: blur(16px);
    pointer-events: auto;
}
.result-gif-badge i {
    color: var(--km-blue);
    font-size: 17px;
}
.result-gif-close-btn {
    background: linear-gradient(135deg, #EF4444, #DC2626);
    border: 1.5px solid #FCA5A5;
    color: #FFFFFF;
    border-radius: 13px;
    min-height: 44px;
    padding: 8px 20px;
    font-size: 14.5px;
    font-weight: 800;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
    transition: all 0.15s ease;
}
.result-gif-close-btn i {
    color: #FFFFFF !important;
    font-size: 15px;
}
.result-gif-close-btn:hover {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    transform: translateY(-1px) scale(1.02);
}

/* Right Vertical Action Dock (Enlarged & Ergonomic for Touch/Kiosk) */
.desktop-preset-bar {
    display: none;
    position: fixed !important;
    top: 50% !important;
    right: 28px !important;
    bottom: auto !important;
    left: auto !important;
    transform: translateY(-50%) !important;
    width: 350px !important;
    max-width: calc(100vw - 48px) !important;
    margin: 0 !important;
    padding: 0 !important;
    pointer-events: auto !important;
    touch-action: manipulation !important;
    z-index: 65;
    font-family: var(--km-font-sans);
    box-sizing: border-box;
}
.stage[data-stage="result"].stage--active .desktop-preset-bar {
    display: flex !important;
    flex-direction: column !important;
}
.stage[data-stage="result"]:not(.stage--active) .desktop-preset-bar {
    display: none !important;
}

.preset-bar-inner {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-start;
    gap: 20px;
    width: 100%;
    max-height: calc(100dvh - 48px);
    overflow-y: auto;
    background: rgba(13, 7, 36, 0.94);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1.5px solid rgba(167, 139, 250, 0.38);
    border-radius: 28px;
    padding: 24px 20px;
    box-shadow: 0 24px 56px -10px rgba(0, 0, 0, 0.8), 0 0 28px rgba(139, 92, 246, 0.24);
    color: var(--km-text-primary);
    box-sizing: border-box;
}

.dock-left-controls {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 16px;
    width: 100%;
    padding-bottom: 20px;
    border-bottom: 1.5px solid rgba(167, 139, 250, 0.28);
}
.dock-right-actions {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 16px;
    width: 100%;
}

.compare-btn {
    width: 100%;
    min-height: 64px;
    padding: 14px 22px;
    background: rgba(22, 14, 58, 0.92);
    border: 1.5px solid rgba(56, 189, 248, 0.45);
    border-radius: 18px;
    color: #F8FAFC;
    font-family: var(--km-font-sans);
    font-size: 17px;
    font-weight: 800;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    box-sizing: border-box;
    transition: all 0.15s ease;
}
.compare-btn i {
    font-size: 20px;
    color: #38BDF8;
}
.compare-btn:hover {
    background: rgba(36, 22, 92, 0.98);
    border-color: #38BDF8;
    transform: translateY(-1px);
}
.compare-btn.active {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.25), rgba(220, 38, 38, 0.32)) !important;
    border-color: #F87171 !important;
    color: #FFFFFF !important;
    box-shadow: 0 0 18px rgba(239, 68, 68, 0.28);
}
.compare-btn.active i {
    color: #FCA5A5 !important;
}

.dock-status-pill {
    width: 100%;
    min-height: 52px;
    font-family: var(--km-font-sans);
    font-size: 16px;
    font-weight: 750;
    padding: 12px 18px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    white-space: nowrap;
    box-sizing: border-box;
}
.dock-status-pill i {
    font-size: 18px;
}
.dock-status-pill.ready-pill {
    background: rgba(22, 14, 58, 0.85);
    border: 1px solid rgba(167, 139, 250, 0.3);
    color: #C4B5FD;
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
    width: 100%;
    min-height: 64px;
    padding: 14px 22px;
    border-radius: 18px;
    font-family: var(--km-font-sans);
    font-size: 17.5px;
    font-weight: 800;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    white-space: nowrap;
    box-sizing: border-box;
    transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
}
.preset-action-btn i {
    font-size: 20px;
}
.preset-action-btn:active {
    transform: scale(0.97);
}
.preset-action-btn.btn-retake {
    background: var(--km-coral-soft);
    border: 1.5px solid #FCA5A5;
    color: #FCA5A5;
}
.preset-action-btn.btn-retake:hover {
    background: var(--km-coral);
    border-color: var(--km-coral);
    color: #FFFFFF;
}
.preset-action-btn.btn-qr-direct {
    background: rgba(16, 185, 129, 0.22);
    border: 1.5px solid #6EE7B7;
    color: #6EE7B7;
}
.preset-action-btn.btn-qr-direct:hover {
    background: var(--km-emerald);
    border-color: var(--km-emerald);
    color: #FFFFFF;
}
.preset-action-btn.btn-confirm {
    min-height: 72px;
    font-size: 19px;
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
    border: 1.5px solid #60A5FA;
    color: #FFFFFF;
    box-shadow: 0 8px 24px rgba(37, 99, 235, 0.45);
}
.preset-action-btn.btn-confirm i {
    font-size: 22px;
}
.preset-action-btn.btn-confirm:hover {
    background: linear-gradient(135deg, #1D4ED8, #1E40AF);
    box-shadow: 0 10px 28px rgba(37, 99, 235, 0.6);
    transform: translateY(-1px);
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
    background: rgba(7, 3, 24, 0.7);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}
.confirm-modal-card {
    position: relative;
    max-width: 580px;
    width: 100%;
    background: linear-gradient(170deg, #130B38 0%, #08031C 100%);
    color: #F8FAFC;
    border-radius: 26px;
    padding: 26px 28px 22px;
    box-shadow: 0 36px 88px rgba(0, 0, 0, 0.8), 0 0 40px rgba(139, 92, 246, 0.22);
    border: 1.5px solid rgba(167, 139, 250, 0.38);
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
    background: rgba(16, 185, 129, 0.16);
    border: 1px solid rgba(16, 185, 129, 0.35);
    color: #34D399;
    font-size: 13px;
    font-weight: 700;
}
.modal-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10B981;
}
.modal-icon-close {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1.5px solid rgba(167, 139, 250, 0.3);
    background: rgba(255, 255, 255, 0.08);
    color: #F8FAFC;
    font-size: 15px;
    cursor: pointer;
    touch-action: manipulation;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.modal-icon-close:hover {
    background: rgba(239, 68, 68, 0.25);
    color: #EF4444;
    border-color: #EF4444;
}

.confirm-modal-title,
.qr-modal-title {
    font-family: var(--km-font-heading);
    font-size: 23px;
    font-weight: 800;
    color: #FFFFFF;
    margin: 0 0 6px;
    letter-spacing: -0.02em;
}
.confirm-modal-desc,
.qr-modal-desc {
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 14px;
    color: #C4B5FD;
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
    background: rgba(14, 8, 38, 0.85);
    border: 1.5px solid rgba(167, 139, 250, 0.25);
}
.pair-slot-item {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 12px;
    background: rgba(22, 14, 58, 0.85);
    border: 2px dashed rgba(167, 139, 250, 0.35);
    transition: all 0.2s ease;
}
.pair-slot-item.filled {
    background: rgba(16, 185, 129, 0.14);
    border: 1.5px solid #10B981;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);
}
.pair-slot-thumb-box {
    width: 44px;
    height: 64px;
    border-radius: 8px;
    background: #080318;
    border: 1px solid rgba(167, 139, 250, 0.3);
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
    color: #A5B4FC;
}
.pair-slot-item.filled .pair-slot-badge {
    color: #34D399;
}
.pair-slot-item strong {
    font-size: 13.5px;
    color: #FFFFFF;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pair-slot-sub {
    font-size: 11.5px;
    color: #C4B5FD;
}
.pair-slot-divider {
    font-size: 16px;
    font-weight: 800;
    color: #38BDF8;
    padding: 0 2px;
}

.pair-feedback-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 10px;
    background: rgba(16, 185, 129, 0.16);
    border: 1.5px solid rgba(16, 185, 129, 0.4);
    color: #34D399;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 14px;
    text-align: left;
    overflow-wrap: anywhere;
    word-break: break-word;
}
#banquetManagerModal {
    z-index: 1000005 !important;
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
    border-radius: 16px;
    border: 1.5px solid rgba(167, 139, 250, 0.32);
    background: rgba(22, 14, 58, 0.88);
    color: #F8FAFC;
    cursor: pointer;
    touch-action: manipulation;
    text-align: left;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.confirm-action-btn:hover:not(:disabled) {
    background: rgba(36, 22, 92, 0.96);
    border-color: #38BDF8;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.5), 0 0 16px rgba(56, 189, 248, 0.25);
    transform: translateY(-2px);
}
.confirm-action-btn:active:not(:disabled) {
    transform: scale(0.98);
}
.confirm-action-btn.highlight-action {
    background: rgba(56, 189, 248, 0.15);
    border-color: rgba(56, 189, 248, 0.5);
}
.confirm-action-btn.highlight-action:hover {
    background: rgba(56, 189, 248, 0.25);
}
.confirm-action-btn.pair-locked {
    opacity: 0.5;
    cursor: not-allowed !important;
    background: rgba(18, 12, 45, 0.6) !important;
    border-color: rgba(167, 139, 250, 0.2) !important;
    box-shadow: none !important;
    transform: none !important;
}

.btn-icon-box {
    width: 44px;
    height: 44px;
    border-radius: 12px;
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
    color: #34D399;
}
.btn-icon-box--blue {
    background: var(--km-blue-soft);
    color: var(--km-blue);
}
.btn-icon-box--slate {
    background: rgba(255, 255, 255, 0.08);
    color: #C4B5FD;
}
.confirm-action-btn .btn-text strong {
    display: block;
    font-family: var(--km-font-heading);
    font-size: 14.5px;
    font-weight: 700;
    color: #FFFFFF;
    margin-bottom: 2px;
}
.confirm-action-btn .btn-text small {
    display: block;
    font-family: 'Be Vietnam Pro', sans-serif;
    font-size: 12px;
    color: #C4B5FD;
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
    background: rgba(255, 255, 255, 0.1);
    color: #F8FAFC;
    border: 1px solid var(--km-border);
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
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.1);
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
    background: rgba(26, 14, 76, 0.7);
    color: #F8FAFC;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.r2-mode-option:hover {
    background: rgba(38, 20, 100, 0.9);
    border-color: var(--km-blue);
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
    color: #E2E8F0;
    margin-bottom: 6px;
}
.r2-field input {
    width: 100%;
    min-height: 44px;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--km-border);
    background: #09051D;
    color: #FFFFFF;
    font-family: var(--km-font-sans);
    font-size: 13px;
    box-sizing: border-box;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.r2-field input:focus {
    outline: none;
    border-color: var(--km-blue);
    background: #120A38;
}
.r2-field input::placeholder {
    color: #64748B;
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
    background: rgba(255, 255, 255, 0.08);
    color: #F8FAFC;
    border: 1.5px solid var(--km-border);
}
.r2-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.16);
    border-color: #CBD5E1;
}
.r2-btn-primary {
    background: var(--km-blue);
    color: #09051D;
    border: 1.5px solid var(--km-blue);
}
.r2-btn-primary:hover {
    background: #7DD3FC;
    border-color: #7DD3FC;
}
.r2-btn-preview {
    width: 100%;
    background: rgba(56, 189, 248, 0.12);
    color: #38BDF8;
    border: 1.5px dashed rgba(56, 189, 248, 0.45);
}
.r2-btn-preview:hover {
    background: rgba(56, 189, 248, 0.22);
    border-color: #38BDF8;
    color: #7DD3FC;
}

/* ==========================================================================
   SPLIT PRINT QUEUE & BANQUET PIPELINE MANAGER STYLES
   ========================================================================== */
.banquet-dock-pill {
    cursor: pointer;
    border: 1.5px solid rgba(245, 158, 11, 0.45) !important;
    background: rgba(245, 158, 11, 0.14) !important;
    color: #FDE68A !important;
    transition: all 0.15s ease;
}
.banquet-dock-pill:hover {
    background: rgba(245, 158, 11, 0.28) !important;
    border-color: #F59E0B !important;
    color: #FFFFFF !important;
}
.banquet-queue-trigger-btn,
.banquet-refresh-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 34px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1.5px solid rgba(245, 158, 11, 0.45);
    background: rgba(245, 158, 11, 0.14);
    color: #FDE68A;
    font-family: var(--km-font-sans);
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
.banquet-queue-trigger-btn:hover,
.banquet-refresh-btn:hover {
    background: rgba(245, 158, 11, 0.28);
    border-color: #F59E0B;
    color: #FFFFFF;
}

.split-print-box {
    margin: 0 0 12px;
    padding: 11px 13px;
    border-radius: 13px;
    background: rgba(15, 9, 44, 0.78);
    border: 1.5px solid rgba(167, 139, 250, 0.28);
    text-align: left;
}
.split-print-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 9px;
}
.split-print-title-wrap {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    font-weight: 700;
    color: #F8FAFC;
}
.split-print-title-wrap i {
    color: #38BDF8;
}
.split-print-hint {
    font-size: 11.5px;
    color: #C4B5FD;
}
.split-qty-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr) auto;
    gap: 7px;
    align-items: stretch;
}
.split-qty-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 7px 6px;
    border-radius: 10px;
    border: 1.5px solid rgba(167, 139, 250, 0.28);
    background: rgba(26, 14, 76, 0.65);
    color: #E2E8F0;
    font-family: var(--km-font-sans);
    cursor: pointer;
    transition: all 0.15s ease;
}
.split-qty-chip strong {
    font-size: 13px;
    font-weight: 800;
    line-height: 1.2;
}
.split-qty-chip small {
    font-size: 10.5px;
    color: #94A3B8;
    margin-top: 2px;
}
.split-qty-chip:hover {
    border-color: #38BDF8;
    background: rgba(56, 189, 248, 0.14);
}
.split-qty-chip.active {
    border-color: #38BDF8;
    background: rgba(56, 189, 248, 0.22);
    color: #FFFFFF;
    box-shadow: 0 0 12px rgba(56, 189, 248, 0.22);
}
.split-qty-chip.active small {
    color: #7DD3FC;
}
.split-qty-stepper {
    display: inline-flex;
    align-items: center;
    border-radius: 10px;
    border: 1.5px solid rgba(167, 139, 250, 0.28);
    background: #09051D;
    overflow: hidden;
}
.qty-step-btn {
    width: 32px;
    height: 100%;
    min-height: 38px;
    border: none;
    background: rgba(255, 255, 255, 0.06);
    color: #F8FAFC;
    font-size: 16px;
    font-weight: 800;
    cursor: pointer;
    transition: background 0.15s ease;
}
.qty-step-btn:hover {
    background: rgba(56, 189, 248, 0.25);
}
.qty-step-val {
    min-width: 28px;
    text-align: center;
    font-size: 13.5px;
    font-weight: 800;
    color: #38BDF8;
}

.banquet-extra-form {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed rgba(167, 139, 250, 0.28);
}
.banquet-extra-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12.5px;
    font-weight: 600;
    color: #FDE68A;
    margin-bottom: 7px;
}
.banquet-input-row {
    display: flex;
    gap: 8px;
}
.banquet-name-input {
    flex: 1;
    min-height: 40px;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1.5px solid rgba(245, 158, 11, 0.5);
    background: #09051D;
    color: #FFFFFF;
    font-family: var(--km-font-sans);
    font-size: 13px;
    font-weight: 600;
}
.banquet-name-input:focus {
    outline: none;
    border-color: #38BDF8;
    box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
}
.banquet-save-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 14px;
    min-height: 40px;
    border-radius: 10px;
    border: 1.5px solid #F59E0B;
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #09051D;
    font-family: var(--km-font-sans);
    font-size: 12.5px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.banquet-save-btn:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
}
.banquet-file-preview-bar {
    margin-top: 6px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
    font-size: 11px;
    color: #94A3B8;
}
.banquet-filename-code {
    color: #38BDF8;
    background: rgba(56, 189, 248, 0.12);
    padding: 2px 7px;
    border-radius: 5px;
    font-family: monospace;
    font-weight: 700;
}

/* Banquet Manager Modal */
.banquet-manager-card {
    max-width: 780px !important;
    width: 94vw !important;
    max-height: 90vh;
    overflow-y: auto;
}
.pipeline-folders-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 12px;
    text-align: left;
}
.pipeline-folder-card {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 11px;
    border-radius: 11px;
    background: rgba(9, 5, 29, 0.72);
    border: 1.5px solid rgba(167, 139, 250, 0.25);
}
.pipeline-folder-card.pf-highlight {
    border-color: rgba(245, 158, 11, 0.6);
    background: rgba(245, 158, 11, 0.1);
}
.pipeline-folder-card.pf-done {
    border-color: rgba(16, 185, 129, 0.55);
    background: rgba(16, 185, 129, 0.1);
}
.pf-stage-num {
    width: 26px;
    height: 26px;
    border-radius: 7px;
    background: rgba(56, 189, 248, 0.18);
    color: #38BDF8;
    font-size: 11.5px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.pf-highlight .pf-stage-num {
    background: rgba(245, 158, 11, 0.25);
    color: #FDE68A;
}
.pf-done .pf-stage-num {
    background: rgba(16, 185, 129, 0.25);
    color: #6EE7B7;
}
.pf-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.pf-info strong {
    font-size: 11.5px;
    color: #F8FAFC;
    font-family: monospace;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pf-info small {
    font-size: 10.5px;
    color: #94A3B8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pf-count {
    font-size: 14px;
    font-weight: 800;
    color: #38BDF8;
    padding: 2px 7px;
    border-radius: 7px;
    background: rgba(56, 189, 248, 0.12);
}

.banquet-control-panel {
    padding: 12px 14px;
    border-radius: 13px;
    background: rgba(9, 5, 29, 0.8);
    border: 1.5px solid rgba(245, 158, 11, 0.4);
    margin-bottom: 12px;
    text-align: left;
}
.banquet-stats-bar {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}
.b-stat-item {
    padding: 7px 10px;
    border-radius: 9px;
    background: rgba(26, 14, 76, 0.6);
    border: 1px solid rgba(167, 139, 250, 0.2);
    display: flex;
    flex-direction: column;
}
.b-stat-item span {
    font-size: 11px;
    color: #C4B5FD;
}
.b-stat-item strong {
    font-size: 14px;
    font-weight: 800;
    color: #FDE68A;
    margin-top: 2px;
}
.banquet-mode-bar {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 10px;
}
.banquet-odd-option {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #E2E8F0;
    cursor: pointer;
}
.banquet-odd-option input[type="radio"] {
    accent-color: #F59E0B;
}
.banquet-run-batch-btn {
    width: 100%;
    min-height: 44px;
    border-radius: 11px;
    border: 1.5px solid #10B981;
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: #FFFFFF;
    font-family: var(--km-font-sans);
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.15s ease;
}
.banquet-run-batch-btn:hover:not(:disabled) {
    filter: brightness(1.1);
}
.banquet-run-batch-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: rgba(100, 116, 139, 0.25);
    border-color: rgba(148, 163, 184, 0.3);
}

.banquet-lists-container {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 10px;
    text-align: left;
}
.banquet-section-heading {
    font-size: 12.5px;
    font-weight: 700;
    color: #E2E8F0;
    margin: 0 0 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.banquet-items-list {
    max-height: 220px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 7px;
    padding-right: 4px;
}
.banquet-empty-msg {
    padding: 16px 12px;
    border-radius: 10px;
    background: rgba(9, 5, 29, 0.5);
    border: 1px dashed rgba(167, 139, 250, 0.25);
    color: #94A3B8;
    font-size: 12px;
    text-align: center;
}
.banquet-item-row {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 7px 10px;
    border-radius: 10px;
    background: rgba(26, 14, 76, 0.65);
    border: 1px solid rgba(167, 139, 250, 0.28);
}
.banquet-item-thumb {
    width: 28px;
    height: 46px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    flex-shrink: 0;
}
.banquet-item-meta {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.banquet-item-meta strong {
    font-size: 12.5px;
    color: #F8FAFC;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.banquet-item-meta code {
    font-size: 10.5px;
    color: #38BDF8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.banquet-item-copies-badge {
    padding: 3px 8px;
    border-radius: 6px;
    background: rgba(245, 158, 11, 0.22);
    color: #FDE68A;
    font-size: 11.5px;
    font-weight: 800;
    white-space: nowrap;
}
.banquet-item-del-btn {
    width: 28px;
    height: 28px;
    border-radius: 7px;
    border: 1px solid rgba(239, 68, 68, 0.4);
    background: rgba(239, 68, 68, 0.15);
    color: #FCA5A5;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.banquet-item-del-btn:hover {
    background: rgba(239, 68, 68, 0.35);
    color: #FFFFFF;
}
@media (max-width: 720px) {
    .pipeline-folders-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .banquet-lists-container {
        grid-template-columns: 1fr;
    }
    .split-qty-row {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
