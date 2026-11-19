import ctypes
from ctypes import wintypes

user32 = ctypes.windll.user32
DESKTOP_ALL = 0x01FF

def switch_to_default_desktop():
    hdesk = user32.OpenDesktopW("Default", 0, False, DESKTOP_ALL)
    if hdesk:
        user32.SetThreadDesktop(hdesk)

def inspect():
    switch_to_default_desktop()
    wins = []
    def cb(hwnd, lparam):
        l = user32.GetWindowTextLengthW(hwnd)
        if l > 0:
            buff = ctypes.create_unicode_buffer(l + 1)
            user32.GetWindowTextW(hwnd, buff, l + 1)
            if any(k in buff.value.lower() for k in ["live view", "photobooth", "capture one"]):
                vis = user32.IsWindowVisible(hwnd)
                wins.append((hwnd, buff.value, vis))
        return 1

    user32.EnumWindows(ctypes.WINFUNCTYPE(ctypes.c_int, wintypes.HWND, wintypes.LPARAM)(cb), 0)
    for hwnd, title, vis in wins:
        print(f"HWND {hwnd}: '{title}' (vis={vis})")

if __name__ == "__main__":
    inspect()
