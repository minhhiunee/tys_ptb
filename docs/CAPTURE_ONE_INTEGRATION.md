# Capture One Pro Integration Guide (Photobooth Studio & Event Kiosk)

Tài liệu kỹ thuật và hướng dẫn triển khai tích hợp **Capture One Pro** thay thế Adobe Lightroom Classic trong hệ thống Photobooth Kiosk.

---

## 1. Architecture Overview (Tổng Quan Kiến Trúc)

Capture One đóng vai trò là **External Image Processing Provider** chuyên trách tiếp nhận ảnh gốc (RAW/JPEG), áp dụng **Capture One Style (`.costyle`)** và xuất ảnh hoàn chỉnh qua **Process Recipe**, trong khi Photobooth giữ toàn quyền điều phối phiên chụp (`session_id`, `pose 1..N`), ghép khung Collage, tạo ảnh động GIF tua nhanh 2x, in nhiệt, mã QR và đồng bộ Cloudflare R2 / LAN.

```text
Camera (Sony a6400 / ZV-E10 / Webcam)
  ↓
Capture / Tethered Capture
  ↓
Capture One Pro (Next Capture Adjustments / Style + Process Recipe)
  ↓
Processed Output Folder (data/c1_output)
  ↓
CaptureOneIngestionService (File Stability + MIME/EXIF + Idempotency SHA-1)
  ↓
CaptureOneIntegrationService (Session & Pose Association + Auto-Fallback)
  ↓
Photobooth Pipeline (Collage + PNG Frame Overlay + 2x Timelapse GIF)
  ↓
Print / QR Delivery / Cloudflare R2 / Offline LAN
```

### Hai chế độ sở hữu Camera (Camera Ownership Modes)
1. **Option B — `photobooth_owns_camera` (Hot Folder Bridge — Mặc định khuyến nghị):**
   - Photobooth điều khiển chụp ảnh và Live View (hỗ trợ cả Sony α6400, ZV-E10, Webcam và Mock).
   - Ngay khi chụp xong từng pose, Photobooth lưu bản backup gốc chưa chỉnh màu (`raw_ungraded_*`), sau đó đẩy pose vào `data/c1_input/` kèm file manifest `.job.json`.
   - Script cầu nối trên Windows Host (`scripts/c1-host-bridge.ps1`) chuyển ảnh vào Capture One Hot Folder để xuất ảnh đã áp Style ra `data/c1_output/`.
2. **Option A — `capture_one_owns_camera` (Direct USB Tethered Capture):**
   - Capture One giữ độc quyền kết nối USB PTP với máy ảnh Sony để chụp RAW trực tiếp.
   - Live View trên màn hình Kiosk lấy qua cổng HDMI Capture Card (UVC) để tránh xung đột USB và khắc phục giới hạn Sony α6400 không có Live View trong Capture One.
   - Ảnh sau khi chụp được Capture One tự động áp Style (`Next Capture Adjustments`) và xuất vào `data/c1_output/`.

---

## 2. Capture One Installation Guide (Cài Đặt Capture One Trên Windows Host)

1. Cài đặt **Capture One Pro** (phiên bản 21, 22, 23 hoặc Capture One Pro 16.x) trên máy tính Windows Host chạy Kiosk.
2. Đường dẫn thực thi mặc định trên Windows:
   ```text
   C:\Program Files\Capture One\Capture One\CaptureOne.exe
   ```
