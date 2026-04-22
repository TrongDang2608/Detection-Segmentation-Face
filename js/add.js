/* ===================== CONFIG ===================== */
const STAGING_BASE      = '../results_anpr/add_staging/';
const LIVE_PATH         = STAGING_BASE + 'live.jpg';
const OFF_IMAGE         = '../images/cam_off.jpg';
const FEED_POLL_MS      = 1000;     // tần suất load 'latest' cho bảng kết quả
const LIVE_INTERVAL     = 160;      // chu kỳ refresh live.jpg (ms) khi fallback
const VIDEO_PLAY_SPEED  = 0.5;      // phát video chậm 1/2
const CAM_WEB_ID        = 5;        // id_cam cố định cho add.php

/* ===================== Helpers ===================== */
async function api(url, opts = {}) {
  const r = await fetch(url, { cache: 'no-store', ...opts });
  try { return await r.json(); } catch { return {}; }
}
const $ = sel => document.querySelector(sel);

function setOverlay(text) {
  const el = $('#camOverlay');
  if (el) el.textContent = text || '';
}

function webSrc(p) {
  if (!p) return '';
  p = String(p).replace(/\\/g, '/').trim();
  if (/^https?:\/\//i.test(p) || p.startsWith('/') || p.startsWith('../')) return p;
  if (p.startsWith('results_anpr/')) return '../' + p;
  return (STAGING_BASE + p).replace(/\/{2,}/g, '/');
}

/* ===================== LIVE preview (fallback khi không có MJPEG) ===================== */
let liveTimer = null;
function startLive() {
  const img = $('#camStream');
  if (!img) return;
  stopLive();

  function tick() {
    const url = LIVE_PATH + '?t=' + Date.now();
    const tmp = new Image();
    tmp.onload = () => { img.src = url; };
    tmp.src = url;
  }
  tick();
  liveTimer = setInterval(tick, LIVE_INTERVAL);
}
function stopLive() {
  if (liveTimer) clearInterval(liveTimer);
  liveTimer = null;
  const img = $('#camStream');
  if (img) img.src = OFF_IMAGE;
}

/* ===================== KẾT QUẢ (bảng 1 dòng mới nhất + auto insert) ===================== */
const $previewWrap = $('#detectResults') || $('#capturePreview');
const $previewBody = $('#previewRow')    || $('#resultsBody');
let   pollTimer    = null;
let   lastKey      = '';
const autoSavedKeys = new Set();

function rowHTML(item) {
  const dateTime = (item.time || '').split(' ');
  const ymd  = dateTime[0] || '';
  const tim  = dateTime[1] || '';
  const plate = (item.text || '').trim().replace(/\s+/, '\n');

  const norm = s => (s || '').replace(/\\/g,'/').replace(/^results_anpr\/add_staging\//,'');
  const plateUrl = webSrc(norm(item.plate_img));
  const vehUrl   = webSrc(norm(item.veh_img));

  return `
  <tr>
    <td><img src="${plateUrl}" style="width:220px;height:150px;object-fit:cover;border-radius:8px"/></td>
    <td><img src="${vehUrl}"   style="width:240px;height:160px;object-fit:cover;border-radius:8px"/></td>
    <td>${ymd}</td>
    <td>${tim}</td>
    <td style="white-space:pre-line;font-weight:700">${plate}</td>
    <td>${CAM_WEB_ID}</td>
    <td><span class="anpr-muted">Tự động lưu</span></td>
  </tr>`;
}

async function autoInsert(it) {
  const key = [it.time, it.text, it.plate_img, it.veh_img].join('|');
  if (autoSavedKeys.has(key)) return;

  const payload = new URLSearchParams({
    action: 'insert',
    so_bien: it.text || '',
    anh_chup: it.plate_img || '',
    anh_toancanh: it.veh_img || '',
    time: it.time || '',
    id_cam: String(CAM_WEB_ID)
  });

  try {
    const res = await api('add.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload
    });
    if (res && res.ok) autoSavedKeys.add(key);
  } catch { /* im lặng, vòng sau thử lại */ }
}

async function pollOne() {
  try {
    const r = await api('add.php?action=latest&n=1&_=' + Date.now());
    const arr = Array.isArray(r) ? r : (Array.isArray(r.items) ? r.items : []);
    if (!arr.length) return;

    const it = arr[0];
    const key = [it.time, it.text, it.plate_img, it.veh_img].join('|');
    if (key === lastKey) return;
    lastKey = key;

    if ($previewBody) {
      $previewBody.innerHTML = rowHTML(it);
      if ($previewWrap) $previewWrap.style.display = '';
    }
    autoInsert(it);
  } catch { /* noop */ }
}

function startPreviewLoop() {
  if (pollTimer) return;
  pollOne();
  pollTimer = setInterval(pollOne, FEED_POLL_MS);
}
function stopPreviewLoop() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

/* ===================== Pipeline ===================== */
/** Khởi chạy WEBCAM: ưu tiên MJPEG nếu API trả stream_url; fallback live.jpg nếu không. */
async function startPipeline(mode, src) {
  if (mode === 'video') return startVideoAbs(src, VIDEO_PLAY_SPEED);

  // đảm bảo sạch phiên cũ
  await api('add.php?action=stop&_=' + Date.now());

  const qs  = new URLSearchParams({
    action: 'start',
    mode,
    src,
    slot: CAM_WEB_ID
  });
  const res = await api('add.php?' + qs.toString());

  const img = $('#camStream');
  if (!(res && res.ok)) {
    alert((res && res.error) ? res.error : 'Không khởi động được tiến trình.');
    return;
  }

  // Ưu tiên MJPEG nếu backend trả stream_url
  stopLive(); // ngắt live trước khi set src mới
  if (res.stream_url && img) {
    img.src = res.stream_url;
  } else {
    // Fallback khi add.php chưa mở MJPEG cho webcam
    startLive();
  }

  startPreviewLoop();
  setOverlay('Đang phát (Webcam)…');
  $('#btnStartCam') && ($('#btnStartCam').disabled = true);
  $('#btnStopCam')  && ($('#btnStopCam').disabled  = false);
}

/** Phát VIDEO bằng MJPEG + speed */
async function startVideoAbs(absPath, speed = VIDEO_PLAY_SPEED) {
  const img = $('#camStream');

  await api('add.php?action=stop&_=' + Date.now());
  stopLive();
  stopPreviewLoop();
  setOverlay('Đang phát video…');

  const body = new URLSearchParams({ file: absPath, speed: String(speed) });
  const res  = await api('add.php?action=start_video', { method: 'POST', body });

  if (res && res.ok && res.stream_url && img) {
    img.src = res.stream_url;   // dùng MJPEG stream
    startPreviewLoop();
    $('#btnStartCam') && ($('#btnStartCam').disabled = true);
    $('#btnStopCam')  && ($('#btnStopCam').disabled  = false);
  } else {
    setOverlay('');
    alert((res && res.error) ? res.error : 'Không khởi động được stream từ video');
  }
}

async function stopAll() {
  await api('add.php?action=stop&_=' + Date.now());
  stopLive();
  stopPreviewLoop();
  setOverlay('Đã tắt');
  const img = $('#camStream'); if (img) img.src = OFF_IMAGE;
  $('#btnStartCam') && ($('#btnStartCam').disabled = false);
  $('#btnStopCam')  && ($('#btnStopCam').disabled  = true);
}

/* ===================== Bind UI ===================== */
function bindButtons() {
  // Webcam
  $('#btnStartCam')?.addEventListener('click', () => startPipeline('webcam', '0'));
  $('#btnStopCam') ?.addEventListener('click', stopAll);

  // Upload video rồi phát
  const fileInput = $('#videoFile');
  $('#btnOpenVideo')?.addEventListener('click', () => fileInput?.click());
  fileInput?.addEventListener('change', async () => {
    const f = fileInput.files?.[0]; if (!f) return;
    const fd = new FormData(); fd.append('video', f);
    setOverlay('Đang upload video…');
    const up = await api('add.php?action=upload_video', { method:'POST', body: fd });
    if (up && up.ok && up.abs_path) {
      await startVideoAbs(up.abs_path, VIDEO_PLAY_SPEED);
    } else {
      alert((up && up.error) ? up.error : 'Upload thất bại.');
    }
    fileInput.value = '';
  });

  // Dọn khi đóng tab (soft stop)
  window.addEventListener('beforeunload', () => {
    try { navigator.sendBeacon('add.php?action=stop&_=' + Date.now(), new Blob([])); } catch {}
  });
}

/* ===================== INIT ===================== */
document.addEventListener('DOMContentLoaded', () => {
  $('#btnStartCam') && ($('#btnStartCam').disabled = false);
  $('#btnStopCam')  && ($('#btnStopCam').disabled  = true);
  bindButtons();
});
