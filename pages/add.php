<?php
// pages/add.php — Camera staging độc lập (Cách 2) + Feed 3 bản mới nhất + Upload/Play video

require_once __DIR__ . '/../config/database.php';
mysqli_set_charset($conn, 'utf8mb4');
// Giờ VN để date()/time() không bị lệch
date_default_timezone_set('Asia/Ho_Chi_Minh');

/* ========== CẤU HÌNH ĐƯỜNG DẪN ========== */
$PYTHON_EXE = 'C:\Users\Vo Dang Vu Phong\AppData\Local\Programs\Python\Python39\python.exe';
$VIDEO_PY   = 'C:\xampp\htdocs\BTVN\Python\video.py';
$WEIGHTS    = 'C:\xampp\htdocs\BTVN\Python\runs\detect\train2\weights\best.pt';

$OUT_MAIN   = 'C:\xampp\htdocs\BTVN\results_anpr';         // kho chính (index/history đọc)
$OUT_STAGE  = $OUT_MAIN . '\add_staging';                  // kho staging riêng cho add.php
$OUTDIR     = $OUT_STAGE;

$RUN_FLAG   = $OUTDIR . DIRECTORY_SEPARATOR . 'RUNNING.flag';
$STOP_FLAG  = $OUTDIR . DIRECTORY_SEPARATOR . 'STOP.flag';
$LIVE_JPG   = $OUTDIR . DIRECTORY_SEPARATOR . 'live.jpg';
$TEXT_LOG   = $OUTDIR . DIRECTORY_SEPARATOR . 'plates.txt';

$MAIN_STOP_FLAG = $OUT_MAIN . DIRECTORY_SEPARATOR . 'STOP.flag'; // để không ảnh hưởng index

$STREAM_HOST = '127.0.0.1';
$ADD_PORT    = 5101;  // cổng MJPEG riêng cho add.php

// Thư mục upload video của trang add
$UPLOAD_DIR  = $OUTDIR . DIRECTORY_SEPARATOR . 'uploads';

/* ========== HELPERS ========== */
function ensure_dir(string $p){ if (!is_dir($p)) @mkdir($p, 0777, true); }
function del_file(string $p){ if (is_file($p)) @unlink($p); }
function port_open_ok($host, $port, $timeout_ms = 250){
  $timeout = max(0.05, $timeout_ms/1000.0);
  $errno=0; $err=''; $fp = @fsockopen($host, intval($port), $errno, $err, $timeout);
  if ($fp){ fclose($fp); return true; }
  return false;
}
function pids_listening_port($port): array {
  $out = [];
  @exec('netstat -ano | findstr :'.intval($port), $lines);
  foreach ($lines as $line){
    $parts = preg_split('/\s+/', trim($line));
    if (count($parts) >= 5){
      $state = strtoupper($parts[count($parts)-2] ?? '');
      $pid   = intval($parts[count($parts)-1] ?? 0);
      if ($pid > 0 && ($state === 'LISTENING' || $state === 'ESTABLISHED')) $out[$pid] = true;
    }
  }
  return array_keys($out);
}
function kill_pid($pid){ if ($pid>0) @exec('taskkill /F /PID '.intval($pid).' >NUL 2>&1'); }

