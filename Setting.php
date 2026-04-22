<?php
// Setting.php — Bảng dữ liệu (lọc live, phân trang AJAX) + lọc tỉnh + hiển thị tên tỉnh
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/admin/setting_auth.php';
mysqli_set_charset($conn, 'utf8mb4');

$TABLE = '`bien-so`';

// BASE URL (vd: /BTVN)
$__parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
$BASE    = '/' . ($__parts[0] ?? '');

// ===== Nạp tỉnh/thành: map code_prefix -> name + mảng gợi ý =====
$PROV_MAP = [];
$provinceOptions = [];
if ($rs = mysqli_query($conn, "SHOW TABLES LIKE 'province'")) {
  if (mysqli_num_rows($rs) > 0) {
    if ($rs2 = mysqli_query($conn, "SELECT code_prefix, province_name FROM province ORDER BY province_name ASC")) {
      while ($r = mysqli_fetch_assoc($rs2)) {
        $code = trim((string)$r['code_prefix']);
        $name = (string)$r['province_name'];
        $PROV_MAP[$code] = $name;
        $provinceOptions[] = ['code'=>$code, 'name'=>$name];
      }
      mysqli_free_result($rs2);
    }
  }
  mysqli_free_result($rs);
}

// PHP 7 fallback cho str_starts_with
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return (string)$needle !== '' && strpos($haystack, $needle) === 0;
  }
}

/* ======================= XÓA BẢN GHI ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  $id = (int)$_POST['delete_id'];
  mysqli_query($conn, "DELETE FROM {$TABLE} WHERE id={$id} LIMIT 1");

  $qs = trim($_POST['return'] ?? '');
  if ($qs !== '' && str_starts_with($qs, '?')) $qs = substr($qs, 1);

  $q = [];
  if ($qs !== '') parse_str($qs, $q);
  unset($q['ajax']);
  $q['deleted'] = 1;

  $redir = 'Setting.php' . ($q ? ('?' . http_build_query($q)) : '');
  header('Location: ' . $redir);
  exit;
}

/* =================== THAM SỐ LỌC & PHÂN TRANG ==================== */
$plate    = trim($_GET['plate']    ?? '');
$camera   = trim($_GET['camera']   ?? '');
$date     = trim($_GET['date']     ?? '');
$time     = trim($_GET['time']     ?? '');
$province = trim($_GET['province'] ?? '');   // <-- ô lọc tỉnh
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 10;

/* ====================== WHERE (prepared) ========================= */
$conds = [];
$types = '';
$args  = [];

if ($plate !== '')                           { $conds[] = "s.so_bien LIKE ?";    $types .= 's'; $args[] = "%{$plate}%"; }
if ($camera !== '' && ctype_digit($camera))  { $conds[] = "s.id_cam = ?";        $types .= 'i'; $args[] = (int)$camera; }
if ($date !== '')                            { $conds[] = "s.ngay_chup = ?";     $types .= 's'; $args[] = $date; }
if ($time !== '')                            { $conds[] = "s.tg_chup LIKE ?";    $types .= 's'; $args[] = "{$time}%"; }

/* Lọc TỈNH/TP: nhận mã (11) hoặc tên (Cao Bằng). Nếu province_code NULL, suy ra từ 2 số đầu biển số */
if ($province !== '') {
  $provCode = preg_match('/^\d{2}$/', $province) ? $province : '';
  if ($provCode === '' && $PROV_MAP) {
    foreach ($PROV_MAP as $code => $name) {
      if (stripos($name, $province) !== false) { $provCode = $code; break; }
    }
  }
  if ($provCode !== '') {
    $conds[] = "COALESCE(s.province_code, LEFT(s.so_bien, 2)) = ?";
    $types  .= 's';
    $args[]  = $provCode;
  }
}

$whereSql = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';

/* ========================= Đếm tổng ============================= */
$total = 0;
if ($stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM {$TABLE} s {$whereSql}")) {
  if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$args);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $total = (int)mysqli_fetch_assoc($res)['c'];
  mysqli_stmt_close($stmt);
}
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

