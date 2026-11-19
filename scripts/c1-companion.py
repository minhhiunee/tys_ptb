#!/usr/bin/env python3
"""
Capture One & Canon Tethering Companion Daemon for Photobooth
Handles automatic shutter triggering via Capture One, instant 6000x4000 CR3/JPG ingestion,
and heartbeat reporting to the Photobooth Docker backend.
"""

import os
import sys
import time
import json
import ctypes
from ctypes import wintypes

user32 = ctypes.windll.user32
kernel32 = ctypes.windll.kernel32

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
INPUT_DIR = os.path.join(REPO_ROOT, "data", "c1_input")
OUTPUT_DIR = os.path.join(REPO_ROOT, "data", "c1_output")
CAPTURE_DIR = os.path.join(REPO_ROOT, "data", "c1_session", "Photobooth", "Capture")
HEARTBEAT_FILE = os.path.join(REPO_ROOT, "data", "c1_host_heartbeat.json")
LOG_DIR = os.path.join(REPO_ROOT, "var", "log")
LOG_FILE = os.path.join(LOG_DIR, "c1-companion.log")

for d in [INPUT_DIR, OUTPUT_DIR, CAPTURE_DIR, LOG_DIR]:
    os.makedirs(d, exist_ok=True)

DESKTOP_ALL = 0x01FF
WM_KEYDOWN = 0x0100
WM_KEYUP = 0x0101
VK_SPACE = 0x20
VK_CONTROL = 0x11
VK_K = 0x4B  # 0x4B is 'K'. Capture One official global capture shortcut is Ctrl+K (131147)
# Unassigned virtual key code used to unlock Windows SetForegroundWindow without triggering WPF Alt Menu bars
VK_UNLOCK_FG = 0x0E


def log(msg):
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{ts}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as lf:
            lf.write(line + "\n")
    except Exception:
        pass


def switch_to_default_desktop():
    try:
        hdesk = user32.OpenDesktopW("Default", 0, False, DESKTOP_ALL)
        if hdesk:
            user32.SetThreadDesktop(hdesk)
    except Exception:
        pass


def find_c1_windows():
    switch_to_default_desktop()
    wins = {}

    def enum_cb(hwnd, lparam):
        if user32.IsWindowVisible(hwnd):
            length = user32.GetWindowTextLengthW(hwnd)
            buff = ctypes.create_unicode_buffer(length + 1)
            user32.GetWindowTextW(hwnd, buff, length + 1)
            title = buff.value
            if title == "Live View" or title.startswith("Live View -"):
                wins["live_view"] = hwnd
            elif title == "Photobooth" or title.startswith("Photobooth - Capture One"):
                wins["main"] = hwnd
            elif "Capture One" in title:
                wins["c1_other"] = hwnd
        return 1

    cb_proto = ctypes.WINFUNCTYPE(ctypes.c_int, wintypes.HWND, wintypes.LPARAM)
    user32.EnumWindows(cb_proto(enum_cb), 0)
    return wins


def update_heartbeat():
    wins = find_c1_windows()
    payload = {
        "updated_at": int(time.time()),
        "host_os": "Windows",
        "capture_one_running": bool(wins),
        "live_view_active": "live_view" in wins,
        "main_window_active": "main" in wins,
        "camera_model": "Canon EOS M50 Mark II",
        "status": "ready" if wins else "waiting_for_capture_one",
    }
    try:
        tmp = HEARTBEAT_FILE + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(payload, f, indent=2)
        os.replace(tmp, HEARTBEAT_FILE)
    except Exception:
        pass


def extract_cr3_jpeg(cr3_path, out_jpg_path):
    """Extract embedded 6000x4000 JPEG preview from Canon CR3 file in ~20ms"""
    with open(cr3_path, "rb") as f:
        data = f.read()

    pos = 0
    best_offset = None
    best_len = 0
    while True:
        idx = data.find(b"\xFF\xD8\xFF", pos)
        if idx == -1:
            break
        eoi = data.find(b"\xFF\xD9", idx)
        if eoi != -1:
            size = eoi + 2 - idx
            if size > best_len:
                best_len = size
                best_offset = idx
        pos = idx + 3

    if best_offset is not None and best_len > 100_000:
        tmp_out = out_jpg_path + ".tmp"
        with open(tmp_out, "wb") as out_f:
            out_f.write(data[best_offset : best_offset + best_len])
        os.replace(tmp_out, out_jpg_path)
        return True
    return False


