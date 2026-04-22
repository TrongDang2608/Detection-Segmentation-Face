<?php
// Prefill từ URL (để giữ lại khi F5 hay share link)
$plate     = $_GET['plate']      ?? '';
$from_date = $_GET['from_date']  ?? '';
$from_time = $_GET['from_time']  ?? '';
$to_date   = $_GET['to_date']    ?? '';
$to_time   = $_GET['to_time']    ?? '';
$camera    = $_GET['camera']     ?? '';
$province  = $_GET['province']   ?? '';
?>
<div class="sidebar">
  <form id="filters" method="get" action="History.php" novalidate>
    <!-- SỐ BIỂN -->
    <div class="filter-group license-plate-filter">
      <h3>SỐ BIỂN</h3>
      <input type="text" name="plate" placeholder="Nhập số biển..." value="<?= htmlspecialchars($plate) ?>">
    </div>

    <!-- NGÀY THÁNG & THỜI GIAN -->
    <div class="filter-group datetime-filter">
      <h3>NGÀY THÁNG & THỜI GIAN</h3>

      <div class="datetime-block">
        <label for="from-date">Từ:</label>
        <div class="datetime-row">
          <input type="date" id="from-date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
          <input type="time" id="from-time" name="from_time" value="<?= htmlspecialchars($from_time) ?>">
        </div>
      </div>

      <div class="datetime-block">
        <label for="to-date">Đến:</label>
        <div class="datetime-row">
          <input type="date" id="to-date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
          <input type="time" id="to-time" name="to_time" value="<?= htmlspecialchars($to_time) ?>">
        </div>
      </div>
    </div>

    <!-- TỈNH THÀNH -->
    <div class="filter-group province-filter">
      <h3>TỈNH THÀNH</h3>
      <input list="provinces" name="province" placeholder="Nhập hoặc chọn tỉnh"
             value="<?= htmlspecialchars($province) ?>">
      <datalist id="provinces">
        <option value="An Giang (67)">
        <option value="Bà Rịa - Vũng Tàu (72)">
        <option value="Bạc Liêu (94)">
        <option value="Bắc Kạn (97)">
        <option value="Bắc Giang (98)">
        <option value="Bắc Ninh (99)">
        <option value="Bến Tre (71)">
        <option value="Bình Dương (61)">
        <option value="Bình Định (77)">
        <option value="Bình Phước (93)">
        <option value="Bình Thuận (86)">
        <option value="Cà Mau (69)">
        <option value="Cao Bằng (11)">
        <option value="Cần Thơ (65)">
        <option value="Đà Nẵng (43)">
        <option value="Đắk Lắk (47)">
        <option value="Đắk Nông (48)">
        <option value="Điện Biên (27)">
        <option value="Đồng Nai (60)">
        <option value="Đồng Tháp (66)">
        <option value="Gia Lai (81)">
        <option value="Hà Giang (23)">
        <option value="Hà Nam (90)">
        <option value="Hà Nội (29)">
        <option value="Hà Tĩnh (38)">
        <option value="Hải Dương (34)">
        <option value="Hải Phòng (15)">
        <option value="Hậu Giang (95)">
        <option value="Hòa Bình (28)">
        <option value="TP. Hồ Chí Minh (50)">
        <option value="Hưng Yên (89)">
        <option value="Khánh Hòa (79)">
        <option value="Kiên Giang (68)">
        <option value="Kon Tum (82)">
        <option value="Lai Châu (25)">
        <option value="Lâm Đồng (49)">
        <option value="Lạng Sơn (12)">
        <option value="Lào Cai (24)">
        <option value="Long An (62)">
        <option value="Nam Định (18)">
        <option value="Nghệ An (37)">
        <option value="Ninh Bình (35)">
        <option value="Ninh Thuận (85)">
        <option value="Phú Thọ (19)">
        <option value="Phú Yên (78)">
        <option value="Quảng Bình (73)">
        <option value="Quảng Nam (92)">
        <option value="Quảng Ngãi (76)">
        <option value="Quảng Ninh (14)">
        <option value="Quảng Trị (74)">
        <option value="Sóc Trăng (83)">
        <option value="Sơn La (26)">
        <option value="Tây Ninh (70)">
        <option value="Thái Bình (17)">
        <option value="Thái Nguyên (20)">
        <option value="Thanh Hóa (36)">
        <option value="Thừa Thiên Huế (75)">
        <option value="Tiền Giang (63)">
        <option value="Trà Vinh (84)">
        <option value="Tuyên Quang (22)">
        <option value="Vĩnh Long (64)">
        <option value="Vĩnh Phúc (88)">
        <option value="Yên Bái (21)">
      </datalist>
    </div>

    <!-- CAMERA -->
    <div class="filter-group camera-filter">
      <h3>CAMERA</h3>
      <label><input type="radio" name="camera" value=""  <?= $camera===''?'checked':''; ?>> Tất cả</label><br>
      <label><input type="radio" name="camera" value="1" <?= $camera==='1'?'checked':''; ?>> Camera 1</label><br>
      <label><input type="radio" name="camera" value="2" <?= $camera==='2'?'checked':''; ?>> Camera 2</label><br>
      <label><input type="radio" name="camera" value="3" <?= $camera==='3'?'checked':''; ?>> Camera 3</label><br>
      <label><input type="radio" name="camera" value="4" <?= $camera==='4'?'checked':''; ?>> Camera 4</label><br>
      <label><input type="radio" name="camera" value="5" <?= $camera==='5'?'checked':''; ?>>Webcam</label>
    </div>

    <!-- Nút dự phòng khi tắt JS -->
    <noscript><button type="submit">Lọc</button></noscript>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // KHÔNG auto-submit trên trang Detail (đã có flag)
  if (window.__DISABLE_SIDEBAR_FILTER__) return;

  const form = document.getElementById('filters');
  if (!form) return;

  // Debounce helper
  const debounce = (fn, ms=250) => {
    let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  };

  // Luôn submit về trang 1: build URL từ form, xoá "page"
  function submitToServer() {
    const url = new URL(form.action, location.origin);
    const fd  = new FormData(form);

    url.search = '';
    for (const [k, v] of fd.entries()) {
      if (v != null && String(v).trim() !== '') url.searchParams.set(k, v);
    }
    url.searchParams.delete('page');  // QUAN TRỌNG: luôn về trang 1

    location.href = url.pathname + '?' + url.searchParams.toString();
  }

  // Lắng nghe thay đổi:
  // - Gõ biển số: debounce để tránh gửi quá dày
  const plate = form.querySelector('input[name="plate"]');
  if (plate) plate.addEventListener('input', debounce(submitToServer, 300));

  // - Đổi ngày/giờ: submit ngay
  form.querySelectorAll('input[type="date"], input[type="time"]').forEach(el => {
    el.addEventListener('change', submitToServer);
  });

  // - Datalist tỉnh: trigger khi blur/change (hoặc enter)
  const province = form.querySelector('input[list="provinces"]');
  if (province) {
    province.addEventListener('change', submitToServer);
    // optional: nếu muốn khi gõ xong 300ms cũng submit:
    province.addEventListener('input', debounce(submitToServer, 500));
  }

  // - Camera: submit ngay khi chọn
  form.querySelectorAll('input[type="radio"][name="camera"]').forEach(r => {
    r.addEventListener('change', submitToServer);
  });

  // Gắn return URL khi click card (để quay lại đúng trạng thái)
  document.querySelectorAll('.main-content a.plate-link').forEach(a => {
    a.addEventListener('click', function () {
      try {
        const u = new URL(a.href, location.origin);
        if (!u.searchParams.has('return')) {
          u.searchParams.set('return', encodeURIComponent(location.pathname + location.search));
          a.href = u.pathname + '?' + u.searchParams.toString();
        }
      } catch (e) {}
    }, { capture: true });
  });
});
</script>
