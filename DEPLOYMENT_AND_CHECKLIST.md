# TÀI LIỆU TRIỂN KHAI, VẬN HÀNH & CHECKLIST NGHIỆM THU (TYS PHOTOBOOTH)

Tài liệu này hướng dẫn cài đặt, vận hành, sao lưu, khắc phục sự cố và danh sách nghiệm thu toàn trình từ **Giai đoạn 0 đến Giai đoạn 8**.

---

## 1. Kiến trúc tổng thể đã hoàn thiện

1. **Xử lý Preset Lightroom cục bộ (`src/Service/PresetService.php`, `src/Processor/ColorPresetProcessor.php`)**:
   - Tự động quét file `.xmp` / `.cube` trong thư mục `PresetColor/`.
   - Tự động nướng (bake) 3D HaldCLUT PNG vào `private/presets/`.
   - Chỉnh cường độ `Amount` (0% – 100%), giữ nút `Before/After` và tự động dán lại lớp Frame (`reapplyFrameOverlay`) để không làm ám màu khung viền.
   - Lưu vết `preset` và `preset_amount` vào file metadata `meta_{filename}.json`.

2. **Tạo ảnh động GIF & Bảo toàn Session (`src/Processor/GifProcessor.php`, `api/applyEffects.php`)**:
   - Lưu trữ toàn bộ các pose gốc (`orig_pose_1_...`, `orig_pose_2_...`) và bản ghép gốc (`orig_...`).
   - Tự động tạo ảnh động `.gif` từ các pose chụp liên tiếp (hỗ trợ cả `Imagick` và bộ mã hóa nhị phân `GD GIF89a` thuần PHP).
   - Khi khách đổi Preset màu và xác nhận, hệ thống tự động cập nhật màu Preset cho cả ảnh thành phẩm lẫn ảnh GIF.

3. **Kiến trúc Camera Đa Thiết Bị (`src/Camera/`, `src/Service/CameraManagerService.php`)**:
   - Giao diện chuẩn `CameraProviderInterface` (`connect`, `reconnect`, `disconnect`, `getStatus`, `capture`).
   - `WebcamProvider`: Chụp qua trình duyệt HTML5 / UVC Webcam, kiểm tra toàn vẹn dữ liệu ảnh.
   - `SonyCameraProvider`: Tối ưu cho **Sony α6400 (ILCE-6400)** và **Sony ZV-E10**, hỗ trợ tự động reset cổng USB PTP khi máy ảnh bị treo/ngủ và cơ chế khóa thiết bị (`var/run/camera_*.lock`) chống xung đột đa tiến trình.
   - `MockCameraProvider`: Giả lập máy ảnh phục vụ kiểm thử luồng nghiệp vụ khi chưa cắm máy ảnh vật lý (`is_mock: true`).

4. **In ảnh & Hàng đợi Windows Spooler (`api/print.php`, `api/printStatus.php`, `scripts/windows-print-spooler.ps1`)**:
   - Chặn tuyệt đối việc in nhầm ảnh `preview_` hoặc `orig_`.
   - Lưu thông tin khổ giấy (`paper_size`), chiều ảnh (`orientation`), lề in (`margin`) và số bản (`copies`) vào `.job.json`.
   - Hỗ trợ kiểm tra trạng thái in thời gian thực và nút **Thử lại (Retry)** qua `api/printStatus.php`.

5. **Đồng bộ Offline-First & Website Chia Sẻ (`src/Service/SyncQueueService.php`, `api/syncReceive.php`, `view.php`)**:
   - Hàng đợi `data/sync_queue.json` giữ an toàn toàn bộ Session khi mất mạng tại sự kiện; chỉ đánh dấu `synced` khi máy chủ xác nhận.
   - Hỗ trợ 3 môi trường: `local`, `staging`, `production` kèm xác thực `X-Photobooth-Api-Key`.
   - Trang khách `view.php` (Mobile-first) hiển thị cả **Ảnh Ghép Khung** và **Ảnh Động GIF** trên cùng một trang Session, bảo vệ đường dẫn khỏi truy cập file nội bộ (`meta_`, `preview_`).

---

## 2. Hướng dẫn Cài đặt & Chạy hệ thống

### Bước 1: Khởi chạy ứng dụng bằng Docker
```bash
docker compose up --build -d
```
- **Màn hình Kiosk chụp ảnh:** `http://localhost`
- **Trang Quản trị Admin:** `http://localhost/admin`
- **Kiểm tra trạng thái Camera Provider:** `http://localhost/api/cameraStatus.php`
- **Kiểm tra trạng thái Hàng đợi Đồng bộ:** `http://localhost/api/syncStatus.php`

