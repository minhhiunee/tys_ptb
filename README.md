# Photobooth System (tys_ptb)

Một hệ thống Photobooth web interface chuyên nghiệp, hiệu năng cao và ổn định, được thiết kế tối ưu cho các sự kiện cưới hỏi, tiệc, kiosk tự động và studio chụp ảnh lấy liền.

---

## 🚀 Điểm Mới & Tính Năng Nổi Bật (Key Features)

Dự án đã được nâng cấp toàn diện từ nền tảng gốc sang hệ thống Photobooth hiện đại với các tính năng vượt trội:

### 1. Hệ thống Preset màu Lightroom cục bộ (Local-first < 0.3s)
* **Auto-discovery XMP/LUT:** Tự động phát hiện và nạp các file preset `.xmp`, `.cube`, `.dng` đặt trong thư mục `PresetColor/`.
* **Nướng màu 3D HaldCLUT:** Tự động parse thông số Camera Raw (Exposure, Contrast, Saturation, Tone Curve, Split Toning...) và nướng thành ma trận màu HaldCLUT PNG chuẩn xác.
* **Thanh trượt Amount (0% – 100%):** Cho phép khách điều chỉnh mượt mà cường độ màu sắc theo ý thích.
* **So sánh Trước/Sau (Hold Before/After):** Nhấn giữ chuột/chạm nút hoặc phím tắt `\` để so sánh tức thì với ảnh gốc.
* **Bảo vệ màu viền khung Frame (`reapplyFrameOverlay`):** Đảm bảo khung viền PNG và logo thương hiệu **không bao giờ bị ám màu** khi khách áp preset lên ảnh.
* **Lưu vết Metadata:** Ghi lại thông tin `preset` và `preset_amount` vào file `meta_{filename}.json` của từng ảnh để có thể tái tạo kết quả chính xác.

### 2. Tự động xuất ảnh động Animated GIF
* **Tạo GIF tự động:** Ghép chuỗi các pose ảnh chụp trong phiên thành file `{session_id}.gif` chuyển động lặp vô hạn.
* **Bộ mã hóa kép (Dual Engine):** Hỗ trợ song song cả **PHP Imagick** lẫn **bộ mã hóa nhị phân GD GIF89a thuần PHP**, hoạt động trơn tru trên mọi môi trường container.
* **Đồng bộ màu sắc:** Khi khách hàng đổi màu Preset cho ảnh thành phẩm, ảnh động GIF cũng tự động được cập nhật tông màu tương ứng.

### 3. Chọn Khung Bố Cục & Chụp Lại Từng Kiểu (Per-shot Retake)
* **Thư viện Khung ảnh trực quan:** Modal chọn khung trước khi chụp kèm hình ảnh thực tế và số kiểu tương ứng:
  * **Khung 1 (Single):** 1 ảnh chân dung tỷ lệ 4:5 (`1975x2400`).
  * **Khung 2 (2 Poses):** 2 ảnh xếp dọc (`1858x2400`).
  * **Khung 3 (Photostrip 1:3):** 3 ảnh dải dọc photostrip phong cách Hàn Quốc (`800x2400`).
  * **Khung 4 (Grid 2x2):** 4 ảnh lưới vuông (`2364x2400`).
* **Xem lại & Chụp lại từng pose (Per-shot Review):** Ngay sau mỗi lần bấm máy, màn hình hiển thị ảnh vừa chụp kèm nút *"Chụp lại bức này"* (tránh trường hợp nhắm mắt) hoặc *"Xác nhận & Chụp tiếp"*.

### 4. Kiến trúc Camera Đa Thiết Bị (`src/Camera/`)
* **Giao diện chuẩn `CameraProviderInterface`:** Quản lý kết nối, trạng thái, chụp và tự động reconnect.
* **Cơ chế Process Lock (`.lock`):** Ngăn chặn hoàn toàn việc xung đột tài nguyên USB giữa tiến trình live view và tiến trình capture.
* **3 Provider độc lập:**
  * `WebcamProvider`: Chụp từ trình duyệt HTML5 WebRTC hoặc webcam USB UVC, kiểm tra toàn vẹn dữ liệu ảnh.
  * `SonyCameraProvider`: Tối ưu chuyên dụng cho **Sony α6400 (ILCE-6400)** và **Sony ZV-E10**, hỗ trợ tự động reset cổng USB PTP khi máy ảnh ngủ hoặc treo kết nối.
  * `MockCameraProvider`: Giả lập chụp ảnh với các pose mẫu sinh động, phục vụ việc kiểm thử trọn vẹn toàn bộ luồng nghiệp vụ khi chưa cắm máy ảnh vật lý.

### 5. Hàng đợi In ấn Đa Hãng trên Windows (Multi-Brand Print Spooler)
* **Bảo vệ in ấn an toàn:** Chặn tuyệt đối việc in nhầm file preview tạm thời hoặc file ảnh gốc chưa lồng khung.
* **PowerShell Spooler (`scripts/windows-print-spooler.ps1`):** Lắng nghe thư mục `data/print/*.job.json` từ container Docker và đẩy lệnh in trực tiếp ra máy in Windows (DNP DS620/RX1, Canon Selphy CP1300/1500, Citizen CX-02, Epson, HiTi...) qua lệnh `PrintTo` không độ trễ.
* **Quản lý trạng thái & Thử lại:** API `api/printStatus.php` hỗ trợ theo dõi trạng thái in thời gian thực và bấm nút **Thử lại (Retry)** nếu máy in kẹt giấy/hết giấy.

### 6. Đồng bộ Offline-First lên Website / Cloud
* **Hàng đợi Offline-first (`src/Service/SyncQueueService.php`):** Giữ an toàn toàn bộ file ảnh và metadata tại `data/sync_queue.json` khi sự kiện bị mất mạng; tự động đồng bộ khi có Internet trở lại.
* **Tách môi trường:** Hỗ trợ hồ sơ `local`, `staging`, `production` kèm xác thực bảo mật `X-Photobooth-Api-Key`.
* **API máy chủ nhận file (`api/syncReceive.php`):** Kiểm tra định dạng MIME, giới hạn dung lượng và lưu trữ an toàn.

### 7. Trang Chia Sẻ Di Động Cho Khách (`view.php`) & Smart QR
* **Thiết kế Mobile-first:** Tải trang cực nhanh, giao diện sang trọng tối ưu cho iPhone và Android.
* **Xem đồng thời Ảnh Ghép Khung & Ảnh Động GIF:** Cho phép khách chuyển tab xem cả 2 định dạng và cung cấp 2 nút tải file độc lập.
* **Smart Local IP Resolution:** Mã QR tự động phát hiện địa chỉ IP LAN nội bộ của máy chủ, giúp điện thoại của khách quét mã truy cập được ngay mà không bị kẹt ở `localhost`.
* **Bảo mật:** Khóa chặt chẽ quyền tải các file nội bộ hệ thống (`meta_`, `preview_`).

---

## 🛠 Công Nghệ Sử Dụng (Tech Stack)

### Backend
- **Ngôn ngữ:** PHP 8.4 (OOP, strict types, PSR-4 autoloading)
- **Thư viện lõi:** 
  - `symfony/*` (Console, Finder, Filesystem, Translation, Config)
  - `league/flysystem` (Filesystem abstraction)
  - `monolog` (Hệ thống ghi log đa kênh)
  - `endroid/qr-code` (Sinh mã QR độ phân giải cao)
  - `phpmailer/phpmailer` (Gửi email kèm ảnh)
- **Xử lý đồ họa:** `Imagick` (ImageMagick), `GD Library`, `HaldCLUT 3D LUT`

### Frontend
- **Tương tác:** JavaScript (ES6+), jQuery, Socket.io (hỗ trợ Remote Buzzer hardware button)
- **Giao diện:** SCSS, TailwindCSS, PostCSS, PhotoSwipe v5, FontAwesome
- **Build tool:** Node.js (>= v20.15), Gulp pipeline

### Môi trường & Triển khai
- **Container:** Docker & Docker Compose (`webdevops/php-apache:8.4`)
- **Hệ điều hành hỗ trợ:** Windows (qua Docker + PowerShell Spooler) & Linux

---

## 📂 Cấu Trúc Thư Mục (Project Structure)

```text
├── admin/               # Giao diện quản trị hệ thống
├── api/                 # Các API endpoints cốt lõi
│   ├── cameraStatus.php # Kiểm tra trạng thái và reconnect camera
│   ├── presets.php      # Xử lý màu sắc Preset và nướng LUT
│   ├── print.php        # Tiếp nhận lệnh in và sinh file .job.json
│   ├── printStatus.php  # Theo dõi trạng thái in và thử lại (Retry)
│   ├── qrcode.php       # Sinh mã QR thông minh (tự nhận diện IP LAN)
│   ├── syncStatus.php   # Quản lý hàng đợi đồng bộ offline
│   ├── syncReceive.php  # Endpoint máy chủ nhận file đồng bộ
│   └── ...
├── assets/              # Mã nguồn frontend (JS, SCSS)
├── config/              # Cấu hình hệ thống (my.config.inc.php, presets.json)
├── data/                # Dữ liệu ảnh thực tế (tmp, images, thumbs, print)
├── Frame/               # Thư mục gốc chứa các mẫu khung ảnh PNG trong suốt
├── private/             # Layouts toạ độ khung (frame-1 -> frame-4) & LUT PNG
├── PresetColor/         # Chứa các file preset Lightroom (.xmp, .cube, .dng)
├── resources/           # Tài nguyên tĩnh đã được build (CSS, JS, fonts)
├── scripts/             # Script hỗ trợ vận hành
│   ├── windows-print-spooler.ps1  # Dịch vụ in ảnh tự động trên Windows
│   └── verify-pipeline.php        # Script kiểm thử và benchmark tự động
├── src/                 # Mã nguồn PHP chuẩn PSR-4
│   ├── Camera/          # Kiến trúc đa thiết bị (Webcam, Sony, Mock)
│   ├── Processor/       # ColorPresetProcessor, GifProcessor
│   ├── Service/         # CameraManager, PresetService, SyncQueue, PrintManager...
│   └── ...
├── template/            # Template giao diện (preset.control, stage.loader...)
├── view.php             # Trang xem và tải ảnh trên điện thoại cho khách quét QR
├── docker-compose.yml   # Cấu hình container Docker
└── DEPLOYMENT_AND_CHECKLIST.md # Tài liệu vận hành & checklist nghiệm thu
```

---

## 🚀 Hướng Dẫn Khởi Chạy (Quick Start)

### 1. Khởi chạy ứng dụng bằng Docker

1. Cài đặt Docker & Docker Compose trên máy tính.
2. Mở terminal tại thư mục dự án và khởi chạy container:
   ```bash
   docker compose up -d
   ```
3. Truy cập các đường dẫn sau trên trình duyệt:
   - **Giao diện chụp ảnh Kiosk:** [http://localhost](http://localhost)
   - **Trang Quản trị Admin:** [http://localhost/admin](http://localhost/admin)
   - **Trang tải ảnh cho khách (Mobile):** [http://localhost/view.php](http://localhost/view.php)
   - **Kiểm tra trạng thái Camera:** [http://localhost/api/cameraStatus.php](http://localhost/api/cameraStatus.php)
   - **Kiểm tra hàng đợi đồng bộ:** [http://localhost/api/syncStatus.php](http://localhost/api/syncStatus.php)

*(Lưu ý: Để trình duyệt cấp quyền truy cập camera webcam, hãy truy cập qua `http://localhost` hoặc giao thức bảo mật `https://`).*

---

### 2. Chạy dịch vụ In tự động trên Windows (Tùy chọn)

Nếu bạn có kết nối máy in ảnh chuyên dụng trên Windows (DNP, Canon Selphy, Citizen, Epson...):
1. Mở PowerShell với quyền Administrator:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\windows-print-spooler.ps1
   ```
2. Mặc định script sẽ dùng máy in mặc định (Default Printer). Nếu muốn chỉ định đích danh máy in:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\windows-print-spooler.ps1 -PrinterName "DNP DS620"
   ```

---

### 3. Thêm Preset Màu Mới

Chỉ cần copy file preset `.xmp` hoặc `.cube` từ Lightroom vào thư mục `PresetColor/`. Hệ thống sẽ tự động quét, phân tích thông số, nướng file LUT và đưa nút bấm lên giao diện màn hình kết quả ngay lập tức.

---

### 4. Cấu Hình 2 Hướng Nhận Ảnh Qua QR (Mạng Địa Điểm vs Website Cloud)

Hệ thống hỗ trợ chuyển đổi linh hoạt giữa 2 hướng ngay trên màn hình Modal QR (biểu tượng ⚙️ góc trên bên phải Modal QR) hoặc bằng script tự động:

#### 📶 Hướng 1: Chạy theo Mạng Wi-Fi Địa Điểm (Cùng Wi-Fi)
Khi mang máy đến địa điểm mới (quán cafe, nhà hàng, sảnh cưới) và bắt Wi-Fi tại đó, chạy lệnh sau trên PowerShell để tự động lấy IP Wi-Fi thực tế của Windows (tránh lỗi lấy nhầm IP ảo Docker `172.x.x.x`) và mở cổng Firewall:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\detect-venue-network.ps1
```
- Cấu hình được lưu tự động vào `config/network_mode.json`.
- Mã QR sẽ tự động trỏ về `http://<IP_WIFI_THẬT>/view.php?image=...` kèm nhắc tên Wi-Fi cho khách kết nối.

#### ☁️ Hướng 2: Đẩy lên Website Riêng trên Cloud (Khách dùng 4G / Mọi Wi-Fi)
1. Upload toàn bộ thư mục [`cloud-server/`](file:///d:/CodingPro/photobooth-dev/cloud-server/) lên Hosting / VPS của bạn (VD: `https://photo.tenmiencuaban.com`) và đặt mã bảo mật trong [`cloud-server/config.php`](file:///d:/CodingPro/photobooth-dev/cloud-server/config.php).
2. Trên màn hình Modal QR của Kiosk, bấm nút **⚙️ Cấu hình** → chọn **☁️ Hướng 2: Web Riêng (4G)** → nhập tên miền `https://photo.tenmiencuaban.com` và API Key → bấm **Lưu**.
3. Ngay khi khách chụp xong, Kiosk tự động nén ảnh Full HD + ảnh động GIF đẩy ngầm lên Website Cloud và sinh mã QR trỏ về tên miền riêng của bạn.

---

### 5. Kiểm Thử Toàn Trình Tự Động (Benchmark)

Chạy script kiểm thử tích hợp toàn trình (Mock Camera → Preset LUT → Animated GIF → Sync Queue) bên trong container:
```bash
docker compose exec photobooth php scripts/verify-pipeline.php
```

Chi tiết kịch bản kiểm thử, sao lưu và khắc phục sự cố được hướng dẫn tại [DEPLOYMENT_AND_CHECKLIST.md](file:///d:/CodingPro/photobooth-dev/DEPLOYMENT_AND_CHECKLIST.md).
