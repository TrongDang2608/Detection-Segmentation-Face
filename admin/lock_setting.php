<?php
// admin/lock_setting.php — Khoá khu vực Cài đặt (logout phần admin_auth)

if (session_status() === PHP_SESSION_NONE) session_start();

$_SESSION['admin_next'] = $_SERVER['HTTP_REFERER'] ?? '/BTVN/Setting.php';

/* Xoá quyền truy cập khu Cài đặt */
unset($_SESSION['admin_auth']);

/* (khuyến nghị) Đổi session id để an toàn hơn */
if (function_exists('session_regenerate_id')) {
    session_regenerate_id(true);
}

/* Đẩy về trang đăng nhập và báo đã khoá */
header('Location: /BTVN/admin/login_setting.php?locked=1');
exit;
