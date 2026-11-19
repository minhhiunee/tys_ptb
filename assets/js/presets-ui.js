/* globals photoBooth photoboothTools csrf */
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
    let selectedCopies = 1;
    let banquetSavedForCurrentSession = false;

    const $resultPage = $('.stage[data-stage="result"]');
    const $statusPill = $('#preset-status');
    const $pairPill = $('#pair-queue-pill');
    const $pairText = $('#pair-queue-text');
    const $btnToggleGif = $('#btn-toggle-gif-preview');

    function sanitizeClientNameForPreview(raw) {
        const str = (raw || '').trim();
        if (!str) {
            return 'KhachHang';
        }
        const normalized = str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D');
        const cleaned = normalized.replace(/[^A-Za-z0-9\s_-]+/g, '');
        const parts = cleaned.trim().split(/[\s_-]+/).filter(Boolean);
        if (!parts.length) {
            return 'KhachHang';
        }
        return parts.map(function (p) {
            return p.charAt(0).toUpperCase() + p.slice(1);
        }).join('').slice(0, 40) || 'KhachHang';
    }

    function updateBanquetFilenamePreview() {
        const sess = currentSessionId || getSessionId(currentFilename) || 'session';
        const extra = Math.max(1, selectedCopies - 1);
        const rawName = $('#banquet-customer-name-input').val() || '';
        const safeName = sanitizeClientNameForPreview(rawName);
        $('#banquet-filename-preview').text(sess + '_' + safeName + '_' + extra + 'x.jpg');
    }

    function setSplitCopies(copies) {
        selectedCopies = Math.max(1, Math.min(20, parseInt(copies || 1, 10)));
        $('#split-qty-custom-val').text(String(selectedCopies));
        $('.split-qty-chip').each(function () {
            const c = parseInt($(this).attr('data-copies') || '0', 10);
            $(this).toggleClass('active', c === selectedCopies);
        });

        if (selectedCopies > 1) {
            const extra = selectedCopies - 1;
            $('#banquet-extra-count-text').text(String(extra));
            $('#btn-banquet-extra-num').text(String(extra));
            $('#banquet-extra-form').removeClass('hidden');
            updateBanquetFilenamePreview();
        } else {
            $('#banquet-extra-form').addClass('hidden');
        }
    }

    function updateBanquetBadges(banquetInfo) {
        if (!banquetInfo || typeof banquetInfo !== 'object') {
            return;
        }
        const totalStrips = parseInt(banquetInfo.total_pending_strips || 0, 10);
        $('#banquet-pending-badge').text(String(totalStrips));
        $('#dock-banquet-text').text('In tiệc: ' + totalStrips + ' dải');
    }

    function setStatus(text, isLoading) {
        if ($statusPill.length) {
            $statusPill.text(text);
            $statusPill.toggleClass('loading', Boolean(isLoading));
        }
    }

    function ajaxPost(opts) {
        if (typeof photoboothTools !== 'undefined' && typeof photoboothTools.ajaxWithCsrf === 'function') {
            return photoboothTools.ajaxWithCsrf(opts);
        }
        if (typeof csrf !== 'undefined' && csrf.key && opts.data) {
            opts.data[csrf.key] = csrf.token;
        }
        return $.ajax(opts);
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

        if (pair.banquet) {
            updateBanquetBadges(pair.banquet);
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
        banquetSavedForCurrentSession = false;
        $('#banquet-customer-name-input').val('');
        setSplitCopies(1);

        $('#result-gif-viewer').addClass('hidden').attr('aria-hidden', 'true');
        $('#result-gif-img').attr('src', '');
        $resultPage.removeClass('is-viewing-gif');
        $btnToggleGif.removeClass('active').html('<i class="fa fa-film" aria-hidden="true"></i> <span>Xem ảnh động GIF</span>');
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

    let gifPollTimer = null;
    let gifPollAttempts = 0;

    function stopGifPolling() {
        if (gifPollTimer) {
            clearTimeout(gifPollTimer);
            gifPollTimer = null;
        }
        gifPollAttempts = 0;
    }

    // Toggle Framed Time-Lapse GIF Viewer
    function toggleGifViewer() {
        if (!currentFilename) {
            currentFilename = extractFilename();
        }
        currentSessionId = getSessionId(currentFilename);

        if (isViewingGif) {
            isViewingGif = false;
            stopGifPolling();
            $resultPage.removeClass('is-viewing-gif');
            $('#result-gif-viewer').addClass('hidden').attr('aria-hidden', 'true');
            $('#result-gif-img').addClass('hidden').attr('src', '');
            $('#result-gif-loading').addClass('hidden');
            $btnToggleGif.removeClass('active').html('<i class="fa fa-film" aria-hidden="true"></i> <span>Xem ảnh động GIF</span>');
        } else {
            isViewingGif = true;
            stopGifPolling();
            const baseDir = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.images)
                ? environment.publicFolders.images
                : 'data/images';
            const gifUrl = baseDir + '/' + currentSessionId + '.gif';

            $('#result-gif-viewer').removeClass('hidden').attr('aria-hidden', 'false');
            $('#result-gif-img').addClass('hidden').attr('src', '');
            $('#result-gif-loading').removeClass('hidden').html(`
                <div class="result-gif-spinner"></div>
                <p class="result-gif-loading-text">Đang tạo ảnh động tua nhanh HD...</p>
                <span class="result-gif-loading-sub">Hệ thống đang ghép khung và nén ảnh GIF (khoảng 3-5 giây)</span>
            `);
            $btnToggleGif.addClass('active').html('<i class="fa fa-times" aria-hidden="true"></i> <span>Đóng xem ảnh GIF</span>');

            function pollForGif() {
                if (!isViewingGif) {
                    return;
                }
                const testImg = new Image();
                testImg.onload = function () {
                    if (!isViewingGif) {
                        return;
                    }
                    $('#result-gif-loading').addClass('hidden');
                    $('#result-gif-img').attr('src', testImg.src).removeClass('hidden');
                    $resultPage.addClass('is-viewing-gif');
                };
                testImg.onerror = function () {
                    if (!isViewingGif) {
                        return;
                    }
                    gifPollAttempts++;
                    if (gifPollAttempts < 20) {
                        gifPollTimer = setTimeout(pollForGif, 800);
                    } else {
                        $('#result-gif-loading').html(`
                            <i class="fa fa-info-circle" style="font-size:32px;color:#38BDF8;margin-bottom:8px;"></i>
                            <p class="result-gif-loading-text">Ảnh động chưa sẵn sàng</p>
                            <span class="result-gif-loading-sub">Hệ thống vẫn đang tạo file trong nền. Bạn có thể bấm thử lại bên dưới.</span>
                            <button type="button" id="btn-retry-gif" class="result-gif-retry-btn">Thử lại</button>
                        `);
                    }
                };
                testImg.src = gifUrl + '?v=' + Date.now();
            }

            pollForGif();
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

    $(document).on('click', '#btn-retry-gif', function (e) {
        e.preventDefault();
        keepAlive();
        gifPollAttempts = 0;
        $('#result-gif-loading').html(`
            <div class="result-gif-spinner"></div>
            <p class="result-gif-loading-text">Đang tải lại ảnh động HD...</p>
            <span class="result-gif-loading-sub">Vui lòng đợi giây lát</span>
        `);
        const baseDir = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.images)
            ? environment.publicFolders.images
            : 'data/images';
        const gifUrl = baseDir + '/' + currentSessionId + '.gif?v=' + Date.now();
        const testImg = new Image();
        testImg.onload = function () {
            $('#result-gif-loading').addClass('hidden');
            $('#result-gif-img').attr('src', testImg.src).removeClass('hidden');
        };
        testImg.onerror = function () {
            alert('Ảnh động chưa xử lý xong, vui lòng đợi thêm chút nữa.');
        };
        testImg.src = gifUrl;
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
                ajaxPost({
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
        if (lastPairStatus && typeof updatePairQueueUi === 'function') {
            updatePairQueueUi(lastPairStatus);
        }
        refreshPairQueueStatus(currentFilename || extractFilename());
        $('#confirmSuccessModal').removeClass('hidden').attr('aria-hidden', 'false');
    });

    // Modal Close (only close confirmSuccessModal when its own backdrop or close button is clicked)
    $(document).on('click', '#modal-btn-close, #confirmSuccessModal > .confirm-modal-backdrop', function (e) {
        e.preventDefault();
        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');
    });

    // Split Print Queue: Quantity Chips & Stepper
    $(document).on('click', '.split-qty-chip', function (e) {
        e.preventDefault();
        keepAlive();
        const c = parseInt($(this).attr('data-copies') || '1', 10);
        banquetSavedForCurrentSession = false;
        setSplitCopies(c);
    });

    $(document).on('click', '#btn-qty-minus', function (e) {
        e.preventDefault();
        keepAlive();
        banquetSavedForCurrentSession = false;
        setSplitCopies(Math.max(1, selectedCopies - 1));
    });

    $(document).on('click', '#btn-qty-plus', function (e) {
        e.preventDefault();
        keepAlive();
        banquetSavedForCurrentSession = false;
        setSplitCopies(Math.min(20, selectedCopies + 1));
    });

    $(document).on('input', '#banquet-customer-name-input', function () {
        keepAlive();
        banquetSavedForCurrentSession = false;
        updateBanquetFilenamePreview();
    });

    // Save N-1 extra copies into 05_print_banquet_pending (and keep 1 copy in 04_print_instant)
    $(document).on('click', '#btn-save-banquet-split', function (e) {
        e.preventDefault();
        keepAlive();

        if (selectedCopies <= 1) {
            return;
        }

        const customerName = ($('#banquet-customer-name-input').val() || '').trim();
        if (!customerName) {
            alert('Vui lòng nhập Tên của bạn (hoặc Số bàn) để đánh dấu file ảnh in thêm!');
            $('#banquet-customer-name-input').trigger('focus');
            return;
        }

        const targetFile = currentFilename || extractFilename();
        if (!targetFile) {
            alert('Không xác định được file ảnh hiện tại.');
            return;
        }

        const $saveBtn = $(this);
        $saveBtn.prop('disabled', true);

        const banquetUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/banquetPrint.php'
            : 'api/banquetPrint.php';

        ajaxPost({
            url: banquetUrl,
            method: 'POST',
            data: {
                action: 'enqueue',
                filename: targetFile,
                copies: selectedCopies,
                customer_name: customerName
            },
            dataType: 'json'
        }).done(function (res) {
            $saveBtn.prop('disabled', false);
            if (res && res.status === 'ok') {
                banquetSavedForCurrentSession = true;
                $('#pair-print-feedback-banner').removeClass('hidden');
                $('#pair-print-feedback-text').text(
                    res.message || ('Đã giữ 1 bản in ngay và đánh dấu ' + (selectedCopies - 1) + ' bản vào thư mục chờ in tiệc!')
                );
                if (res.pair) {
                    updatePairQueueUi(res.pair);
                }
                if (res.banquet) {
                    updateBanquetBadges(res.banquet);
                }
            } else {
                alert((res && res.error) ? res.error : 'Không thể lưu vào hàng đợi in tiệc.');
            }
        }).fail(function (xhr) {
            $saveBtn.prop('disabled', false);
            const errJson = xhr && xhr.responseJSON;
            alert((errJson && errJson.error) ? errJson.error : 'Lỗi kết nối khi lưu hàng đợi in tiệc.');
        });
    });

    // Print Button inside Modal -> Trigger 2-Strip Merged Print on Canon CP1500
    $(document).on('click', '#modal-btn-print', function (e) {
        e.preventDefault();
        keepAlive();

        const $btn = $(this);
        if ($btn.hasClass('pair-locked') || $btn.prop('disabled')) {
            return;
        }

        let copiesToSend = 1;
        let customerNameToSend = '';

        if (selectedCopies > 1 && !banquetSavedForCurrentSession) {
            customerNameToSend = ($('#banquet-customer-name-input').val() || '').trim();
            if (!customerNameToSend) {
                alert('Bạn đang chọn in ' + selectedCopies + ' bản. Vui lòng nhập Tên khách hàng để đánh dấu ' + (selectedCopies - 1) + ' bản in thêm lúc tiệc!');
                $('#banquet-customer-name-input').trigger('focus');
                return;
            }
            copiesToSend = selectedCopies;
        }

        $btn.prop('disabled', true).addClass('pair-locked');
        $('#modal-print-title').text('Đang ghép 2 dải ảnh & gửi máy in Canon CP1500...');

        const printUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/print.php'
            : 'api/print.php';

        const targetFile = currentFilename || extractFilename();
        const queryData = {
            filename: targetFile,
            copies: copiesToSend,
            customer_name: customerNameToSend,
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
                banquetSavedForCurrentSession = true;
                $('#pair-print-feedback-banner').removeClass('hidden');
                if (res.banquet_enqueued) {
                    $('#pair-print-feedback-text').text(
                        'Đã gửi in ngay 1 bản (ghép 2 dải) ra máy in Canon CP1500 và lưu ' +
                        res.banquet_enqueued.remaining_copies + ' bản in thêm của [' +
                        res.banquet_enqueued.customer_name + '] vào 05_print_banquet_pending (' +
                        res.banquet_enqueued.banquet_file + ')!'
                    );
                } else {
                    $('#pair-print-feedback-text').text('Đã gửi lệnh in 2 dải ảnh thành công ra máy in Canon SELPHY CP1500! Đã chuyển ảnh vào 06_printed.');
                }
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

    // =========================================================================
    // BANQUET EXTRA-PRINT & SEQUENTIAL FOLDER PIPELINE MANAGER
    // =========================================================================
    function renderBanquetManagerUi(banquetData) {
        if (!banquetData || typeof banquetData !== 'object') {
            return;
        }
        updateBanquetBadges(banquetData);

        // 1. Update 6 Sequential Pipeline Folder counts
        const folders = Array.isArray(banquetData.pipeline_folders) ? banquetData.pipeline_folders : [];
        folders.forEach(function (f) {
            if (f && f.stage) {
                $('#pf-count-' + f.stage).text(String(f.count || 0));
            }
        });

        // 2. Update Banquet Stats
        const pendingCustomers = parseInt(banquetData.pending_customers || 0, 10);
        const totalStrips = parseInt(banquetData.total_pending_strips || 0, 10);
        const totalSheets = parseInt(banquetData.total_sheets_needed || 0, 10);
        const evenPairs = parseInt(banquetData.even_pairs_count || 0, 10);

        $('#b-stat-customers').text(pendingCustomers + ' khách');
        $('#b-stat-strips').text(totalStrips + ' dải');
        $('#b-stat-sheets').text(totalSheets + ' tờ 4x6"');

        const oddMode = $('input[name="banquet_odd_mode"]:checked').val() || 'duplicate';
        const sheetsToRun = oddMode === 'wait' ? evenPairs : totalSheets;
        const $runBtn = $('#btn-run-banquet-batch');
        const $runLabel = $('#btn-run-banquet-label');

        if (sheetsToRun > 0) {
            $runBtn.prop('disabled', false);
            $runLabel.text('Ghép tuần tự 2 ảnh/lượt & In ngay (' + sheetsToRun + ' tờ 4x6" • ' + totalStrips + ' dải)');
        } else {
            $runBtn.prop('disabled', true);
            $runLabel.text(
                totalStrips === 1 && oddMode === 'wait'
                    ? 'Đang có 1 dải lẻ (Chọn "In hết toàn bộ" để ghép nhân đôi hoặc chờ thêm ảnh)'
                    : 'Chưa có ảnh chờ in tiệc (0 dải)'
            );
        }

        // 3. Render Pending Banquet Items (05_print_banquet_pending)
        const $pendingList = $('#banquet-pending-list');
        $pendingList.empty();
        const items = Array.isArray(banquetData.items) ? banquetData.items : [];
        if (!items.length) {
            $pendingList.html('<div class="banquet-empty-msg">Chưa có file ảnh nào trong <code>05_print_banquet_pending</code>.</div>');
        } else {
            items.forEach(function (item) {
                const $row = $('<div class="banquet-item-row"></div>');
                const $thumb = $('<img class="banquet-item-thumb" alt="">').attr('src', item.image_url || ('data/images/' + item.source_file));
                const $meta = $('<div class="banquet-item-meta"></div>');
                $meta.append($('<strong></strong>').text(item.customer_name || item.safe_customer_name));
                $meta.append($('<code></code>').text(item.banquet_file));
                const $badge = $('<span class="banquet-item-copies-badge"></span>').text(item.remaining_copies + ' bản');
                const $delBtn = $('<button type="button" class="banquet-item-del-btn" title="Xóa mục này"></button>')
                    .attr('data-target', item.banquet_file)
                    .html('<i class="fa fa-trash-o" aria-hidden="true"></i>');

                $row.append($thumb, $meta, $badge, $delBtn);
                $pendingList.append($row);
            });
        }

        // 4. Render Recent Printed Archive (06_printed)
        const $printedList = $('#banquet-printed-list');
        $printedList.empty();
        const recent = Array.isArray(banquetData.recent_printed) ? banquetData.recent_printed : [];
        if (!recent.length) {
            $printedList.html('<div class="banquet-empty-msg">Chưa có lịch sử in trong <code>06_printed</code>.</div>');
        } else {
            recent.forEach(function (entry) {
                const $row = $('<div class="banquet-item-row"></div>');
                const $meta = $('<div class="banquet-item-meta"></div>');
                const isBanquet = entry.type === 'banquet_pair';
                const titleText = isBanquet
                    ? ('In tiệc: ' + (entry.left_customer || '') + ' + ' + (entry.right_customer || ''))
                    : ('In ngay: ' + (entry.left_file || '') + ' + ' + (entry.right_file || ''));
                $meta.append($('<strong></strong>').text(titleText));
                $meta.append($('<code></code>').text((entry.sheet_file || entry.job_id || '') + ' • ' + (entry.printed_at_iso || '')));
                const $badge = $('<span class="banquet-item-copies-badge" style="background:rgba(16,185,129,0.2);color:#6EE7B7;">Đã in ✓</span>');
                $row.append($meta, $badge);
                $printedList.append($row);
            });
        }
    }

    function refreshBanquetManager() {
        const banquetUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/banquetPrint.php'
            : 'api/banquetPrint.php';

        $.ajax({
            url: banquetUrl,
            method: 'GET',
            data: { action: 'status', _t: Date.now() },
            dataType: 'json'
        }).done(function (res) {
            if (res && res.banquet) {
                renderBanquetManagerUi(res.banquet);
            }
            if (res && res.pair) {
                updatePairQueueUi(res.pair);
            }
        });
    }

    $(document).on('click', '#btn-open-banquet-modal, #btn-dock-banquet', function (e) {
        e.preventDefault();
        keepAlive();
        $('#banquet-batch-feedback').addClass('hidden');
        refreshBanquetManager();
        $('#banquetManagerModal').removeClass('hidden').attr('aria-hidden', 'false');
    });

    $(document).on('click', '#banquet-modal-btn-close, #banquet-modal-backdrop', function (e) {
        e.preventDefault();
        $('#banquetManagerModal').addClass('hidden').attr('aria-hidden', 'true');
    });

    $(document).on('click', '#btn-refresh-banquet', function (e) {
        e.preventDefault();
        refreshBanquetManager();
    });

    $(document).on('change', 'input[name="banquet_odd_mode"]', function () {
        refreshBanquetManager();
    });

    $(document).on('click', '.banquet-item-del-btn', function (e) {
        e.preventDefault();
        const target = $(this).attr('data-target') || '';
        if (!target) {
            return;
        }
        const banquetUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/banquetPrint.php'
            : 'api/banquetPrint.php';

        ajaxPost({
            url: banquetUrl,
            method: 'POST',
            data: {
                action: 'remove_item',
                target: target
            },
            dataType: 'json'
        }).done(function (res) {
            if (res && res.banquet) {
                renderBanquetManagerUi(res.banquet);
            }
        });
    });

    $(document).on('click', '#btn-run-banquet-batch', function (e) {
        e.preventDefault();
        const $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        $btn.prop('disabled', true);
        $('#btn-run-banquet-label').text('Đang ghép tuần tự 2 ảnh/lượt & gửi máy in Canon CP1500...');

        const oddMode = $('input[name="banquet_odd_mode"]:checked').val() || 'duplicate';
        const banquetUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/banquetPrint.php'
            : 'api/banquetPrint.php';

        ajaxPost({
            url: banquetUrl,
            method: 'POST',
            data: {
                action: 'print_batch',
                odd_mode: oddMode
            },
            dataType: 'json'
        }).done(function (res) {
            if (res && res.status === 'ok') {
                $('#banquet-batch-feedback').removeClass('hidden');
                $('#banquet-batch-feedback-text').text(res.message || 'Đã ghép và in thành công toàn bộ hàng đợi tiệc!');
                if (res.banquet) {
                    renderBanquetManagerUi(res.banquet);
                } else {
                    refreshBanquetManager();
                }
            } else {
                alert((res && res.error) ? res.error : 'Không thể ghép in hàng đợi tiệc.');
                refreshBanquetManager();
            }
        }).fail(function (xhr) {
            const errJson = xhr && xhr.responseJSON;
            alert((errJson && errJson.error) ? errJson.error : 'Lỗi kết nối khi chạy in tiệc.');
            refreshBanquetManager();
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

        const isInGallery = $('#gallery').hasClass('gallery--open') || $('.pswp--open').length > 0;
        const isFromStartScreen = !$resultPage.hasClass('stage--active') || isInGallery;
        const targetFile = explicitFilename || ((!isFromStartScreen && currentFilename) ? currentFilename : 'latest');
        currentSessionId = getSessionId(targetFile);
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

                $img.attr('src', qrSrc).off('load error').on('load', function () {
                    if (openSeq !== qrOpenSeq) {
                        return;
                    }
                    $spinner.hide();
                    $img.show();
                }).on('error', function () {
                    if (openSeq !== qrOpenSeq) {
                        return;
                    }
                    $spinner.html('<span style="color:#ef4444;">Không thể tải hình ảnh mã QR.</span>');
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
        $('#cloudR2SetupModal').removeClass('hidden').attr('aria-hidden', 'false');
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
                const cfg = data.config || {};
                const activeMode = cfg.mode || data.mode || 'cloud_web';
                $('input[name="qr_delivery_mode"][value="' + activeMode + '"]').prop('checked', true).trigger('change');
                $('#input-cloud-url').val(cfg.cloud_url || data.cloud_url || 'https://tysptb.kocodau2006.workers.dev');
                $('#input-cloud-key').val(cfg.cloud_api_key || cfg.cloud_key || data.cloud_key || 'tys_secret_key_2026');
                $('#input-venue-ip').val(cfg.venue_ip || data.venue_ip || '');
                $('#qr-net-save-msg').text('');
            }
        });
    }

    $(document).on('click', '#btn-toggle-qr-settings, #btn-start-qr-settings', function (e) {
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

    $(document).on('click', '#btn-test-cloud-r2', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const $msg = $('#qr-net-save-msg');
        const selectedMode = $('input[name="qr_delivery_mode"]:checked').val() || 'cloud_web';
        const origHtml = $btn.html();

        if (selectedMode === 'venue_wifi') {
            const venueIp = ($('#input-venue-ip').val() || '').trim();
            const testHost = venueIp || window.location.host;
            const testUrl = (testHost.startsWith('http') ? testHost : 'http://' + testHost) + '/api/networkMode.php?_t=' + Date.now();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> <span>Đang kiểm tra LAN...</span>');
            $msg.css('color', '#38BDF8').text('Đang kiểm tra mạng nội bộ...');
            $.ajax({
                url: testUrl,
                method: 'GET',
                timeout: 4000
            }).done(function () {
                $btn.prop('disabled', false).html(origHtml);
                $msg.css('color', '#10B981').text('Kết nối Wi-Fi nội bộ OK ✓');
            }).fail(function () {
                $btn.prop('disabled', false).html(origHtml);
                $msg.css('color', '#EF4444').text('Không thể kết nối IP LAN: ' + testHost);
            });
            return;
        }

        const cloudUrl = ($('#input-cloud-url').val() || '').trim();
        if (!cloudUrl) {
            $msg.css('color', '#EF4444').text('Vui lòng nhập URL Cloudflare Worker');
            return;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> <span>Đang kiểm tra...</span>');
        $msg.css('color', '#38BDF8').text('Đang kiểm tra Cloudflare R2...');

        const postData = {
            action: 'test_cloud',
            cloud_url: cloudUrl
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }

        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        ajaxPost({
            url: netApiUrl,
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function (res) {
            $btn.prop('disabled', false).html(origHtml);
            if (res && res.success) {
                $msg.css('color', '#10B981').text(res.message || 'Kết nối Cloud R2 thành công ✓');
            } else {
                $msg.css('color', '#EF4444').text('Lỗi: ' + ((res && res.error) ? res.error : 'Không thể kết nối R2'));
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).html(origHtml);
            $msg.css('color', '#EF4444').text('Lỗi máy chủ: HTTP ' + xhr.status);
        });
    });

    $(document).on('click', '#btn-r2-test-qr-latest', function (e) {
        e.preventDefault();
        $('#cloudR2SetupModal').addClass('hidden').attr('aria-hidden', 'true');
        openQrDeliveryModal('latest');
    });

    $(document).on('click', '#btn-save-qr-network', function (e) {
        e.preventDefault();
        const selectedMode = $('input[name="qr_delivery_mode"]:checked').val() || 'cloud_web';
        const postData = {
            action: 'save',
            mode: selectedMode,
            cloud_url: ($('#input-cloud-url').val() || '').trim(),
            cloud_api_key: ($('#input-cloud-key').val() || '').trim(),
            cloud_key: ($('#input-cloud-key').val() || '').trim(),
            venue_ip: ($('#input-venue-ip').val() || '').trim()
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }
        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        ajaxPost({
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
            if (typeof callback === 'function') {
                callback(null);
            }
            return;
        }
        const syncUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/syncStatus.php'
            : 'api/syncStatus.php';

        const cleanSession = getSessionId(sessionIdOrFilename);
        const postData = {
            action: 'sync_session',
            session_id: cleanSession
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }

        ajaxPost({
            url: syncUrl,
            method: 'POST',
            data: postData,
            dataType: 'json',
            timeout: 8000
        }).done(function (res) {
            if (typeof callback === 'function') {
                callback(res);
            }
        }).fail(function () {
            if (typeof callback === 'function') {
                callback(null);
            }
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

        // Fetch current pair queue status on startup
        refreshPairQueueStatus('');

        if ($resultPage.length) {
            observer.observe($resultPage[0], { attributes: true, attributeFilter: ['class', 'data-img'] });
            if ($resultPage.hasClass('stage--active')) {
                initResultDockForImage();
            }
        }
    });

    // Public API hooks
    window.openCloudQrModal = openQrDeliveryModal;
    window.openQrDeliveryModal = openQrDeliveryModal;
    window.updatePairQueueUi = updatePairQueueUi;
    window.refreshPairQueueStatus = refreshPairQueueStatus;
    window.triggerBackgroundCloudSync = triggerBackgroundCloudSync;

})(jQuery);
