// ====== Cấu hình ======
  const OFF_IMAGE = 'images/cam_off.jpg';
  const FEED_MAX  = 15;

  // ====== Helpers fetch/storage ======
  async function api(u) {
    const r = await fetch(u, { cache: 'no-store' });
    return r.json();
  }

  const STORE_KEY = 'anpr_active_stream'; // lưu {slot, camIndex, stream_url}

  function saveActiveStream(data) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(data)); } catch {}
  }
  function loadActiveStream() {
    try {
      const raw = localStorage.getItem(STORE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch { return null; }
  }
  function clearActiveStream() {
    try { localStorage.removeItem(STORE_KEY); } catch {}
  }

  function setButton(n, on) {
    const b = document.getElementById('powerButton' + n);
    if (!b) return;
    b.classList.remove('power-on', 'power-off');
    b.classList.add(on ? 'power-on' : 'power-off');
  }
  function setButtonsOff() {
    [1, 2, 3, 4].forEach(n => setButton(n, false));
  }

  // ====== Overlay đồng hồ ======
  function startClock(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const tick = () => {
      const d = new Date();
      const pad = x => String(x).padStart(2, '0');
      el.textContent = `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    };
    tick();
    setInterval(tick, 1000);
  }
  [1, 2, 3, 4].forEach(n => startClock('timestampOverlay' + n));

  // ====== Plate feed (cột phải) ======
  const resultsEl = document.getElementById('plateResults');
  let pollTimer = null;
  let lastId = 0;

  function buildPlateCard(item) {
    const ymd = item.ngay_chup || '';
    let datePretty = ymd;
    if (ymd && ymd.includes('-')) {
      const [yyyy, mm, dd] = ymd.split('-');
      datePretty = `${dd}-${mm}-${yyyy}`;
    }
    const imgSrc = (item.anh_chup || '').replace(/\\/g, '/');
    return `
      <a href="detail.php?id=${item.id}" class="plate-link" data-id="${item.id}">
        <div class="plate-item">
          <img src="${imgSrc}?t=${Date.now()}" alt="Plate" />
          <div class="plate-info">
            <div>Date: <span>${datePretty}</span></div>
            <div>Time: <span>${item.tg_chup || ''}</span></div>
            <div>License Plate: <br /><span>${item.so_bien || ''}</span></div>
          </div>
        </div>
      </a>
    `;
  }

  async function loadInitialFeed() {
    if (!resultsEl) return;
    try {
      const r = await api('index.php?action=feed&_=' + Date.now());
      const rows = Array.isArray(r) ? r : [];
      const first = rows.slice(0, FEED_MAX);
      resultsEl.innerHTML = first.map(buildPlateCard).join('');
      lastId = first.reduce((mx, it) => Math.max(mx, Number(it.id || 0)), 0);
      while (resultsEl.children.length > FEED_MAX) resultsEl.lastElementChild.remove();
    } catch (e) {
      console.error('loadInitialFeed', e);
    }
  }

  async function pollFeedOnce() {
    if (!resultsEl) return;
    try {
      const r = await api(`index.php?action=feed&after_id=${lastId}&_=${Date.now()}`);
      let rows = Array.isArray(r) ? r : [];
      if (!rows.length) return;
      rows = rows.filter(it => Number(it.id || 0) > lastId).sort((a, b) => Number(a.id) - Number(b.id));
      for (const it of rows) {
        if (!resultsEl.querySelector(`a.plate-link[data-id="${it.id}"]`)) {
          resultsEl.insertAdjacentHTML('afterbegin', buildPlateCard(it));
        }
        lastId = Math.max(lastId, Number(it.id || 0));
      }
      while (resultsEl.children.length > FEED_MAX) resultsEl.lastElementChild.remove();
    } catch (e) {
      console.error('pollFeedOnce', e);
    }
  }

  function startFeedPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollFeedOnce, 200);
    pollFeedOnce();
  }
  function stopFeedPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // ====== Nút Cam (MJPEG) ======
  async function attachCamButton(camNo, btnId, imgId) {
    const btn = document.getElementById(btnId);
    const img = document.getElementById(imgId);
    if (!btn || !img) return;

    btn.addEventListener('click', async () => {
      const turningOn = btn.classList.contains('power-off');

      if (turningOn) {
        setButtonsOff();
        setButton(camNo, true);

        // Tắt các ô khác
        [1, 2, 3, 4].forEach(n => {
          if (n !== camNo) {
            const o = document.getElementById('cam' + n + 'Stream');
            if (o) o.src = OFF_IMAGE;
          }
        });

        try {
          const camIndex = 0; // 1 webcam laptop
          const r = await api(
            `index.php?action=start&cam=${camIndex}&slot=${camNo}&_=${Date.now()}`
          );
          if (r && r.ok && r.stream_url) {
            img.src = r.stream_url; // MJPEG trực tiếp
            saveActiveStream({ slot: camNo, camIndex, stream_url: r.stream_url });
            await loadInitialFeed();
            startFeedPolling();
          } else {
            setButtonsOff();
            img.src = OFF_IMAGE;
            clearActiveStream();
            alert('Không khởi động được Camera.py hoặc stream.');
          }
        } catch (e) {
          setButtonsOff();
          img.src = OFF_IMAGE;
          clearActiveStream();
          alert('Lỗi khi gọi start.');
        }
      } else {
        // Người dùng NHẤN TẮT -> mới gọi stop (rời trang KHÔNG gọi)
        try { await api('index.php?action=stop&_=' + Date.now()); } catch (_) {}
        setButtonsOff();
        img.src = OFF_IMAGE;
        clearActiveStream();
        stopFeedPolling();
      }
    });
  }

  // Gắn sự kiện cho 4 nút
  attachCamButton(1, 'powerButton1', 'cam1Stream');
  attachCamButton(2, 'powerButton2', 'cam2Stream');
  attachCamButton(3, 'powerButton3', 'cam3Stream');
  attachCamButton(4, 'powerButton4', 'cam4Stream');

  // ====== Khôi phục stream khi tải trang mới ======
  (async function restoreActive() {
    const saved = loadActiveStream();
    if (saved && saved.stream_url && saved.slot) {
      // Gắn lại stream đã lưu
      const img = document.getElementById('cam' + saved.slot + 'Stream');
      if (img) {
        setButtonsOff();
        setButton(saved.slot, true);
        // tắt hình ô khác
        [1, 2, 3, 4].forEach(n => {
          if (n !== saved.slot) {
            const o = document.getElementById('cam' + n + 'Stream');
            if (o) o.src = OFF_IMAGE;
          }
        });
        img.src = saved.stream_url;
        await loadInitialFeed();
        startFeedPolling();
        return;
      }
    }

    // Nếu không có saved, thử xem có slot nào đang chạy để gán luôn
    const st = await api('index.php?action=status&_=' + Date.now());
    if (st && st.ok && Array.isArray(st.running_slots) && st.running_slots.length) {
      const slot = st.running_slots[0];
      const portBase = 5001; // nhớ sync với $BASE_PORT trong PHP
      const port = portBase + (slot - 1);
      const stream_url = `http://127.0.0.1:${port}/stream`;
      const img = document.getElementById('cam' + slot + 'Stream');
      if (img) {
        setButtonsOff();
        setButton(slot, true);
        [1, 2, 3, 4].forEach(n => {
          if (n !== slot) {
            const o = document.getElementById('cam' + n + 'Stream');
            if (o) o.src = OFF_IMAGE;
          }
        });
        img.src = stream_url;
        saveActiveStream({ slot, camIndex: 0, stream_url });
        await loadInitialFeed();
        startFeedPolling();
      }
    }
  })();