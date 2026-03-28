<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();
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

$platformName = (string)($rowSettings['platform_name'] ?? ($settings['platform_name'] ?? 'منصتي التعليمية'));
$logo = (string)($rowSettings['platform_logo'] ?? ($settings['platform_logo'] ?? ''));

if ($logo === '') $logo = null;

function h(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
/* =========================
   ✅ إضافة: صلاحيات المشرف لإظهار/إخفاء الأزرار في السايدبار
   ========================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'مشرف');
$allowedMenuKeys = get_allowed_menu_keys($pdo, $adminId, $adminRole);

function menu_visible(array $allowedKeys, string $key, string $role): bool {
  if ($role === 'مدير') return true;
  if ($key === 'logout') return true;
  return menu_allowed($allowedKeys, $key);
}

/**
 * ملاحظة:
 * - تعطيل المستخدم يتم من خلال العمود is_active في جدول admins
 */
$success = null;
$error = null;

/* =========================
   CREATE
   ========================= */
if (($_POST['action'] ?? '') === 'create') {
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $role = trim($_POST['role'] ?? 'مشرف');

  if ($username === '' || $password === '') {
    $error = 'من فضلك اكتب اسم المستخدم وكلمة السر.';
  } elseif (!in_array($role, ['مدير', 'مشرف'], true)) {
    $error = 'صلاحية غير صحيحة.';
  } else {
    try {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)");
      $stmt->execute([$username, $hash, $role]);

      header('Location: users.php?added=1');
      exit;
    } catch (PDOException $e) {
      $error = 'اسم المستخدم موجود بالفعل.';
    }
  }
}

/* =========================
   UPDATE
   ========================= */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $role = trim($_POST['role'] ?? 'مشرف');
  $isActive = isset($_POST['is_active']) ? 1 : 0;

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } elseif ($username === '') {
    $error = 'اسم المستخدم مطلوب.';
  } elseif (!in_array($role, ['مدير', 'مشرف'], true)) {
    $error = 'صلاحية غير صحيحة.';
  } else {
    try {
      if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE admins SET username=?, password_hash=?, role=?, is_active=? WHERE id=?");
        $stmt->execute([$username, $hash, $role, $isActive, $id]);
      } else {
        $stmt = $pdo->prepare("UPDATE admins SET username=?, role=?, is_active=? WHERE id=?");
        $stmt->execute([$username, $role, $isActive, $id]);
      }

      header('Location: users.php?updated=1');
      exit;
    } catch (PDOException $e) {
      $error = 'تعذر التعديل (ربما اسم المستخدم مكرر).';
    }
  }
}

/* =========================
   TOGGLE ACTIVE (SUSPEND/ACTIVATE)
   ========================= */
if (($_POST['action'] ?? '') === 'toggle_active') {
  $id = (int)($_POST['id'] ?? 0);

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } else {
    $stmt = $pdo->prepare("UPDATE admins SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?");
    $stmt->execute([$id]);

    header('Location: users.php?toggled=1');
    exit;
  }
}

/* =========================
   DELETE
   ========================= */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } else {
    $stmt = $pdo->prepare("DELETE FROM admins WHERE id=?");
    $stmt->execute([$id]);

    header('Location: users.php?deleted=1');
    exit;
  }
}

/* =========================
   MESSAGES
   ========================= */
if (isset($_GET['added'])) $success = 'تمت إضافة المستخدم بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل المستخدم بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف المستخدم بنجاح.';
if (isset($_GET['toggled'])) $success = 'تم تحديث حالة المستخدم بنجاح.';

/* =========================
   FETCH LIST
   ========================= */
$rows = $pdo->query("SELECT id, username, role, is_active, created_at FROM admins ORDER BY id DESC")->fetchAll();

$totalUsers = count($rows);
$activeUsers = 0;
$suspendedUsers = 0;
$adminsCount = 0;
$supervisorsCount = 0;

foreach ($rows as $r) {
  if ((int)$r['is_active'] === 1) $activeUsers++;
  else $suspendedUsers++;

  if (($r['role'] ?? '') === 'مدير') $adminsCount++;
  if (($r['role'] ?? '') === 'مشرف') $supervisorsCount++;
}

