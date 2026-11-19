import ctypes
from ctypes import wintypes
import time
import os

user32 = ctypes.windll.user32
hdesk = user32.OpenDesktopW("Default", 0, False, 0x01FF)
if hdesk:
    user32.SetThreadDesktop(hdesk)

# Find Live View dynamically
live_hwnd = 0
def cb(h, l):
    global live_hwnd
    if user32.IsWindowVisible(h):
        b = ctypes.create_unicode_buffer(512)
        user32.GetWindowTextW(h, b, 512)
        if b.value == "Live View" or b.value.startswith("Live View"):
            live_hwnd = h
            return 0
    return 1

user32.EnumWindows(ctypes.WINFUNCTYPE(ctypes.c_int, wintypes.HWND, wintypes.LPARAM)(cb), 0)
print("Found Live View:", live_hwnd)

if live_hwnd:
    class RECT(ctypes.Structure):
        _fields_ = [("left", ctypes.c_long),
                    ("top", ctypes.c_long),
                    ("right", ctypes.c_long),
                    ("bottom", ctypes.c_long)]

    rect = RECT()
    user32.GetWindowRect(live_hwnd, ctypes.byref(rect))
    print(f"Live View rect: {rect.left}, {rect.top}, {rect.right}, {rect.bottom}")

    capture_dir = r"D:\CodingPro\photobooth-dev\data\c1_session\Photobooth\Capture"
    before = set(os.listdir(capture_dir))

    # Test Spacebar with adequate hold time
    user32.SetForegroundWindow(live_hwnd)
    time.sleep(0.12)
    VK_SPACE = 0x20
    user32.keybd_event(VK_SPACE, 0, 0, 0)
    time.sleep(0.12)
    user32.keybd_event(VK_SPACE, 0, 2, 0)
    time.sleep(0.25)
    print("Sent Spacebar! Checking capture...")

    for i in range(12):
        time.sleep(0.5)
        diff = set(os.listdir(capture_dir)) - before
        new_imgs = [f for f in diff if f.lower().endswith(('.cr3', '.jpg'))]
        if new_imgs:
            print(f"SUCCESS in {i*0.5 + 0.5}s! Files:", new_imgs)
            break
    else:
        print("No new file after 6s")