function join_abs(string $base, string $p): string {
  $p = str_replace('\\','/', trim($p));
  if ($p === '') return '';
  if (preg_match('~^[a-z]:/|^/|^https?://~i', $p)) return $p;
  return rtrim($base, "/\\").DIRECTORY_SEPARATOR.ltrim($p, "/\\");
}
function copy_to_main(string $srcRel, string $type, string $OUT_STAGE, string $OUT_MAIN): string {
  if ($srcRel==='') return '';
  $srcAbs = join_abs($OUT_STAGE, $srcRel);
  if (!is_file($srcAbs)) return '';
  // NOTE: 'plate' -> thư mục 'crops' để thống nhất với Python/History
  $subdir  = ($type==='plate') ? 'crops' : (($type==='veh') ? 'vehicles' : 'misc');
  $destDir = $OUT_MAIN.DIRECTORY_SEPARATOR.$subdir; ensure_dir($destDir);
  $ext  = pathinfo($srcAbs, PATHINFO_EXTENSION) ?: 'jpg';
  try { $rand = bin2hex(random_bytes(3)); } catch(\Throwable $e) { $rand = uniqid(); }
  $name = 'add_'.date('Ymd_His').'_'.$rand.'.'.$ext;
  $dest = $destDir.DIRECTORY_SEPARATOR.$name;
  if (!@copy($srcAbs, $dest)) return '';
  return str_replace('\\','/', $subdir.'/'.$name);
}
function stage_rel(string $p, string $OUT_STAGE): string {
  $p = str_replace('\\','/', trim($p));
  if ($p==='') return '';
  $pref = 'results_anpr/add_staging/';
  if (stripos($p,$pref)===0) return substr($p, strlen($pref));
  foreach (['/crops/','/vehicles/'] as $mk){
    $pos = strpos($p,$mk);
    if ($pos!==false) return ltrim(substr($p, $pos+1), '/'); // crops/... hoặc vehicles/...
  }
  $abs = join_abs($OUT_STAGE, $p);
  if (is_file($abs)) return ltrim($p,'/');
  return ltrim($p,'/');
}

