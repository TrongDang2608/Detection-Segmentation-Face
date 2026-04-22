<?php
// History.php — Grid ảnh đã chụp (DB thật) + GIỮ BỘ LỌC khi sang Detail + PHÂN TRANG (chỉ hiện pager khi >30)
require __DIR__ . '/config/database.php';
mysqli_set_charset($conn, 'utf8mb4');

// --- LẤY THAM SỐ LỌC ---
$plate_q   = trim($_GET['plate'] ?? '');
$from_date = trim($_GET['from_date'] ?? '');
$from_time = trim($_GET['from_time'] ?? '');
$to_date   = trim($_GET['to_date'] ?? '');
$to_time   = trim($_GET['to_time'] ?? '');
$camera    = trim($_GET['camera'] ?? '');
$province  = trim($_GET['province'] ?? '');

$PER_PAGE = 30; // 6 x 5
$page     = max(1, (int)($_GET['page'] ?? 1));

// --- datetime mốc ---
$from_dt = null; $to_dt = null;
if ($from_date !== '' || $from_time !== '') {
  $from_dt = ($from_date ?: '1970-01-01') . ' ' . ($from_time ?: '00:00:00');
}
if ($to_date !== '' || $to_time !== '') {
  $to_dt = ($to_date ?: '9999-12-31') . ' ' . ($to_time ?: '23:59:59');
}

// --- mã tỉnh từ chuỗi province ---
$prov_code = '';
if ($province !== '') {
  if (preg_match('/\((\d{2,3})\)\s*$/', $province, $m)) $prov_code = $m[1];
  elseif (preg_match('/\b(\d{2,3})\b/', $province, $m)) $prov_code = $m[1];
}

// --- điều kiện chung ---
$conds = []; $types = ''; $args = [];
if ($plate_q !== '') { $conds[] = "so_bien LIKE ?"; $types.='s'; $args[] = "%$plate_q%"; }
if ($camera !== '' && ctype_digit($camera)) { $conds[] = "id_cam = ?"; $types.='i'; $args[] = (int)$camera; }
if ($from_dt !== null) { $conds[] = "CONCAT(ngay_chup,' ',tg_chup) >= ?"; $types.='s'; $args[] = $from_dt; }
if ($to_dt !== null)   { $conds[] = "CONCAT(ngay_chup,' ',tg_chup) <= ?"; $types.='s'; $args[] = $to_dt; }
if ($prov_code !== '') {
  $conds[] = "(province_code = ? OR REPLACE(REPLACE(so_bien,' ',''),'-','') LIKE CONCAT(?, '%'))";
  $types  .= 'ss';
  $args[]  = $prov_code;
  $args[]  = $prov_code;
}
$whereSql = $conds ? (" WHERE ".implode(" AND ", $conds)) : "";

// --- đếm tổng ---
$total_rows = 0;
$count_sql = "SELECT COUNT(*) c FROM `bien-so`".$whereSql;
if ($stmt = mysqli_prepare($conn,$count_sql)) {
  if ($types!=='') mysqli_stmt_bind_param($stmt,$types,...$args);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  if ($r = mysqli_fetch_assoc($res)) $total_rows = (int)$r['c'];
  mysqli_stmt_close($stmt);
}
$total_pages = max(1,(int)ceil($total_rows/$PER_PAGE));
if ($page>$total_pages) $page=$total_pages;
$offset = ($page-1)*$PER_PAGE;

// CHỈ hiển thị pager nếu tổng > 30
$show_pager = ($total_rows > $PER_PAGE);

// --- truy vấn trang ---
$sql = "SELECT id, ngay_chup, tg_chup, anh_chup, so_bien, province_code, id_cam
        FROM `bien-so`".$whereSql." ORDER BY id DESC".($show_pager ? " LIMIT ?, ?" : "");
$rows=[];
if ($stmt=mysqli_prepare($conn,$sql)) {
  if ($show_pager) {
    $types2 = $types.'ii';
    $args2  = array_merge($args,[$offset,$PER_PAGE]);
    mysqli_stmt_bind_param($stmt,$types2,...$args2);
  } else if ($types!=='') {
    // khi không phân trang, vẫn bind điều kiện lọc (không có limit)
    mysqli_stmt_bind_param($stmt,$types,...$args);
  }
  mysqli_stmt_execute($stmt);
  $res=mysqli_stmt_get_result($stmt);
  while($r=mysqli_fetch_assoc($res)){
    $r['anh_chup']=str_replace('\\','/',$r['anh_chup']??'');
    $r['ngay_fmt']=$r['ngay_chup']?date('d/m/Y',strtotime($r['ngay_chup'])):'';
    $r['gio_fmt']=$r['tg_chup']?date('H:i:s',strtotime('1970-01-01 '.$r['tg_chup'])):'';
    $from_plate_digits=substr(preg_replace('/\D/','',$r['so_bien']),0,2);
    $r['prov_js']=$r['province_code']?:$from_plate_digits;
    $rows[]=$r;
  }
  mysqli_stmt_close($stmt);
}