/* ======= Lấy dữ liệu trang hiện tại (JOIN camera để có location) ======= */
$sql = "
SELECT
  s.id, s.ngay_chup, s.tg_chup, s.anh_chup, s.anh_toancanh, s.so_bien, s.province_code, s.id_cam,
  c.location,
  STR_TO_DATE(CONCAT(s.ngay_chup,' ',s.tg_chup), '%Y-%m-%d %H:%i:%s') AS order_dt
FROM {$TABLE} s
LEFT JOIN camera c ON c.camera_id = s.id_cam
{$whereSql}
ORDER BY order_dt DESC, s.id DESC
LIMIT ? OFFSET ?
";
$rows = [];
if ($stmt = mysqli_prepare($conn, $sql)) {
  if ($types !== '') {
    $types2 = $types . 'ii';
    $args2  = $args;
    $args2[] = $perPage;
    $args2[] = $offset;
    mysqli_stmt_bind_param($stmt, $types2, ...$args2);
  } else {
    mysqli_stmt_bind_param($stmt, 'ii', $perPage, $offset);
  }
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  mysqli_stmt_close($stmt);
}

/* ========== Render tbody & pager ========== */
function render_tbody(array $rows, string $BASE, array $PROV_MAP): string {
  if (!$rows) {
    return '
      <tr>
        <td colspan="9">
          <div class="anpr-muted" style="padding:12px;border:1px dashed #d1d5db;border-radius:10px;background:#fafafa;text-align:center">
            Chưa có dữ liệu phù hợp.
          </div>
        </td>
      </tr>';
  }

  $q = $_GET ?? [];
  unset($q['ajax'], $q['deleted'], $q['updated']);
  $currentQueryStr = '?' . http_build_query($q);
  $returnHidden    = htmlspecialchars($currentQueryStr, ENT_QUOTES);

  $html = '';
  foreach ($rows as $r) {
    $id   = (int)$r['id'];

    $imgMain = htmlspecialchars(str_replace('\\','/',$r['anh_chup'] ?? ''));
    $imgAlt  = htmlspecialchars(str_replace('\\','/',$r['anh_toancanh'] ?? ''));

    $date_fmt = !empty($r['ngay_chup']) ? date('d/m/Y', strtotime($r['ngay_chup'])) : '—';
    $time_fmt = !empty($r['tg_chup'])   ? date('H:i:s', strtotime('1970-01-01 '.$r['tg_chup'])) : '—';

    $plate = htmlspecialchars($r['so_bien'] ?? '');
    $cam   = htmlspecialchars($r['id_cam'] ?? '');
    $loc   = htmlspecialchars($r['location'] ?? '');

    // Mã tỉnh: ưu tiên province_code, rỗng thì lấy 2 số đầu trong so_bien
    $prov_code = trim((string)($r['province_code'] ?? ''));
    if ($prov_code === '') {
      $digits = preg_replace('/\D/', '', (string)($r['so_bien'] ?? ''));
      $prov_code = substr($digits, 0, 2) ?: '';
    }
    $prov_name = $PROV_MAP[$prov_code] ?? '';
    $prov_cell = $prov_code
      ? htmlspecialchars($prov_code) . ($prov_name ? '<br><small class="anpr-muted">'.htmlspecialchars($prov_name).'</small>' : '')
      : '<span class="anpr-muted">—</span>';

    $img_td_main = $imgMain !== '' ? "<img class=\"anpr-thumb\" src=\"{$imgMain}\" alt=\"snapshot\">" : "<span class=\"anpr-muted\">—</span>";
    $img_td_alt  = $imgAlt  !== '' ? "<img class=\"anpr-thumb\" src=\"{$imgAlt}\"  alt=\"overview\">" : "<span class=\"anpr-muted\">—</span>";

    $q2 = $_GET ?? [];
    unset($q2['ajax'], $q2['deleted'], $q2['updated']);
    $returnUrl = 'Setting.php' . ($q2 ? ('?' . http_build_query($q2)) : '');
    $editUrl   = $BASE . "/pages/edit.php?id={$id}&return=" . rawurlencode($returnUrl);

    $html .= '
      <tr>
        <td>'.$img_td_main.'</td>
        <td>'.$img_td_alt.'</td>
        <td>'.$date_fmt.'</td>
        <td>'.$time_fmt.'</td>
        <td><strong>'.$plate.'</strong></td>
        <td>'.$prov_cell.'</td>
        <td>'.$cam.'</td>
        <td>'.$loc.'</td>
        <td>
          <div class="anpr-actions">
            <a class="anpr-btn small sec" href="'.$editUrl.'">Sửa</a>
            <form method="post" onsubmit="return confirm(\'Xoá bản ghi này?\');" style="display:inline">
              <input type="hidden" name="delete_id" value="'.$id.'">
              <input type="hidden" name="return" value="'.$returnHidden.'">
              <button class="anpr-btn small danger" type="submit">Xoá</button>
            </form>
          </div>
        </td>
      </tr>';
  }
  return $html;
}

