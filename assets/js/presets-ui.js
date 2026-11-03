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
            if (typeof callback === 'function') callback();
            return;
        }
        setStatus('Saving...', true);
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
        }).always(function () {
            setStatus('Ready', false);
            if (typeof callback === 'function') callback();
        });
    }

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

    function openQrDeliveryModal() {
        if (!currentFilename) {
            currentFilename = extractFilename();
        }
        if (!currentFilename) {
            console.warn('Cannot open QR modal: filename not detected');
            return;
        }

        $('#confirmSuccessModal').addClass('hidden').attr('aria-hidden', 'true');
        const $modal = $('#qrDeliveryModal');
        const $img = $('#qr-modal-img');
        const $spinner = $('#qr-loading-spinner');
        const $badge = $('#qr-mode-badge');
        const $instruction = $('#qr-modal-instruction');
        const $link = $('#qr-direct-link');
        const $countVal = $('#qr-countdown-val');

        // Reset state
        $img.hide().attr('src', '');
        $spinner.show();
        $countVal.text('30');
        if (qrCountdownTimer) {
            clearInterval(qrCountdownTimer);
            qrCountdownTimer = null;
        }

        $modal.removeClass('hidden').attr('aria-hidden', 'false');

        // Fetch QR metadata
        const apiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/qrcode.php'
            : 'api/qrcode.php';

        $.ajax({
            url: apiUrl,
            method: 'GET',
            data: {
                filename: currentFilename,
                json: 1,
                _t: Date.now()
            },
            dataType: 'json'
        }).done(function (data) {
            if (data && data.url) {
                $link.attr('href', data.url).show();

                // Populate inline settings fields with current backend state
                const mode = data.mode || (data.is_cloud ? 'cloud_web' : 'venue_wifi');
                $('input[name="qr_delivery_mode"][value="' + mode + '"]').prop('checked', true);
                if (data.venue_ip) $('#input-venue-ip').val(data.venue_ip);
                if (typeof data.venue_ssid === 'string') $('#input-venue-ssid').val(data.venue_ssid);
                if (data.cloud_url) $('#input-cloud-url').val(data.cloud_url);
                $('#qr-fields-venue').toggle(mode === 'venue_wifi');
                $('#qr-fields-cloud').toggle(mode === 'cloud_web');

                if (data.is_cloud) {
                    const syncTag = data.sync_status === 'synced' ? ' • Đã đồng bộ ✓' : '';
                    $badge.attr('class', 'qr-badge cloud').html('<i class="fa fa-cloud"></i> <span>Web Riêng / 4G' + syncTag + '</span>');
                    $instruction.text('Mở camera điện thoại (dùng 4G/5G hoặc bất kỳ WiFi nào) quét mã QR để tải Ảnh & GIF về máy.');
                } else {
                    const ssidLabel = data.venue_ssid ? ('WiFi: ' + data.venue_ssid) : 'WiFi Địa Điểm';
                    const ipHint = data.venue_ip ? (' (' + data.venue_ip + ')') : '';
                    $badge.attr('class', 'qr-badge local').html('<i class="fa fa-wifi"></i> <span>' + ssidLabel + '</span>');
                    if (data.venue_ssid) {
                        $instruction.html('Kết nối điện thoại vào WiFi địa điểm <b>"' + data.venue_ssid + '"</b>' + ipHint + ', sau đó mở camera quét mã QR để tải Ảnh & GIF.');
                    } else {
                        $instruction.text('Kết nối điện thoại vào cùng mạng WiFi của địa điểm' + ipHint + ', sau đó mở camera quét mã QR để tải Ảnh & GIF.');
                    }
                }

                $img.off('load error').on('load', function () {
                    $spinner.hide();
                    $img.fadeIn(200);
                }).on('error', function () {
                    $spinner.html('<span style="color:#ef4444;"><i class="fa fa-exclamation-triangle"></i> Lỗi tạo mã QR</span>');
                });

                const qrImgSrc = data.qr_image || (apiUrl + '?filename=' + encodeURIComponent(currentFilename) + '&_t=' + Date.now());
                $img.attr('src', qrImgSrc);
            }
        }).fail(function () {
            // Fallback direct image load
            $img.attr('src', apiUrl + '?filename=' + encodeURIComponent(currentFilename) + '&_t=' + Date.now()).show();
            $spinner.hide();
        });

        // Start 30-second countdown
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

    $(document).on('click', '#btn-save-qr-network', function (e) {
        e.preventDefault();
        keepAlive();
        const selectedMode = $('input[name="qr_delivery_mode"]:checked').val() || 'venue_wifi';
        const postData = {
            action: 'save',
            mode: selectedMode,
            venue_ip: $('#input-venue-ip').val() || '',
            venue_ssid: $('#input-venue-ssid').val() || '',
            cloud_url: $('#input-cloud-url').val() || '',
            cloud_api_key: $('#input-cloud-key').val() || 'tys_secret_key_2026'
        };
        if (typeof csrf !== 'undefined' && csrf.key) {
            postData[csrf.key] = csrf.token;
        }
        const netApiUrl = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.api)
            ? environment.publicFolders.api + '/networkMode.php'
            : 'api/networkMode.php';

        $('#qr-net-save-msg').text('Đang lưu...');
        $.ajax({
            url: netApiUrl,
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function (res) {
            if (res && res.success) {
                $('#qr-net-save-msg').text('Đã lưu ✓');
                setTimeout(function () {
                    $('#qr-net-save-msg').text('');
                    $('#qr-network-settings-panel').slideUp(150);
                }, 700);
                // Re-generate QR immediately with the new mode
                openQrDeliveryModal();
            }
        });
    });

    $(document).ready(function () {
        if ($resultPage.length) {
            observer.observe($resultPage[0], { attributes: true, attributeFilter: ['class', 'data-img'] });
            if ($resultPage.hasClass('stage--active')) {
                initPresetBarForImage();
            }
        }
    });

})(jQuery);
