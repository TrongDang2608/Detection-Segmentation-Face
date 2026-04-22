<?php
// pages/edit.php — Sửa 1 bản ghi + upload ảnh (gọn nhẹ)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../admin/setting_auth.php';
mysqli_set_charset($conn, 'utf8mb4');

$TABLE = '`bien-so`';

// BASE (ví dụ /BTVN) để build URL tuyệt đối
$__parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
$BASE    = '/' . ($__parts[0] ?? '');

// ===== URL quay lại trang Setting với filter cũ =====
$backUrlRaw = trim($_GET['return'] ?? '');
$backUrlRel = 'Setting.php';
if ($backUrlRaw !== '' && preg_match('~^/?Setting\.php(\?.*)?$~', $backUrlRaw)) {
  $backUrlRel = ltrim($backUrlRaw, '/');
}
$BACK_HREF = htmlspecialchars($BASE . '/' . $backUrlRel, ENT_QUOTES);

// ===== Helper ảnh cho <img>
function build_image_url(?string $p, string $BASE): string {
  $p = trim((string)$p);
  if ($p === '') return '';
  $p = str_replace('\\','/',$p);
  if (preg_match('~^https?://~i', $p)) return $p;
  return rtrim($BASE,'/') . '/' . ltrim($p, '/');
}

/* ================== MINI-API UPLOAD ẢNH ================== */
function ensure_dir($p){ if (!is_dir($p)) @mkdir($p, 0777, true); }

if (($_GET['action'] ?? '') === 'upload_img') {
  header('Content-Type: application/json; charset=utf-8');

  $type   = (($_POST['type'] ?? '') === 'veh') ? 'veh' : 'plate';
  $subdir = ($type === 'veh') ? 'vehicles' : 'crops';

  if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Không nhận được file']); exit;
  }

  // (optional) lọc ảnh cơ bản
  $mime = @mime_content_type($_FILES['file']['tmp_name']);
  if ($mime && !preg_match('~^image/(png|jpe?g|webp|bmp)$~i', $mime)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Chỉ cho phép PNG/JPG/WEBP/BMP']); exit;
  }

  $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\'); // C:\xampp\htdocs
  $baseDir = rtrim($BASE, '/');                       // /BTVN
  $destDir = $docRoot . $baseDir . "/results_anpr/{$subdir}";
  ensure_dir($destDir);

  $ext = strtolower(pathinfo($_FILES['file']['name'] ?? 'jpg', PATHINFO_EXTENSION) ?: 'jpg');
  if (!preg_match('/^[a-z0-9]+$/', $ext)) $ext = 'jpg';
  try { $rnd = bin2hex(random_bytes(4)); } catch(Throwable $e) { $rnd = uniqid(); }
  $prefix = ($type==='veh' ? 'veh_' : 'plate_');
  $fname  = $prefix . date('Ymd_His') . "_{$rnd}.{$ext}";

  $abs = $destDir . DIRECTORY_SEPARATOR . $fname;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $abs)) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Không lưu được file']); exit;
  }

  echo json_encode(['ok'=>true, 'web_path'=>"results_anpr/{$subdir}/{$fname}"]); exit;
}
/* ========================================================= */

// ===== id bản ghi =====
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: ' . $BASE . '/Setting.php'); exit; }

// ===== Lấy dữ liệu bản ghi =====
$sql = "SELECT id, ngay_chup, tg_chup, anh_chup, anh_toancanh, so_bien, province_code, id_cam, '' as location
        FROM {$TABLE} WHERE id=? LIMIT 1";
$row = null;
if ($stmt = mysqli_prepare($conn, $sql)) {
  mysqli_stmt_bind_param($stmt, 'i', $id);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = $res ? mysqli_fetch_assoc($res) : null;
  mysqli_stmt_close($stmt);
}
if (!$row) {
  http_response_code(404);
  echo "<!doctype html><meta charset='utf-8'><style>body{font-family:system-ui,Arial}</style>
        <p>Không tìm thấy bản ghi.</p>
        <p><a href='{$BACK_HREF}'>&larr; Quay lại danh sách</a></p>";
  exit;
}

