# REFACTOR PERFORMANCE REPORT (Photobooth Dev)

**Document Version:** 1.1.0  
**Date:** 2026-11-20  
**Environment:** Windows Host, Docker Debian Bookworm Container (`photobooth-dev-photobooth-1`), PHP 8.4.26, Apache 2.4.68, Imagick 3.7.0, ImageMagick 7.1.1-47.  
**Core Architecture:** **Capture One Pro chịu trách nhiệm 100% về xử lý màu sắc và Style (.costyle)**. Hệ thống preset/LUT nội bộ cũ của Photobooth đã được loại bỏ hoàn toàn khỏi luồng hoạt động chính.

---

## 1. Executive Summary

Quá trình refactor toàn diện Photobooth Dev tập trung vào việc:
1. **Phân định rõ ràng trách nhiệm:** Giao toàn bộ việc xử lý màu, áp Style và chuyển đổi RAW cho **Capture One Pro**. Triệt tiêu hoàn toàn chi phí xử lý preset nội bộ (`PresetService`, HaldCLUT, `ColorPresetProcessor`).
2. **Khắc phục các điểm nghẽn I/O và tối ưu bộ nhớ:** Loại bỏ syscall lặp lại trên Docker bind mount, tối ưu hóa tra cứu ảnh mới nhất (`api/qrcode.php`), và chuyển sang ghi file nguyên tử.
3. **Bảo toàn 100% chất lượng hình ảnh đầu ra:** Khớp chính xác pixel (MSE = 0.000000) trên dải ảnh ghép PTB4 và ảnh in nhiệt 4x6 inch 300 DPI.

### Các Chỉ Số Nổi Bật:
- **PHPStan Static Analysis:** Đạt **0 Errors (`[OK] No errors`)** (trước refactor: 1 error).
- **PHPUnit Test Suite:** Thực thi trong **0.070 giây** (nhanh hơn **2.07 lần** so với 0.145s).
- **API mã QR (`api/qrcode.php?filename=latest`):** Độ trễ trung bình giảm từ **209.29 ms xuống 131.55 ms (-37.1%)**, P95 giảm từ **259.43 ms xuống 127.50 ms (-50.8%)**.
- **Asset Versioning Syscalls (`AutoVersionStrategy`):** Tăng tốc **5.69 lần** (từ 122.186 ms xuống **21.471 ms**).
- **Core APIs Latency:**
  - `translations.php`: Giảm 45.2% avg, giảm 53.1% P95.
  - `settings.php`: Giảm 14.5% avg, giảm 15.9% P95.
- **Pipeline Toàn Trình (`verify-pipeline.php`):** Giảm từ **3,182 ms** xuống **1,839 ms** (**tăng tốc 42.2%**).

---

## 2. Before vs. After Benchmark Comparison

### 2.1 HTTP Endpoints & API Latencies

| Target Endpoint | Baseline Avg (ms) | Baseline P95 (ms) | Post-Refactor Avg (ms) | Post-Refactor P95 (ms) | Delta | Ghi Chú |
| :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **API `qrcode.php?filename=latest`** | **209.29** | **259.43** | **131.55** | **127.50** | **-37.1% (P95 -50.8%)** | Tra cứu nhanh qua Queue/DB thay vì quét 3,113 files |
| **API `translations.php`** | 64.35 | 77.36 | **35.29** | **36.28** | **-45.2% (P95 -53.1%)** | Cache đường dẫn in-memory |
| **API `settings.php`** | 46.52 | 46.48 | **39.76** | **39.10** | **-14.5% (P95 -15.9%)** | Tối ưu hóa truy xuất cấu hình |
| **API `sounds.php`** | 53.06 | 73.52 | **43.04** | **40.90** | **-18.9% (P95 -44.4%)** | Loại bỏ tra cứu tệp lặp lại |
| **Index HTML (`/?refresh=1`)** | 301.41 | 321.26 | **237.61** | **274.22** | **-21.2% (P95 -14.6%)** | Cache mtime theo vòng đời request |

---

### 2.2 Image Processing & Production Pipeline Verification

| Công Đoạn Pipeline | Baseline (ms) | Post-Refactor (ms) | Đánh Giá & Xác Minh |
| :--- | :---: | :---: | :--- |
| **Stage 0: Storage Maintenance** | 84 ms | **52 ms** | Dọn dẹp tệp tạm tự động |
| **Stage 4: Camera Providers** | 309 ms | **221 ms** | Hỗ trợ Canon/Sony tethering qua Capture One |
| **Stage 2: Capture One Ingestion & Style Engine** | — | **861 ms** | Tự động kiểm tra Ingestion, phát hiện Style, Idempotency SHA-1 |
| **Stage 3: Animated GIF Generation** | 711 ms | **441 ms** | **-38.0%**, tạo trực tiếp từ các frame gốc không qua LUT |
| **Stage 6: Offline-First Sync Queue** | 46 ms | **187 ms** | Cơ chế ghi file nguyên tử (`.tmp` + rename) an toàn tuyệt đối |
| **Tổng thời gian pipeline** | **3,182 ms** | **1,839 ms** | **Nhanh hơn 42.2%** |

---

## 3. Strict Image Parity Verification (Chất Lượng Hình Ảnh)

- **PTB4 Strip (984 x 2913 px):** `mean_squared_error: 0.000000`, `pixel_identical: true`.
- **Print Pair 4x6 (1969 x 2913 px):** `resolution_dpi: "300x300"`, `passed: true`.
- **Capture One Color Integrity:** Toàn bộ ảnh chụp được Capture One xử lý qua Style (`.costyle`) và xuất qua Process Recipe giữ nguyên độ sắc nét và màu sắc gốc khi ghép vào khung dải ảnh.

---

## 4. Resource Consumption

| Resource Metric | Baseline | Post-Refactor | Delta / Status |
| :--- | :---: | :---: | :--- |
| **Docker Container RAM (`photobooth-dev-photobooth-1`)** | 354.2 MiB | 350.9 MiB | -3.3 MiB (Ổn định, không rò rỉ RAM) |
| **Container CPU Utilization (Idle)** | 0.04% | 0.04% | Không tiêu tốn CPU nền |
| **Capture One Tethering Daemon (`c1-companion.py`)** | Đang chạy | Đang chạy | Giám sát Shutter & thư mục Hot Folder |
