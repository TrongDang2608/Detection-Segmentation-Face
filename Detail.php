<?php
// Detail.php — Hiển thị chi tiết 1 bản ghi và GIỮ BỘ LỌC khi điều hướng
require __DIR__ . '/config/database.php'; // tạo $conn (mysqli)
mysqli_set_charset($conn, 'utf8mb4');

// ====== INPUTS ======
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Các khóa filter mà ta muốn bảo toàn giữa các trang
$FILTER_KEYS = ['plate','from_date','from_time','to_date','to_time','camera','province','page'];
$filters = [];
foreach ($FILTER_KEYS as $k) {
  if (isset($_GET[$k]) && $_GET[$k] !== '') $filters[$k] = (string)$_GET[$k];
}
$filterQuery = http_build_query($filters);

// ====== BACK URL (ưu tiên tham số return, nếu không có thì tự dựng History.php?filters) ======
$backUrl = '';
if (!empty($_GET['return'])) {
  $backUrl = urldecode($_GET['return']); // do History.php urlencode trước đó
}
if ($backUrl === '') {
  $backUrl = 'History.php' . ($filterQuery ? ('?'.$filterQuery) : '');
}
$backUrlSafe = htmlspecialchars($backUrl, ENT_QUOTES);

// ====== LẤY BẢN GHI (JOIN camera để lấy location) ======
$row = null;
if ($id > 0 && ($stmt = mysqli_prepare(
  $conn,
  "SELECT b.id, b.ngay_chup, b.tg_chup, b.anh_chup, b.so_bien, b.anh_toancanh, b.id_cam, b.province_code,
          c.location
   FROM `bien-so` b
   LEFT JOIN `camera` c ON c.camera_id = b.id_cam
   WHERE b.id = ?
   LIMIT 1"
))) {
  mysqli_stmt_bind_param($stmt, 'i', $id);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = mysqli_fetch_assoc($res) ?: null;
  mysqli_stmt_close($stmt);
}

