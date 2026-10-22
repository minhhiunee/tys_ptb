
<div class="stage stage--loader rotarygroup" data-stage="loader">
    <div class="stage-inner">
        <canvas id="video--sensor"></canvas>
        <div class="stage-image <?=$config['picture']['flip']?>"></div>
        <div class="stage-message"></div>
        <div class="buttonbar buttonbar--bottom"></div>
    </div>
</div>

<style>
/* Per-shot Review Buttons Styling */
.btn-review-pill {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    gap: 8px !important;
    width: auto !important;
    height: auto !important;
    min-height: 48px !important;
    padding: 10px 24px !important;
    border-radius: 30px !important;
    font-family: inherit !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    cursor: pointer !important;
    border: none !important;
    user-select: none !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
.btn-review-pill:hover {
    transform: translateY(-2px) scale(1.02) !important;
}
.btn-review-pill:active {
    transform: translateY(0) scale(0.98) !important;
}
.btn-review-retake {
    background: #ef4444 !important;
    color: #ffffff !important;
    box-shadow: 0 6px 18px rgba(239, 68, 68, 0.45) !important;
}
.btn-review-retake:hover {
    background: #dc2626 !important;
    box-shadow: 0 8px 24px rgba(239, 68, 68, 0.6) !important;
}
.btn-review-confirm-next {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    color: #ffffff !important;
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45) !important;
}
.btn-review-confirm-next:hover {
    background: linear-gradient(135deg, #34d399, #059669) !important;
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.6) !important;
}
.btn-review-confirm-process {
    background: linear-gradient(135deg, #0ea5e9, #2563eb) !important;
    color: #ffffff !important;
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5) !important;
}
.btn-review-confirm-process:hover {
    background: linear-gradient(135deg, #38bdf8, #1d4ed8) !important;
    box-shadow: 0 8px 26px rgba(37, 99, 235, 0.65) !important;
}
.btn-review-abort {
    background: rgba(15, 23, 42, 0.75) !important;
    backdrop-filter: blur(10px) !important;
    -webkit-backdrop-filter: blur(10px) !important;
    color: #f1f5f9 !important;
    border: 1px solid rgba(255, 255, 255, 0.25) !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35) !important;
    font-size: 14px !important;
    padding: 10px 20px !important;
}
.btn-review-abort:hover {
    background: rgba(30, 41, 59, 0.9) !important;
    color: #ffffff !important;
    border-color: rgba(255, 255, 255, 0.4) !important;
}
</style>