/* =========================
   EDIT MODE
   ========================= */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT id, username, role, is_active FROM admins WHERE id=? LIMIT 1");
  $stmt->execute([$editId]);
  $editRow = $stmt->fetch() ?: null;
}

/* =========================
   Sidebar menu
   ✅ FIX: كان href لصلاحيات المستخدمين "#user-permissions"
   لذلك لا يحدث انتقال. تم تغييره إلى user-permissions.php
   + إضافة key لكل عنصر لتطبيق صلاحيات المشرف
   ========================= */
$menu = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],

  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php', 'active' => true],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php'],

  ['key' => 'grades', 'label' => 'الصفوف الدراسية', 'icon' => '🏫', 'href' => 'grades.php'],
  ['key' => 'centers', 'label' => 'السناتر', 'icon' => '🏢', 'href' => 'centers.php'],
  ['key' => 'groups', 'label' => 'المجموعات', 'icon' => '👥', 'href' => 'groups.php'],
  ['key' => 'students', 'label' => 'الطلاب', 'icon' => '🧑‍🎓', 'href' => 'students.php'], // ✅✅ (التعديل المطلوب)

  ['key' => 'courses', 'label' => 'الكورسات', 'icon' => '📚', 'href' => 'courses.php'], // ✅✅ (التعديل المطلوب)
  ['key' => 'lectures', 'label' => 'المحاضرات', 'icon' => '🧑‍🏫', 'href' => 'lectures.php'], // ✅✅ (التعديل المطلوب)
  ['key' => 'videos', 'label' => 'الفيديوهات', 'icon' => '🎥', 'href' => 'videos.php'], // ✅✅ (التعديل المطلوب)

  // ✅✅ المطلوب: زر ملفات PDF يفتح صفحة pdfs.php
  ['key' => 'pdfs', 'label' => 'ملفات PDF', 'icon' => '📑', 'href' => 'pdfs.php'],

  // ✅✅ المطلوب: زر اكواد الكورسات يفتح صفحة اكواد الكورسات
  ['key' => 'course_codes', 'label' => 'اكواد الكورسات', 'icon' => '🎟️', 'href' => 'course-codes.php'],

  // ✅✅ المطلوب: زر اكواد المحاضرات يفتح صفحة اكواد المحاضرات
  ['key' => 'lecture_codes', 'label' => 'اكواد المحاضرات', 'icon' => '🧾', 'href' => 'lecture-codes.php'],

  // ✅✅ المطلوب: زر أسئلة الواجبات يفتح صفحة بنوك أسئلة الواجبات
  ['key' => 'assignment_questions', 'label' => 'أسئلة الواجبات', 'icon' => '🗂️', 'href' => 'assignment-question-banks.php'],

  // ✅✅✅ التعديل المطلوب: زر الواجبات يفتح صفحة assignments.php
  ['key' => 'assignments', 'label' => 'الواجبات', 'icon' => '📌', 'href' => 'assignments.php'],

  // ✅✅ التعديل المطلوب: زر الامتحانات يفتح صفحة exams.php
  ['key' => 'exams', 'label' => 'الامتحانات', 'icon' => '🧠', 'href' => 'exams.php'],

  // ✅✅ التعديل المطلوب: زر اسئلة الامتحانات يفتح صفحة بنك اسئلة الامتحانات
  ['key' => 'exam_questions', 'label' => 'أسئلة الامتحانات', 'icon' => '❔', 'href' => 'exam-question-banks.php'],

  // ✅✅✅ التعديل المطلوب هنا: اجعل الرابط يذهب لصفحة student-notifications.php بدل #
  ['key' => 'student_notifications', 'label' => 'اشعارات الطلاب', 'icon' => '🔔', 'href' => 'student-notifications.php'],

  ['key' => 'attendance', 'label' => 'حضور الطلاب', 'icon' => '🧾', 'href' => 'attendance.php'],
  ['key' => 'facebook', 'label' => 'فيس بوك المنصة', 'icon' => '📘', 'href' => 'platform-posts.php'],
  ['key' => 'chat', 'label' => 'شات الطلاب', 'icon' => '💬', 'href' => 'student-conversations.php'],

  // ✅✅✅ التعديل المطلوب: زر الإعدادات يفتح صفحة settings.php بدل #settings
  ['key' => 'settings', 'label' => 'الإعدادات', 'icon' => '⚙️', 'href' => 'settings.php'],

  ['key' => 'logout', 'label' => 'تسجيل الخروج', 'icon' => '🚪', 'href' => 'logout.php', 'danger' => true],
];

