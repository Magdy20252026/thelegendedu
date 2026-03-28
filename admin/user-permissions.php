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

/* ✅ حماية: فقط المدير */
$role = $_SESSION['admin_role'] ?? 'مشرف';
if ($role !== 'مدير') {
  http_response_code(403);
  exit('Access denied');
}

/* ===== قائمة الأزرار (Menu) ===== */
$menuItems = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],
  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php'],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php'],
  ['key' => 'grades', 'label' => 'الصفوف الدراسية', 'icon' => '🏫', 'href' => '#grades'],
  ['key' => 'centers', 'label' => 'السناتر', 'icon' => '🏢', 'href' => '#centers'],
  ['key' => 'groups', 'label' => 'المجموعات', 'icon' => '👥', 'href' => '#groups'],
  ['key' => 'students', 'label' => 'الطلاب', 'icon' => '🧑‍🎓', 'href' => '#students'],
  ['key' => 'courses', 'label' => 'الكورسات', 'icon' => '📚', 'href' => '#courses'],
  ['key' => 'lectures', 'label' => 'المحاضرات', 'icon' => '🧑‍🏫', 'href' => '#lectures'],
  ['key' => 'videos', 'label' => 'الفيديوهات', 'icon' => '🎥', 'href' => '#videos'],
  ['key' => 'pdfs', 'label' => 'ملفات PDF', 'icon' => '📑', 'href' => '#pdfs'],
  ['key' => 'course_codes', 'label' => 'اكواد الكورسات', 'icon' => '🎟️', 'href' => '#course-codes'],
  ['key' => 'lecture_codes', 'label' => 'اكواد المحاضرات', 'icon' => '🧾', 'href' => '#lecture-codes'],
  ['key' => 'assignments', 'label' => 'الواجبات', 'icon' => '📌', 'href' => '#assignments'],
  ['key' => 'exams', 'label' => 'الامتحانات', 'icon' => '🧠', 'href' => '#exams'],
  ['key' => 'assignment_questions', 'label' => 'أسئلة الواجبات', 'icon' => '🗒️', 'href' => '#assignment-questions'],
  ['key' => 'exam_questions', 'label' => 'أسئلة الامتحانات', 'icon' => '❔', 'href' => '#exam-questions'],
  ['key' => 'student_notifications', 'label' => 'اشعارات الطلاب', 'icon' => '🔔', 'href' => '#student-notifications'],
  ['key' => 'attendance', 'label' => 'حضور الطلاب', 'icon' => '🧾', 'href' => '#attendance'],
  ['key' => 'facebook', 'label' => 'فيس بوك المنصة', 'icon' => '📘', 'href' => '#facebook'],
  ['key' => 'chat', 'label' => 'شات الطلاب', 'icon' => '💬', 'href' => '#chat'],
  ['key' => 'settings', 'label' => 'الإعدادات', 'icon' => '⚙️', 'href' => '#settings'],
];

/* ===== قائمة الإحصائيات (Widgets) ===== */
$widgetItems = [
  ['key' => 'users_count', 'label' => 'عدد المستخدمين', 'icon' => '👤'],
  ['key' => 'active_users', 'label' => 'المستخدمين النشط', 'icon' => '🟢'],
  ['key' => 'suspended_users', 'label' => 'المستخدمين المعلّق', 'icon' => '⏸️'],
  ['key' => 'grades_count', 'label' => 'عدد الصفوف الدراسية', 'icon' => '🏫'],
  ['key' => 'centers_count', 'label' => 'عدد السناتر', 'icon' => '🏢'],
  ['key' => 'groups_count', 'label' => 'عدد المجموعات', 'icon' => '👥'],
  ['key' => 'students_count', 'label' => 'عدد الطلاب', 'icon' => '🧑‍🎓'],
  ['key' => 'courses_count', 'label' => 'عدد الكورسات', 'icon' => '📚'],
  ['key' => 'lectures_count', 'label' => 'عدد المحاضرات', 'icon' => '🧑‍🏫'],
  ['key' => 'videos_count', 'label' => 'عدد الفيديوهات', 'icon' => '🎥'],
  ['key' => 'pdfs_count', 'label' => 'عدد ملفات PDF', 'icon' => '📑'],
];

$supervisors = $pdo->query("SELECT id, username FROM admins WHERE role='مشرف' ORDER BY username ASC")->fetchAll();

$selectedId = (int)($_GET['admin_id'] ?? ($_POST['admin_id'] ?? 0));
$selected = null;
if ($selectedId > 0) {
  $stmt = $pdo->prepare("SELECT id, username FROM admins WHERE id=? AND role='مشرف' LIMIT 1");
  $stmt->execute([$selectedId]);
  $selected = $stmt->fetch() ?: null;
}

