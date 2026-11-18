/* globals photoBooth photoboothTools remoteBuzzerClient csrf */
/**
 * Photobooth Kiosk Controller:
 * Handles 2-Strip Print Pair Queue for Canon SELPHY CP1500,
 * Full-HD Framed GIF Viewer, and QR Code Delivery.
 * Color grading is processed automatically upstream by Capture One.
 */
(function ($) {
    'use strict';

    let currentFilename = '';
    let currentSessionId = '';
    let isViewingGif = false;
    let qrOpenSeq = 0;
    let qrCountdownTimer = null;
    let lastPairStatus = { count: 0, required: 2, can_print: false, pending: [] };

    const $resultPage = $('.stage[data-stage="result"]');
    const $statusPill = $('#preset-status');
    const $pairPill = $('#pair-queue-pill');
    const $pairText = $('#pair-queue-text');
    const $btnToggleGif = $('#btn-toggle-gif-preview');

    function setStatus(text, isLoading) {
        if ($statusPill.length) {
            $statusPill.text(text);
            $statusPill.toggleClass('loading', Boolean(isLoading));
        }
    }

    function extractFilename() {
        let fn = $resultPage.attr('data-img');
        if (!fn && typeof photoBooth !== 'undefined' && photoBooth.filename) {
            fn = photoBooth.filename;
        }
        if (!fn) {
            const rawStyle = $resultPage.css('--stage-background-image') || '';
            const match = rawStyle.match(/url\(["']?([^"')?#]+)[^"')]*["']?\)/);
            if (match && match[1]) {
                const parts = match[1].split('/');
                fn = parts[parts.length - 1];
            }
        }
        return fn ? fn.trim() : '';
    }

    function getSessionId(filename) {
        const fn = filename || currentFilename;
        if (!fn) {
            return '';
        }
        return fn.replace(/\.[^/.]+$/, '').replace(/^(orig_|raw_pose_\d+_|preview_base_)/, '');
    }

    function updatePairQueueUi(pair) {
        if (!pair || typeof pair !== 'object') {
            return;
        }
        lastPairStatus = pair;
        const count = parseInt(pair.count || 0, 10);
        const canPrint = Boolean(pair.can_print);
        const pending = Array.isArray(pair.pending) ? pair.pending : [];

        // Update Dock Pill & Button Label
        if (canPrint) {
            $pairPill.addClass('ready');
            $pairText.text('In ghép: 2/2 ✓');
            $('#btn-preset-confirm-label').text('In ảnh ngay (2/2)');
        } else {
            $pairPill.removeClass('ready');
            $pairText.text('In ghép: ' + count + '/2');
            $('#btn-preset-confirm-label').text('Hoàn tất (' + count + '/2)');
        }

        // Update Modal Slot 1 (Left Half)
        const $slotLeft = $('#pair-slot-left');
        const $slotLeftImg = $('#pair-slot-left-img');
        const $slotLeftPh = $('#pair-slot-left-placeholder');
        const $slotLeftName = $('#pair-slot-left-name');
        const $slotLeftSub = $('#pair-slot-left-sub');

        if (pending[0]) {
            $slotLeft.addClass('filled');
            const leftUrl = pending[0].image_url || ('data/images/' + pending[0].filename);
            $slotLeftImg.attr('src', leftUrl).removeClass('hidden');
            $slotLeftPh.addClass('hidden');
            $slotLeftName.text('#' + pending[0].session_id);
            $slotLeftSub.text('Khung: ' + (pending[0].layout === 'ptb4-2' ? 'FramePTB4_2' : 'FramePTB4_1') + ' ✓');
        } else {
            $slotLeft.removeClass('filled');
            $slotLeftImg.attr('src', '').addClass('hidden');
            $slotLeftPh.removeClass('hidden');
            $slotLeftName.text('Chưa có ảnh lượt 1');
            $slotLeftSub.text('Chờ lượt chụp');
        }

        // Update Modal Slot 2 (Right Half)
        const $slotRight = $('#pair-slot-right');
        const $slotRightImg = $('#pair-slot-right-img');
        const $slotRightPh = $('#pair-slot-right-placeholder');
        const $slotRightName = $('#pair-slot-right-name');
        const $slotRightSub = $('#pair-slot-right-sub');

        if (pending[1]) {
            $slotRight.addClass('filled');
            const rightUrl = pending[1].image_url || ('data/images/' + pending[1].filename);
            $slotRightImg.attr('src', rightUrl).removeClass('hidden');
            $slotRightPh.addClass('hidden');
            $slotRightName.text('#' + pending[1].session_id);
            $slotRightSub.text('Khung: ' + (pending[1].layout === 'ptb4-2' ? 'FramePTB4_2' : 'FramePTB4_1') + ' ✓');
        } else {
            $slotRight.removeClass('filled');
            $slotRightImg.attr('src', '').addClass('hidden');
            $slotRightPh.removeClass('hidden');
            $slotRightName.text(count === 1 ? 'Chờ chụp lượt 2...' : 'Chưa có ảnh lượt 2');
            $slotRightSub.text(count === 1 ? 'Chụp thêm để mở khóa In' : 'Chờ lượt chụp');
        }

        // Update Print Button State
        const $printBtn = $('#modal-btn-print');
        const $printTitle = $('#modal-print-title');
        const $printSub = $('#modal-print-sub');

        if (canPrint) {
            $printBtn.removeClass('pair-locked').prop('disabled', false);
            $printTitle.text('In ảnh ghép (2/2) • Canon CP1500');
            $printSub.text('Ghép Nửa Trái + Nửa Phải trên khổ giấy FramePTB');
        } else {
            $printBtn.addClass('pair-locked').prop('disabled', true);
            $printTitle.text(count === 1 ? 'Chờ đủ 2 dải ảnh để in (1/2)' : 'Chờ đủ 2 dải ảnh để in (0/2)');
            $printSub.text('Hãy bấm "Chụp lượt tiếp theo" bên dưới để đủ 2 tấm ghép in');
        }
    }

    function refreshPairQueueStatus(registerFilename, callback) {
        const statusUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/printStatus.php'
            : 'api/printStatus.php';

        $.ajax({
            url: statusUrl,
            method: 'GET',
            data: {
                action: 'pair_status',
                register_file: registerFilename || '',
                _t: Date.now()
            },
            dataType: 'json'
        }).done(function (res) {
            if (res && res.pair) {
                updatePairQueueUi(res.pair);
            }
            if (typeof callback === 'function') {
                callback(res && res.pair ? res.pair : null);
            }
        }).fail(function () {
            if (typeof callback === 'function') {
                callback(null);
            }
        });
    }

    function initResultDockForImage() {
        currentFilename = extractFilename();
        currentSessionId = getSessionId(currentFilename);
        isViewingGif = false;

        $('#result-gif-viewer').addClass('hidden').attr('aria-hidden', 'true');
        $('#result-gif-img').attr('src', '');
        $btnToggleGif.removeClass('active').html('<i class="fa fa-film" aria-hidden="true"></i> <span>Xem GIF</span>');
        $('#pair-print-feedback-banner').addClass('hidden');

        if (currentFilename) {
            const baseDir = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.images)
                ? environment.publicFolders.images
                : 'data/images';
            const freshUrl = baseDir + '/' + currentFilename + '?v=' + Date.now();
            $resultPage.css('--stage-background-image', `url(${freshUrl})`);

            // Register strip and refresh queue
            refreshPairQueueStatus(currentFilename);
            triggerBackgroundCloudSync(currentSessionId || currentFilename);
        } else {
            refreshPairQueueStatus('');
        }

        $('#desktop-preset-bar').removeClass('hidden');
        setStatus('Sẵn sàng', false);
    }

    // Toggle Framed Time-Lapse GIF Viewer
    function toggleGifViewer() {
        if (!currentFilename) {
            currentFilename = extractFilename();
        }
        currentSessionId = getSessionId(currentFilename);

        if (isViewingGif) {
            isViewingGif = false;
            $('#result-gif-viewer').addClass('hidden').attr('aria-hidden', 'true');
            $btnToggleGif.removeClass('active').html('<i class="fa fa-film" aria-hidden="true"></i> <span>Xem GIF</span>');
        } else {
            isViewingGif = true;
            const baseDir = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.images)
                ? environment.publicFolders.images
                : 'data/images';
            const gifUrl = baseDir + '/' + currentSessionId + '.gif?v=' + Date.now();
            $('#result-gif-img').attr('src', gifUrl);
            $('#result-gif-viewer').removeClass('hidden').attr('aria-hidden', 'false');
            $btnToggleGif.addClass('active').html('<i class="fa fa-times" aria-hidden="true"></i> <span>Đóng GIF</span>');
        }
    }

    $(document).on('click', '#btn-toggle-gif-preview', function (e) {
        e.preventDefault();
        keepAlive();
        toggleGifViewer();
    });

    $(document).on('click', '#btn-close-gif-viewer', function (e) {
        e.preventDefault();
        keepAlive();
        if (isViewingGif) {
            toggleGifViewer();
        }
    });

    // Retake button on Result Screen
    $(document).on('click', '#btn-preset-retake', function (e) {
        e.preventDefault();
        keepAlive();
        if (confirm('Bạn muốn chụp lại lượt chụp này?')) {
            const statusUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
                ? environment.publicFolders.api + '/printStatus.php'
                : 'api/printStatus.php';
            const discardFile = currentFilename || extractFilename();
            if (discardFile) {
                $.ajax({
                    url: statusUrl,
                    method: 'POST',
                    data: { action: 'remove_strip', file: discardFile }
                });
            }
            if (typeof photoBooth !== 'undefined') {
                photoBooth.takingPic = false;
                photoBooth.nextCollageNumber = 0;
                photoBooth.reset();
                if (photoBooth.collageLayout) {
                    photoBooth.thrill('collage');
                } else {
                    photoBooth.thrill('photo');
                }
            } else {
                location.assign('./');
            }
        }
    });

    // Direct QR button on dock -> open Guest QR Delivery Modal
    $(document).on('click', '#btn-preset-qr, #modal-btn-qr', function (e) {
        e.preventDefault();
        keepAlive();
        openQrDeliveryModal();
    });

    // Confirm button on dock -> open Confirm Modal
    $(document).on('click', '#btn-preset-confirm', function (e) {
        e.preventDefault();
        keepAlive();
        refreshPairQueueStatus(currentFilename || extractFilename());
        $('#confirmSuccessModal').removeClass('hidden').attr('aria-hidden', 'false');
    });

    // Modal Close
    $(document).on('click', '#modal-btn-close, .confirm-modal-backdrop', function (e) {
        e.preventDefault();
        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');
    });

    // Print Button inside Modal -> Trigger 2-Strip Merged Print on Canon CP1500
    $(document).on('click', '#modal-btn-print', function (e) {
        e.preventDefault();
        keepAlive();

        const $btn = $(this);
        if ($btn.hasClass('pair-locked') || $btn.prop('disabled')) {
            return;
        }

        $btn.prop('disabled', true).addClass('pair-locked');
        $('#modal-print-title').text('Đang ghép 2 dải ảnh & gửi máy in Canon CP1500...');

        const printUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/print.php'
            : 'api/print.php';

        const targetFile = currentFilename || extractFilename();
        const queryData = {
            filename: targetFile,
            copies: 1,
            _t: Date.now()
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            queryData[csrf.key] = csrf.token;
        }

        $.ajax({
            url: printUrl,
            method: 'GET',
            data: queryData,
            dataType: 'json'
        }).done(function (res) {
            if (res && (res.status === 'ok' || res.status === 'queued' || res.spooled)) {
                $('#pair-print-feedback-banner').removeClass('hidden');
                $('#pair-print-feedback-text').text('Đã gửi lệnh in 2 dải ảnh thành công ra máy in Canon SELPHY CP1500! Hàng đợi đã sẵn sàng cho 2 lượt tiếp theo.');
                if (res.pair) {
                    updatePairQueueUi(res.pair);
                } else {
                    refreshPairQueueStatus('');
                }
            } else {
                alert(res.error || 'Có lỗi khi gửi lệnh in.');
                $btn.prop('disabled', false).removeClass('pair-locked');
                $('#modal-print-title').text('Thử in lại (2/2)');
            }
        }).fail(function (xhr) {
            alert('Lỗi kết nối máy in: ' + (xhr.statusText || 'Vui lòng kiểm tra lại cáp kết nối máy in'));
            $btn.prop('disabled', false).removeClass('pair-locked');
            $('#modal-print-title').text('Thử in lại (2/2)');
        });
    });

    // Home button: Return to Start Screen for Next Strip
    $(document).on('click', '#modal-btn-home', function (e) {
        e.preventDefault();
        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');
        window.location.assign('./');
    });

    // Gallery button: Open Photobooth Gallery
    $(document).on('click', '#modal-btn-gallery', function (e) {
        e.preventDefault();
        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');
        const $btn = $('[data-command="gallerybtn"], .gallerybtn');
        if ($btn.length) {
            $btn.trigger('click');
        }
    });

    // QR Delivery Modal Logic
    function openQrDeliveryModal(explicitFilename) {
        if (!currentFilename && $resultPage.hasClass('stage--active')) {
            currentFilename = extractFilename();
        }
        currentSessionId = getSessionId(currentFilename);

        const isInGallery = $('#gallery').hasClass('gallery--open') || $('.pswp--open').length > 0;
        const isFromStartScreen = !$resultPage.hasClass('stage--active') || isInGallery;
        const targetFile = explicitFilename || ((!isFromStartScreen && currentFilename) ? currentFilename : 'latest');
        const openSeq = ++qrOpenSeq;

        $('#confirmSuccessModal, #cloudR2SetupModal').addClass('hidden').attr('aria-hidden', 'true');
        const $modal = $('#qrDeliveryModal');
        const $img = $('#qr-modal-img');
        const $spinner = $('#qr-loading-spinner');
        const $badge = $('#qr-mode-badge');
        const $instruction = $('#qr-modal-instruction');
        const $link = $('#qr-direct-link');
        const $countVal = $('#qr-countdown-val');
        const $countBox = $('#qr-countdown-box');
        const $finishBtn = $('#qr-btn-finish');

        $img.hide().attr('src', '');
        $spinner.show();
        $link.attr('href', '#').text('Đang tải liên kết nhận ảnh...');
        $badge.removeClass('local').addClass('cloud').html('<i class="fa fa-cloud" aria-hidden="true"></i> <span>Đang khởi tạo mã QR...</span>');

        $modal.removeClass('hidden').attr('aria-hidden', 'false');

        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        $.ajax({
            url: netApiUrl,
            method: 'GET',
            data: { file: targetFile, _t: Date.now() },
            dataType: 'json'
        }).done(function (data) {
            if (openSeq !== qrOpenSeq) {
                return;
            }
            if (data && data.url) {
                const qrSrc = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
                    ? environment.publicFolders.api + '/qrcode.php?url=' + encodeURIComponent(data.url) + '&_t=' + Date.now()
                    : 'api/qrcode.php?url=' + encodeURIComponent(data.url) + '&_t=' + Date.now();

                $img.attr('src', qrSrc).on('load', function () {
                    if (openSeq !== qrOpenSeq) {
                        return;
                    }
                    $spinner.hide();
                    $img.show();
                });

                $link.attr('href', data.url).html('<i class="fa fa-external-link" aria-hidden="true"></i> <span>' + data.url + '</span>');

                if (data.mode === 'venue_wifi') {
                    $badge.removeClass('cloud').addClass('local').html('<i class="fa fa-wifi" aria-hidden="true"></i> <span>Wi-Fi Nội Bộ Kiosk</span>');
                    $instruction.text('Quý khách vui lòng kết nối vào Wi-Fi của sự kiện để quét mã QR và tải ảnh trực tiếp từ máy Kiosk.');
                } else {
                    $badge.removeClass('local').addClass('cloud').html('<i class="fa fa-cloud" aria-hidden="true"></i> <span>Cloud R2 (4G / 5G / Wi-Fi)</span>');
                    $instruction.text('Mở camera điện thoại (dùng 4G/5G hoặc Wi-Fi bất kỳ) hướng vào mã bên dưới để nhận cả Ảnh ghép khung & Ảnh động GIF.');
                }
            } else {
                $spinner.html('<span style="color:#ef4444;">Không thể tạo mã QR. Vui lòng thử lại.</span>');
            }
        }).fail(function () {
            if (openSeq !== qrOpenSeq) {
                return;
            }
            $spinner.html('<span style="color:#ef4444;">Lỗi kết nối tạo mã QR.</span>');
        });

        // Countdown timer (45s on result screen, 60s from start screen)
        clearInterval(qrCountdownTimer);
        let secondsLeft = isFromStartScreen ? 60 : 45;
        $countVal.text(secondsLeft);
        $countBox.show();

        if (isFromStartScreen) {
            $finishBtn.html('<i class="fa fa-check" aria-hidden="true"></i> <span>Đã quét xong • Đóng cửa sổ</span>');
        } else {
            $finishBtn.html('<i class="fa fa-check" aria-hidden="true"></i> <span>Đã quét xong • Mời lượt chụp tiếp theo</span>');
        }

        qrCountdownTimer = setInterval(function () {
            secondsLeft--;
            $countVal.text(secondsLeft);
            if (secondsLeft <= 0) {
                clearInterval(qrCountdownTimer);
                closeQrDeliveryModal();
                if (!isFromStartScreen) {
                    window.location.assign('./');
                }
            }
        }, 1000);
    }

    function closeQrDeliveryModal() {
        qrOpenSeq++;
        clearInterval(qrCountdownTimer);
        $('#qrDeliveryModal').addClass('hidden').attr('aria-hidden', 'true');
    }

    $(document).on('click', '#qr-btn-finish', function (e) {
        e.preventDefault();
        closeQrDeliveryModal();
        if ($resultPage.hasClass('stage--active')) {
            window.location.assign('./');
        }
    });

    $(document).on('click', '#qr-btn-close, #qr-modal-backdrop', function (e) {
        e.preventDefault();
        closeQrDeliveryModal();
    });

    // Cloud R2 Setup Modal Logic
    function openCloudR2SetupModal() {
        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        $.ajax({
            url: netApiUrl,
            method: 'GET',
            data: { _t: Date.now() },
            dataType: 'json'
        }).done(function (data) {
            if (data) {
                $('input[name="qr_delivery_mode"][value="' + (data.mode || 'cloud_web') + '"]').prop('checked', true).trigger('change');
                if (data.cloud_url) {
                    $('#input-cloud-url').val(data.cloud_url);
                }
                if (data.cloud_key) {
                    $('#input-cloud-key').val(data.cloud_key);
                }
                if (data.venue_ip) {
                    $('#input-venue-ip').val(data.venue_ip);
                }
            }
            $('#cloudR2SetupModal').removeClass('hidden').attr('aria-hidden', 'false');
        });
    }

    $(document).on('click', '#btn-toggle-qr-settings', function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeQrDeliveryModal();
        openCloudR2SetupModal();
    });

    $(document).on('click', '#r2-setup-btn-close, #r2-setup-backdrop', function (e) {
        e.preventDefault();
        $('#cloudR2SetupModal').addClass('hidden').attr('aria-hidden', 'true');
    });

    $(document).on('change', 'input[name="qr_delivery_mode"]', function () {
        const val = $(this).val();
        $('#qr-fields-venue').toggle(val === 'venue_wifi');
        $('#qr-fields-cloud').toggle(val === 'cloud_web');
    });

    $(document).on('click', '#btn-save-qr-network', function (e) {
        e.preventDefault();
        const selectedMode = $('input[name="qr_delivery_mode"]:checked').val() || 'cloud_web';
        const postData = {
            action: 'save',
            mode: selectedMode,
            cloud_url: ($('#input-cloud-url').val() || '').trim(),
            cloud_key: ($('#input-cloud-key').val() || '').trim(),
            venue_ip: ($('#input-venue-ip').val() || '').trim()
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }
        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        $.ajax({
            url: netApiUrl,
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function (res) {
            if (res && res.success) {
                $('#qr-net-save-msg').text('Đã lưu cấu hình ✓');
                setTimeout(function () {
                    $('#cloudR2SetupModal').addClass('hidden').attr('aria-hidden', 'true');
                }, 800);
            }
        });
    });

    // Background Cloud Sync Trigger
    function triggerBackgroundCloudSync(sessionIdOrFilename, callback) {
        if (!sessionIdOrFilename) {
            if (typeof callback === 'function') callback(null);
            return;
        }
        const syncUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/syncRunner.php'
            : 'api/syncRunner.php';

        const cleanSession = getSessionId(sessionIdOrFilename);
        const postData = {
            action: 'sync_session',
            session_id: cleanSession
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }

        $.ajax({
            url: syncUrl,
            method: 'POST',
            data: postData,
            dataType: 'json',
            timeout: 8000
        }).done(function (res) {
            if (typeof callback === 'function') callback(res);
        }).fail(function () {
            if (typeof callback === 'function') callback(null);
        });
    }

    function keepAlive() {
        if (typeof photoBooth !== 'undefined' && typeof photoBooth.resetTimeOut === 'function') {
            photoBooth.resetTimeOut();
        }
    }

    // Result stage observer
    let lastActiveImg = null;
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.attributeName === 'class' || mutation.attributeName === 'data-img') {
                if ($resultPage.hasClass('stage--active')) {
                    const currentImg = $resultPage.attr('data-img') || extractFilename();
                    if (currentImg && currentImg !== lastActiveImg) {
                        lastActiveImg = currentImg;
                        initResultDockForImage();
                    }
                } else {
                    lastActiveImg = null;
                }
            }
        });
    });

    $(document).ready(function () {
        $('#confirmSuccessModal, #qrDeliveryModal, #cloudR2SetupModal').appendTo(document.body);

        if ($resultPage.length) {
            observer.observe($resultPage[0], { attributes: true, attributeFilter: ['class', 'data-img'] });
            if ($resultPage.hasClass('stage--active')) {
                initResultDockForImage();
            }
        }
    });

    // Public API hooks
    window.updatePairQueueUi = updatePairQueueUi;
    window.refreshPairQueueStatus = refreshPairQueueStatus;
    window.triggerBackgroundCloudSync = triggerBackgroundCloudSync;

})(jQuery);
