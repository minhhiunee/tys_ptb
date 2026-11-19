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
    w = rect.right - rect.left
    h = rect.bottom - rect.top
    print(f"Live View rect: {rect.left}, {rect.top}, {w}x{h}")

    # Let's take a screenshot of Live View right now using PrintWindow
    gdi32 = ctypes.windll.gdi32
    hwndDC = user32.GetWindowDC(live_hwnd)
    mfcDC = gdi32.CreateCompatibleDC(hwndDC)
    saveBitMap = gdi32.CreateCompatibleBitmap(hwndDC, w, h)
    gdi32.SelectObject(mfcDC, saveBitMap)
    user32.PrintWindow(live_hwnd, mfcDC, 2)

    from PIL import Image
    import numpy as np

    class BITMAPINFOHEADER(ctypes.Structure):
        _fields_ = [
            ('biSize', wintypes.DWORD),
            ('biWidth', wintypes.LONG),
            ('biHeight', wintypes.LONG),
            ('biPlanes', wintypes.WORD),
            ('biBitCount', wintypes.WORD),
            ('biCompression', wintypes.DWORD),
            ('biSizeImage', wintypes.DWORD),
            ('biXPelsPerMeter', wintypes.LONG),
            ('biYPelsPerMeter', wintypes.LONG),
            ('biClrUsed', wintypes.DWORD),
            ('biClrImportant', wintypes.DWORD)
        ]

    bmi = BITMAPINFOHEADER()
    bmi.biSize = ctypes.sizeof(BITMAPINFOHEADER)
    bmi.biWidth = w
    bmi.biHeight = -h
    bmi.biPlanes = 1
    bmi.biBitCount = 32
    bmi.biCompression = 0

    buff = (ctypes.c_char * (w * h * 4))()
    gdi32.GetDIBits(mfcDC, saveBitMap, 0, h, buff, ctypes.byref(bmi), 0)
    img = Image.frombuffer('RGBA', (w, h), buff, 'raw', 'BGRA', 0, 1).convert('RGB')
    img.save(r"d:\CodingPro\photobooth-dev\scripts\lv_current.png")
    print("Saved current Live View screenshot to d:\\CodingPro\\photobooth-dev\\scripts\\lv_current.png")
