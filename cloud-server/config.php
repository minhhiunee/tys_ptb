<?php

declare(strict_types=1);

/**
 * CẤU HÌNH WEBSITE CLOUD RIÊNG (HƯỚNG 2 — KHÁCH TRUY CẬP BẰNG 4G / MỌI WI-FI)
 * Upload toàn bộ thư mục `cloud-server/` này lên Hosting/VPS của bạn.
 */

return [
    // Mã bảo mật (Phải trùng khớp với Cloud API Key cấu hình tại máy Photobooth ở sự kiện)
    'api_key' => getenv('PHOTOBOOTH_API_KEY') ?: 'tys_secret_key_2026',

    // Tiêu đề hiển thị trên trang tải ảnh của khách
    'event_title' => 'TYS Photobooth',
    'event_subtitle' => 'Cảm ơn bạn đã lưu giữ khoảnh khắc!',

    // Màu chủ đạo giao diện
    'primary_color' => '#1e293b',
    'background_color' => '#f8fafc',

    // Tự động xóa ảnh cũ sau N ngày để giải phóng dung lượng Hosting (0 = Không bao giờ xóa)
    'expire_days' => 14,

    // Giới hạn dung lượng tối đa mỗi file tải lên (MB)
    'max_file_size_mb' => 25,

    // Thư mục lưu trữ ảnh trên Cloud Hosting
    'storage_dir' => __DIR__ . '/storage/images',
];

