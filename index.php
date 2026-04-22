<?php
// index.php — Giao diện 4 camera + API start/stop/status + Plate Feed (MJPEG từ video.py)

$PYTHON   = 'C:\\Users\\Vo Dang Vu Phong\\AppData\\Local\\Programs\\Python\\Python39\\python.exe';
$VIDEO_PY = __DIR__ . DIRECTORY_SEPARATOR . 'Python' . DIRECTORY_SEPARATOR . 'video.py';

$STREAM_HOST = '127.0.0.1';
$BASE_PORT   = 5001; // Cam1=5001, Cam2=5002, Cam3=5003, Cam4=5004

$OUTDIR   = __DIR__ . DIRECTORY_SEPARATOR . 'results_anpr';
$TEXT_LOG = $OUTDIR . DIRECTORY_SEPARATOR . 'plates.txt';
$WEIGHTS  = __DIR__ . DIRECTORY_SEPARATOR . 'Python' . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . 'detect' . DIRECTORY_SEPARATOR . 'train2' . DIRECTORY_SEPARATOR . 'weights' . DIRECTORY_SEPARATOR . 'best.pt';

// ========== Helpers (PHP) ==========
function port_open_ok($host, $port, $timeout_ms = 300){
    $timeout = max(0.05, $timeout_ms / 1000.0);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, intval($port), $errno, $errstr, $timeout);
    if ($fp) { fclose($fp); return true; }
    return false;
}
function port_for_slot($slot, $basePort){
    $slot = max(1, min(4, intval($slot)));
    return $basePort + ($slot - 1);
}
function base_url_for_slot($host, $slot, $basePort){
    $port = port_for_slot($slot, $basePort);
    return ["http://{$host}:{$port}", $port];
}

/**
 * Bật (nếu cần) và trả URL MJPEG /stream từ video.py cho một slot (Cam1..4).
 */
function ensure_mjpeg_from_video_py($pythonExe, $videoPy, $weights, $outdir, $host, $basePort, $camIndex, $slot){
    list($baseUrl, $port) = base_url_for_slot($host, $slot, $basePort);
    $streamUrl = $baseUrl . '/stream';

    // cổng đã mở => server đang chạy
    if (port_open_ok($host, $port, 300)) return [$streamUrl, true];
    if (!is_file($videoPy)) return [null, false];

    // Spawn video.py (Windows non-blocking)
    $cmd  = 'start "" /B ';
    $cmd .= '"' . $pythonExe . '" ';
    $cmd .= '"' . $videoPy . '" ';
    $cmd .= '--mode webcam ';
    $cmd .= '--source ' . intval($camIndex) . ' ';
    $cmd .= '--cam ' . intval($slot) . ' ';
    $cmd .= '--out "' . $outdir . '" ';
    $cmd .= '--weights "' . $weights . '" ';
    $cmd .= '--mjpeg_port ' . intval($port) . ' ';
    $cmd .= '--no_live ';
    // $cmd .= '--no_overlay '; // bật nếu muốn tiết kiệm CPU
    $cmd .= '> NUL 2>&1';
    @pclose(@popen($cmd, 'r'));

    // Đợi tối đa 6s cho tới khi cổng mở
    $t0 = microtime(true);
    do {
        usleep(200000);
        if (port_open_ok($host, $port, 200)) return [$streamUrl, true];
    } while ((microtime(true) - $t0) < 6.0);

    return [null, false];
}