$success = null;
$error = null;

/* ===== حفظ الأزرار + الإحصائيات ===== */
if (($_POST['action'] ?? '') === 'save' && $selectedId > 0) {
  $allowedMenu = $_POST['allowed_menu'] ?? [];
  $allowedWidgets = $_POST['allowed_widgets'] ?? [];

  if (!is_array($allowedMenu)) $allowedMenu = [];
  if (!is_array($allowedWidgets)) $allowedWidgets = [];

  $validMenuKeys = array_column($menuItems, 'key');
  $validWidgetKeys = array_column($widgetItems, 'key');

  $cleanMenu = [];
  foreach ($allowedMenu as $k) {
    if (is_string($k) && in_array($k, $validMenuKeys, true)) $cleanMenu[] = $k;
  }
  if (!in_array('dashboard', $cleanMenu, true)) $cleanMenu[] = 'dashboard';

  $cleanWidgets = [];
  foreach ($allowedWidgets as $k) {
    if (is_string($k) && in_array($k, $validWidgetKeys, true)) $cleanWidgets[] = $k;
  }
  if (!in_array('users_count', $cleanWidgets, true)) $cleanWidgets[] = 'users_count';

  try {
    $menuJson = json_encode(array_values(array_unique($cleanMenu)), JSON_UNESCAPED_UNICODE);
    $widgetsJson = json_encode(array_values(array_unique($cleanWidgets)), JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
      INSERT INTO admin_permissions (admin_id, allowed_menu)
      VALUES (?, ?)
      ON DUPLICATE KEY UPDATE allowed_menu=VALUES(allowed_menu)
    ");
    $stmt->execute([$selectedId, $menuJson]);

    $stmt = $pdo->prepare("
      INSERT INTO admin_dashboard_widgets (admin_id, allowed_widgets)
      VALUES (?, ?)
      ON DUPLICATE KEY UPDATE allowed_widgets=VALUES(allowed_widgets)
    ");
    $stmt->execute([$selectedId, $widgetsJson]);

    header("Location: user-permissions.php?admin_id={$selectedId}&saved=1");
    exit;
  } catch (Throwable $e) {
    $error = 'تعذر حفظ الصلاحيات.';
  }
}

if (isset($_GET['saved'])) $success = 'تم حفظ الصلاحيات بنجاح.';

/* ===== تحميل الحالي ===== */
$currentAllowedMenu = ['dashboard'];
$currentAllowedWidgets = ['users_count'];

if ($selectedId > 0) {
  $stmt = $pdo->prepare("SELECT allowed_menu FROM admin_permissions WHERE admin_id=? LIMIT 1");
  $stmt->execute([$selectedId]);
  $row = $stmt->fetch();
  if ($row && !empty($row['allowed_menu'])) {
    $decoded = json_decode((string)$row['allowed_menu'], true);
    if (is_array($decoded) && $decoded) $currentAllowedMenu = $decoded;
  }

  $stmt = $pdo->prepare("SELECT allowed_widgets FROM admin_dashboard_widgets WHERE admin_id=? LIMIT 1");
  $stmt->execute([$selectedId]);
  $row = $stmt->fetch();
  if ($row && !empty($row['allowed_widgets'])) {
    $decoded = json_decode((string)$row['allowed_widgets'], true);
    if (is_array($decoded) && $decoded) $currentAllowedWidgets = $decoded;
  }
}

/* ===== Sidebar menu (للمدير) ===== */
$sidebarMenu = [['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],

  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php'],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php', 'active' => true],

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
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>صلاحيات المستخدمين - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/user-permissions.css">
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
        <?php foreach ($sidebarMenu as $item): ?>
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
      <section class="perm-hero">
        <div class="perm-hero-title">
          <h1>🔐 صلاحيات المستخدمين</h1>
        </div>

        <div class="perm-metrics">
          <div class="metric">
            <div class="metric-ico">🧑‍💼</div>
            <div class="metric-meta">
              <div class="metric-label">عدد المشرفين</div>
              <div class="metric-val"><?php echo number_format(count($supervisors)); ?></div>
            </div>
          </div>

          <div class="metric">
            <div class="metric-ico">✅</div>
            <div class="metric-meta">
              <div class="metric-label">المحدد</div>
              <div class="metric-val"><?php echo $selected ? '1' : '0'; ?></div>
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
            <span class="cardx-badge">👤</span>
            <h2>اختيار المشرف</h2>
          </div>
        </div>

        <form class="perm-select" method="get" autocomplete="off">
          <label class="field" style="margin:0">
            <span class="label">اختر مشرف</span>
            <select class="input2" name="admin_id" onchange="this.form.submit()">
              <option value="0">— اختر —</option>
              <?php foreach ($supervisors as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo ($selectedId === (int)$s['id']) ? 'selected' : ''; ?>>
                  <?php echo h($s['username']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
      </section>

      <?php if ($selected): ?>
        <div class="tabs">
          <button class="tab active" type="button" data-tab="menu">الأزرار</button>
          <button class="tab" type="button" data-tab="widgets">الإحصائيات</button>
        </div>

        <form method="post" class="perm-form" style="margin-top:12px;">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="admin_id" value="<?php echo (int)$selectedId; ?>">

          <section class="cardx tab-panel" id="tab-menu">
            <div class="cardx-head">
              <div class="cardx-title">
                <span class="cardx-badge">🧭</span>
                <h2>تحديد الأزرار المسموحة</h2>
              </div>
            </div>

            <div class="perm-grid">
              <?php foreach ($menuItems as $it): ?>
                <?php
                  $key = $it['key'];
                  $checked = in_array($key, $currentAllowedMenu, true) || $key === 'dashboard';
                ?>
                <label class="perm-item">
                  <div class="perm-meta">
                    <div class="perm-ico"><?php echo $it['icon']; ?></div>
                    <div class="perm-text">
                      <div class="perm-title"><?php echo h($it['label']); ?></div>
                      <div class="perm-key"><?php echo h($key); ?></div>
                    </div>
                  </div>

                  <div class="perm-toggle">
                    <input
                      class="perm-check"
                      type="checkbox"
                      name="allowed_menu[]"
                      value="<?php echo h($key); ?>"
                      <?php echo $checked ? 'checked' : ''; ?>
                      <?php echo ($key === 'dashboard') ? 'disabled' : ''; ?>
                    >
                    <span class="perm-switch" aria-hidden="true"></span>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="cardx tab-panel" id="tab-widgets" style="margin-top:12px; display:none;">
            <div class="cardx-head">
              <div class="cardx-title">
                <span class="cardx-badge">📊</span>
                <h2>تحديد الإحصائيات التي تظهر</h2>
              </div>
            </div>

            <div class="perm-grid">
              <?php foreach ($widgetItems as $it): ?>
                <?php
                  $key = $it['key'];
                  $checked = in_array($key, $currentAllowedWidgets, true) || $key === 'users_count';
                ?>
                <label class="perm-item">
                  <div class="perm-meta">
                    <div class="perm-ico"><?php echo $it['icon']; ?></div>
                    <div class="perm-text">
                      <div class="perm-title"><?php echo h($it['label']); ?></div>
                      <div class="perm-key"><?php echo h($key); ?></div>
                    </div>
                  </div>

                  <div class="perm-toggle">
                    <input
                      class="perm-check"
                      type="checkbox"
                      name="allowed_widgets[]"
                      value="<?php echo h($key); ?>"
                      <?php echo $checked ? 'checked' : ''; ?>
                      <?php echo ($key === 'users_count') ? 'disabled' : ''; ?>
                    >
                    <span class="perm-switch" aria-hidden="true"></span>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </section>

          <div class="perm-actions">
            <button class="btn" type="submit">حفظ الصلاحيات</button>
            <a class="btn ghost" href="user-permissions.php?admin_id=<?php echo (int)$selectedId; ?>">تحديث</a>
          </div>
        </form>
      <?php else: ?>
        <section class="cardx" style="margin-top:12px;">
          <div style="padding:14px; color: var(--muted); font-weight:900;">
            اختر مشرفًا أولاً لعرض التبويبات (الأزرار / الإحصائيات).
          </div>
        </section>
      <?php endif; ?>
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

      themeSwitch.addEventListener('change', () => applyTheme(themeSwitch.checked ? 'dark' : 'light'));

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
        backdrop.classList.add('show');
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

      // ✅ Tabs
      const tabs = document.querySelectorAll('.tab');
      const panelMenu = document.getElementById('tab-menu');
      const panelWidgets = document.getElementById('tab-widgets');

      function activate(which){
        tabs.forEach(t => t.classList.remove('active'));
        const btn = document.querySelector('.tab[data-tab="'+which+'"]');
        if (btn) btn.classList.add('active');

        if(which === 'menu'){
          panelMenu.style.display = '';
          panelWidgets.style.display = 'none';
        }else{
          panelMenu.style.display = 'none';
          panelWidgets.style.display = '';
        }
      }

      tabs.forEach(t => {
        t.addEventListener('click', () => activate(t.getAttribute('data-tab')));
      });
    })();
  </script>
</body>
</html>
