import ctypes
from ctypes import wintypes

user32 = ctypes.windll.user32
hdesk = user32.OpenDesktopW("Default", 0, False, 0x01FF)
if hdesk:
    user32.SetThreadDesktop(hdesk)

hwnd_wpf = 70366
class RECT(ctypes.Structure):
    _fields_ = [('left', ctypes.c_long), ('top', ctypes.c_long), ('right', ctypes.c_long), ('bottom', ctypes.c_long)]

w_rect = RECT()
user32.GetWindowRect(hwnd_wpf, ctypes.byref(w_rect))
pt_wpf = wintypes.POINT(0, 0)
user32.ClientToScreen(hwnd_wpf, ctypes.byref(pt_wpf))
print(f"HWND 70366 screen origin: ({pt_wpf.x}, {pt_wpf.y}), size: {w_rect.right - w_rect.left}x{w_rect.bottom - w_rect.top}")

# Live View origin
live = 266070
pt_live = wintypes.POINT(0, 0)
user32.ClientToScreen(live, ctypes.byref(pt_live))
print(f"Live View screen origin: ({pt_live.x}, {pt_live.y})")

# Shutter button center in screen space:
# In lv_left_full.png, button center was (284, 308) from Live View origin
screen_btn_x = pt_live.x + 284
screen_btn_y = pt_live.y + 308
print(f"Shutter button in Screen coordinates: ({screen_btn_x}, {screen_btn_y})")

# In HWND 70366 client space:
wpf_btn_x = screen_btn_x - pt_wpf.x
wpf_btn_y = screen_btn_y - pt_wpf.y
print(f"Shutter button in HWND 70366 client coordinates: ({wpf_btn_x}, {wpf_btn_y})")
