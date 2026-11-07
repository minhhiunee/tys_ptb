/**
 * Photobooth Cloudflare Worker + R2 Storage + Mobile Web Viewer (All-in-One)
 * --------------------------------------------------------------------------
 * - Lưu trữ: Cloudflare R2 (binding: R2_BUCKET) - 10GB Free, $0 Egress Fee
 * - Upload API: POST /api/upload hoặc POST /api/syncReceive.php
 * - Session Info API: GET /api/session?s={session_id}
 * - Media Stream/Download: GET /media/{session_id}/{photo.jpg|anim.gif}?download=1
 * - Giao diện Web cho khách quét QR: GET /?s={session_id} (hoặc /view.php?image=...)
 */

const CORS_HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type, X-Photobooth-Api-Key, X-Photobooth-Session',
};

function sanitizeSessionId(raw) {
  if (!raw) return '';
  // Strip file extension (.jpg, .gif, .png) if passed as image filename
  const cleaned = String(raw).trim().replace(/\.(jpg|jpeg|png|gif)$/i, '');
  const safe = cleaned.replace(/[^A-Za-z0-9._-]/g, '');
  return safe.slice(0, 80);
}

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data, null, 2), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store, no-cache, must-revalidate',
      ...CORS_HEADERS,
    },
  });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const path = url.pathname;

    // 1. Preflight CORS
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: CORS_HEADERS });
    }

    // 2. Upload Endpoint from Photobooth Kiosk (POST /api/upload or /api/syncReceive.php)
    if (
      request.method === 'POST' &&
      (path === '/api/upload' || path === '/api/syncReceive.php' || path.endsWith('/syncReceive.php'))
    ) {
      return handleUpload(request, env, url);
    }

    // 3. Session Status & Metadata JSON API (GET /api/session?s=...)
    if (request.method === 'GET' && path === '/api/session') {
      const rawSession = url.searchParams.get('s') || url.searchParams.get('session') || url.searchParams.get('image') || '';
      const sessionId = sanitizeSessionId(rawSession);
      return handleGetSession(sessionId, env, url);
    }

    // 4. Stream / Download Media from R2 (GET /media/:sessionId/:type)
    if (request.method === 'GET' && path.startsWith('/media/')) {
      return handleMediaStream(request, env, url);
    }

    // 5. Health Check
    if (request.method === 'GET' && path === '/api/health') {
      return jsonResponse({
        ok: true,
        service: 'photobooth-r2-cloud',
        r2_bound: Boolean(env.R2_BUCKET),
        timestamp: Date.now(),
      });
    }

    // 6. Web Viewer UI (GET / or /view.php)
    const brandTitle = env.BRAND_TITLE || 'TYS Photobooth';
    const brandSubtitle = env.BRAND_SUBTITLE || 'Quét QR • Tải Ảnh & GIF Chất Lượng Cao';
    const expireDays = env.EXPIRE_DAYS || '7';
    return new Response(renderViewerHtml({ brandTitle, brandSubtitle, expireDays }), {
      status: 200,
      headers: {
        'Content-Type': 'text/html; charset=utf-8',
        'Cache-Control': 'public, max-age=60',
      },
    });
  },
};

/**
 * Xử lý nhận file ảnh tĩnh (.jpg) + ảnh động (.gif) + metadata từ máy Photobooth đẩy lên R2
 */