// --- giữ filters (trừ page) ---
$FILTER_KEYS=['plate','from_date','from_time','to_date','to_time','camera','province'];
$currFilters=[];
foreach($FILTER_KEYS as $k){ if(isset($_GET[$k]) && $_GET[$k]!=='') $currFilters[$k]=(string)$_GET[$k]; }
function build_page_url(int $p,array $filters):string{
  return 'History.php?'.http_build_query(array_merge($filters,['page'=>max(1,$p)]));
}
$filterQuery = http_build_query(array_merge($currFilters,['page'=>$page]));
$returnFull = $_SERVER['REQUEST_URI'] ?? 'History.php';

function web_src(string $p):string{
  $p=str_replace('\\','/',$p);
  if($p==='') return '';
  if(preg_match('~^(https?://|/)~i',$p)) return $p;
  return $p;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ứng dụng nhận diện biển số — Lịch sử</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/sidebar.css">
  <link rel="stylesheet" href="css/main-content.css">
</head>
<body>
  <div class="wrapper">
    <div class="menu">
      <div></div>
      <div class="menu-right">
        <a href="index.php">Home</a>
        <a href="History.php" class="active">Lịch sử</a>
        <a href="Setting.php">Cài đặt</a>
      </div>
    </div>

    <div id="main">
      <?php include 'pages/sidebar.php'; ?>

      <div class="content-wrap">
        <!-- Lưới kết quả -->
        <div class="main-content">
          <?php if (empty($rows)): ?>
            <div class="plate-item" style="width:100%; max-width:none; text-align:center;">
              <div class="plate-info">
                <div style="font-weight:600; font-size:16px;">Không có bản ghi nào.</div>
                <div class="muted">Thử chụp mới hoặc bỏ bớt bộ lọc.</div>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $detailUrl="detail.php?id=".(int)$row['id']."&return=".rawurlencode($returnFull);
                if($filterQuery) $detailUrl.='&'.$filterQuery;
              ?>
              <a href="<?= htmlspecialchars($detailUrl) ?>"
                 class="plate-link"
                 data-plate="<?= htmlspecialchars(strtoupper($row['so_bien'])) ?>"
                 data-date="<?= htmlspecialchars($row['ngay_chup']) ?>"
                 data-time="<?= htmlspecialchars($row['tg_chup']) ?>"
                 data-cam="<?= (int)$row['id_cam'] ?>"
                 data-prov="<?= htmlspecialchars($row['prov_js']) ?>">
                <div class="plate-item">
                  <img src="<?= htmlspecialchars(web_src($row['anh_chup'])) ?>"
                       alt="License Plate <?= htmlspecialchars($row['so_bien']) ?>"
                       onerror="this.src='images/cam_off.jpg'">
                  <div class="plate-info">
                    <div>License Plate: <span class="plate-number"><?= htmlspecialchars($row['so_bien']) ?></span></div>
                    <div>Date: <span><?= htmlspecialchars($row['ngay_fmt']) ?></span></div>
                    <div>Time: <span><?= htmlspecialchars($row['gio_fmt']) ?></span></div>
                    <div>ID camera: <span><?= htmlspecialchars($row['id_cam'] ?? '–') ?></span></div>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- PAGER DƯỚI: chỉ render nếu tổng > 30 -->
        <?php if ($show_pager): ?>
        <div class="pager">
          <?php
            $prev_disabled = ($page <= 1) ? 'disabled' : '';
            $next_disabled = ($page >= $total_pages) ? 'disabled' : '';
            $prev_url = $page > 1 ? build_page_url($page-1, $currFilters) : '#';
            $next_url = $page < $total_pages ? build_page_url($page+1, $currFilters) : '#';
          ?>
          <a class="pager-btn <?=$prev_disabled?>" href="<?= htmlspecialchars($prev_url) ?>" aria-disabled="<?= $prev_disabled ? 'true':'false' ?>"><i class="fa-solid fa-arrow-left"></i></a>
          <div class="pager-indicator">Trang <span><?= (int)$page ?></span> / <?= (int)$total_pages ?></div>
          <a class="pager-btn <?=$next_disabled?>" href="<?= htmlspecialchars($next_url) ?>" aria-disabled="<?= $next_disabled ? 'true':'false' ?>"><i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer"><p></p></div>
  </div>
</body>
</html>
