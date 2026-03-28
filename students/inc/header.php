<?php
// students/inc/header.php
// expects: $pdo defined (PDO)
require_once __DIR__ . '/platform_settings.php';
require_once __DIR__ . '/student_auth.php'; // ✅ to read session safely

if (!function_exists('h')) {
  function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
  }
}

$row = get_platform_settings_row($pdo);

$platformName = trim((string)($row['platform_name'] ?? 'منصتي التعليمية'));
if ($platformName === '') $platformName = 'منصتي التعليمية';

$logoDb = trim((string)($row['platform_logo'] ?? ''));

// DB usually stores: uploads/platform/xxx.png
$logoUrl = student_public_asset_url($logoDb);

$isLogged = !empty($_SESSION['student_id']);
?>
<header class="site-header" role="banner">
  <div class="container">
    <div class="site-header__bar">

      <!-- LEFT: brand + switch (desktop), switch only (mobile) -->
      <div class="left-pack">
        <a class="brand" href="index.php" aria-label="<?php echo h($platformName); ?>">
          <?php if ($logoUrl): ?>
            <img class="brand__logo" src="<?php echo h($logoUrl); ?>" alt="Logo">
          <?php else: ?>
            <span class="brand__logo-fallback" aria-hidden="true"></span>
          <?php endif; ?>
          <span class="brand__name"><?php echo h($platformName); ?></span>
        </a>

        <div class="theme-switch" data-theme-switch aria-label="تبديل الوضع">
          <button class="theme-switch__btn" type="button" data-theme="light" aria-label="لايت">☀</button>
          <button class="theme-switch__btn" type="button" data-theme="dark" aria-label="دارك">🌙</button>
          <span class="theme-switch__knob" aria-hidden="true"></span>
        </div>
      </div>

      <!-- CENTER: mobile logo (clickable) -->
      <div class="mobile-center">
        <a class="mobile-center__link" href="index.php" aria-label="<?php echo h($platformName); ?>">
          <?php if ($logoUrl): ?>
            <img class="mobile-center__logo" src="<?php echo h($logoUrl); ?>" alt="Logo">
          <?php else: ?>
            <span class="mobile-center__logo-fallback" aria-hidden="true"></span>
          <?php endif; ?>
        </a>
      </div>

      <!-- RIGHT: actions (desktop only) -->
      <nav class="actions" aria-label="روابط">
        <?php if ($isLogged): ?>
          <a class="btnx outline" href="account.php">حسابي</a>
          <a class="btnx solid" href="logout.php">تسجيل الخروج</a>
        <?php else: ?>
          <a class="btnx outline" href="login.php">تسجيل الدخول</a>
          <a class="btnx solid" href="register.php">حساب جديد</a>
        <?php endif; ?>
      </nav>

      <!-- Burger (mobile only) -->
      <button class="nav-burger" type="button" aria-label="فتح القائمة" data-nav-toggle>
        <span></span><span></span><span></span>
      </button>

    </div>

    <!-- Mobile drawer -->
    <div class="mobile-drawer" data-mobile-drawer aria-hidden="true">
      <div class="mobile-drawer__box">
        <?php if ($isLogged): ?>
          <a class="btnx solid btnx--big" href="account.php">حسابي</a>
          <a class="btnx solid btnx--big" href="logout.php">تسجيل الخروج</a>
        <?php else: ?>
          <a class="btnx solid btnx--big" href="register.php">حساب جديد</a>
          <a class="btnx solid btnx--big" href="login.php">تسجيل الدخول</a>
        <?php endif; ?>
      </div>
    </div>

  </div>
</header>