/* ✅ فلترة القائمة للمشرف حسب صلاحياته */
if ($adminRole !== 'مدير') {
  $filtered = [];
  foreach ($menu as $it) {
    $key = (string)($it['key'] ?? '');
    if ($key === '') continue;

    if (menu_visible($allowedMenuKeys, $key, $adminRole)) {
      $filtered[] = $it;
    }
  }
  $menu = $filtered;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>المستخدمين - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
</head>

<body class="app" data-theme="auto">
  <div class="bg" aria-hidden="true">
    <div class="bg-grad"></div>
    <div class="bg-noise"></div>
  </div>

  <header class="topbar">
    <button class="burger" id="burger" type="button" aria-label="فتح القائمة">☰</button>

    <div class="brand">
      <?php if (!empty($logo)) : ?>
        <img class="brand-logo" src="<?php echo h($logo); ?>" alt="Logo">
      <?php else: ?>
        <div class="brand-fallback" aria-hidden="true"></div>
      <?php endif; ?>
      <div class="brand-text">
        <div class="brand-name"><?php echo h($platformName); ?></div>
        <div class="brand-sub">لوحة التحكم</div>
      </div>
    </div>

    <div class="top-actions">
      <a class="back-btn" href="dashboard.php">🏠 الرجوع للوحة التحكم</a>

      <div class="theme-emoji" title="تبديل الوضع">
        <span class="emoji" aria-hidden="true">🌞</span>
        <label class="emoji-switch">
          <input id="themeSwitch" type="checkbox" />
          <span class="emoji-slider" aria-hidden="true"></span>
        </label>
        <span class="emoji" aria-hidden="true">🌚</span>
      </div>
    </div>
  </header>

  <div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="القائمة الجانبية">
      <div class="sidebar-head">
        <div class="sidebar-title">🧭 التنقل</div>
      </div>

      <nav class="nav">
        <?php foreach ($menu as $item): ?>
          <?php
            $cls = 'nav-item';
            if (!empty($item['active'])) $cls .= ' active';
            if (!empty($item['danger'])) $cls .= ' danger';
          ?>
          <a class="<?php echo $cls; ?>" href="<?php echo h($item['href']); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo $item['icon']; ?></span>
            <span class="nav-label"><?php echo h($item['label']); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </aside>

    <main class="main">
      <section class="users-hero">
        <div class="users-hero-title">
          <h1>إدارة المستخدمين</h1>
        </div>

        <div class="users-metrics">
          <div class="metric">
            <div class="metric-ico">👥</div>
            <div class="metric-meta">
              <div class="metric-label">الإجمالي</div>
              <div class="metric-val"><?php echo number_format($totalUsers); ?></div>
            </div>
          </div>

          <div class="metric">
            <div class="metric-ico">🟢</div>
            <div class="metric-meta">
              <div class="metric-label">النشط</div>
              <div class="metric-val"><?php echo number_format($activeUsers); ?></div>
            </div>
          </div>

          <div class="metric">
            <div class="metric-ico">⏸️</div>
            <div class="metric-meta">
              <div class="metric-label">المعلّق</div>
              <div class="metric-val"><?php echo number_format($suspendedUsers); ?></div>
            </div>
          </div>

          <div class="metric">
            <div class="metric-ico">👑</div>
            <div class="metric-meta">
              <div class="metric-label">المدير</div>
              <div class="metric-val"><?php echo number_format($adminsCount); ?></div>
            </div>
          </div>

          <div class="metric">
            <div class="metric-ico">🛡️</div>
            <div class="metric-meta">
              <div class="metric-label">المشرفين</div>
              <div class="metric-val"><?php echo number_format($supervisorsCount); ?></div>
            </div>
          </div>
        </div>
      </section>

      <?php if ($success): ?>
        <div class="alert success" role="alert"><?php echo h($success); ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert" role="alert"><?php echo h($error); ?></div>
      <?php endif; ?>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge"><?php echo $editRow ? '✏️ تعديل' : '➕ إضافة'; ?></span>
            <h2><?php echo $editRow ? 'تعديل مستخدم' : 'إضافة مستخدم جديد'; ?></h2>
          </div>

          <?php if ($editRow): ?>
            <a class="btn ghost" href="users.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="formx-horizontal" autocomplete="off">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">اسم المستخدم</span>
            <input class="input2" name="username" required value="<?php echo $editRow ? h($editRow['username']) : ''; ?>" placeholder="مثال: admin" />
          </label>

          <label class="field">
            <span class="label"><?php echo $editRow ? 'كلمة السر (اختياري)' : 'كلمة السر'; ?></span>
            <input class="input2" name="password" type="password" <?php echo $editRow ? '' : 'required'; ?> placeholder="••••••••" />
          </label>

          <label class="field">
            <span class="label">الصلاحية</span>
            <select class="input2" name="role" required>
              <?php $currentRole = $editRow ? $editRow['role'] : 'مشرف'; ?>
              <option value="مدير" <?php echo $currentRole === 'مدير' ? 'selected' : ''; ?>>مدير</option>
              <option value="مشرف" <?php echo $currentRole === 'مشرف' ? 'selected' : ''; ?>>مشرف</option>
            </select>
          </label>

          <?php if ($editRow): ?>
            <label class="check" style="align-self:end; padding-bottom:6px;">
              <input type="checkbox" name="is_active" <?php echo ((int)$editRow['is_active'] === 1) ? 'checked' : ''; ?> />
              <span>نشط</span>
            </label>
          <?php endif; ?>

          <div class="form-actions horizontal-actions">
            <button class="btn" type="submit"><?php echo $editRow ? 'حفظ التعديل' : 'إضافة المستخدم'; ?></button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة المستخدمين</h2>
          </div>

          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalUsers); ?></span>
          </div>
        </div>

        <div class="table-wrap scroll-pro">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>اسم المستخدم</th>
                <th>الصلاحية</th>
                <th>الحالة</th>
                <th>تاريخ الإضافة</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="6" style="text-align:center">لا يوجد مستخدمين بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($rows as $r): ?>
                <?php $isActiveRow = ((int)$r['is_active'] === 1); ?>
                <tr>
                  <td data-label="#"><?php echo (int)$r['id']; ?></td>
                  <td data-label="اسم المستخدم"><?php echo h($r['username']); ?></td>
                  <td data-label="الصلاحية"><?php echo h($r['role']); ?></td>

                  <td data-label="الحالة">
                    <form method="post" class="inline" onsubmit="return confirm('هل تريد تغيير حالة هذا المستخدم؟');">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <button class="link <?php echo $isActiveRow ? '' : 'danger'; ?>" type="submit">
                        <?php echo $isActiveRow ? 'نشط' : 'معلّق'; ?>
                      </button>
                    </form>
                  </td>

                  <td data-label="تاريخ الإضافة"><?php echo h($r['created_at']); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <a class="link" href="users.php?edit=<?php echo (int)$r['id']; ?>">تعديل</a>

                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم؟');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <button class="link danger" type="submit">حذف</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

    </main>
  </div>

  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <script>
    (function () {
      const root = document.body;

      // Theme
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

      // Sidebar overlay (mobile)
      const burger = document.getElementById('burger');
      const sidebar = document.getElementById('sidebar');
      const backdrop = document.getElementById('backdrop');

      function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 980px)').matches;
      }

      function openSidebar() {
        if (!isMobile()) return;
        sidebar.classList.add('open');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
      }
      function closeSidebar() {
        if (!isMobile()) return;
        sidebar.classList.remove('open');
        backdrop.classList.remove('show');
        document.body.style.overflow = '';
      }

      function syncInitial() {
        if (isMobile()) closeSidebar();
        else {
          sidebar.classList.remove('open');
          backdrop.classList.remove('show');
          document.body.style.overflow = '';
        }
      }
      syncInitial();

      burger.addEventListener('click', (e) => {
        e.preventDefault();
        if (!isMobile()) return;
        if (sidebar.classList.contains('open')) closeSidebar();
        else openSidebar();
      });

      backdrop.addEventListener('click', (e) => {
        e.preventDefault();
        closeSidebar();
      });

      window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSidebar();
      });

      window.addEventListener('resize', syncInitial);
    })();
  </script>
</body>
</html>