/* ========== API JSON ========== */
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action){
  header('Content-Type: application/json; charset=utf-8');
  ensure_dir($OUTDIR);

  switch ($action) {

    /* ---- START (webcam) ---- */
    case 'start':
      $mode = $_GET['mode'] ?? 'webcam';
      $src  = rawurldecode($_GET['src'] ?? '0');
      $slot = intval($_GET['slot'] ?? 1);

      // Dọn port & flags trước
      foreach (pids_listening_port($ADD_PORT) as $pid) kill_pid($pid);
      del_file($STOP_FLAG); del_file($MAIN_STOP_FLAG); del_file($RUN_FLAG);

      $cmd = 'start "" /B "'.$PYTHON_EXE.'" "'.$VIDEO_PY
            .'" --mode '.$mode.' --source "'.$src.'"'
            .' --cam '.$slot
            .' --out "'.$OUT_STAGE.'" --weights "'.$WEIGHTS.'"'
            .' --mjpeg_port '.$ADD_PORT
            .' --no_live'                    // dùng MJPEG, bỏ live.jpg
            .' --plate_conf 0.40 --speed 1.0';
      pclose(popen($cmd, 'r'));

      // Đợi port mở tối đa 6s
      $t0 = microtime(true);
      do {
        usleep(200000);
        if (port_open_ok($STREAM_HOST, $ADD_PORT, 200)){
          echo json_encode([
            'ok'=>true, 'running'=>true,
            'stream_url'=>"http://{$STREAM_HOST}:{$ADD_PORT}/stream"
          ]);
          exit;
        }
      } while ((microtime(true)-$t0) < 6.0);

      echo json_encode(['ok'=>false,'error'=>'Không khởi động được MJPEG']);
      exit;
      
    /* ---- STOP (webcam/video) ---- */
    case 'stop': {
      // THƯ MỤC STAGING đang chạy video.py
      $OUT = $OUT_STAGE;
      $RUN = $OUT . DIRECTORY_SEPARATOR . 'RUNNING.flag';
      $STP = $OUT . DIRECTORY_SEPARATOR . 'STOP.flag';

      // 1) Soft stop: tạo STOP.flag
      @file_put_contents($STP, "1");

      // 2) Đợi tiến trình tự tắt (video.py xóa RUNNING.flag khi thoát)
      $ok = false;
      for ($i = 0; $i < 10; $i++) {           // ~2s
        usleep(200000);
        clearstatcache();
        if (!file_exists($RUN)) { $ok = true; break; }
      }

      // 3) Hard stop: còn RUNNING.flag => kill theo PID
      if (!$ok && file_exists($RUN)) {
        $meta = @json_decode(@file_get_contents($RUN), true) ?: [];
        $pid  = intval($meta['pid'] ?? 0);
        if ($pid > 0) {
          // Giết cả cây tiến trình để chắc chắn nhả webcam
          @pclose(popen("taskkill /PID $pid /T /F", "r"));
          usleep(300000);
        }
        @unlink($RUN);
      }

      // 4) Dọn STOP.flag
      @unlink($STP);

      // Fallback: nếu kho chính còn RUNNING.flag thì kill
      $RUN_MAIN = $OUT_MAIN . DIRECTORY_SEPARATOR . 'RUNNING.flag';
      if (file_exists($RUN_MAIN)) {
        $meta = @json_decode(@file_get_contents($RUN_MAIN), true) ?: [];
        $pid  = intval($meta['pid'] ?? 0);
        if ($pid > 0) {
          @pclose(popen("taskkill /PID $pid /T /F", "r"));
          usleep(300000);
        }
        @unlink($RUN_MAIN);
      }

      echo json_encode(['ok' => true]); exit;
    }

    /* ---- STATUS ---- */
    case 'status': {
      $running = file_exists($RUN_FLAG) || port_open_ok($STREAM_HOST, $ADD_PORT, 150);
      $live_mtime = is_file($LIVE_JPG) ? filemtime($LIVE_JPG) : null;
      $info = null;
      if (is_file($RUN_FLAG)) {
        $raw = @file_get_contents($RUN_FLAG);
        $info = @json_decode($raw, true);
      }
      $stream_url = port_open_ok($STREAM_HOST, $ADD_PORT, 100) ? "http://{$STREAM_HOST}:{$ADD_PORT}/stream" : null;
      echo json_encode(['ok'=>true,'running'=>$running,'info'=>$info,'live_updated'=>$live_mtime,'stream_url'=>$stream_url]); exit;
    }

    /* ---- FEED: trả 3 bản mới nhất cho bảng Kết quả ---- */
    case 'feed': {
      $after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;
      $limit    = 3; // luôn kẹp tối đa 3

      try {
        if ($after_id > 0) {
          // trả bản mới hơn lastId (để chèn incremental)
          $sql = "SELECT id, ngay_chup, tg_chup, anh_chup, anh_toancanh, so_bien, id_cam
                  FROM `bien-so`
                  WHERE id > ?
                  ORDER BY id ASC
                  LIMIT ?";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, 'ii', $after_id, $limit);
        } else {
          // lần đầu: lấy 3 bản mới nhất
          $sql = "SELECT id, ngay_chup, tg_chup, anh_chup, anh_toancanh, so_bien, id_cam
                  FROM `bien-so`
                  ORDER BY id DESC
                  LIMIT ?";
          $stmt = mysqli_prepare($conn, $sql);
          mysqli_stmt_bind_param($stmt, 'i', $limit);
        }

        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
          if (!empty($r['anh_chup']))     $r['anh_chup']     = str_replace('\\','/',$r['anh_chup']);
          if (!empty($r['anh_toancanh'])) $r['anh_toancanh'] = str_replace('\\','/',$r['anh_toancanh']);
          $rows[] = $r;
        }
        echo json_encode($rows); exit;
      } catch (Exception $e) {
        echo json_encode(['error'=>true,'message'=>$e->getMessage()]); exit;
      }
    }

    /* ---- LATEST: đọc plates.txt trong STAGING (tuỳ chọn) ---- */
    case 'latest': {
      $N = isset($_GET['n']) ? max(1, min(50, intval($_GET['n']))) : 20;
      $rows=[];
      if (is_file($TEXT_LOG)){
        $lines = file($TEXT_LOG, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -$N);
        foreach ($lines as $line){
          $parts = preg_split("/\t/", $line);
          $rows[] = [
            'time'      => $parts[0] ?? '',
            'text'      => $parts[1] ?? '',
            'veh_img'   => stage_rel($parts[2] ?? '', $OUT_STAGE),
            'plate_img' => stage_rel($parts[3] ?? '', $OUT_STAGE),
          ];
        }
      }
      echo json_encode(['ok'=>true, 'items'=>$rows]); exit;
    }

    /* ---- INSERT: lưu DB + đồng bộ ảnh từ STAGING -> MAIN (ổn cho cả VIDEO & WEBCAM) ---- */
    case 'insert': {
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      try{
        $so_bien  = trim($_POST['so_bien'] ?? '');
        $plateRel = trim($_POST['anh_chup'] ?? '');
        $vehRel   = trim($_POST['anh_toancanh'] ?? '');
        $id_cam   = (int)($_POST['id_cam'] ?? 5);
        $timeStr  = trim($_POST['time'] ?? '');

        if ($so_bien==='') throw new Exception('Thiếu số biển');
        if ($timeStr==='' || strlen($timeStr)<16) $timeStr = date('Y-m-d H:i:s');
        $date = substr($timeStr,0,10);
        $time = substr($timeStr,11,8);

        // Chuẩn hoá path
        $norm = function($p){ $p = str_replace('\\','/', trim((string)$p)); return ltrim($p, '/'); };
        $plateRel = $norm($plateRel);
        $vehRel   = $norm($vehRel);

        $plateWeb = ''; $vehWeb = '';

        // Nếu là path MAIN (results_anpr/...), dùng luôn
        if ($plateRel !== '' && stripos($plateRel, 'results_anpr/') === 0) $plateWeb = $plateRel;
        if ($vehRel   !== '' && stripos($vehRel,   'results_anpr/') === 0) $vehWeb   = $vehRel;

        // Nếu là path STAGING (crops/... hoặc vehicles/...), copy sang MAIN
        if ($plateWeb === '' && $plateRel !== '') {
          $plateRelMain = copy_to_main($plateRel, 'plate', $OUT_STAGE, $OUT_MAIN);
          if ($plateRelMain !== '') $plateWeb = 'results_anpr/' . ltrim($plateRelMain,'/');
        }
        if ($vehWeb === '' && $vehRel !== '') {
          $vehRelMain = copy_to_main($vehRel, 'veh', $OUT_STAGE, $OUT_MAIN);
          if ($vehRelMain !== '') $vehWeb = 'results_anpr/' . ltrim($vehRelMain,'/');
        }

        // Fallback (nếu copy fail mà file có ở STAGING)
        if ($plateWeb === '' && $plateRel !== '') {
          $cand = $OUT_STAGE . DIRECTORY_SEPARATOR . $plateRel;
          if (is_file($cand)) {
            @ensure_dir($OUT_MAIN . DIRECTORY_SEPARATOR . 'crops');
            $basename = basename($plateRel);
            @copy($cand, $OUT_MAIN . DIRECTORY_SEPARATOR . 'crops' . DIRECTORY_SEPARATOR . $basename);
            $plateWeb = 'results_anpr/crops/' . $basename;
          }
        }
        if ($vehWeb === '' && $vehRel !== '') {
          $cand = $OUT_STAGE . DIRECTORY_SEPARATOR . $vehRel;
          if (is_file($cand)) {
            @ensure_dir($OUT_MAIN . DIRECTORY_SEPARATOR . 'vehicles');
            $basename = basename($vehRel);
            @copy($cand, $OUT_MAIN . DIRECTORY_SEPARATOR . 'vehicles' . DIRECTORY_SEPARATOR . $basename);
            $vehWeb = 'results_anpr/vehicles/' . $basename;
          }
        }

        // Ghi DB
        $sql = "INSERT INTO `bien-so` (ngay_chup, tg_chup, anh_chup, anh_toancanh, so_bien, province_code, id_cam)
                VALUES (?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conn, $sql);
        $prov = null;
        mysqli_stmt_bind_param($stmt, 'ssssssi', $date, $time, $plateWeb, $vehWeb, $so_bien, $prov, $id_cam);
        mysqli_stmt_execute($stmt);
        $newId = mysqli_insert_id($conn);

        // Ghi thêm plates.txt ở kho chính (để index/history thấy ngay)
        @file_put_contents(
          $OUT_MAIN.DIRECTORY_SEPARATOR.'plates.txt',
          $date.' '.$time."\t".$so_bien."\t".$vehWeb."\t".$plateWeb."\r\n",
          FILE_APPEND|LOCK_EX
        );

        echo json_encode([
          'ok'=>true,
          'id'=>$newId,
          'debug'=>[
            'in_plate'=>$plateRel,'in_veh'=>$vehRel,
            'out_plate'=>$plateWeb,'out_veh'=>$vehWeb
          ]
        ]); 
        exit;

      } catch (Throwable $e){
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'INSERT_FAIL: '.$e->getMessage()]); 
        exit;
      }
    }

    /* ---- UPLOAD VIDEO ---- */
    case 'upload_video': {
      ensure_dir($OUTDIR);
      ensure_dir($UPLOAD_DIR);

      if (empty($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'Không nhận được file hoặc lỗi upload']); exit;
      }

      $origName = $_FILES['video']['name'] ?? 'video.mp4';
      $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION) ?: 'mp4');
      $safeExt  = preg_match('/^[a-z0-9]+$/', $ext) ? $ext : 'mp4';

      try { $rnd = bin2hex(random_bytes(4)); } catch(Throwable $e) { $rnd = uniqid(); }
      $fname   = 'up_'.$rnd.'.'.$safeExt;
      $destAbs = $UPLOAD_DIR . DIRECTORY_SEPARATOR . $fname;

      if (!move_uploaded_file($_FILES['video']['tmp_name'], $destAbs)) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>'Không lưu được file upload']); exit;
      }

      echo json_encode([
        'ok'        => true,
        'abs_path'  => $destAbs,
        'web_path'  => 'results_anpr/add_staging/uploads/'.$fname
      ]); 
      exit;
    }

    /* ---- START VIDEO MODE ---- */
    case 'start_video': {
      $fileAbs = isset($_POST['file']) ? trim($_POST['file']) : '';
      if ($fileAbs === '' || !is_file($fileAbs)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Thiếu hoặc sai đường dẫn file video']); exit;
      }

      // LẤY SPEED từ client, mặc định 0.5 (phát nửa tốc độ)
      $speed = isset($_POST['speed']) ? floatval($_POST['speed']) : 0.5;
      if (!is_finite($speed) || $speed <= 0) $speed = 0.5;
      $speed = max(0.1, min(2.0, $speed));

      // Kill port và dọn flag trước khi chạy
      foreach (pids_listening_port($ADD_PORT) as $pid) kill_pid($pid);
      del_file($STOP_FLAG);
      del_file($MAIN_STOP_FLAG);
      del_file($RUN_FLAG);

      // Chạy video.py chế độ video + SPEED
      $cmd = 'start "" /B "'.$PYTHON_EXE.'" "'.$VIDEO_PY
            .'" --mode video --source "'.$fileAbs.'"'
            .' --cam 99'
            .' --out "'.$OUTDIR.'" --weights "'.$WEIGHTS.'"'
            .' --mjpeg_port '.$ADD_PORT
            .' --no_live'
            .' --speed '.$speed;
      pclose(popen($cmd, 'r'));

      // Đợi port mở tối đa 6s
      $t0 = microtime(true);
      do {
        usleep(200000);
        if (port_open_ok($STREAM_HOST, $ADD_PORT, 200)){
          echo json_encode(['ok'=>true, 'stream_url'=>"http://{$STREAM_HOST}:{$ADD_PORT}/stream"]); exit;
        }
      } while ((microtime(true)-$t0) < 6.0);

      echo json_encode(['ok'=>false, 'error'=>'Không khởi động được stream từ video']); exit;
    }
  }

  echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;
}