// ===== Submit cập nhật =====
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ngay  = trim($_POST['ngay_chup']      ?? '');
  $gio   = trim($_POST['tg_chup']        ?? '');
  $img   = trim($_POST['anh_chup']       ?? '');
  $img2  = trim($_POST['anh_toancanh']   ?? '');
  $plate = trim($_POST['so_bien']        ?? '');
  $prov  = trim($_POST['province_code']  ?? '');
  $cam   = (int)($_POST['id_cam']        ?? 0);
  $loc   = trim($_POST['location']       ?? ''); // giữ trường để không vỡ form

  // Chuẩn hoá ngày/giờ
  if ($ngay === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ngay)) {
    $ngay = $row['ngay_chup'] ?: date('Y-m-d');
  }
  if ($gio === '' || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $gio)) {
    $gio = $row['tg_chup'] ?: date('H:i:s');
  } elseif (strlen($gio) === 5) {
    $gio .= ':' . date('s'); // tự thêm giây hiện tại
  }

  if ($plate === '') {
    $err = 'Biển số không được để trống.';
  } else {
    $up = "UPDATE {$TABLE}
           SET ngay_chup=?, tg_chup=?, anh_chup=?, anh_toancanh=NULLIF(?, ''), 
               so_bien=?, province_code=NULLIF(?, ''), id_cam=?
           WHERE id=? LIMIT 1";
    if ($stmt = mysqli_prepare($conn, $up)) {
      mysqli_stmt_bind_param($stmt, 'ssssssii', $ngay, $gio, $img, $img2, $plate, $prov, $cam, $id);
      if (!mysqli_stmt_execute($stmt)) $err = 'Không thể cập nhật CSDL.';
      mysqli_stmt_close($stmt);

      if ($err === '') {
        header('Location: ' . ($backUrlRel ? $BASE.'/'.$backUrlRel : $BASE.'/Setting.php'));
        exit;
      }
    } else {
      $err = 'Không thể chuẩn bị truy vấn.';
    }
  }

  // giữ lại khi lỗi
  $row['ngay_chup']     = $ngay;
  $row['tg_chup']       = $gio;
  $row['anh_chup']      = $img;
  $row['anh_toancanh']  = $img2;
  $row['so_bien']       = $plate;
  $row['province_code'] = $prov;
  $row['id_cam']        = $cam;
  $row['location']      = $loc;
}

// Hiển thị
$imgUrlMain = build_image_url($row['anh_chup']     ?? '', $BASE);
$imgUrlAlt  = build_image_url($row['anh_toancanh'] ?? '', $BASE);
$ngay_value = $row['ngay_chup'] ? date('Y-m-d', strtotime($row['ngay_chup'])) : '';
$gio_value  = $row['tg_chup']   ? date('H:i:s', strtotime('1970-01-01 '.$row['tg_chup'])) : '';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <title>Sửa #<?= (int)$row['id'] ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="<?=$BASE?>/css/style.css">
  <link rel="stylesheet" href="<?=$BASE?>/css/edit.css">
</head>
<body>
<div class="wrapper">
  <div class="menu">
    <div class="menu-right">
      <a href="<?=$BASE?>/index.php">Home</a>
      <a href="<?=$BASE?>/History.php">Lịch sử</a>
      <a class="active" href="<?=$BASE?>/Setting.php">Cài đặt</a>
    </div>
  </div>

  <div id="main">
    <?php include __DIR__ . '/sidebar_setting.php'; ?>

    <div class="main-content">
      <div class="edit-grid">
        <!-- Ảnh -->
        <div class="pane">
          <div class="pane-hd">Ảnh</div>
          <div class="pane-bd">
            <div class="img-grid vertical">
              <div class="img-box">
                <h4>Ảnh biển số</h4>
                <?php if ($imgUrlMain): ?>
                  <img id="imgMain" src="<?= htmlspecialchars($imgUrlMain) ?>" alt="Ảnh biển số">
                <?php else: ?>
                  <div id="imgMainHolder" class="muted">Chưa có ảnh.</div>
                <?php endif; ?>
              </div>
              <div class="img-box">
                <h4>Ảnh toàn cảnh</h4>
                <?php if ($imgUrlAlt): ?>
                  <img id="imgAlt" src="<?= htmlspecialchars($imgUrlAlt) ?>" alt="Ảnh toàn cảnh">
                <?php else: ?>
                  <div id="imgAltHolder" class="muted">Chưa có ảnh.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Form -->
        <div class="pane">
          <div class="pane-hd">Sửa bản ghi</div>
          <div class="pane-bd">
            <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <form method="post" autocomplete="off">
              <div class="form-row">
                <label>Ngày chụp</label>
                <input class="ipt" type="date" name="ngay_chup" value="<?= htmlspecialchars($ngay_value) ?>">
              </div>

              <div class="form-row">
                <label>Giờ chụp</label>
                <input class="ipt" type="time" step="1" name="tg_chup" value="<?= htmlspecialchars($gio_value) ?>">
              </div>

              <div class="form-row">
                <label>Đường dẫn ảnh biển số</label>
                <div style="display:flex; gap:8px; align-items:center">
                  <input class="ipt" type="text" name="anh_chup" value="<?= htmlspecialchars($row['anh_chup'] ?? '') ?>" placeholder="results_anpr/crops/...">
                  <label class="btn sec" style="white-space:nowrap;cursor:pointer">
                    Chọn ảnh…
                    <input id="pickPlate" type="file" accept="image/*" style="display:none">
                  </label>
                </div>
              </div>

              <div class="form-row">
                <label>Đường dẫn ảnh toàn cảnh</label>
                <div style="display:flex; gap:8px; align-items:center">
                  <input class="ipt" type="text" name="anh_toancanh" value="<?= htmlspecialchars($row['anh_toancanh'] ?? '') ?>" placeholder="results_anpr/vehicles/...">
                  <label class="btn sec" style="white-space:nowrap;cursor:pointer">
                    Chọn ảnh…
                    <input id="pickVeh" type="file" accept="image/*" style="display:none">
                  </label>
                </div>
              </div>

              <div class="form-row">
                <label>Biển số</label>
                <input class="ipt" type="text" name="so_bien" value="<?= htmlspecialchars($row['so_bien'] ?? '') ?>">
              </div>

              <div class="form-row">
                <label>Mã tỉnh</label>
                <input class="ipt" type="text" name="province_code" value="<?= htmlspecialchars($row['province_code'] ?? '') ?>" placeholder="VD: 65">
              </div>

              <div class="form-row">
                <label>ID camera</label>
                <input class="ipt" type="number" name="id_cam" value="<?= htmlspecialchars((string)($row['id_cam'] ?? '')) ?>">
              </div>

              <div class="form-row">
                <label>Tên đường (tùy chọn)</label>
                <input class="ipt" type="text" name="location" value="<?= htmlspecialchars($row['location'] ?? '') ?>">
              </div>

              <div class="btn-row">
                <a class="btn sec" href="<?=$BACK_HREF?>">← Quay lại</a>
                <button class="btn" type="submit">💾 Lưu</button>
              </div>
            </form>

          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="footer"><p></p></div>