def find_photobooth_browser():
    switch_to_default_desktop()
    found = None

    def enum_browser_cb(hwnd, lparam):
        nonlocal found
        if user32.IsWindowVisible(hwnd):
            length = user32.GetWindowTextLengthW(hwnd)
            buff = ctypes.create_unicode_buffer(length + 1)
            user32.GetWindowTextW(hwnd, buff, length + 1)
            title = buff.value.lower()
            if "photobooth" in title and any(
                br in title
                for br in ["cốc cốc", "chrome", "edge", "firefox", "opera", "brave", "browser"]
            ):
                found = hwnd
                return 0
        return 1

    cb_proto = ctypes.WINFUNCTYPE(ctypes.c_int, wintypes.HWND, wintypes.LPARAM)
    user32.EnumWindows(cb_proto(enum_browser_cb), 0)
    return found


def force_foreground(hwnd, wait_confirm_ms=180):
    if not hwnd or not user32.IsWindow(hwnd):
        return False
    try:
        if user32.IsIconic(hwnd):
            user32.ShowWindow(hwnd, 9)  # SW_RESTORE

        for _ in range(2):
            fore_hwnd = user32.GetForegroundWindow()
            if fore_hwnd == hwnd:
                return True

            fore_thread = user32.GetWindowThreadProcessId(fore_hwnd, None) if fore_hwnd else 0
            target_thread = user32.GetWindowThreadProcessId(hwnd, None)
            cur_thread = kernel32.GetCurrentThreadId()

            if fore_thread and fore_thread != cur_thread:
                user32.AttachThreadInput(cur_thread, fore_thread, True)
            if target_thread and target_thread != cur_thread:
                user32.AttachThreadInput(cur_thread, target_thread, True)

            user32.keybd_event(VK_UNLOCK_FG, 0, 0, 0)
            user32.BringWindowToTop(hwnd)
            user32.SetForegroundWindow(hwnd)
            user32.SetActiveWindow(hwnd)
            user32.SetFocus(hwnd)
            user32.keybd_event(VK_UNLOCK_FG, 0, 2, 0)

            if fore_thread and fore_thread != cur_thread:
                user32.AttachThreadInput(cur_thread, fore_thread, False)
            if target_thread and target_thread != cur_thread:
                user32.AttachThreadInput(cur_thread, target_thread, False)

            deadline = time.time() + (wait_confirm_ms / 1000.0)
            while time.time() < deadline:
                if user32.GetForegroundWindow() == hwnd:
                    return True
                time.sleep(0.015)

        return user32.GetForegroundWindow() == hwnd
    except Exception as e:
        log(f"[C1 Companion] force_foreground error: {e}")
        return False


def focus_browser(browser_hwnd=None):
    if not browser_hwnd or not user32.IsWindow(browser_hwnd):
        browser_hwnd = find_photobooth_browser()
    if browser_hwnd and user32.IsWindow(browser_hwnd):
        force_foreground(browser_hwnd, wait_confirm_ms=80)


