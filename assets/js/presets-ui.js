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

    const $presetBar = $('#desktop-preset-bar');
    const $resultPage = $('.stage[data-stage="result"]');
    const $amountSlider = $('#preset-amount-slider');
    const $amountVal = $('#preset-amount-val');
    const $statusPill = $('#preset-status');
    const $btnCompare = $('#btn-hold-compare');

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
        $amountSlider.val(100);
        $amountVal.text('100%');

        $('.preset-btn').removeClass('active');
        $('.preset-btn[data-preset="original"]').addClass('active');

        // Capture initial original image URL from CSS
        const rawStyle = $resultPage.css('--stage-background-image') || '';
        const match = rawStyle.match(/url\(["']?([^"')]+)["']?\)/);
        if (match && match[1]) {
            originalImageUrl = match[1];
            currentProcessedUrl = originalImageUrl;
        } else if (currentFilename) {
            const baseDir = (typeof environment !== 'undefined' && environment.publicFolders && environment.publicFolders.images) ? environment.publicFolders.images : 'data/images';
            originalImageUrl = baseDir + '/' + currentFilename;
            currentProcessedUrl = originalImageUrl;
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
                    console.log('Preset permanently saved to image:', currentFilename, presetId, amount);
                }
            });
        }, 350);
    }

    // Preset button click (Mouse)
    $(document).on('click', '.preset-btn', function (e) {
        e.preventDefault();
        const presetId = $(this).data('preset');
        $('.preset-btn').removeClass('active');
        $(this).addClass('active');
        applyPresetPreview(presetId, parseInt($amountSlider.val(), 10) || 100);
    });

    // Amount slider (Mouse Drag & Input)
    $amountSlider.on('input', function () {
        const val = $(this).val();
        $amountVal.text(val + '%');
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            applyPresetPreview(currentPreset, parseInt(val, 10));
        }, 80);
    });

    // Before / After Hold (Mouse down = view original, Mouse up = view preset)
    $btnCompare.on('mousedown', function (e) {
        e.preventDefault();
        if (currentPreset === 'original') return;
        isComparing = true;
        $(this).addClass('comparing');
        $resultPage.css('--stage-background-image', `url(${originalImageUrl})`);
    });

    $(document).on('mouseup mouseleave', function () {
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

    // Watch for result stage activation and image changes
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.attributeName === 'class' || mutation.attributeName === 'data-img') {
                if ($resultPage.hasClass('stage--active')) {
                    initPresetBarForImage();
                }
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
