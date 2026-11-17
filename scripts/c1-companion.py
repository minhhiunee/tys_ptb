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

for d in [INPUT_DIR, OUTPUT_DIR, CAPTURE_DIR]:
    os.makedirs(d, exist_ok=True)

DESKTOP_ALL = 0x01FF
WM_KEYDOWN = 0x0100
WM_KEYUP = 0x0101
VK_SPACE = 0x20
VK_CONTROL = 0x11
VK_D = 0x44
VK_F7 = 0x76

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
            if title == "Live View":
                wins['live_view'] = hwnd
            elif title == "Photobooth":
                wins['main'] = hwnd
            elif "Capture One" in title:
                wins['c1_other'] = hwnd
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
        "status": "ready" if wins else "waiting_for_capture_one"
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
            out_f.write(data[best_offset:best_offset + best_len])
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
            if 'photobooth' in title and any(br in title for br in ['cốc cốc', 'chrome', 'edge', 'firefox', 'opera', 'brave', 'browser']):
                found = hwnd
                return 0
        return 1
    cb_proto = ctypes.WINFUNCTYPE(ctypes.c_int, wintypes.HWND, wintypes.LPARAM)
    user32.EnumWindows(cb_proto(enum_browser_cb), 0)
    return found

def focus_browser(browser_hwnd=None):
    if not browser_hwnd or not user32.IsWindow(browser_hwnd):
        browser_hwnd = find_photobooth_browser()
    if browser_hwnd and user32.IsWindow(browser_hwnd):
        user32.keybd_event(0x12, 0, 0, 0) # Alt key down to unlock SetForegroundWindow
        user32.SetForegroundWindow(browser_hwnd)
        user32.keybd_event(0x12, 0, 2, 0) # Alt key up

def trigger_capture_and_ingest(trigger_data, trigger_file):
    print(f"[C1 Companion] Trigger received for job: {trigger_data.get('job_id')}")
    switch_to_default_desktop()
    wins = find_c1_windows()
    
    target_hwnd = wins.get('live_view') or wins.get('main')
    if not target_hwnd:
        print("[C1 Companion] Warning: Capture One window not found!")
        return False

    # Remember the browser window running Photobooth so we can restore it immediately after trigger
    browser_hwnd = find_photobooth_browser()

    # Snapshot existing files in Capture directory
    before_files = set(os.listdir(CAPTURE_DIR)) if os.path.exists(CAPTURE_DIR) else set()
    
    # Send Shutter trigger to Capture One
    if user32.IsIconic(target_hwnd):
        user32.ShowWindow(target_hwnd, 9)
    user32.SetForegroundWindow(target_hwnd)
    time.sleep(0.08)
    
    if wins.get('live_view'):
        # In Live View, Spacebar triggers capture
        user32.keybd_event(VK_SPACE, 0, 0, 0)
        time.sleep(0.05)
        user32.keybd_event(VK_SPACE, 0, 2, 0)
        print("[C1 Companion] Sent SPACEBAR to Live View window")
    else:
        # In Main window, F7 triggers capture
        user32.keybd_event(VK_F7, 0, 0, 0)
        time.sleep(0.05)
        user32.keybd_event(VK_F7, 0, 2, 0)
        print("[C1 Companion] Sent F7 to Main window")

    # Immediately restore the browser window so Live View never covers the Photobooth screen
    focus_browser(browser_hwnd)

    # Wait for new file to arrive in Capture folder (up to 8s)
    new_capture_file = None
    start_wait = time.time()
    while time.time() - start_wait < 8.0:
        time.sleep(0.1)
        current_files = set(os.listdir(CAPTURE_DIR))
        diff = current_files - before_files
        image_diff = [f for f in diff if f.lower().endswith(('.cr3', '.cr2', '.jpg', '.jpeg'))]
        if image_diff:
            # Sort by mod time to get the freshest file
            image_diff.sort(key=lambda fn: os.path.getmtime(os.path.join(CAPTURE_DIR, fn)), reverse=True)
            candidate = os.path.join(CAPTURE_DIR, image_diff[0])
            # Ensure file is done writing
            time.sleep(0.2)
            if os.path.exists(candidate) and os.path.getsize(candidate) > 100_000:
                new_capture_file = candidate
                break

    if not new_capture_file:
        print("[C1 Companion] Timeout: No new capture file received from Canon")
        focus_browser(browser_hwnd)
        return False

    print(f"[C1 Companion] Captured file from Canon: {os.path.basename(new_capture_file)}")
    expected_filename = trigger_data.get("expected_filename", trigger_data.get("job_id") + ".jpg")
    dest_path = os.path.join(OUTPUT_DIR, expected_filename)
    
    # Ensure Photobooth browser window remains on top
    focus_browser(browser_hwnd)

    # Extract 6000x4000 full resolution JPEG immediately
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
        print(f"[C1 Companion] Ingested 24MP full-res photo -> {dest_path}")
        done_path = os.path.join(OUTPUT_DIR, trigger_data.get("job_id") + ".done.json")
        try:
            with open(done_path, "w", encoding="utf-8") as df:
                json.dump({
                    "job_id": trigger_data.get("job_id"),
                    "session_id": trigger_data.get("session_id"),
                    "pose_index": trigger_data.get("pose_index"),
                    "captured_file": os.path.basename(new_capture_file),
                    "output_file": expected_filename,
                    "completed_at": int(time.time()),
                    "status": "completed"
                }, df, indent=2)
        except Exception:
            pass
        return True
    return False

def main():
    print("[C1 Companion Daemon] Starting Canon EOS M50 Mark II & Capture One companion...")
    last_hb = 0
    while True:
        now = time.time()
        if now - last_hb >= 1.0:
            update_heartbeat()
            last_hb = now

        # Check for trigger jobs
        try:
            trigger_files = [f for f in os.listdir(INPUT_DIR) if f.endswith(".trigger.json")]
            for tf in trigger_files:
                tf_path = os.path.join(INPUT_DIR, tf)
                try:
                    with open(tf_path, "r", encoding="utf-8") as f:
                        data = json.load(f)
                    trigger_capture_and_ingest(data, tf_path)
                except Exception as err:
                    print(f"[C1 Companion] Error processing trigger {tf}: {err}")
                finally:
                    try:
                        os.remove(tf_path)
                    except Exception:
                        pass
        except Exception as err:
            pass

        time.sleep(0.08)

if __name__ == "__main__":
    main()