/* ====== HANDLER CHO FORM NHẬP TAY ====== */
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'manual') {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  $so_bien = trim($_POST['plate'] ?? '');
  $id_cam  = 5;
  if ($id_cam < 1 || $id_cam > 5) $id_cam = 1; // canh chừng dữ liệu lạ

  // Luôn lấy thời gian hiện tại để bản nhập tay lên đầu
  $date = date('Y-m-d');
  $time = date('H:i:s');

  // province_code: rỗng -> NULL, còn lại để nguyên (text 2 ký tự số)
  $prov = trim($_POST['province_code'] ?? '');
  if ($prov === '') $prov = null;

  if ($so_bien === '') {
    $manual_error = 'Thiếu số biển.';
  } else {
    $plateWeb = ''; $vehWeb = '';

    // Ảnh biển số
    if (!empty($_FILES['imgFile']) && $_FILES['imgFile']['error'] === UPLOAD_ERR_OK) {
      ensure_dir($OUT_MAIN . DIRECTORY_SEPARATOR . 'crops');
      $ext = strtolower(pathinfo($_FILES['imgFile']['name'] ?? 'jpg', PATHINFO_EXTENSION) ?: 'jpg');
      if (!preg_match('/^[a-z0-9]+$/', $ext)) $ext = 'jpg';
      $fname = 'manual_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
      $dest  = $OUT_MAIN . DIRECTORY_SEPARATOR . 'crops' . DIRECTORY_SEPARATOR . $fname;
      if (move_uploaded_file($_FILES['imgFile']['tmp_name'], $dest)) {
        $plateWeb = 'results_anpr/crops/' . $fname;
      }
    }

    // Ảnh toàn cảnh
    if (!empty($_FILES['imgFile2']) && $_FILES['imgFile2']['error'] === UPLOAD_ERR_OK) {
      ensure_dir($OUT_MAIN . DIRECTORY_SEPARATOR . 'vehicles');
      $ext2 = strtolower(pathinfo($_FILES['imgFile2']['name'] ?? 'jpg', PATHINFO_EXTENSION) ?: 'jpg');
      if (!preg_match('/^[a-z0-9]+$/', $ext2)) $ext2 = 'jpg';
      $fname2 = 'manual_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext2;
      $dest2  = $OUT_MAIN . DIRECTORY_SEPARATOR . 'vehicles' . DIRECTORY_SEPARATOR . $fname2;
      if (move_uploaded_file($_FILES['imgFile2']['tmp_name'], $dest2)) {
        $vehWeb = 'results_anpr/vehicles/' . $fname2;
      }
    }

    try {
      // CHÈN DB –> Setting.php sẽ nhìn thấy
      $sql = "INSERT INTO `bien-so`
              (ngay_chup, tg_chup, anh_chup, anh_toancanh, so_bien, province_code, id_cam)
              VALUES (?,?,?,?,?,?,?)";
      $stmt = mysqli_prepare($conn, $sql);
      mysqli_stmt_bind_param($stmt, 'ssssssi',
        $date, $time, $plateWeb, $vehWeb, $so_bien, $prov, $id_cam
      );
      mysqli_stmt_execute($stmt);
      $newId = mysqli_insert_id($conn);

      // Ghi thêm vào plates.txt để History nhìn thấy ngay (vẫn giữ)
      @file_put_contents(
        $OUT_MAIN . DIRECTORY_SEPARATOR . 'plates.txt',
        $date . ' ' . $time . "\t" . $so_bien . "\t" . $vehWeb . "\t" . $plateWeb . "\r\n",
        FILE_APPEND | LOCK_EX
      );

      // về lại add.php báo ok
      header('Location: add.php?ok=1&id=' . $newId);
      exit;

    } catch (Throwable $e) {
      // báo lỗi rõ ràng để dễ sửa
      $manual_error = 'INSERT_FAIL: ' . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8" />
  <title>Thêm dữ liệu biển số</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="../css/style.css" />
  <link rel="stylesheet" href="../css/Setting.css" />
  <link rel="stylesheet" href="../css/add.css" />
</head>
<body>
<div class="wrapper">
  <div class="menu">
    <div class="menu-right">
      <a href="/BTVN/index.php">Home</a>
      <a href="/BTVN/History.php">Lịch sử</a>
      <a href="/BTVN/Setting.php">Cài đặt</a>
    </div>
  </div>

  <div id="main">
    <?php include __DIR__ . '/sidebar_setting.php'; ?>
    <div class="main-content">
      <?php if (isset($_GET['ok'])): ?>
        <div class="anpr-alert success">Đã thêm bản ghi thủ công thành công (ID: <?= (int)($_GET['id'] ?? 0) ?>).</div>
      <?php elseif (!empty($manual_error)): ?>
        <div class="anpr-alert danger"><?= htmlspecialchars($manual_error) ?></div>
      <?php endif; ?>

      <div class="anpr-card">
        <div class="anpr-grid3">

          <!-- 1) CAM -->
          <section class="card card--cam">
            <h3>Thêm bằng camera / video</h3>
            <div class="cam-frame">
              <img id="camStream" src="../images/cam_off.jpg" alt="stream" />
              <div class="cam-overlay" id="camOverlay">Chưa mở camera</div>
            </div>
            <div class="cam-actions">
              <button class="anpr-btn"  type="button" id="btnStartCam">
                <i class="fa fa-camera"></i> Mở camera
              </button>
              <button class="anpr-btn" type="button" id="btnOpenVideo">
                <i class="fa fa-film"></i> Thêm video
              </button>
              <button class="anpr-btn danger" type="button" id="btnStopCam" disabled>
                <i class="fa fa-power-off"></i> Tắt
              </button>
              <!-- input file ẩn để chọn video -->
              <input type="file" id="videoFile" accept="video/*" style="display:none" />
            </div>
          </section>

          <!-- 2) NHẬP TAY -->
          <section class="card card--form">
            <h3>Nhập tay</h3>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="mode" value="manual" />
              <div class="form-grid">
                <div class="form-row"><label>Biển số</label><input type="text" name="plate" required placeholder="VD: 65A12345" /></div>
                <div class="form-row"><label>Camera</label>
                  <select name="cam">
                    <option value="1">Camera 1</option>
                    <option value="2">Camera 2</option>
                    <option value="3">Camera 3</option>
                    <option value="4">Camera 4</option>
                    <option value="5">Camera 5</option>
                  </select>
                </div>
                <div class="form-row"><label>Ngày</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" /></div>
                <div class="form-row"><label>Giờ</label><input type="time" name="time" value="<?= date('H:i') ?>" /></div>
                <div class="form-row"><label>Mã tỉnh</label><input type="text" name="province_code" placeholder="VD: 62" /></div>
                <div class="form-row" style="grid-column:1/-1"><label>Ảnh biển số</label><input type="file" name="imgFile" accept="image/*" /></div>
                <div class="form-row" style="grid-column:1/-1"><label>Ảnh toàn cảnh (tùy chọn)</label><input type="file" name="imgFile2" accept="image/*" /></div>
              </div>
              <div class="form-actions"><button class="anpr-btn" type="submit"><i class="fa fa-plus"></i> Thêm</button></div>
            </form>
          </section>

          <!-- 3) KẾT QUẢ (3 bản mới nhất) -->
          <section class="card card--results" id="detectResults" style="display:none">
            <h3>Kết quả mới chụp</h3>
            <div class="anpr-table-wrap results-scroll">
              <table class="anpr-table results-table">
                <thead>
                  <tr>
                    <th>HÌNH BIỂN</th>
                    <th>TOÀN CẢNH</th>
                    <th>NGÀY</th>
                    <th>GIỜ</th>
                    <th>BIỂN SỐ</th>
                    <th style="width:90px;text-align:center">CAM</th>
                    <th style="width:110px">THAO TÁC</th>
                  </tr>
                </thead>
                <tbody id="previewRow"></tbody>
              </table>
            </div>
          </section>

        </div>
      </div>
    </div>
  </div>

  <div class="clear"></div>
  <div class="footer"><p></p></div>
</div>

<!-- JS tách riêng -->
<script src="../js/add.js"></script>
</body>
</html>