</div>

<script>
  const BASE = "<?= $BASE ?>";
  const toWeb = p => {
    p = (p||'').trim().replace(/\\/g,'/');
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    return BASE.replace(/\/$/,'') + '/' + p.replace(/^\/+/, '');
  };
  const withBust = url => url ? (url + (url.includes('?')?'&':'?') + 't=' + Date.now()) : '';

  // Preview khi gõ tay
  (function(){
    const ipMain = document.querySelector('input[name="anh_chup"]');
    const ipAlt  = document.querySelector('input[name="anh_toancanh"]');
    const imgMain = document.getElementById('imgMain');
    const imgAlt  = document.getElementById('imgAlt');
    const hMain   = document.getElementById('imgMainHolder');
    const hAlt    = document.getElementById('imgAltHolder');

    function setPreview(ip, img, holder){
      const v = (ip.value||'').trim();
      if (!v) { if (img) img.src=''; if (holder) holder.style.display=''; return; }
      if (img){ img.src = withBust(toWeb(v)); img.style.display=''; }
      if (holder) holder.style.display='none';
    }
    ipMain?.addEventListener('input', ()=>setPreview(ipMain,imgMain,hMain));
    ipAlt ?.addEventListener('input', ()=>setPreview(ipAlt ,imgAlt ,hAlt ));
  })();

  // Upload + tự điền ô + preview
  async function uploadImage(file, type){
    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', type); // 'plate' | 'veh'
    const res = await fetch('?action=upload_img', { method:'POST', body: fd, cache:'no-store' });
    try { return await res.json(); } catch { return { ok:false, error:'JSON parse fail' }; }
  }
  function setFieldAndPreview(selector, value, imgEl, holderEl){
    const ip = document.querySelector(selector);
    if (ip){ ip.value = value || ''; ip.dispatchEvent(new Event('input', { bubbles:true })); }
    if (imgEl && value){ imgEl.src = withBust(toWeb(value)); imgEl.style.display=''; }
    if (holderEl && value) holderEl.style.display='none';
  }
  (function bindPickers(){
    const pickPlate = document.getElementById('pickPlate');
    const pickVeh   = document.getElementById('pickVeh');
    const imgMain = document.getElementById('imgMain'), hMain = document.getElementById('imgMainHolder');
    const imgAlt  = document.getElementById('imgAlt') , hAlt  = document.getElementById('imgAltHolder');

    pickPlate?.addEventListener('change', async ()=>{
      const f = pickPlate.files?.[0]; if (!f) return;
      if (hMain) hMain.textContent = 'Đang tải ảnh...';
      const r = await uploadImage(f, 'plate');
      if (r?.ok && r.web_path) setFieldAndPreview('input[name="anh_chup"]', r.web_path, imgMain, hMain);
      else alert(r?.error || 'Upload thất bại (biển số).');
      pickPlate.value = '';
    });
    pickVeh?.addEventListener('change', async ()=>{
      const f = pickVeh.files?.[0]; if (!f) return;
      if (hAlt) hAlt.textContent = 'Đang tải ảnh...';
      const r = await uploadImage(f, 'veh');
      if (r?.ok && r.web_path) setFieldAndPreview('input[name="anh_toancanh"]', r.web_path, imgAlt, hAlt);
      else alert(r?.error || 'Upload thất bại (toàn cảnh).');
      pickVeh.value = '';
    });
  })();
</script>
</body>
</html>