def send_shutter_pulse(wins, target_hwnd, attempt=1):
    """
    Send shutter trigger to Capture One using native Ctrl+K (global Capture command).
    NEVER use F7 (in Capture One F7 creates 'New Variant' [1], [2], [3], [4]).
    NEVER use ESC (ESC cancels active camera autofocus and exposure).
    """
    main_hwnd = wins.get("main") or wins.get("c1_other")
    live_hwnd = wins.get("live_view")

    # Primary: trigger Capture One via official Ctrl+K on Main Window
    if main_hwnd:
        force_foreground(main_hwnd, wait_confirm_ms=160)
        time.sleep(0.08)
        user32.keybd_event(VK_CONTROL, 0, 0, 0)
        time.sleep(0.04)
        user32.keybd_event(VK_K, 0, 0, 0)
        time.sleep(0.10)
        user32.keybd_event(VK_K, 0, 2, 0)
        time.sleep(0.04)
        user32.keybd_event(VK_CONTROL, 0, 2, 0)
        time.sleep(0.12)
        log(f"[C1 Companion] Sent Ctrl+K capture command to Capture One (attempt {attempt})")
    elif live_hwnd:
        # Fallback if only Live View window is found: Spacebar
        force_foreground(live_hwnd, wait_confirm_ms=160)
        time.sleep(0.08)
        user32.keybd_event(VK_SPACE, 0, 0, 0)
        time.sleep(0.10)
        user32.keybd_event(VK_SPACE, 0, 2, 0)
        time.sleep(0.12)
        log(f"[C1 Companion] Sent SPACEBAR shutter pulse to Live View (attempt {attempt})")


def has_inflight_transfer(before_files):
    """Check if Canon/Capture One has already started writing a temporary ~*.CR3 or new file."""
    if not os.path.exists(CAPTURE_DIR):
        return False
    current = set(os.listdir(CAPTURE_DIR))
    diff = current - before_files
    for fn in diff:
        lower = fn.lower()
        if lower.endswith((".cr3", ".cr2", ".jpg", ".jpeg", ".tmp")):
            return True
    return False


def trigger_capture_and_ingest(trigger_data):
    job_id = trigger_data.get("job_id", "unknown")
    pose_idx = trigger_data.get("pose_index", 0)
    log(f"[C1 Companion] Trigger received for job: {job_id} (pose_index={pose_idx})")

    switch_to_default_desktop()
    wins = find_c1_windows()

    target_hwnd = wins.get("live_view") or wins.get("main") or wins.get("c1_other")
    if not target_hwnd:
        log("[C1 Companion] Warning: Capture One window not found!")
        return False

    browser_hwnd = find_photobooth_browser()
    before_files = set(os.listdir(CAPTURE_DIR)) if os.path.exists(CAPTURE_DIR) else set()

    # 1st Shutter trigger pulse (Spacebar to Live View)
    send_shutter_pulse(wins, target_hwnd, attempt=1)
    # Immediately restore browser focus so photobooth UI remains seamless
    focus_browser(browser_hwnd)

    new_capture_file = None
    start_wait = time.time()
    retry_1_sent = False
    retry_2_sent = False

    while time.time() - start_wait < 25.0:
        elapsed = time.time() - start_wait
        time.sleep(0.08)
        current_files = set(os.listdir(CAPTURE_DIR))
        diff = current_files - before_files
        # Ignore temporary partial files starting with '~' until Capture One renames them to final .CR3
        image_diff = [
            f
            for f in diff
            if not f.startswith("~") and f.lower().endswith((".cr3", ".cr2", ".jpg", ".jpeg"))
        ]
        if image_diff:
            image_diff.sort(
                key=lambda fn: os.path.getmtime(os.path.join(CAPTURE_DIR, fn)), reverse=True
            )
            candidate = os.path.join(CAPTURE_DIR, image_diff[0])
            # Wait for file to finish writing (size > 100KB and stable)
            for _ in range(16):
                if os.path.exists(candidate) and os.path.getsize(candidate) > 200_000:
                    time.sleep(0.25)
                    new_capture_file = candidate
                    break
                time.sleep(0.15)
            if new_capture_file:
                break

        # If a file transfer is already detected in progress, NEVER retry (it will abort or double capture)
        if has_inflight_transfer(before_files):
            continue

        # Gentle Retry #1 only after 7.0s if camera hasn't responded (gives Canon M50 Mark II time to meter & write)
        if not retry_1_sent and elapsed >= 7.0:
            retry_1_sent = True
            log(f"[C1 Companion] {elapsed:.1f}s without transfer for {job_id} -> gentle Retry #1...")
            wins = find_c1_windows()
            send_shutter_pulse(wins, target_hwnd, attempt=2)
            focus_browser(browser_hwnd)

        # Gentle Retry #2 only after 14.0s
        if not retry_2_sent and elapsed >= 14.0:
            retry_2_sent = True
            log(f"[C1 Companion] {elapsed:.1f}s without transfer for {job_id} -> gentle Retry #2...")
            wins = find_c1_windows()
            send_shutter_pulse(wins, target_hwnd, attempt=3)
            focus_browser(browser_hwnd)

    if not new_capture_file:
        log(f"[C1 Companion] Timeout: No new capture file received from Canon for {job_id}")
        focus_browser(browser_hwnd)
        return False

    log(f"[C1 Companion] Captured file from Canon: {os.path.basename(new_capture_file)} (in {time.time() - start_wait:.2f}s)")
    expected_filename = trigger_data.get("expected_filename", job_id + ".jpg")
    dest_path = os.path.join(OUTPUT_DIR, expected_filename)

    focus_browser(browser_hwnd)

    ext = os.path.splitext(new_capture_file)[1].lower()
    success = False
    if ext == ".cr3":
        success = extract_cr3_jpeg(new_capture_file, dest_path)
    elif ext in [".jpg", ".jpeg"]:
        import shutil

        tmp_out = dest_path + ".tmp"
        shutil.copy2(new_capture_file, tmp_out)
        os.replace(tmp_out, dest_path)
        success = True

    if success:
        log(f"[C1 Companion] Ingested 24MP full-res photo -> {dest_path}")
        done_path = os.path.join(OUTPUT_DIR, job_id + ".done.json")
        tmp_done = done_path + ".tmp"
        try:
            with open(tmp_done, "w", encoding="utf-8") as df:
                json.dump(
                    {
                        "job_id": job_id,
                        "session_id": trigger_data.get("session_id"),
                        "pose_index": trigger_data.get("pose_index"),
                        "captured_file": os.path.basename(new_capture_file),
                        "output_file": expected_filename,
                        "completed_at": int(time.time()),
                        "status": "completed",
                    },
                    df,
                    indent=2,
                )
            os.replace(tmp_done, done_path)
        except Exception:
            pass
        return True
    return False