function render_pager(int $total, int $totalPages, int $currentPage): string {
  $q = [];
  parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
  $q_prev = $q; $q_next = $q;
  $q_prev['page'] = max(1, $currentPage - 1);
  $q_next['page'] = min($totalPages, $currentPage + 1);
  $href_prev = 'Setting.php?' . http_build_query($q_prev);
  $href_next = 'Setting.php?' . http_build_query($q_next);

  $prev_disabled = $currentPage <= 1;
  $next_disabled = $currentPage >= $totalPages;

  $prev = $prev_disabled ? '<span class="pg-btn disabled"><i class="fa fa-arrow-left"></i></span>'
                         : '<a class="pg-btn" href="'.htmlspecialchars($href_prev).'"><i class="fa fa-arrow-left"></i></a>';

  $next = $next_disabled ? '<span class="pg-btn disabled"><i class="fa fa-arrow-right"></i></span>'
                         : '<a class="pg-btn" href="'.htmlspecialchars($href_next).'"><i class="fa fa-arrow-right"></i></a>';

  return '
    <div class="pager-dark">
      '.$prev.'
      <div class="pg-center">Trang <span class="pg-current">'.$currentPage.'</span> / <span class="pg-total">'.$totalPages.'</span></div>
      '.$next.'
    </div>';
}