// ========== API ==========
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!is_dir($OUTDIR)) @mkdir($OUTDIR, 0777, true);

    switch ($_GET['action']) {
        case 'start':
            $camIndex = isset($_GET['cam']) ? max(0, intval($_GET['cam'])) : 0;
            $slot     = isset($_GET['slot']) ? max(1, min(4, intval($_GET['slot']))) : ($camIndex + 1);

            list($stream_url, $ok) = ensure_mjpeg_from_video_py(
                $PYTHON, $VIDEO_PY, $WEIGHTS, $OUTDIR, $STREAM_HOST, $BASE_PORT, $camIndex, $slot
            );

            if ($ok && $stream_url){
                echo json_encode(['ok'=>true, 'started_cam'=>$slot, 'stream_url'=>$stream_url]);
            } else {
                echo json_encode(['ok'=>false, 'error'=>'cannot_start_video_py_or_stream']);
            }
            exit;

        case 'stop':
            // Dừng vòng lặp: tạo STOP.flag để video.py tự dừng
            $stopFlag = $OUTDIR . DIRECTORY_SEPARATOR . 'STOP.flag';
            @file_put_contents($stopFlag, "1");
            echo json_encode(['ok'=>true]);
            exit;

        case 'status':
            // Trả xem có slot nào đang phát /stream không + danh sách slot đang chạy
            $running = false;
            $running_slots = [];
            for ($slot=1; $slot<=4; $slot++){
                $port = port_for_slot($slot, $BASE_PORT);
                if (port_open_ok($STREAM_HOST, $port, 200)) {
                    $running = true;
                    $running_slots[] = $slot;
                }
            }
            echo json_encode(['ok'=>true, 'running'=>$running, 'running_slots'=>$running_slots]);
            exit;

        case 'feed':
            // trả danh sách bản ghi từ DB (giống get_latest.php)
            $after_id = isset($_GET['after_id']) ? intval($_GET['after_id']) : 0;
            $limit    = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 50;

            $host = "localhost";
            $user = "root";
            $pass = "";
            $db   = "biensoxe";

            try {
                $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                if ($after_id > 0) {
                    $sql = "SELECT id, ngay_chup, tg_chup, anh_chup, so_bien, id_cam
                            FROM `bien-so`
                            WHERE id > :after_id
                            ORDER BY id ASC
                            LIMIT :lim";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':after_id', $after_id, PDO::PARAM_INT);
                    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                } else {
                    $sql = "SELECT id, ngay_chup, tg_chup, anh_chup, so_bien, id_cam
                            FROM `bien-so`
                            ORDER BY id DESC
                            LIMIT :lim";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                }

                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    if (!empty($r['anh_chup'])) $r['anh_chup'] = str_replace('\\','/',$r['anh_chup']);
                }
                echo json_encode($rows); exit;
            } catch (Exception $e) {
                echo json_encode(['error'=>true,'message'=>$e->getMessage()]); exit;
            }

        case 'last':
            // đọc bản cuối từ plates.txt (nếu cần)
            $plate = null; $datetime = null; $img = null;
            if (is_file($TEXT_LOG)) {
                $lines = file($TEXT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!empty($lines)) {
                    $last = trim($lines[count($lines)-1]);
                    $parts = preg_split("/\t/", $last);
                    $datetime = $parts[0] ?? null;
                    $plate    = $parts[1] ?? null;
                    $img      = isset($parts[3]) ? str_replace('\\','/', $parts[3]) : null;
                }
            }
            echo json_encode(['ok'=>true,'plate'=>$plate,'datetime'=>$datetime,'img'=>$img]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Ứng dụng nhận diện biển số</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="stylesheet" href="css/style.css" />
</head>
<script src="js/index.js?v=20250907-2" defer></script>
<body>
<div class="wrapper">
    <div class="menu">
        <div class="power-group">
            <div class="power-control-group">
                <button id="powerButton1" class="circle-button power-off"><i class="fas fa-power-off"></i></button>
                <span class="power-text">Cam1</span>
            </div>
            <div class="power-control-group">
                <button id="powerButton2" class="circle-button power-off"><i class="fas fa-power-off"></i></button>
                <span class="power-text">Cam2</span>
            </div>
            <div class="power-control-group">
                <button id="powerButton3" class="circle-button power-off"><i class="fas fa-power-off"></i></button>
                <span class="power-text">Cam3</span>
            </div>
            <div class="power-control-group">
                <button id="powerButton4" class="circle-button power-off"><i class="fas fa-power-off"></i></button>
                <span class="power-text">Cam4</span>
            </div>
        </div>
        <div class="menu-right">
            <a href="History.php">Lịch sử</a>
            <a href="Setting.php">Cài đặt</a>
        </div>
    </div>

    <div id="main">
        <div class="video-container">
            <div class="camera-box">
                <img id="cam1Stream" src="images/cam_off.jpg" alt="Cam1" />
                <div id="timestampOverlay1" class="cam-label">Cam1</div>
            </div>
            <div class="camera-box">
                <img id="cam2Stream" src="images/cam_off.jpg" alt="Cam2" />
                <div id="timestampOverlay2" class="cam-label">Cam2</div>
            </div>
            <div class="camera-box">
                <img id="cam3Stream" src="images/cam_off.jpg" alt="Cam3" />
                <div id="timestampOverlay3" class="cam-label">Cam3</div>
            </div>
            <div class="camera-box">
                <img id="cam4Stream" src="images/cam_off.jpg" alt="Cam4" />
                <div id="timestampOverlay4" class="cam-label">Cam4</div>
            </div>
        </div>

        <div class="plate-results" id="plateResults"></div>
    </div>

    <div class="clear"></div>
    <div class="footer"><p></p></div>
</div>
</body>
</html>