### Bước 2: Khởi chạy dịch vụ In tự động trên Windows
Mở PowerShell trên máy tính Windows kết nối máy in (DNP DS620, Canon Selphy, Citizen, Epson...):
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\windows-print-spooler.ps1
```

### Bước 3: Chạy kiểm thử tự động toàn trình (Không cần cắm DSLR)
```bash
docker exec -it photobooth-dev-photobooth-1 php scripts/verify-pipeline.php
```

---

## 3. Hướng dẫn Sao lưu, Cập nhật & Khắc phục sự cố

### Sao lưu dữ liệu sự kiện (Backup)
Toàn bộ dữ liệu sự kiện nằm tại thư mục `data/`:
- `data/images/`: Chứa ảnh thành phẩm, ảnh động `.gif`, ảnh gốc `orig_*` và file `meta_*.json`.
- `data/sync_queue.json`: Chứa lịch sử và trạng thái đồng bộ của mọi phiên chụp.
- Sao lưu nhanh bằng lệnh:
  ```powershell
  Copy-Item -Path .\data -Destination D:\Backup_Photobooth_$(Get-Date -Format "yyyyMMdd_HHmm") -Recurse
  ```

### Khắc phục sự cố thường gặp (Troubleshooting)
1. **Camera Sony α6400 / ZV-E10 không nhận lệnh chụp:**
   - Kiểm tra trên thân máy Sony: `Menu -> Network -> PC Remote Function -> PC Remote: On`, `PC Remote Cnct Method: USB`.
   - Tắt các phần mềm chiếm quyền USB trên máy tính (Sony Imaging Edge, Lightroom Tether, EOS Utility, OneDrive auto-import).
   - Gọi API tự động reset kết nối: `POST /api/cameraStatus.php?action=reconnect`.
2. **Mất mạng Internet tại địa điểm sự kiện:**
   - Hệ thống vẫn chụp, ghép khung, tạo GIF, in ảnh và phát mã QR qua Wi-Fi nội bộ (LAN IP) bình thường.
   - Các phiên chụp được lưu ở trạng thái `pending` / `retry_pending` trong `data/sync_queue.json`. Khi có mạng lại, gọi `POST /api/syncStatus.php?action=process_queue` để đẩy bù toàn bộ ảnh lên Website.
3. **Máy in hết giấy hoặc kẹt giấy:**
   - Thay giấy, sau đó sử dụng `POST /api/printStatus.php?action=retry&job_id={ID}` hoặc nhấn In lại trên màn hình kết quả.

---

## 4. Checklist Nghiệm Thu Toàn Trình (Stage 0 – Stage 8)

- [x] **Giai đoạn 1:** Chụp qua Webcam / Mock ổn định, kiểm tra toàn vẹn ảnh, tách biệt giao diện Kiosk (`index.php`) và trang tải ảnh khách (`view.php`).
- [x] **Giai đoạn 2:** Đọc `.xmp`, bake 3D HaldCLUT, chỉnh thanh trượt Amount (0–100%), giữ nút Before/After, bảo vệ màu khung Frame, lưu preset vào `meta_*.json`.
- [x] **Giai đoạn 3:** Chọn Frame trước khi chụp, chụp lại từng pose (Per-shot Retake), bảo toàn ảnh gốc `orig_*`, tự động xuất ảnh động `.gif`.
- [x] **Giai đoạn 4:** Kiến trúc đa thiết bị `CameraProviderInterface` (`WebcamProvider`, `SonyCameraProvider`, `MockCameraProvider`), cơ chế khóa file `.lock` chống xung đột.
- [x] **Giai đoạn 5:** Hàng đợi in `.job.json` + Windows Print Spooler, chặn in nhầm file `preview_`/`orig_`, theo dõi trạng thái in và thử lại (`api/printStatus.php`).
- [x] **Giai đoạn 6:** Hàng đợi đồng bộ Offline-First (`SyncQueueService`), chống trùng bản ghi theo `session_id`, xác thực API Key (`api/syncReceive.php`).
- [x] **Giai đoạn 7:** Trang `view.php` hiển thị đồng thời Ảnh Ghép Khung + Ảnh Động GIF, tự động phân giải IP LAN cho QR Code, chặn tải file nội bộ.
- [x] **Giai đoạn 8:** Script kiểm thử & đo hiệu năng tự động (`scripts/verify-pipeline.php`) và tài liệu vận hành.

