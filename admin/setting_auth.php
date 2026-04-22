<?php
// admin/setting_auth.php
// "Lá chắn" cho các trang Cài đặt: kiểm tra đăng nhập và timeout 5 phút

if (session_status() === PHP_SESSION_NONE) session_start();

define('SETTING_TTL', 3 * 60); // 3 phút 

// Nếu chưa đăng nhập hoặc hết hạn -> ép về trang login
$ok = isset($_SESSION['admin_auth']['user'], $_SESSION['admin_auth']['last']);
if ($ok) {
    $idle = time() - (int)$_SESSION['admin_auth']['last'];
    if ($idle > SETTING_TTL) {
        $ok = false;
        unset($_SESSION['admin_auth']);
    }
}

if (!$ok) {
    // Lưu lại URL hiện tại để quay lại sau khi login
    $_SESSION['admin_next'] = $_SERVER['REQUEST_URI'] ?? '/BTVN/Setting.php';

    // Chỉ hiển thị thông báo "đổi mật khẩu" nếu người dùng vào đúng trang đổi mật khẩu
    $path = $_SERVER['REQUEST_URI'] ?? '';
    $needChangeMsg = (strpos($path, '/admin/setting_password.php') !== false);

    $login = '/BTVN/admin/login_setting.php' . ($needChangeMsg ? '?reason=change' : '');
    header('Location: ' . $login);
    exit;
}

// Đã hợp lệ: làm mới thời điểm hoạt động
$_SESSION['admin_auth']['last'] = time();
