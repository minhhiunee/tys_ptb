import ctypes
from ctypes import wintypes
import subprocess

user32 = ctypes.windll.user32
hdesk = user32.OpenDesktopW("Default", 0, False, 0x01FF)
if hdesk:
    user32.SetThreadDesktop(hdesk)

out = subprocess.check_output('powershell -Command "(Get-Process CaptureOne).Id"', shell=True).decode().strip()
c1_pids = {int(x) for x in out.split() if x.isdigit()}
print("CaptureOne PIDs:", c1_pids)

def enum_cb(hwnd, l):
    if user32.IsWindowVisible(hwnd):
        pid = wintypes.DWORD()
        user32.GetWindowThreadProcessId(hwnd, ctypes.byref(pid))
        if pid.value in c1_pids:
            length = user32.GetWindowTextLengthW(hwnd)
            buff = ctypes.create_unicode_buffer(length + 1)
            user32.GetWindowTextW(hwnd, buff, length + 1)
            cls_buff = ctypes.create_unicode_buffer(256)
            user32.GetClassNameW(hwnd, cls_buff, 256)
            print(f"HWND {hwnd}: Title='{buff.value}', Class='{cls_buff.value}'")
    return 1

user32.EnumWindows(ctypes.WINFUNCTYPE(ctypes.c_int, wintypes.HWND, wintypes.LPARAM)(enum_cb), 0)