async function handleUpload(request, env, url) {
  try {
    if (!env.R2_BUCKET) {
      return jsonResponse({
        confirmed: false,
        error: 'R2_BUCKET binding chưa được cấu hình trong Cloudflare Worker.',
      }, 500);
    }

    const expectedKey = (env.PHOTOBOOTH_API_KEY || 'tys_secret_key_2026').trim();
    const headerKey = (request.headers.get('X-Photobooth-Api-Key') || '').trim();

    const formData = await request.formData();
    const formKey = String(formData.get('api_key') || '').trim();
    const incomingKey = headerKey || formKey;

    if (expectedKey && incomingKey !== expectedKey) {
      return jsonResponse({ confirmed: false, error: 'Unauthorized API Key' }, 401);
    }

    const rawSessionId = formData.get('session_id') || request.headers.get('X-Photobooth-Session') || '';
    const sessionId = sanitizeSessionId(rawSessionId);
    if (!sessionId) {
      return jsonResponse({ confirmed: false, error: 'Invalid or missing session_id' }, 400);
    }

    const prefix = `sessions/${sessionId}`;
    const metaKey = `${prefix}/meta.json`;

    // Đọc metadata hiện có trên R2 (nếu ảnh tĩnh và GIF được đẩy ở 2 nhịp khác nhau)
    let existingMeta = {};
    const existingMetaObj = await env.R2_BUCKET.get(metaKey);
    if (existingMetaObj) {
      try {
        existingMeta = await existingMetaObj.json();
      } catch (_) {
        existingMeta = {};
      }
    }

    let incomingMeta = {};
    const rawMetaStr = formData.get('metadata');
    if (typeof rawMetaStr === 'string' && rawMetaStr.trim() !== '') {
      try {
        incomingMeta = JSON.parse(rawMetaStr);
      } catch (_) {
        incomingMeta = {};
      }
    }

    const savedFiles = {};
    let hasPhoto = Boolean(existingMeta.has_photo);
    let hasGif = Boolean(existingMeta.has_gif);

    // Lưu ảnh tĩnh lồng khung (final_photo)
    const finalPhoto = formData.get('final_photo');
    if (finalPhoto && typeof finalPhoto === 'object' && finalPhoto.size > 0) {
      const photoBuffer = await finalPhoto.arrayBuffer();
      const contentType = finalPhoto.type || 'image/jpeg';
      await env.R2_BUCKET.put(`${prefix}/photo.jpg`, photoBuffer, {
        httpMetadata: {
          contentType,
          cacheControl: 'public, max-age=604800',
        },
      });
      savedFiles.final_photo = 'photo.jpg';
      hasPhoto = true;
    }

    // Lưu ảnh động tua nhanh (gif_photo)
    const gifPhoto = formData.get('gif_photo');
    if (gifPhoto && typeof gifPhoto === 'object' && gifPhoto.size > 0) {
      const gifBuffer = await gifPhoto.arrayBuffer();
      await env.R2_BUCKET.put(`${prefix}/anim.gif`, gifBuffer, {
        httpMetadata: {
          contentType: 'image/gif',
          cacheControl: 'public, max-age=604800',
        },
      });
      savedFiles.gif_photo = 'anim.gif';
      hasGif = true;
    }

    const now = Date.now();
    const mergedMeta = {
      ...existingMeta,
      ...incomingMeta,
      session_id: sessionId,
      has_photo: hasPhoto,
      has_gif: hasGif,
      created_at: existingMeta.created_at || now,
      updated_at: now,
    };

    await env.R2_BUCKET.put(metaKey, JSON.stringify(mergedMeta, null, 2), {
      httpMetadata: {
        contentType: 'application/json; charset=utf-8',
        cacheControl: 'no-cache',
      },
    });

    const shareUrl = `${url.origin}/?s=${encodeURIComponent(sessionId)}`;

    return jsonResponse({
      confirmed: true,
      session_id: sessionId,
      has_photo: hasPhoto,
      has_gif: hasGif,
      saved_files: savedFiles,
      share_url: shareUrl,
    });
  } catch (err) {
    return jsonResponse({
      confirmed: false,
      error: err instanceof Error ? err.message : 'Upload processing error',
    }, 500);
  }
}

/**
 * Trả về thông tin phiên chụp cho giao diện Web Viewer
 */
async function handleGetSession(sessionId, env, url) {
  if (!sessionId) {
    return jsonResponse({ ok: false, error: 'Thiếu mã phiên chụp (session_id).' }, 400);
  }
  if (!env.R2_BUCKET) {
    return jsonResponse({ ok: false, error: 'R2_BUCKET chưa được kết nối.' }, 500);
  }

  const prefix = `sessions/${sessionId}`;
  const metaObj = await env.R2_BUCKET.get(`${prefix}/meta.json`);
  let meta = null;

  if (metaObj) {
    try {
      meta = await metaObj.json();
    } catch (_) {
      meta = null;
    }
  }

  // Fallback kiểm tra trực tiếp file nếu chưa có meta.json
  let hasPhoto = Boolean(meta && meta.has_photo);
  let hasGif = Boolean(meta && meta.has_gif);

  if (!meta) {
    const [photoHead, gifHead] = await Promise.all([
      env.R2_BUCKET.head(`${prefix}/photo.jpg`),
      env.R2_BUCKET.head(`${prefix}/anim.gif`),
    ]);
    hasPhoto = Boolean(photoHead);
    hasGif = Boolean(gifHead);
  }

  if (!hasPhoto && !hasGif) {
    return jsonResponse({
      ok: true,
      ready: false,
      session_id: sessionId,
      message: 'Đang đồng bộ ảnh từ máy Photobooth lên Cloud...',
    });
  }

  const versionTag = (meta && meta.updated_at) ? meta.updated_at : Date.now();
  const baseMedia = `${url.origin}/media/${encodeURIComponent(sessionId)}`;

  return jsonResponse({
    ok: true,
    ready: true,
    session_id: sessionId,
    has_photo: hasPhoto,
    has_gif: hasGif,
    photo_url: hasPhoto ? `${baseMedia}/photo.jpg?v=${versionTag}` : null,
    photo_download_url: hasPhoto ? `${baseMedia}/photo.jpg?download=1&v=${versionTag}` : null,
    gif_url: hasGif ? `${baseMedia}/anim.gif?v=${versionTag}` : null,
    gif_download_url: hasGif ? `${baseMedia}/anim.gif?download=1&v=${versionTag}` : null,
    metadata: meta || { session_id: sessionId },
  });
}

