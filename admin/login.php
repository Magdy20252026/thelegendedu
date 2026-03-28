<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

/* ✅✅ التعديل المطلوب: اجلب الإعدادات مباشرة من جدول platform_settings (آخر قيمة محفوظة) */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS platform_settings (
      id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      platform_name VARCHAR(190) NOT NULL DEFAULT 'منصتي التعليمية',
      platform_logo VARCHAR(255) DEFAULT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
  $rowSettings = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
  if (!$rowSettings) {
    $pdo->exec("INSERT INTO platform_settings (id, platform_name, platform_logo) VALUES (1, 'منصتي التعليمية', NULL)");
    $rowSettings = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
  }
} catch (Throwable $e) {
  $rowSettings = null;
}

$settings = get_platform_settings($pdo);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  $stmt = $pdo->prepare("SELECT id, username, password_hash, role, is_active FROM admins WHERE username = ? LIMIT 1");
  $stmt->execute([$username]);
  $admin = $stmt->fetch();

  if (!$admin || (int)$admin['is_active'] !== 1 || !password_verify($password, $admin['password_hash'])) {
    $error = 'بيانات الدخول غير صحيحة.';
  } else {
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'] ?? 'مشرف';

    header('Location: dashboard.php');
    exit;
  }
}

$logo = (string)($rowSettings['platform_logo'] ?? ($settings['platform_logo'] ?? ''));
if ($logo === '') $logo = null;

$platformName = (string)($rowSettings['platform_name'] ?? ($settings['platform_name'] ?? 'منصتي التعليمية'));
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>تسجيل الدخول - <?php echo htmlspecialchars($platformName); ?></title>

  <!-- Thick Arabic font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/login.css">
</head>

<body class="app" data-theme="auto">
  <div class="bg" aria-hidden="true">
    <div class="bg-grad"></div>
    <div class="bg-ring r1"></div>
    <div class="bg-ring r2"></div>
    <div class="bg-ring r3"></div>
    <div class="bg-noise"></div>
  </div>

  <header class="topbar">
    <div class="brand">
      <?php if (!empty($logo)) : ?>
        <img class="brand-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="Logo">
      <?php else: ?>
        <div class="brand-fallback" aria-hidden="true"></div>
      <?php endif; ?>

      <div class="brand-text">
        <div class="brand-name"><?php echo htmlspecialchars($platformName); ?></div>
        <div class="brand-sub">Admin Console</div>
      </div>
    </div>

    <!-- Emoji Switch -->
    <div class="theme-emoji">
      <span class="emoji" aria-hidden="true">☀️</span>
      <label class="emoji-switch" title="Light / Dark">
        <input id="themeSwitch" type="checkbox" />
        <span class="emoji-slider" aria-hidden="true"></span>
      </label>
      <span class="emoji" aria-hidden="true">🌙</span>
    </div>
  </header>

  <main class="wrap">
    <section class="card" aria-label="تسجيل الدخول">
      <div class="card-head">
        <h1>تسجيل الدخول</h1>
        <p>أدخل بياناتك للمتابعة إلى لوحة التحكم.</p>
      </div>

      <?php if ($error): ?>
        <div class="alert" role="alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="post" class="form" autocomplete="off">
        <label class="field">
          <span class="label">اسم المستخدم</span>
          <div class="control">
            <span class="icon" aria-hidden="true">👤</span>
            <input class="input" type="text" name="username" required placeholder="admin" />
          </div>
        </label>

        <label class="field">
          <span class="label">كلمة المرور</span>
          <div class="control">
            <span class="icon" aria-hidden="true">🔒</span>
            <input class="input" id="password" type="password" name="password" required placeholder="••••••••" />
            <button class="icon-btn" type="button" id="togglePassword" aria-label="إظهار/إخفاء كلمة المرور">👁️</button>
          </div>
        </label>

        <button class="btn" type="submit">دخول</button>

        <div class="meta">
          <span>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($platformName); ?></span>
        </div>
      </form>
    </section>
  </main>

  <script>
    (function () {
      const root = document.body;
      const themeSwitch = document.getElementById('themeSwitch');

      const stored = localStorage.getItem('admin_theme') || 'auto';

      function osPrefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      }

      function applyTheme(mode) {
        root.setAttribute('data-theme', mode);
        localStorage.setItem('admin_theme', mode);
        themeSwitch.checked = (mode === 'dark') || (mode === 'auto' && osPrefersDark());
      }

      applyTheme(stored);

      themeSwitch.addEventListener('change', () => {
        applyTheme(themeSwitch.checked ? 'dark' : 'light');
      });

      if (stored === 'auto' && window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme('auto'));
      }

      const pass = document.getElementById('password');
      const toggle = document.getElementById('togglePassword');
      toggle.addEventListener('click', () => {
        const isPwd = pass.type === 'password';
        pass.type = isPwd ? 'text' : 'password';
        toggle.textContent = isPwd ? '🙈' : '👁️';
      });
    })();
  </script>
</body>
</html>