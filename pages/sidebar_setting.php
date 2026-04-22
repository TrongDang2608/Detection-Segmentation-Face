<?php
$__parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
$BASE    = '/' . ($__parts[0] ?? '');
?>
<link rel="stylesheet" href="/BTVN/css/Setting.css">

<div class="sidebar">
  <div class="title">Danh mục thao tác</div>

  <div class="tile-menu" id="tileMenu">
    <a class="tile-btn" data-view="edit" href="/BTVN/Setting.php">
      <span class="tile-ico">✏️</span>
      <span class="tile-text">
        <div class="t">Tuỳ chỉnh dữ liệu</div>
        <div class="d">Sửa/Xoá nội dung trong kho dữ liệu</div>
      </span>
    </a>

    <a class="tile-btn" data-view="add" href="/BTVN/pages/add.php">
      <span class="tile-ico">➕</span>
      <span class="tile-text">
        <div class="t">Thêm dữ liệu</div>
        <div class="d">Thêm biển số mới (camera hoặc nhập tay)</div>
      </span>
    </a>
  </div>

  <!-- Ổ khóa nhỏ -->
  <div class="sidebar-foot">
    <a class="sidebar-lock"
       href="<?=$BASE?>/admin/lock_setting.php"
       title="Khu vực Cài đặt" aria-label="Khu vực Cài đặt">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 2a4 4 0 00-4 4v3H7a2 2 0 00-2 2v8a2 2 0 002 2h10a2 2 0 002-2v-8a2 2 0 00-2-2h-1V6a4 4 0 00-4-4zm-2 7V6a2 2 0 114 0v3h-4zm2 5a2 2 0 110 4 2 2 0 010-4z"/>
      </svg>
    </a>
  </div>
</div> 

<script>
document.addEventListener('DOMContentLoaded', function () {
  const buttons = document.querySelectorAll('#tileMenu .tile-btn');
  const path  = location.pathname.toLowerCase();
  const file  = path.substring(path.lastIndexOf('/') + 1);
  const map = { 'add.php':'add', 'setting.php':'edit', 'history.php':'history', 'index.php':'home' };
  const view = map[file] || 'home';
  buttons.forEach(b => b.classList.toggle('active', b.dataset.view === view));
});
</script>