3. Kích hoạt bản quyền **Capture One Pro** hoặc **Capture One Enterprise / Studio** (khuyến nghị bản có hỗ trợ **Hot Folder** nếu dùng chế độ `photobooth_owns_camera`).
4. Khởi chạy script cầu nối trên Windows Host (PowerShell):
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\c1-host-bridge.ps1
   ```

---

## 3. Capture One Session Setup (Thiết Lập Session)

Không tạo Session mới cho từng tấm ảnh để đảm bảo tốc độ phản hồi tức thì. Sử dụng **1 Session cố định cho sự kiện**:

1. Mở Capture One Pro -> `File > New Session...`
2. Đặt tên Session: `Photobooth` và trỏ thư mục lưu về thư mục `data/c1_session` của dự án:
   - `Capture Folder`: `d:\CodingPro\photobooth-dev\data\c1_session\Capture`
   - `Output Folder`: `d:\CodingPro\photobooth-dev\data\c1_output`
   - `Selects Folder`: `d:\CodingPro\photobooth-dev\data\c1_session\Selects`
   - `Trash Folder`: `d:\CodingPro\photobooth-dev\data\c1_session\Trash`
3. Nếu dùng chế độ **Hot Folder (`photobooth_owns_camera`)**:
   - Bật `Camera > Hot Folder Enabled` trong thanh menu của Capture One.

---

## 4. Tethered Capture Setup (Cấu Hình Cho Sony α6400 & Sony ZV-E10)

### Cấu hình trên thân máy Sony (α6400 / ZV-E10)
1. Vào `Menu > Network > PC Remote Function`:
   - `PC Remote`: **On**
   - `PC Remote Cnct Method`: **USB**
   - `Still Img. Save Dest.`: **PC Only** (hoặc **PC + Camera** nếu muốn lưu thẻ nhớ dự phòng).
2. Lưu ý về **Live View**:
   - **Sony ZV-E10 (Mark I):** Hỗ trợ cả Tethered Capture và Live View trong Capture One.
   - **Sony α6400 (ILCE-6400):** Hỗ trợ Tethered Capture qua USB nhưng **không hỗ trợ Live View trong Capture One**. Để hiển thị khung ngắm trên giao diện Kiosk Photobooth, hãy sử dụng cổng HDMI Out của α6400 qua HDMI-to-USB Capture Card (nhận diện như Webcam UVC trong trình duyệt).

---

## 5. Styles Installation and Management (Cài Đặt & Quản Lý Style Màu)

1. Đặt các file Capture One Style (`.costyle`), Lightroom XMP (`.xmp`), hoặc 3D LUT (`.cube`) vào thư mục:
   ```text
   d:\CodingPro\photobooth-dev\PresetColor\
   ```
2. Hệ thống `PresetService` của Photobooth sẽ tự động:
   - Phát hiện file `.costyle` (ví dụ: `PresetColor/CaptureOne Studio Skin.costyle`).
   - Phân tích thông số màu (`Exposure`, `Brightness`, `Contrast`, `Saturation`, `HighlightRecovery`, `ShadowRecovery`, `BlackLevel`, `Clarity`) và sinh bảng màu 3D HaldCLUT tương ứng trong `private/presets/`.
3. Trong Capture One Pro:
   - Import file `.costyle` vào tab `Adjustments > Styles > User Styles`.
   - Tại bảng **Next Capture Adjustments**, chọn mục **Styles** và chọn Style mặc định cho sự kiện để mọi ảnh mới chụp đều được áp màu tự động.

---

## 6. Process Recipe Configuration (Cấu Hình Xuất Ảnh)

Trong tab **Output / Export -> Process Recipes** của Capture One:
1. Tạo một Recipe mới tên là: `Photobooth_JPEG_sRGB` (khớp với cấu hình trong Admin).
2. Tab **Basic**:
   - `Format`: **JPEG**
   - `Quality`: **92**
   - `ICC Profile`: **sRGB IEC61966-2.1**
   - `Resolution`: **300 px/in**
   - `Scale`: **Dimensions -> Long Edge -> 2400 px** (hoặc `Fixed 100%`).
3. Tab **File**:
   - `Root Folder`: **Output Location** (trỏ về `d:\CodingPro\photobooth-dev\data\c1_output`).
   - `Sub Folder`: *(Để trống)*
   - `Output Naming`: **[Image Name]** (giữ nguyên tên file `c1_<sessionId>_p<poseIdx>` để Photobooth khớp chính xác 100%).

---

## 7. Folder Integration & Docker Volume Mapping

Trong [docker-compose.yml](file:///d:/CodingPro/photobooth-dev/docker-compose.yml), thư mục `./data` trên Windows Host được bind-mount trực tiếp vào `/app/data` trong Linux Container:

| Đường dẫn trong Container | Đường dẫn trên Windows Host | Chức năng |
|---|---|---|
| `/app/data/c1_input` | `.\data\c1_input` | Nhận ảnh pose và file `.job.json` từ Photobooth gửi sang Capture One |
| `/app/data/c1_output` | `.\data\c1_output` | Nhận ảnh JPEG/TIFF đã xử lý từ Capture One Process Recipe |
| `/app/data/c1_archive` | `.\data\c1_archive` | Lưu lịch sử các ảnh đã ingest thành công và các file trùng lặp bị chặn |
| `/app/data/c1_queue.json` | `.\data\c1_queue.json` | Cơ sở dữ liệu trạng thái hàng đợi, SHA-1 idempotency và thống kê |

---

## 8. Troubleshooting (Xử Lý Sự Cố)

| Triệu chứng | Nguyên nhân | Cách khắc phục |
|---|---|---|
| Admin báo `Host Bridge: STANDBY (Auto-Fallback Ready)` | Script `scripts/c1-host-bridge.ps1` chưa bật trên Windows Host | Mở PowerShell trên Windows chạy `.\scripts\c1-host-bridge.ps1`. Trong lúc chờ, Photobooth vẫn hoạt động bình thường nhờ cơ chế Auto-Fallback về `PresetService`. |
| Ảnh xuất ra trong `data/c1_output` không được nhận | Sai định dạng hoặc file bị hỏng | Kiểm tra `var/log/c1-ingest.log` hoặc mở `api/captureOneStatus.php` xem trường `recent_errors`. Chỉ nhận `.jpg`, `.jpeg`, `.tif`, `.tiff`. |
| Xung đột cổng USB máy ảnh Sony | Cả `gphoto2` và `Capture One` cùng cố mở USB PTP | Chuyển `Camera Ownership Mode` trong Admin sang `capture_one_owns_camera` nếu dây USB cắm vào Capture One, hoặc tắt Tethering của Sony trong `Capture One > Preferences > Capture` nếu dùng `photobooth_owns_camera`. |

---

## 9. Admin Configuration (Cấu Hình Trong Bảng Điều Khiển)

Truy cập `/admin` -> mục **Capture One Pro**:
- **Enable Capture One Integration:** Bật/tắt tích hợp Capture One.
- **Camera Ownership Mode:** Chọn giữa `Option B (Hot Folder Bridge)` và `Option A (Direct USB Tethered)`.
- **Default Capture One Style:** Chọn Style màu mặc định.
- **Process Recipe Name:** Tên Recipe trong Capture One.
- **Auto-Fallback to Local Preset Engine on Timeout:** Khi bật (mặc định `ON`), nếu Capture One bị đóng đột ngột hoặc quá thời gian chờ, hệ thống tự động áp màu bằng `PresetService` nội bộ để Kiosk không bao giờ bị gián đoạn.

---

## 10. Developer Integration Guide (Hướng Dẫn Cho Lập Trình Viên)

### Các Class & API chính
- [src/Configuration/Section/CaptureOneConfiguration.php](file:///d:/CodingPro/photobooth-dev/src/Configuration/Section/CaptureOneConfiguration.php): Định nghĩa schema cấu hình Symfony Config.
- [src/Service/CaptureOneIntegrationService.php](file:///d:/CodingPro/photobooth-dev/src/Service/CaptureOneIntegrationService.php): Quản lý job, điều phối ingest, fallback, và chẩn đoán hệ thống.
- [src/Service/CaptureOneIngestionService.php](file:///d:/CodingPro/photobooth-dev/src/Service/CaptureOneIngestionService.php): Kiểm tra tính ổn định của file (`verifyFileStabilityAndValidity`), đọc EXIF, khớp `(session_id, pose_index)` và chuyển đổi TIFF sang JPEG.
- [api/captureOneStatus.php](file:///d:/CodingPro/photobooth-dev/api/captureOneStatus.php):
  - `GET /api/captureOneStatus.php`: Lấy toàn bộ thông tin Diagnostics & Queue.
  - `POST /api/captureOneStatus.php` (`action=self_test`): Chạy kiểm thử tự động toàn trình (End-to-End Ingestion + Idempotency Self-Test).
  - `POST /api/captureOneStatus.php` (`action=scan_now`): Kích hoạt quét thư mục `data/c1_output` ngay lập tức.

