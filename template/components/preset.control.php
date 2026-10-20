<?php

declare(strict_types=1);

use Photobooth\Service\PresetService;

$presetService = PresetService::getInstance();
$presets = $presetService->getEnabledPresets();
?>

<div id="desktop-preset-bar" class="desktop-preset-bar hidden">
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

        <!-- Before / After Hold Button -->
        <div class="preset-compare-container">
            <button type="button" id="btn-hold-compare" class="compare-btn" title="Click & hold mouse button to view original image (or press \ )">
                <i class="fa fa-columns"></i> Hold: Before/After
            </button>
            <span id="preset-status" class="preset-status-pill">Ready</span>
        </div>
    </div>
</div>

<style>
.desktop-preset-bar {
    position: absolute;
    bottom: 90px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 100;
    pointer-events: auto;
    transition: opacity 0.25s ease, transform 0.25s ease;
}
.desktop-preset-bar.hidden {
    display: none !important;
}
.preset-bar-inner {
    display: flex;
    align-items: center;
    gap: 16px;
    background: rgba(18, 24, 38, 0.88);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 40px;
    padding: 8px 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), 0 0 1px 1px rgba(255, 255, 255, 0.1);
    color: #f1f5f9;
    font-family: inherit;
    font-size: 14px;
}
.preset-group {
    display: flex;
    align-items: center;
    gap: 6px;
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
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
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
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.3);
    color: #ffffff;
    transform: translateY(-1px);
}
.preset-btn.active {
    background: linear-gradient(135deg, #0ea5e9, #2563eb);
    border-color: #38bdf8;
    color: #ffffff;
    box-shadow: 0 2px 10px rgba(14, 165, 233, 0.4);
    font-weight: 600;
}
.preset-amount-container {
    display: flex;
    align-items: center;
    gap: 10px;
    border-left: 1px solid rgba(255, 255, 255, 0.12);
    border-right: 1px solid rgba(255, 255, 255, 0.12);
    padding: 0 14px;
}
.preset-amount-label {
    font-size: 12px;
    font-weight: 600;
    color: #cbd5e1;
    min-width: 85px;
}
.preset-slider {
    -webkit-appearance: none;
    appearance: none;
    width: 110px;
    height: 6px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 3px;
    outline: none;
    cursor: pointer;
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
    gap: 8px;
}
.compare-btn {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #e2e8f0;
    padding: 6px 12px;
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
.preset-status-pill {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.08);
    color: #94a3b8;
}
.preset-status-pill.loading {
    background: rgba(14, 165, 233, 0.2);
    color: #38bdf8;
}
</style>