/**
 * Stream trực tiếp file ảnh / GIF từ Cloudflare R2 về trình duyệt điện thoại
 */
async function handleMediaStream(request, env, url) {
  if (!env.R2_BUCKET) {
    return new Response('R2_BUCKET not configured', { status: 500 });
  }

  // Path format: /media/{sessionId}/{filename}
  const parts = url.pathname.split('/').filter(Boolean);
  if (parts.length < 3) {
    return new Response('Invalid media path', { status: 400 });
  }

  const sessionId = sanitizeSessionId(parts[1]);
  const fileType = parts[2].toLowerCase();

  let objectKey = '';
  let contentType = 'image/jpeg';
  let downloadName = `Photobooth_${sessionId}.jpg`;

  if (fileType === 'photo.jpg' || fileType === 'photo') {
    objectKey = `sessions/${sessionId}/photo.jpg`;
    contentType = 'image/jpeg';
    downloadName = `Photobooth_${sessionId}.jpg`;
  } else if (fileType === 'anim.gif' || fileType === 'gif') {
    objectKey = `sessions/${sessionId}/anim.gif`;
    contentType = 'image/gif';
    downloadName = `Photobooth_${sessionId}_Timelapse.gif`;
  } else {
    return new Response('Unsupported media file', { status: 404 });
  }

  const obj = await env.R2_BUCKET.get(objectKey);
  if (!obj) {
    return new Response('Media not found', { status: 404, headers: CORS_HEADERS });
  }

  const headers = new Headers(CORS_HEADERS);
  headers.set('Content-Type', (obj.httpMetadata && obj.httpMetadata.contentType) || contentType);
  headers.set('Cache-Control', 'public, max-age=604800');
  if (obj.httpEtag) {
    headers.set('ETag', obj.httpEtag);
  }

  if (url.searchParams.get('download') === '1') {
    headers.set('Content-Disposition', `attachment; filename="${downloadName}"`);
  } else {
    headers.set('Content-Disposition', `inline; filename="${downloadName}"`);
  }

  return new Response(obj.body, {
    status: 200,
    headers,
  });
}

/**
 * Giao diện Web Viewer chuẩn Mobile-First cho khách quét QR
 */
