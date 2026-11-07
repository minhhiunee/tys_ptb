# Hướng Dẫn Deploy Web Nhận Ảnh & GIF Với Cloudflare R2 (100% Free)

Giải pháp này sử dụng **Cloudflare R2** (10 GB miễn phí, **0đ phí băng thông tải về - Zero Egress**) kết hợp **Cloudflare Workers** để vừa nhận file ảnh tĩnh + ảnh động từ máy Photobooth, vừa hiển thị giao diện Web tải ảnh theo từng `session_id` khi khách quét mã QR bằng 4G/5G hoặc bất kỳ mạng Wi-Fi nào.

---

## Cách 1: Deploy trực tiếp trên trình duyệt (Không cần gõ lệnh - 3 phút)

### Bước 1: Tạo R2 Bucket lưu trữ ảnh & GIF
1. Đăng nhập vào [Cloudflare Dashboard](https://dash.cloudflare.com/).
2. Ở menu bên trái, chọn **R2 Object Storage** -> nhấn **Create bucket**.
3. Đặt tên bucket là: `photobooth-media` -> nhấn **Create bucket**.

### Bước 2: Tạo Cloudflare Worker (Web Viewer + Upload API)
1. Ở menu bên trái, chọn **Compute (Workers)** -> **Workers & Pages** -> nhấn **Create**.
2. Chọn **Create Worker** -> đặt tên (ví dụ: `photobooth-r2-cloud`) -> nhấn **Deploy**.
3. Sau khi tạo xong, nhấn **Edit code**:
   - Mở file [`cloud-r2-viewer/src/worker.js`](./src/worker.js) trong project này, copy toàn bộ nội dung và dán đè vào file `worker.js` trên trình duyệt.
   - Nhấn **Deploy** ở góc phải trên cùng.

### Bước 3: Kết nối R2 Bucket vào Worker
1. Quay lại trang quản lý Worker `photobooth-r2-cloud` -> chọn tab **Settings** -> **Bindings**.
2. Nhấn **Add** -> chọn **R2 bucket**:
   - **Variable name:** `R2_BUCKET` *(nhập chính xác chữ in hoa này)*
   - **R2 bucket:** chọn `photobooth-media`
   - Nhấn **Deploy / Save**.
3. *(Tùy chọn)* Tại phần **Variables and Secrets**, bạn có thể thêm biến `PHOTOBOOTH_API_KEY` (mặc định là `tys_secret_key_2026`) và `BRAND_TITLE` (tên thương hiệu sự kiện của bạn).

---

## Cách 2: Deploy tự động bằng dòng lệnh (Wrangler CLI - 1 phút)

Nếu máy bạn có sẵn Node.js, mở terminal tại thư mục `cloud-r2-viewer`:

```bash
cd cloud-r2-viewer
npm install
npx wrangler login
npm run r2:create
npm run deploy
```

Sau khi chạy xong, terminal sẽ in ra địa chỉ Web của bạn, ví dụ:
`https://photobooth-r2-cloud.<ten-ban>.workers.dev`

---

## Bước Cuối: Kết nối vào Photobooth

1. Trên màn hình kết quả chụp của Photobooth, bấm **Quét Mã QR** -> bấm nút **⚙️ Chế độ Mạng** ở góc trên.
2. Chọn **☁️ Hướng 2: Web Riêng (4G / Cloud R2)**.
3. Điền địa chỉ Worker vừa tạo vào ô **Địa chỉ Website Cloud riêng**:
   - Ví dụ: `https://photobooth-r2-cloud.<ten-ban>.workers.dev` (hoặc tên miền riêng của bạn nếu đã gắn Custom Domain).
4. Điền **Cloud API Key**: `tys_secret_key_2026` (trùng với `PHOTOBOOTH_API_KEY` trên Worker).
5. Bấm **Lưu & Cập nhật Mã QR**.

Từ lúc này:
- Mỗi lượt chụp sẽ tự động đẩy cả **Ảnh tĩnh lồng khung (`.jpg`)** và **Ảnh động tua nhanh x3 (`.gif`)** lên Cloudflare R2.
- Mã QR sẽ trỏ tới đúng phiên chụp: `https://photobooth-r2-cloud.<ten-ban>.workers.dev/?s=<session_id>`.
- Khách dùng 4G/5G quét mã QR sẽ thấy ngay 2 tab **📸 Ảnh Lồng Khung** và **🎞️ Ảnh Động (x3)** cùng nút tải về điện thoại!