// Chuẩn hoá hiển thị + tra tên tỉnh
$province_name = '';
if ($row) {
  $row['anh_chup']      = str_replace('\\', '/', (string)$row['anh_chup']);
  $row['anh_toancanh']  = str_replace('\\', '/', (string)($row['anh_toancanh'] ?? ''));
  $row['ngay_fmt']      = $row['ngay_chup'] ? date('d/m/Y', strtotime($row['ngay_chup'])) : '';
  $row['gio_fmt']       = $row['tg_chup']   ? date('H:i:s', strtotime('1970-01-01 '.$row['tg_chup'])) : '';
  $row['location']      = $row['location'] ?? '';

  // Lấy mã tỉnh: ưu tiên province_code, fallback 2 số đầu trong so_bien
  $prov_code = trim((string)($row['province_code'] ?? ''));
  if ($prov_code === '') {
    $digits = preg_replace('/\D/', '', (string)($row['so_bien'] ?? ''));
    $prov_code = substr($digits, 0, 2) ?: '';
  }

  if ($prov_code !== '') {
    if ($stmt2 = mysqli_prepare($conn, "SELECT province_name FROM province WHERE code_prefix = ? LIMIT 1")) {
      mysqli_stmt_bind_param($stmt2, 's', $prov_code);
      mysqli_stmt_execute($stmt2);
      $res2 = mysqli_stmt_get_result($stmt2);
      if ($r2 = mysqli_fetch_assoc($res2)) {
        $province_name = (string)$r2['province_name'];
      }
      mysqli_stmt_close($stmt2);
    }
  }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Chi tiết biển số #<?= (int)$id ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/sidebar.css">
  <link rel="stylesheet" href="css/main-content.css">
  <link rel="stylesheet" href="css/Detail.css">
  <script>
    // Tắt JS lọc ở sidebar trên trang Detail (để khỏi giấu mất card)
    window.__DISABLE_SIDEBAR_FILTER__ = true;

    // Lưu filters hiện có vào localStorage (phòng trường hợp user back bằng nút trình duyệt)
    document.addEventListener('DOMContentLoaded', function(){
      try{
        const params = new URL(location.href).searchParams;
        const keys = ['plate','from_date','from_time','to_date','to_time','camera','province','page'];
        const f = {}; let has=false;
        keys.forEach(k => { if (params.has(k)) { f[k]=params.get(k); has=true; }});
        if (has) localStorage.setItem('ANPR_HISTORY_FILTERS_V1', JSON.stringify(f));
      }catch(e){}
    });
  </script>
</head>
<body>
  <div class="wrapper">
    <div class="menu">
      <div class=""></div>
      <div class="menu-right">
        <!-- Giữ filters khi nhảy menu -->
        <a href="index.php">Home</a>
        <a href="<?= 'History.php'.($filterQuery?('?'.$filterQuery):'') ?>">Lịch sử</a>
        <a href="Setting.php">Cài đặt</a>
      </div>
    </div>

    <div id="main">
      <?php include 'pages/sidebar.php'; ?>

      <div class="main-content">
        <?php if (!$row): ?>
          <div class="detail-card">
            <div class="detail-toolbar">
              <a class="btn" href="<?= $backUrlSafe ?>"><i class="fa fa-arrow-left"></i> Quay lại</a>
            </div>
            <div class="detail-empty">
              Không tìm thấy bản ghi với ID = <?= (int)$id ?>.
            </div>
          </div>
        <?php else: ?>
          <div class="detail-card">
            <div class="detail-toolbar">
              <a class="btn" href="<?= $backUrlSafe ?>"><i class="fa fa-arrow-left"></i> Quay lại</a>
            </div>

            <div class="detail-grid">
              <div class="detail-photos">
                <div class="photo-main">
                  <img
                    src="<?= htmlspecialchars($row['anh_chup']) ?>"
                    alt="Ảnh biển số <?= htmlspecialchars($row['so_bien'] ?: ('#'.$row['id'])) ?>"
                    loading="lazy">
                </div>

                <div class="photo-alt">
                  <img
                    src="<?= htmlspecialchars($row['anh_toancanh'] ?: 'images/placeholder.jpg') ?>"
                    alt="Ảnh toàn cảnh"
                    loading="lazy">
                </div>
              </div>

              <div class="detail-info">
                <div class="plate-headline">
                  <span class="plate-badge"><?= htmlspecialchars($row['so_bien'] ?: '—') ?></span>
                  <span class="id-badge">#<?= (int)$row['id'] ?></span>
                </div>
              
                <ul class="meta-list">
                  <li><span>Ngày:</span><strong><?= htmlspecialchars($row['ngay_fmt']) ?></strong></li>
                  <li><span>Giờ:</span><strong><?= htmlspecialchars($row['gio_fmt']) ?></strong></li>
                  <li><span>Camera:</span><strong><?= htmlspecialchars($row['id_cam'] ?? '–') ?></strong></li>
                  <li><span>Đường (location):</span><strong><?= htmlspecialchars($row['location'] ?: '—') ?></strong></li>
                  <?php if ($province_name !== ''): ?>
                    <li><span>Tỉnh/Thành phố:</span><strong><?= htmlspecialchars($province_name) ?></strong></li>
                  <?php endif; ?>
                  <li><span>Đường dẫn ảnh:</span>
                    <a class="file-link" href="<?= htmlspecialchars($row['anh_chup']) ?>" target="_blank">
                      <?= htmlspecialchars($row['anh_chup']) ?>
                    </a>
                  </li>
                  <li><span>Đường dẫn ảnh (toàn cảnh):</span>
                    <a class="file-link" href="<?= htmlspecialchars($row['anh_toancanh']) ?>" target="_blank">
                      <?= htmlspecialchars($row['anh_toancanh']) ?>
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer"><p></p></div>
  </div>
</body>
</html>