def read_trigger_json_safely(tf_path, max_attempts=6):
    """
    Wait for atomic write/flush to complete before parsing JSON.
    Never returns None due to transient 0-byte file on Docker-Windows bind mount.
    """
    for attempt in range(max_attempts):
        if not os.path.exists(tf_path):
            return None
        try:
            if os.path.getsize(tf_path) < 10:
                time.sleep(0.06)
                continue
            with open(tf_path, "r", encoding="utf-8") as f:
                raw = f.read().strip()
            if not raw:
                time.sleep(0.06)
                continue
            return json.loads(raw)
        except Exception:
            time.sleep(0.06)
    return None


def main():
    log("[C1 Companion Daemon] Starting Canon EOS M50 Mark II & Capture One companion...")
    last_hb = 0
    while True:
        now = time.time()
        if now - last_hb >= 1.0:
            update_heartbeat()
            last_hb = now

        try:
            trigger_files = [
                f
                for f in os.listdir(INPUT_DIR)
                if f.endswith(".trigger.json") and not f.startswith(".")
            ]
            trigger_files.sort()
            for tf in trigger_files:
                tf_path = os.path.join(INPUT_DIR, tf)
                data = read_trigger_json_safely(tf_path)
                if data is None:
                    log(f"[C1 Companion] Skipping unreadable/empty trigger file {tf}")
                    try:
                        if os.path.exists(tf_path) and (time.time() - os.path.getmtime(tf_path)) > 5.0:
                            os.remove(tf_path)
                    except Exception:
                        pass
                    continue

                # Remove trigger file BEFORE or right after reading valid JSON so we don't re-trigger
                try:
                    os.remove(tf_path)
                except Exception:
                    pass

                try:
                    trigger_capture_and_ingest(data)
                except Exception as err:
                    log(f"[C1 Companion] Error processing trigger {tf}: {err}")
        except Exception:
            pass

        time.sleep(0.06)


if __name__ == "__main__":
    main()