/* ================= JSON cho AJAX (ajax=1) ================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'tbody' => render_tbody($rows, $BASE, $PROV_MAP),
    'pager' => render_pager($total, $totalPages, $page),
    'total' => $total,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ứng dụng nhận diện biển số</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/Setting.css">
</head>
<body>
<div class="wrapper">
  <div class="menu">
    <div class="menu-right">
      <a href="index.php">Home</a>
      <a href="History.php">Lịch sử</a>
      <a class="active" href="Setting.php">Cài đặt</a>
    </div>
  </div>

  <div id="main">
    <?php include __DIR__ . '/pages/sidebar_setting.php'; ?>

    <div class="main-content">
      <div class="anpr-card">
        <div class="anpr-card-hd">
          <strong>Quản lý dữ liệu biển số</strong>

          <!-- Form lọc nhanh -->
          <form id="filters" class="anpr-filters" method="get" action="Setting.php">
            <input type="text" name="plate" value="<?= htmlspecialchars($plate) ?>" placeholder="Tìm biển số…">

            <select name="camera">
              <option value="">Tất cả camera</option>
              <?php for($i=1;$i<=5;$i++): ?>
                <option value="<?= $i ?>" <?= $camera===(string)$i ? 'selected' : '' ?>>Camera <?= $i ?></option>
              <?php endfor; ?>
            </select>

            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
            <input type="time" name="time" value="<?= htmlspecialchars($time) ?>">

            <!-- LỌC TỈNH: nhập mã hoặc tên; có datalist gợi ý -->
            <input type="text" name="province"
                   value="<?= htmlspecialchars($province) ?>"
                   placeholder="Tỉnh (mã hoặc tên)…" list="provlist">
            <datalist id="provlist">
              <?php foreach ($provinceOptions as $pv): ?>
                <option value="<?= htmlspecialchars($pv['code']) ?>"><?= htmlspecialchars($pv['name']) ?></option>
                <option value="<?= htmlspecialchars($pv['name']) ?>"></option>
              <?php endforeach; ?>
            </datalist>

            <a class="anpr-btn sec" href="Setting.php">Xoá lọc</a>
          </form>
        </div>

        <div class="anpr-card-bd">
          <?php if (isset($_GET['deleted'])): ?>
            <div class="anpr-muted">Đã xoá bản ghi.</div>
          <?php endif; ?>

          <div class="anpr-table-wrap">
            <table class="anpr-table">
              <thead>
                <tr>
                  <th>HÌNH ẢNH</th>
                  <th>TOÀN CẢNH</th>
                  <th>NGÀY</th>
                  <th>GIỜ</th>
                  <th>BIỂN SỐ</th>
                  <th>MÃ TỈNH</th>
                  <th>CAMERA</th>
                  <th>ĐƯỜNG</th>
                  <th>THAO TÁC</th>
                </tr>
              </thead>
              <tbody id="tbody">
                <?= render_tbody($rows, $BASE, $PROV_MAP) ?>
              </tbody>
            </table>
          </div>

          <div id="pager" style="display:flex;gap:6px;align-items:center;justify-content:center;margin-top:14px">
            <?= render_pager($total, $totalPages, $page) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="footer"><p></p></div>
</div>

<script>
(function () {
  const form  = document.getElementById('filters');
  const tbody = document.getElementById('tbody');
  const pager = document.getElementById('pager');
  if (!form || !tbody || !pager) return;

  function buildQuery(page) {
    const p = new URLSearchParams(new FormData(form));
    if (page) p.set('page', page);
    p.set('ajax','1');
    const v = new URLSearchParams(p); v.delete('ajax');
    history.replaceState(null, '', 'Setting.php?' + v.toString());
    return p.toString();
  }

  function getScrollParent(el) {
    let p = el.parentElement;
    while (p) {
      const s = getComputedStyle(p);
      if (/(auto|scroll)/.test(s.overflowY)) return p;
      p = p.parentElement;
    }
    return document.scrollingElement || document.documentElement;
  }

  async function fetchAndRender(page=1, reason='filter') {
    const res = await fetch('Setting.php?' + buildQuery(page));
    const data = await res.json();
    tbody.innerHTML = data.tbody;
    pager.innerHTML = data.pager;

    pager.querySelectorAll('a').forEach(a => {
      a.onclick = e => {
        e.preventDefault();
        const url = new URL(a.href);
        const p = url.searchParams.get('page') || '1';
        fetchAndRender(p, 'pager');
      };
    });

    if (reason === 'pager') {
      const card = document.querySelector('.anpr-card');
      const menuH = document.querySelector('.menu')?.offsetHeight || 64;
      if (card) {
        const scroller = getScrollParent(card);
        const rect = card.getBoundingClientRect();
        const scRect = scroller.getBoundingClientRect();
        const current = (scroller === document.scrollingElement) ? window.scrollY : scroller.scrollTop;
        const target = rect.top - scRect.top + current - menuH;
        if (scroller === document.scrollingElement) {
          window.scrollTo({ top: target, behavior: 'smooth' });
        } else {
          scroller.scrollTo({ top: target, behavior: 'smooth' });
        }
      }
    }
  }

  let t;
  if (form.plate) form.plate.oninput = () => {
    clearTimeout(t);
    t = setTimeout(() => fetchAndRender(1, 'filter'), 300);
  };
  ['camera','date','time','province'].forEach(n => {
    if (form[n]) form[n].onchange = () => fetchAndRender(1, 'filter');
  });

  pager.querySelectorAll('a').forEach(a => {
    a.onclick = e => {
      e.preventDefault();
      const url = new URL(a.href);
      const p = url.searchParams.get('page') || '1';
      fetchAndRender(p, 'pager');
    };
  });
})();
</script>
</body>
</html>
