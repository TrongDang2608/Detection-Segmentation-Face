<?php
// admin/login_setting.php — Đăng nhập khu vực Cài đặt (nằm trong khung app)
// KHÔNG dùng hash: so sánh trực tiếp với cột `password` (plain text).

require_once __DIR__ . '/../config/database.php';
mysqli_set_charset($conn, 'utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
  $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  session_set_cookie_params([
    'httponly' => true,
    'secure'   => $secure,
    'samesite' => 'Lax',
  ]);
  session_start();
}

define('SETTING_TTL', 3 * 60); // 3 phút TTL
$err  = '';
$info = '';

/* ===================== FLASH MESSAGE ===================== */
/* Khi URL có ?reason=change -> set flash rồi redirect về URL sạch */
if (isset($_GET['reason']) && $_GET['reason'] === 'change') {
  $_SESSION['flash_info'] = 'Vui lòng đăng nhập để đổi mật khẩu.';
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); // remove querystring
  exit;
}
/* Lấy flash nếu có (chỉ 1 lần) */
if (!empty($_SESSION['flash_info'])) {
  $info = $_SESSION['flash_info'];
  unset($_SESSION['flash_info']);
}
/* ========================================================= */

if (isset($_GET['reset']))   $info = 'Đổi mật khẩu thành công. Vui lòng đăng nhập lại.';
if (isset($_GET['locked']))  $info = 'Phiên Cài đặt đã được khoá. Vui lòng đăng nhập lại.';
if (isset($_GET['expired'])) $info = 'Phiên đăng nhập đã hết hạn do không hoạt động.';

// ====== Nếu đã đăng nhập còn hạn → vào thẳng
if (isset($_SESSION['admin_auth']['user'], $_SESSION['admin_auth']['last'])) {
  $idle = time() - (int)$_SESSION['admin_auth']['last'];
  if ($idle <= SETTING_TTL) {
    header('Location: ' . ($_SESSION['admin_next'] ?? '/BTVN/Setting.php'));
    exit;
  } else {
    unset($_SESSION['admin_auth']);
    if (!$info) $info = 'Phiên của bạn đã hết hạn. Vui lòng đăng nhập lại.';
  }
}

// ====== Xử lý đăng nhập (KHÔNG HASH)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username'] ?? '');
  $p = (string)($_POST['password'] ?? '');

  if ($u === '' || $p === '') {
    $err = 'Vui lòng nhập đầy đủ tài khoản và mật khẩu.';
  } else {
    $stmt = mysqli_prepare($conn, "SELECT id, username, password FROM `admin` WHERE username=? LIMIT 1");
    if ($stmt) {
      mysqli_stmt_bind_param($stmt, 's', $u);
      mysqli_stmt_execute($stmt);
      $res = mysqli_stmt_get_result($stmt);
      $row = $res ? mysqli_fetch_assoc($res) : null;
      mysqli_stmt_close($stmt);
    } else { $row = null; }

    $okLogin = $row && ($p === (string)($row['password'] ?? ''));

    if (!$okLogin) {
      $err = 'Tài khoản hoặc mật khẩu không đúng.';
    } else {
      if (function_exists('session_regenerate_id')) session_regenerate_id(true);
      $_SESSION['admin_auth'] = ['user' => $row['username'], 'last' => time()];
      $goto = $_SESSION['admin_next'] ?? '/BTVN/Setting.php';
      unset($_SESSION['admin_next']);
      header('Location: ' . $goto);
      exit;
    }
  }
}

// ====== Tính BASE cho link CSS/điều hướng tuyệt đối
$__parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));   // vd: ['BTVN','admin','login_setting.php']
$BASE    = '/' . ($__parts[0] ?? '');                           // -> '/BTVN'
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đăng nhập Cài đặt</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?=$BASE?>/css/style.css">
  <link rel="stylesheet" href="<?=$BASE?>/css/Setting.css">
  <link rel="stylesheet" href="<?=$BASE?>/css/login_setting.css">
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
    <div class="sidebar">
      <div class="title">DANH MỤC THAO TÁC</div>
      <div class="empty-note">
        Vui lòng đăng nhập để sử dụng các chức năng trong khu vực Cài đặt.
      </div>
    </div>

    <div class="main-content">
      <div class="login-center">
        <div class="login-card">
          <div class="login-hd">
            <div class="login-badge"><i class="fa fa-shield-halved"></i></div>
            Đăng nhập khu vực Cài đặt
          </div>
          <div class="login-bd">
            <?php if (!empty($info)): ?>
              <div class="msg inf"><?= htmlspecialchars($info) ?></div>
            <?php endif; ?>

            <?php if (!empty($err)): ?>
              <div class="msg err"><?= htmlspecialchars($err) ?></div>
            <?php endif; ?>

            <form class="login-form" method="post" autocomplete="off">
              <div class="login-row">
                <label for="u">Tài khoản</label>
                <input id="u" class="login-ipt" type="text" name="username" required autofocus placeholder="Nhập tài khoản">
              </div>

              <div class="login-row">
                <label for="p">Mật khẩu</label>
                <input id="p" class="login-ipt" type="password" name="password" required placeholder="Nhập mật khẩu">
              </div>

              <button class="login-btn" type="submit">
                <i class="fa fa-lock"></i> Đăng nhập
              </button>

              <div class="login-hint">
                Sau khi đăng nhập, phiên sẽ tự hết hạn sau 3 phút không thao tác.
              </div>

              <!-- Link đổi mật khẩu: nếu đã đăng nhập sẽ vào trang đổi; nếu chưa, setting_auth sẽ đưa về login kèm reason=change -->
              <div class="login-extra">
                <a href="<?=$BASE?>/admin/setting_password.php">
                  <i class="fa fa-key"></i> Đổi mật khẩu
                </a>
              </div>
            </form>

          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="footer"><p></p></div>
</div>
</body>
</html>
