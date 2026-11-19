import ctypes
from ctypes import wintypes

user32 = ctypes.windll.user32
hdesk = user32.OpenDesktopW("Default", 0, False, 0x01FF)
if hdesk:
    user32.SetThreadDesktop(hdesk)

hwnd = 462610
pt = wintypes.POINT(284, 308)
user32.ScreenToClient(hwnd, ctypes.byref(pt))
inner = user32.ChildWindowFromPoint(hwnd, pt)
cls_b = ctypes.create_unicode_buffer(256)
user32.GetClassNameW(inner, cls_b, 256)
txt_b = ctypes.create_unicode_buffer(256)
user32.GetWindowTextW(inner, txt_b, 256)
print(f"Inner child at: HWND {inner}, Class='{cls_b.value}', Title='{txt_b.value}'")
