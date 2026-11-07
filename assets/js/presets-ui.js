/**
 * Desktop Mouse-Centric Preset Controller for Photobooth
 * Supports click selection, mouse slider drag for Amount, and Hold-to-Compare Before/After.
 */
(function ($) {
    'use strict';

    let currentPreset = 'original';
    let currentAmount = 100;
    let originalImageUrl = '';
    let currentProcessedUrl = '';
    let currentFilename = '';
    let debounceTimer = null;
    let isComparing = false;
    let isViewingGif = false;

    const $presetBar = $('#desktop-preset-bar');
    const $resultPage = $('.stage[data-stage="result"]');
    const $amountSlider = $('#preset-amount-slider');
    const $amountVal = $('#preset-amount-val');
    const $statusPill = $('#preset-status');
    const $btnCompare = $('#btn-hold-compare');
    const $btnToggleGif = $('#btn-toggle-gif-preview');

    function setStatus(text, isLoading) {
        $statusPill.text(text);
        if (isLoading) {
            $statusPill.addClass('loading');
        } else {
            $statusPill.removeClass('loading');
        }
    }

    function extractFilename() {
        let fn = $resultPage.attr('data-img');
        if (!fn && typeof api !== 'undefined' && api.filename) {
            fn = api.filename;
        }
        if (!fn) {
            // Try extracting from --stage-background-image
            const rawStyle = $resultPage.css('--stage-background-image') || '';
            const match = rawStyle.match(/url\(["']?([^"')?#]+)[^"')]*["']?\)/);
            if (match && match[1]) {
                const parts = match[1].split('/');
                fn = parts[parts.length - 1];
            }
        }
        return fn || '';
    }

    function initPresetBarForImage() {
        currentFilename = extractFilename();
        currentPreset = 'original';
        currentAmount = 100;
        isViewingGif = false;
        $('#result-gif-viewer').addClass('hidden').attr('aria-hidden', 'true');
        $('#result-gif-img').attr('src', '');
        $btnToggleGif.removeClass('active comparing').html('<i class="fa fa-film"></i> <span>Ảnh Động (Tua nhanh)</span>');
        $amountSlider.val(100);
        $amountVal.text('100%');

        $('.preset-btn').removeClass('active');
        $('.preset-btn[data-preset="original"]').addClass('active');

        // Always build fresh image URL from currentFilename with cache-busting timestamp
        if (currentFilename) {
            const baseDir = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.images) ? environment.publicFolders.images : 'data/images';
            originalImageUrl = baseDir + '/' + currentFilename + '?v=' + Date.now();
            currentProcessedUrl = originalImageUrl;
            $resultPage.css('--stage-background-image', `url(${originalImageUrl})`);
            // Pre-warm GIF creation silently in background so it's ready when user clicks
            prewarmGifInBackground(currentFilename);
        } else {
            const rawStyle = $resultPage.css('--stage-background-image') || '';
            const match = rawStyle.match(/url\(["']?([^"')]+)["']?\)/);
            if (match && match[1]) {
                originalImageUrl = match[1];
                currentProcessedUrl = originalImageUrl;
            }
        }

        $presetBar.removeClass('hidden');
        setStatus('Ready', false);
    }

    function applyPresetPreview(presetId, amount) {
        if (!currentFilename) {
            currentFilename = extractFilename();
        }
        if (!currentFilename) {
            console.warn('Cannot apply preset: filename not detected');
            return;
        }

        currentPreset = presetId;
        currentAmount = amount;

        if (presetId === 'original' || amount === 0) {
            $resultPage.css('--stage-background-image', `url(${originalImageUrl})`);
            currentProcessedUrl = originalImageUrl;
            setStatus('Original', false);
            scheduleBake('original', 100);
            return;
        }

        setStatus('Rendering...', true);

        const postData = {
            action: 'apply',
            file: currentFilename,
            preset: presetId,
            amount: amount,
            preview: 1
        };

        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }

        const apiEndpoint = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/presets.php'
            : 'api/presets.php';

        $.ajax({
            method: 'POST',
            url: apiEndpoint,
            data: postData,
            dataType: 'json'
        })
        .done(function (data) {
            if (data && data.success && data.url) {
                currentProcessedUrl = data.url;
                if (!isComparing) {
                    $resultPage.css('--stage-background-image', `url(${data.url})`);
                }
                setStatus('Applied', false);
                scheduleBake(presetId, amount);
            } else {
                setStatus('Failed', false);
            }
        })
        .fail(function (xhr, status, err) {
            console.error('Preset preview error:', err);
            setStatus('Error', false);
        });
    }

    let bakeTimer = null;
    function scheduleBake(presetId, amount) {
        clearTimeout(bakeTimer);
        bakeTimer = setTimeout(function () {
            if (!currentFilename) return;
            const postData = {
                action: 'apply',
                file: currentFilename,
                preset: presetId,
                amount: amount,
                preview: 0
            };
            if (typeof csrf !== 'undefined' && csrf.key) {
                postData[csrf.key] = csrf.token;
            }
            const apiEndpoint = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
                ? environment.publicFolders.api + '/presets.php'
                : 'api/presets.php';
            $.ajax({
                method: 'POST',
                url: apiEndpoint,
                data: postData,
                dataType: 'json'
            }).done(function (data) {
                if (data && data.success) {
                    if (isViewingGif && data.gif_url) {
                        $('#result-gif-img').attr('src', data.gif_url);
                    }
                    setStatus(isViewingGif ? 'GIF Tua nhanh' : 'Ready', false);
                    console.log('Preset permanently saved to image:', currentFilename, presetId, amount);
                }
            });
        }, 350);
    }

    // Helper to keep photobooth result screen alive while interacting
    function keepAlive() {
        if (typeof photoBooth !== 'undefined' && photoBooth.resetTimeOut) {
            photoBooth.resetTimeOut();
        }
    }

    // Preset button click (Mouse)
    $(document).on('click', '.preset-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        keepAlive();
        const presetId = $(this).data('preset');
        $('.preset-btn').removeClass('active');
        $(this).addClass('active');
        applyPresetPreview(presetId, parseInt($('#preset-amount-slider').val(), 10) || 100);
    });

    // Amount slider (Mouse Drag & Input)
    $(document).on('input change', '#preset-amount-slider', function (e) {
        e.stopPropagation();
        keepAlive();
        const val = parseInt($(this).val(), 10);
        $('#preset-amount-val').text(val + '%');

        // If user drags slider while still on 'original', auto-select first preset so slider adjustment is immediately visible
        if (currentPreset === 'original') {
            const $firstPreset = $('.preset-btn').not('[data-preset="original"]').first();
            if ($firstPreset.length) {
                $('.preset-btn').removeClass('active');
                $firstPreset.addClass('active');
                currentPreset = $firstPreset.data('preset');
            }
        }

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            applyPresetPreview(currentPreset, val);
        }, 80);
    });

    function triggerBackgroundCloudSync(filenameOrSessionId, callback) {
        if (!filenameOrSessionId) {
            if (typeof callback === 'function') callback(null);
            return;
        }
        const sessionId = String(filenameOrSessionId).replace(/\.[^.]+$/, '');
        const postData = {
            action: 'sync_session',
            session_id: sessionId
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }
        const syncEndpoint = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/syncStatus.php'
            : 'api/syncStatus.php';

        $.ajax({
            method: 'POST',
            url: syncEndpoint,
            data: postData,
            dataType: 'json'
        }).done(function (res) {
            if (typeof callback === 'function') callback(res);
        }).fail(function () {
            if (typeof callback === 'function') callback(null);
        });
    }

    function prewarmGifInBackground(filename) {
        if (!filename) return;
        const postData = {
            action: 'bake_gif',
            file: filename,
            preset: currentPreset || 'original',
            amount: currentAmount || 100
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }
        const apiEndpoint = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/presets.php'
            : 'api/presets.php';

        $.ajax({
            method: 'POST',
            url: apiEndpoint,
            data: postData,
            dataType: 'json'
        }).done(function (res) {
            console.log('GIF background pre-warm ready:', res);
            // Automatically push Photo + GIF to Cloud R2 in background right after GIF prewarm finishes
            triggerBackgroundCloudSync(filename);
        });
    }

    function toggleGifDisplay(forceState) {
        if (!currentFilename) {
            currentFilename = extractFilename();
        }
        if (!currentFilename) return;

        const targetState = typeof forceState === 'boolean' ? forceState : !isViewingGif;
        isViewingGif = targetState;
        const $btn = $('#btn-toggle-gif-preview');

        if (isViewingGif) {
            const sessionId = currentFilename.replace(/\.[^.]+$/, '');
            const gifUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.images ? environment.publicFolders.images : 'data/images') + '/' + sessionId + '.gif?v=' + Date.now();

            setStatus('Đang tải Ảnh Động...', true);
            $btn.addClass('active').html('<i class="fa fa-picture-o"></i> <span>Xem Ảnh Tĩnh</span>');

            const postData = {
                action: 'bake_gif',
                file: currentFilename,
                preset: currentPreset || 'original',
                amount: currentAmount || 100
            };
            if (typeof csrf !== 'undefined' && csrf.key) {
                postData[csrf.key] = csrf.token;
            }
            const apiEndpoint = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
                ? environment.publicFolders.api + '/presets.php'
                : 'api/presets.php';

            $.ajax({
                method: 'POST',
                url: apiEndpoint,
                data: postData,
                dataType: 'json'
            }).always(function () {
                // Preload GIF image then smoothly swap directly onto stage background
                const gifPreload = new Image();
                gifPreload.onload = function () {
                    if (isViewingGif) {
                        $resultPage.css('--stage-background-image', `url(${gifUrl})`);
                        setStatus('Ảnh Động (Tua nhanh 10s)', false);
                    }
                };
                gifPreload.onerror = function () {
                    if (isViewingGif) {
                        $resultPage.css('--stage-background-image', `url(${gifUrl})`);
                        setStatus('Ảnh Động (Tua nhanh 10s)', false);
                    }
                };
                gifPreload.src = gifUrl;
            });
        } else {
            // Revert background back to pristine static photo
            const staticUrl = currentProcessedUrl || originalImageUrl;
            if (staticUrl) {
                $resultPage.css('--stage-background-image', `url(${staticUrl})`);
            }
            $btn.removeClass('active').html('<i class="fa fa-film"></i> <span>Ảnh Động (Tua nhanh)</span>');
            setStatus('Ready', false);
        }
    }

    // Toggle Time-Lapse Animated GIF preview right on Result screen
    $(document).on('click', '#btn-toggle-gif-preview', function (e) {
        e.preventDefault();
        e.stopPropagation();
        keepAlive();
        toggleGifDisplay();
    });

    $(document).on('click', '#btn-close-gif-viewer', function (e) {
        e.preventDefault();
        e.stopPropagation();
        keepAlive();
        toggleGifDisplay(false);
    });

    // Before / After Hold (Mouse down / Touch start = view original, release = view preset)
    $btnCompare.on('mousedown touchstart', function (e) {
        e.preventDefault();
        if (currentPreset === 'original') return;
        isComparing = true;
        $(this).addClass('comparing');
        $resultPage.css('--stage-background-image', `url(${originalImageUrl})`);
    });

    $(document).on('mouseup mouseleave touchend touchcancel', function () {
        if (isComparing) {
            isComparing = false;
            $btnCompare.removeClass('comparing');
            if (currentProcessedUrl) {
                $resultPage.css('--stage-background-image', `url(${currentProcessedUrl})`);
            }
        }
    });

    // Keyboard Shortcuts (1, 2, 3 and \)
    $(document).on('keydown', function (e) {
        if (!$resultPage.hasClass('stage--active')) {
            return;
        }

        // Avoid triggering inside input fields
        if ($(e.target).is('input, textarea, select')) {
            return;
        }

        if (e.key === '1') {
            $('.preset-btn[data-preset="original"]').trigger('click');
        } else if (e.key === '2') {
            const $btn = $('.preset-btn').eq(1);
            if ($btn.length) $btn.trigger('click');
        } else if (e.key === '3') {
            const $btn = $('.preset-btn').eq(2);
            if ($btn.length) $btn.trigger('click');
        } else if (e.key === '\\') {
            // Toggle before/after
            isComparing = !isComparing;
            if (isComparing) {
                $btnCompare.addClass('comparing');
                $resultPage.css('--stage-background-image', `url(${originalImageUrl})`);
            } else {
                $btnCompare.removeClass('comparing');
                $resultPage.css('--stage-background-image', `url(${currentProcessedUrl})`);
            }
        }
    });

    // Watch for result stage activation and image changes (debounced to avoid multiple redundant reloads)
    let lastActiveImg = null;
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.attributeName === 'class' || mutation.attributeName === 'data-img') {
                if ($resultPage.hasClass('stage--active')) {
                    const currentImg = $resultPage.attr('data-img') || extractFilename();
                    if (currentImg && currentImg !== lastActiveImg) {
                        lastActiveImg = currentImg;
                        initPresetBarForImage();
                    }
                } else {
                    lastActiveImg = null;
                }
            }
        });
    });

    // Retake button on Result Screen
    $(document).on('click', '#btn-preset-retake', function (e) {
        e.preventDefault();
        keepAlive();
        if (confirm('Bạn muốn chụp lại lượt chụp này?')) {
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

    function forceBakeNow(presetId, amount, callback) {
        clearTimeout(bakeTimer);
        if (!currentFilename || !presetId || presetId === 'original') {
            if (currentFilename) {
                triggerBackgroundCloudSync(currentFilename);
            }
            if (typeof callback === 'function') callback();
            return;
        }
        setStatus('Saving...', true);
        const postData = {
            action: 'apply',
            file: currentFilename,
            preset: presetId,
            amount: amount,
            bake_gif: 1,
            preview: 0
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }
        const apiEndpoint = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/presets.php'
            : 'api/presets.php';
        $.ajax({
            method: 'POST',
            url: apiEndpoint,
            data: postData,
            dataType: 'json'
        }).always(function () {
            setStatus('Ready', false);
            triggerBackgroundCloudSync(currentFilename);
            if (typeof callback === 'function') callback();
        });
    }

    // Direct QR button on Preset Bar -> bake current preset and immediately show QR code!
    $(document).on('click', '#btn-preset-qr', function (e) {
        e.preventDefault();
        keepAlive();
        const $btn = $(this);
        $btn.css('opacity', '0.7').prop('disabled', true);
        forceBakeNow(currentPreset, currentAmount, function () {
            $btn.css('opacity', '1').prop('disabled', false);
            openQrDeliveryModal();
        });
    });

    // Confirm button on Result Screen
    $(document).on('click', '#btn-preset-confirm', function (e) {
        e.preventDefault();
        keepAlive();
        const $btn = $(this);
        $btn.css('opacity', '0.7').prop('disabled', true);
        forceBakeNow(currentPreset, currentAmount, function () {
            $btn.css('opacity', '1').prop('disabled', false);
            $('#confirmSuccessModal').removeClass('hidden').attr('aria-hidden', 'false');
        });
    });

    let qrCountdownTimer = null;

    function openQrDeliveryModal(explicitFilename) {
        if (!currentFilename && $resultPage.hasClass('stage--active')) {
            currentFilename = extractFilename();
        }
        const isInGallery = $('#gallery').hasClass('gallery--open') || $('.pswp--open').length > 0;
        const isFromStartScreen = !$resultPage.hasClass('stage--active') || isInGallery;
        const targetFile = explicitFilename || ((!isFromStartScreen && currentFilename) ? currentFilename : 'latest');

        // Close any legacy modal if open
        if (typeof photoboothTools !== 'undefined' && photoboothTools.modal && typeof photoboothTools.modal.close === 'function') {
            photoboothTools.modal.close();
        }
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

        if ($modal.parent()[0] !== document.body) {
            $modal.appendTo(document.body);
        }

        // Reset state
        $img.hide().attr('src', '');
        $spinner.show();
        if (qrCountdownTimer) {
            clearInterval(qrCountdownTimer);
            qrCountdownTimer = null;
        }

        $modal.removeClass('hidden').attr('aria-hidden', 'false');

        const apiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/qrcode.php'
            : 'api/qrcode.php';

        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: {
                filename: targetFile,
                json: 1,
                _t: Date.now()
            },
            dataType: 'json'
        }).done(function (data) {
            if (data && data.url) {
                const resolvedFile = data.filename || targetFile;
                $link.attr('href', data.url).show();

                $instruction.text('Mở camera điện thoại (dùng 4G/5G hoặc bất kỳ WiFi nào) quét mã QR bên dưới để tải Ảnh gốc & Ảnh động GIF về máy.');
                if (data.sync_status === 'synced') {
                    $badge.attr('class', 'qr-badge cloud').html('<i class="fa fa-cloud"></i> <span>Cloud R2 • Đã đồng bộ Ảnh & GIF ✓</span>');
                } else if (resolvedFile && resolvedFile !== 'demo_session.jpg') {
                    $badge.attr('class', 'qr-badge cloud').html('<i class="fa fa-spinner fa-spin"></i> <span>Đang đẩy Ảnh & GIF lên Cloud R2...</span>');
                    triggerBackgroundCloudSync(data.session_id || resolvedFile, function (syncRes) {
                        if (syncRes && syncRes.success && syncRes.record && syncRes.record.status === 'synced') {
                            $badge.attr('class', 'qr-badge cloud').html('<i class="fa fa-cloud"></i> <span>Cloud R2 • Đã đồng bộ Ảnh & GIF ✓</span>');
                        } else {
                            $badge.attr('class', 'qr-badge cloud').html('<i class="fa fa-exclamation-circle"></i> <span>Chờ đồng bộ Cloud R2</span>');
                        }
                    });
                } else {
                    $badge.attr('class', 'qr-badge cloud').html('<i class="fa fa-cloud"></i> <span>Cloud R2 • Sẵn sàng</span>');
                }

                $img.off('load error').on('load', function () {
                    $spinner.hide();
                    $img.fadeIn(200);
                }).on('error', function () {
                    $spinner.html('<span style="color:#ef4444;"><i class="fa fa-exclamation-triangle"></i> Lỗi tạo mã QR</span>');
                });

                const qrImgSrc = data.qr_image || (apiUrl + '?filename=' + encodeURIComponent(resolvedFile) + '&_t=' + Date.now());
                $img.attr('src', qrImgSrc);
            }
        }).fail(function () {
            $img.attr('src', apiUrl + '?filename=' + encodeURIComponent(targetFile) + '&_t=' + Date.now()).show();
            $spinner.hide();
        });

        if (!isFromStartScreen) {
            $countBox.show();
            $finishBtn.show();
            $countVal.text('30');
            let secondsLeft = 30;
            qrCountdownTimer = setInterval(function () {
                secondsLeft--;
                $countVal.text(secondsLeft);
                if (secondsLeft <= 0) {
                    clearInterval(qrCountdownTimer);
                    qrCountdownTimer = null;
                    window.location.assign('./');
                }
            }, 1000);
        } else {
            $countBox.hide();
            $finishBtn.toggle(!isInGallery);
        }
    }

    // Expose globally so Gallery / History (PhotoSwipe) & core.js use this exact Cloud R2 QR modal
    window.openCloudQrModal = openQrDeliveryModal;

    function openCloudR2SetupModal() {
        const $modal = $('#cloudR2SetupModal');
        if ($modal.parent()[0] !== document.body) {
            $modal.appendTo(document.body);
        }
        $('#qr-net-save-msg').text('');
        $modal.removeClass('hidden').attr('aria-hidden', 'false');

        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        $.ajax({
            url: netApiUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function (res) {
            if (res && res.config) {
                if (res.config.cloud_url) $('#input-cloud-url').val(res.config.cloud_url);
                if (res.config.cloud_api_key) $('#input-cloud-key').val(res.config.cloud_api_key);
            }
        });
    }

    function closeQrDeliveryModal() {
        if (qrCountdownTimer) {
            clearInterval(qrCountdownTimer);
            qrCountdownTimer = null;
        }
        $('#qrDeliveryModal').addClass('hidden').attr('aria-hidden', 'true');
    }

    function triggerPrintAction() {
        if (!currentFilename) {
            currentFilename = extractFilename();
        }
        if (!currentFilename) {
            alert('Không tìm thấy file ảnh để in.');
            return;
        }

        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');

        if (typeof photoboothTools !== 'undefined' && typeof photoboothTools.printImage === 'function') {
            photoboothTools.printImage(currentFilename, 1, function () {
                if (typeof remoteBuzzerClient !== 'undefined' && remoteBuzzerClient.inProgress) {
                    remoteBuzzerClient.inProgress(false);
                }
            });
        } else {
            // Direct API fallback
            const apiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
                ? environment.publicFolders.api + '/print.php'
                : 'api/print.php';

            $.ajax({
                method: 'GET',
                url: apiUrl,
                data: {
                    filename: currentFilename,
                    copies: 1,
                    [csrf.key]: csrf.token
                },
                dataType: 'json'
            }).done(function (data) {
                if (data.status === 'ok' || data.status === 'queued') {
                    alert('Lệnh in đã được chuyển tới máy in! Vui lòng nhận ảnh tại khay in.');
                } else if (data.status === 'locking') {
                    alert('Máy in đang bị khóa do đã đạt giới hạn bản in.');
                } else {
                    alert('Lỗi in ảnh: ' + (data.error || 'Vui lòng kiểm tra kết nối máy in.'));
                }
            }).fail(function () {
                window.print();
            });
        }
    }

    // Confirmation Modal actions
    $(document).on('click', '#modal-btn-print', function (e) {
        e.preventDefault();
        triggerPrintAction();
    });

    $(document).on('click', '#btn-start-qr-settings', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openCloudR2SetupModal();
    });

    $(document).on('click', '#r2-setup-btn-close, #r2-setup-backdrop', function (e) {
        e.preventDefault();
        $('#cloudR2SetupModal').addClass('hidden').attr('aria-hidden', 'true');
    });

    $(document).on('click', '#btn-r2-test-qr-latest', function (e) {
        e.preventDefault();
        openQrDeliveryModal('latest');
    });

    $(document).on('click', '#modal-btn-qr, [data-command="qrbtn"], .qrbtn', function (e) {
        e.preventDefault();
        openQrDeliveryModal();
    });

    $(document).on('click', '#qr-btn-finish', function (e) {
        e.preventDefault();
        closeQrDeliveryModal();
        window.location.assign('./');
    });

    $(document).on('click', '#qr-btn-close, #qr-modal-backdrop', function (e) {
        e.preventDefault();
        closeQrDeliveryModal();
    });

    $(document).on('click', '#modal-btn-gallery', function (e) {
        e.preventDefault();
        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');
        const $btn = $('[data-command="gallerybtn"], .gallerybtn');
        if ($btn.length) {
            $btn.trigger('click');
        }
    });

    $(document).on('click', '#modal-btn-home', function (e) {
        e.preventDefault();
        window.location.assign('./');
    });

    $(document).on('click', '#modal-btn-close, .confirm-modal-backdrop', function (e) {
        e.preventDefault();
        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');
    });

    // Toggle inline QR Network Mode settings (Hướng 1 vs Hướng 2)
    $(document).on('click', '#btn-toggle-qr-settings', function (e) {
        e.preventDefault();
        keepAlive();
        const $panel = $('#qr-network-settings-panel');
        $panel.slideToggle(180);
    });

    $(document).on('change', 'input[name="qr_delivery_mode"]', function () {
        keepAlive();
        const val = $(this).val();
        $('#qr-fields-venue').toggle(val === 'venue_wifi');
        $('#qr-fields-cloud').toggle(val === 'cloud_web');
    });

    $(document).on('click', '#btn-test-cloud-r2', function (e) {
        e.preventDefault();
        keepAlive();
        const cloudUrl = ($('#input-cloud-url').val() || '').trim();
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

        $('#qr-net-save-msg').css('color', '#0284c7').text('Đang kiểm tra kết nối R2...');
        $.ajax({
            url: netApiUrl,
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function (res) {
            if (res && res.success) {
                $('#qr-net-save-msg').css('color', '#10b981').text(res.message || 'Kết nối Cloud R2 thành công ✓');
            } else {
                $('#qr-net-save-msg').css('color', '#ef4444').text('Lỗi: ' + (res && res.error ? res.error : 'Không thể kết nối'));
            }
        }).fail(function () {
            $('#qr-net-save-msg').css('color', '#ef4444').text('Không thể kiểm tra kết nối');
        });
    });

    $(document).on('click', '#btn-save-qr-network', function (e) {
        e.preventDefault();
        keepAlive();
        const postData = {
            action: 'save',
            mode: 'cloud_web',
            cloud_url: ($('#input-cloud-url').val() || '').trim(),
            cloud_api_key: ($('#input-cloud-key').val() || 'tys_secret_key_2026').trim()
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }
        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        $('#qr-net-save-msg').css('color', '#0284c7').text('Đang lưu...');
        $.ajax({
            url: netApiUrl,
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function (res) {
            if (res && res.success) {
                $('#qr-net-save-msg').css('color', '#10b981').text('Đã lưu cấu hình Cloud R2 ✓');
                setTimeout(function () {
                    $('#cloudR2SetupModal').addClass('hidden').attr('aria-hidden', 'true');
                }, 800);
            }
        });
    });

    $(document).ready(function () {
        // Attach modals directly to body so they work on both Start Screen and Result Screen
        $('#confirmSuccessModal, #qrDeliveryModal, #cloudR2SetupModal').appendTo(document.body);

        if ($resultPage.length) {
            observer.observe($resultPage[0], { attributes: true, attributeFilter: ['class', 'data-img'] });
            if ($resultPage.hasClass('stage--active')) {
                initPresetBarForImage();
            }
        }
    });

})(jQuery);
