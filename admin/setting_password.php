<?php
// admin/setting_password.php — Đổi mật khẩu (cùng khung giao diện với login)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/setting_auth.php'; // bắt buộc đã đăng nhập
mysqli_set_charset($conn, 'utf8mb4');

$user = $_SESSION['admin_auth']['user'] ?? '';
$err = '';
$info = '';

// Xử lý submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $cfm = (string)($_POST['cfm'] ?? '');

    if ($new !== $cfm) {
        $err = 'Xác nhận mật khẩu không khớp.';
    } elseif (strlen($new) < 6) {
        $err = 'Mật khẩu mới tối thiểu 6 ký tự.';
    } else {
        // Lấy mật khẩu hiện tại (plain text)
        $stmt = mysqli_prepare($conn, "SELECT password FROM `admin` WHERE username=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $user);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$row || $old !== (string)$row['password']) {
            $err = 'Mật khẩu cũ không đúng.';
        } else {
            // Cập nhật mật khẩu mới (plain text)
            $stmt = mysqli_prepare($conn, "UPDATE `admin` SET password=?, updated_at=NOW() WHERE username=? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'ss', $new, $user);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Bắt buộc đăng nhập lại
            unset($_SESSION['admin_auth']);
            if (function_exists('session_regenerate_id')) {
                session_regenerate_id(true);
            }

            // Tính BASE cho redirect
            $__parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/')); // ['BTVN','admin','setting_password.php']
            $BASE    = '/' . ($__parts[0] ?? '');                         // '/BTVN'

            header('Location: ' . $BASE . '/admin/login_setting.php?reset=1');
            exit;
        }
    }
}

// BASE cho link/css
$__parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
$BASE    = '/' . ($__parts[0] ?? '');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đổi mật khẩu Cài đặt</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- Dùng lại CSS khung + phong cách card như login -->
  <link rel="stylesheet" href="<?=$BASE?>/css/style.css">
  <link rel="stylesheet" href="<?=$BASE?>/css/Setting.css">
  <link rel="stylesheet" href="<?=$BASE?>/css/login_setting.css">
</head>
<body>
<div class="wrapper">
  <!-- Menu trên cùng -->
  <div class="menu">
    <div class="menu-right">
      <a href="<?=$BASE?>/index.php">Home</a>
      <a href="<?=$BASE?>/History.php">Lịch sử</a>
      <a class="active" href="<?=$BASE?>/Setting.php">Cài đặt</a>
    </div>
  </div>

  <div id="main">
    <!-- Sidebar giữ layout -->
    <div class="sidebar">
      <div class="title">DANH MỤC THAO TÁC</div>
      <div class="empty-note">
        Bạn đang ở trang đổi mật khẩu khu vực Cài đặt.
      </div>
    </div>

    <!-- Nội dung chính: dùng lại khung card giống login -->
    <div class="main-content">
      <div class="login-center">
        <div class="login-card">
          <div class="login-hd">
            <div class="login-badge"><i class="fa fa-key"></i></div>
            Đổi mật khẩu (<?= htmlspecialchars($user) ?>)
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
                <label for="old">Mật khẩu cũ</label>
                <input id="old" class="login-ipt" type="password" name="old" required placeholder="Nhập mật khẩu cũ">
              </div>

              <div class="login-row">
                <label for="new">Mật khẩu mới</label>
                <input id="new" class="login-ipt" type="password" name="new" required placeholder="Nhập mật khẩu mới (≥ 6 ký tự)">
              </div>

              <div class="login-row">
                <label for="cfm">Nhập lại mật khẩu mới</label>
                <input id="cfm" class="login-ipt" type="password" name="cfm" required placeholder="Nhập lại mật khẩu mới">
              </div>

              <!-- Hàng nút: xanh lá cập nhật + xanh dương quay lại đăng nhập -->
              <div class="btn-dual-row">
                <button class="btn-green" type="submit">
                  <i class="fa fa-save"></i> Cập nhật
                </button>
              </div>
            </form>

          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="footer"><p></p></div>
</div>

<!-- Bổ sung vài class nút để khớp phong cách -->
<style>
  .btn-dual-row{display:flex;gap:10px;justify-content:flex-end;margin-top:6px}
  .btn-green,.btn-blue{
    display:inline-flex;align-items:center;gap:8px;
    padding:12px 14px;border-radius:12px;font-weight:700;text-decoration:none;
    border:1px solid transparent;cursor:pointer
  }
  .btn-green{background:#10b981;border-color:#10b981;color:#fff}
  .btn-green:hover{filter:brightness(1.03)}
  .btn-blue{background:#3b82f6;border-color:#3b82f6;color:#fff}
  .btn-blue:hover{filter:brightness(1.05)}
</style>
</body>
</html>