function renderViewerHtml({ brandTitle, brandSubtitle, expireDays }) {
  return `<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover" />
  <meta name="theme-color" content="#090d16" />
  <title>${brandTitle} • Tải Ảnh & GIF</title>
  <style>
    :root {
      --bg: #090d16;
      --card: rgba(18, 25, 43, 0.88);
      --border: rgba(255, 255, 255, 0.12);
      --accent: #38bdf8;
      --accent-2: #6366f1;
      --text: #f8fafc;
      --muted: #94a3b8;
      --success: #10b981;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
    body {
      min-height: 100dvh;
      background: radial-gradient(circle at 50% 0%, #1e293b 0%, #090d16 70%);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 16px 14px 36px;
    }
    .shell {
      width: 100%;
      max-width: 460px;
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .header {
      text-align: center;
      padding: 8px 4px 2px;
    }
    .brand-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: 999px;
      background: rgba(56, 189, 248, 0.12);
      border: 1px solid rgba(56, 189, 248, 0.3);
      color: var(--accent);
      font-size: 11.5px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .header h1 {
      font-size: 22px;
      font-weight: 800;
      letter-spacing: -0.02em;
    }
    .header p {
      font-size: 13px;
      color: var(--muted);
      margin-top: 3px;
    }

    /* Tabs切換 Ảnh tĩnh / Ảnh động */
    .media-tabs {
      display: none;
      background: rgba(15, 23, 42, 0.9);
      border: 1px solid var(--border);
      padding: 4px;
      border-radius: 14px;
      gap: 6px;
    }
    .media-tabs.visible {
      display: grid;
      grid-template-columns: 1fr 1fr;
    }
    .tab-btn {
      border: none;
      background: transparent;
      color: var(--muted);
      padding: 10px 12px;
      border-radius: 10px;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .tab-btn.active {
      background: linear-gradient(135deg, #2563eb, #0ea5e9);
      color: #fff;
      box-shadow: 0 4px 14px rgba(14, 165, 233, 0.35);
    }

    /* Khung hiển thị ảnh */
    .stage-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 22px;
      padding: 12px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
      backdrop-filter: blur(16px);
      position: relative;
      min-height: 360px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }
    .media-view {
      width: 100%;
      display: none;
      flex-direction: column;
      align-items: center;
    }
    .media-view.active {
      display: flex;
    }
    .media-view img {
      width: 100%;
      max-height: 68dvh;
      object-fit: contain;
      border-radius: 14px;
      display: block;
      background: #0f172a;
    }
    .meta-bar {
      width: 100%;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 10px;
      padding: 0 4px;
      font-size: 12px;
      color: var(--muted);
    }
    .preset-tag {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.12);
      padding: 3px 10px;
      border-radius: 999px;
      color: #e2e8f0;
      font-weight: 600;
    }

    /* Trạng thái chờ đồng bộ */
    .state-box {
      text-align: center;
      padding: 36px 18px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    .spinner {
      width: 42px;
      height: 42px;
      border: 3.5px solid rgba(56, 189, 248, 0.2);
      border-top-color: var(--accent);
      border-radius: 50%;
      animation: spin 0.85s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .state-title { font-size: 16px; font-weight: 700; }
    .state-desc { font-size: 13px; color: var(--muted); line-height: 1.45; max-width: 300px; }

    /* Các nút tải ảnh */
    .actions {
      display: none;
      flex-direction: column;
      gap: 10px;
    }
    .actions.visible {
      display: flex;
    }
    .btn-primary, .btn-secondary, .btn-share {
      width: 100%;
      padding: 14px 18px;
      border-radius: 16px;
      font-size: 15px;
      font-weight: 700;
      text-decoration: none;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      border: none;
      transition: transform 0.15s ease, opacity 0.15s ease;
    }
    .btn-primary:active, .btn-secondary:active, .btn-share:active {
      transform: scale(0.98);
    }
    .btn-primary {
      background: linear-gradient(135deg, #2563eb, #0ea5e9);
      color: #ffffff;
      box-shadow: 0 10px 25px rgba(14, 165, 233, 0.35);
    }
    .btn-secondary {
      background: rgba(30, 41, 59, 0.9);
      border: 1px solid rgba(56, 189, 248, 0.4);
      color: #7dd3fc;
    }
    .btn-share {
      background: rgba(255, 255, 255, 0.07);
      border: 1px solid var(--border);
      color: #e2e8f0;
      font-size: 13.5px;
      padding: 12px 16px;
    }
    .ios-tip {
      text-align: center;
      font-size: 12px;
      color: var(--muted);
      line-height: 1.4;
      padding: 4px 8px;
    }
    .footer {
      margin-top: 8px;
      text-align: center;
      font-size: 11.5px;
      color: #64748b;
    }
  </style>
</head>
<body>
  <div class="shell">
    <div class="header">
      <div class="brand-pill">☁️ Cloud R2 Instant Delivery</div>
      <h1>${brandTitle}</h1>
      <p>${brandSubtitle}</p>
    </div>

    <!-- Thanh chuyển đổi Ảnh tĩnh / Ảnh động -->
    <div id="media-tabs" class="media-tabs">
      <button type="button" class="tab-btn active" data-target="photo">
        📸 Ảnh Lồng Khung
      </button>
      <button type="button" class="tab-btn" data-target="gif">
        🎞️ Ảnh Động (x3)
      </button>
    </div>

    <!-- Khung hiển thị chính -->
    <div class="stage-card">
      <!-- Trạng thái Loading / Đang đồng bộ từ máy chụp -->
      <div id="state-loading" class="state-box">
        <div class="spinner"></div>
        <div class="state-title" id="loading-title">Đang tải ảnh của bạn...</div>
        <div class="state-desc" id="loading-desc">Nếu bạn vừa chụp xong, hệ thống đang đẩy ảnh chất lượng cao và GIF lên Cloud R2 (khoảng 1–3 giây).</div>
      </div>

      <!-- Khung xem Ảnh tĩnh -->
      <div id="view-photo" class="media-view">
        <img id="img-photo" src="" alt="Photobooth Photo" />
      </div>

      <!-- Khung xem Ảnh động GIF -->
      <div id="view-gif" class="media-view">
        <img id="img-gif" src="" alt="Photobooth Timelapse GIF" />
      </div>

      <div id="meta-bar" class="meta-bar" style="display:none;">
        <span id="session-label">Mã phiên: --</span>
        <span id="preset-label" class="preset-tag">Original</span>
      </div>
    </div>

    <!-- Các nút tải về điện thoại -->
    <div id="action-buttons" class="actions">
      <a id="btn-dl-photo" href="#" class="btn-primary" download>
        ⬇️ Tải Ảnh Gốc Chất Lượng Cao (JPG)
      </a>
      <a id="btn-dl-gif" href="#" class="btn-secondary" style="display:none;" download>
        🎞️ Tải Ảnh Động Tua Nhanh (GIF)
      </a>
      <button type="button" id="btn-native-share" class="btn-share" style="display:none;">
        📲 Lưu vào Album / Chia sẻ nhanh (iOS & Android)
      </button>
      <div class="ios-tip">
        💡 <b>Mẹo trên iPhone/iPad:</b> Bạn cũng có thể <b>nhấn giữ trực tiếp vào bức ảnh</b> bên trên và chọn <b>"Lưu vào Ảnh" (Save to Photos)</b>.
      </div>
    </div>

    <div class="footer">
      Ảnh được lưu trữ an toàn trên Cloudflare R2 trong ${expireDays} ngày.
    </div>
  </div>

  <script>
    (function () {
      const params = new URLSearchParams(window.location.search);
      const rawSession = params.get('s') || params.get('session') || params.get('image') || '';
      const sessionId = rawSession.replace(/\\.(jpg|jpeg|png|gif)$/i, '').replace(/[^A-Za-z0-9._-]/g, '');

      const stateLoading = document.getElementById('state-loading');
      const loadingTitle = document.getElementById('loading-title');
      const loadingDesc = document.getElementById('loading-desc');
      const mediaTabs = document.getElementById('media-tabs');
      const viewPhoto = document.getElementById('view-photo');
      const viewGif = document.getElementById('view-gif');
      const imgPhoto = document.getElementById('img-photo');
      const imgGif = document.getElementById('img-gif');
      const metaBar = document.getElementById('meta-bar');
      const sessionLabel = document.getElementById('session-label');
      const presetLabel = document.getElementById('preset-label');
      const actionButtons = document.getElementById('action-buttons');
      const btnDlPhoto = document.getElementById('btn-dl-photo');
      const btnDlGif = document.getElementById('btn-dl-gif');
      const btnNativeShare = document.getElementById('btn-native-share');

      let currentSessionData = null;
      let activeTab = 'photo';
      let pollCount = 0;

      if (!sessionId) {
        loadingTitle.textContent = 'Chưa có mã phiên chụp';
        loadingDesc.textContent = 'Vui lòng quét mã QR trên màn hình Photobooth sau khi chụp để xem và tải ảnh của bạn.';
        document.querySelector('.spinner').style.display = 'none';
        return;
      }

      // Chuyển đổi tab Ảnh tĩnh / Ảnh động
      document.querySelectorAll('.tab-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
          const target = btn.getAttribute('data-target');
          activeTab = target;
          document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
          btn.classList.add('active');
          viewPhoto.classList.toggle('active', target === 'photo');
          viewGif.classList.toggle('active', target === 'gif');
        });
      });

      // Hỗ trợ Web Share API trên iOS / Android để lưu thẳng vào Album Ảnh
      if (navigator.share && navigator.canShare) {
        btnNativeShare.style.display = 'flex';
        btnNativeShare.addEventListener('click', async () => {
          if (!currentSessionData) return;
          const isGif = activeTab === 'gif' && currentSessionData.gif_url;
          const fileUrl = isGif ? currentSessionData.gif_url : currentSessionData.photo_url;
          const fileName = isGif
            ? 'Photobooth_' + sessionId + '.gif'
            : 'Photobooth_' + sessionId + '.jpg';
          const mimeType = isGif ? 'image/gif' : 'image/jpeg';

          const origText = btnNativeShare.innerHTML;
          btnNativeShare.innerHTML = '⏳ Đang chuẩn bị file...';
          try {
            const res = await fetch(fileUrl);
            const blob = await res.blob();
            const file = new File([blob], fileName, { type: mimeType });
            if (navigator.canShare({ files: [file] })) {
              await navigator.share({
                title: '${brandTitle}',
                files: [file],
              });
            } else {
              window.location.href = isGif ? currentSessionData.gif_download_url : currentSessionData.photo_download_url;
            }
          } catch (e) {
            if (e && e.name !== 'AbortError') {
              window.location.href = isGif ? currentSessionData.gif_download_url : currentSessionData.photo_download_url;
            }
          } finally {
            btnNativeShare.innerHTML = origText;
          }
        });
      }

      async function fetchSession() {
        try {
          const res = await fetch('/api/session?s=' + encodeURIComponent(sessionId) + '&_t=' + Date.now());
          const data = await res.json();

          if (data && data.ready) {
            currentSessionData = data;
            stateLoading.style.display = 'none';
            metaBar.style.display = 'flex';
            actionButtons.classList.add('visible');

            sessionLabel.textContent = 'Phiên: ' + sessionId;
            const presetName = (data.metadata && data.metadata.preset) ? data.metadata.preset : 'Original';
            presetLabel.textContent = 'Preset: ' + presetName;

            if (data.has_photo && data.photo_url) {
              imgPhoto.src = data.photo_url;
              btnDlPhoto.href = data.photo_download_url;
              btnDlPhoto.style.display = 'flex';
              viewPhoto.classList.add('active');
            }

            if (data.has_gif && data.gif_url) {
              imgGif.src = data.gif_url;
              btnDlGif.href = data.gif_download_url;
              btnDlGif.style.display = 'flex';
              mediaTabs.classList.add('visible');
            } else {
              // Nếu GIF đang được đẩy lên trễ hơn ảnh tĩnh 1-2 giây, kiểm tra ngầm thêm vài lần
              if (pollCount < 10) {
                pollCount++;
                setTimeout(checkGifUpdateSilently, 2000);
              }
            }
            return;
          }

          // Nếu ảnh đang trên đường tải lên R2, tự động hỏi lại sau 1.5s
          pollCount++;
          if (pollCount <= 25) {
            loadingTitle.textContent = 'Đang đồng bộ ảnh từ Photobooth...';
            loadingDesc.textContent = 'Vui lòng giữ màn hình này trong giây lát (' + pollCount + '). Ảnh sẽ tự động hiện ra ngay khi tải xong.';
            setTimeout(fetchSession, 1500);
          } else {
            loadingTitle.textContent = 'Chưa tìm thấy ảnh trên Cloud';
            loadingDesc.textContent = 'Phiên chụp "' + sessionId + '" chưa được đồng bộ hoặc đã hết hạn lưu trữ.';
            document.querySelector('.spinner').style.display = 'none';
          }
        } catch (err) {
          pollCount++;
          if (pollCount <= 15) {
            setTimeout(fetchSession, 2000);
          } else {
            loadingTitle.textContent = 'Lỗi kết nối mạng';
            loadingDesc.textContent = 'Không thể kết nối tới máy chủ lưu trữ. Vui lòng tải lại trang.';
            document.querySelector('.spinner').style.display = 'none';
          }
        }
      }

      async function checkGifUpdateSilently() {
        try {
          const res = await fetch('/api/session?s=' + encodeURIComponent(sessionId) + '&_t=' + Date.now());
          const data = await res.json();
          if (data && data.has_gif && data.gif_url) {
            currentSessionData = data;
            imgGif.src = data.gif_url;
            btnDlGif.href = data.gif_download_url;
            btnDlGif.style.display = 'flex';
            mediaTabs.classList.add('visible');
          } else if (pollCount < 10) {
            pollCount++;
            setTimeout(checkGifUpdateSilently, 2000);
          }
        } catch (_) {}
      }

      fetchSession();
    })();
  </script>
</body>
</html>`;
}

