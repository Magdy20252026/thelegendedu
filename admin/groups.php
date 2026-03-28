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
   ✅ صلاحيات المشرف لإظهار/إخفاء الأزرار في السايدبار
   ========================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'مشرف');
$allowedMenuKeys = get_allowed_menu_keys($pdo, $adminId, $adminRole);

function menu_visible(array $allowedKeys, string $key, string $role): bool {
  if ($role === 'مدير') return true;
  if ($key === 'logout') return true;
  return menu_allowed($allowedKeys, $key);
}

/* =========================
   CRUD - Groups
   - name + grade_id + center_id
   - تفريغ الحقول بعد الإضافة/التعديل/الحذف عبر Redirect (PRG)
   ========================= */
$success = null;
$error = null;

/* ✅ جلب الصفوف للقائمة المنسدلة */
$gradesList = $pdo->query("SELECT id, name FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll();
$gradesMap = [];
foreach ($gradesList as $g) $gradesMap[(int)$g['id']] = (string)$g['name'];

/* ✅ جلب السناتر للقائمة المنسدلة */
$centersList = $pdo->query("SELECT id, name FROM centers ORDER BY name ASC")->fetchAll();
$centersMap = [];
foreach ($centersList as $c) $centersMap[(int)$c['id']] = (string)$c['name'];

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $name = trim($_POST['name'] ?? '');
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $centerId = (int)($_POST['center_id'] ?? 0);

  if ($name === '') {
    $error = 'من فضلك اكتب اسم المجموعة.';
  } elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) {
    $error = 'من فضلك اختر الصف الدراسي.';
  } elseif ($centerId <= 0 || !isset($centersMap[$centerId])) {
    $error = 'من فضلك اختر السنتر.';
  } else {
    try {
      $stmt = $pdo->prepare("INSERT INTO `groups` (name, grade_id, center_id) VALUES (?, ?, ?)");
      $stmt->execute([$name, $gradeId, $centerId]);

      header('Location: groups.php?added=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر الإضافة (ربما اسم المجموعة مكرر).';
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $centerId = (int)($_POST['center_id'] ?? 0);

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } elseif ($name === '') {
    $error = 'اسم المجموعة مطلوب.';
  } elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) {
    $error = 'من فضلك اختر الصف الدراسي.';
  } elseif ($centerId <= 0 || !isset($centersMap[$centerId])) {
    $error = 'من فضلك اختر السنتر.';
  } else {
    try {
      $stmt = $pdo->prepare("UPDATE `groups` SET name=?, grade_id=?, center_id=? WHERE id=?");
      $stmt->execute([$name, $gradeId, $centerId, $id]);

      header('Location: groups.php?updated=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر التعديل (ربما اسم المجموعة مكرر).';
    }
  }
}

/* DELETE */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } else {
    try {
      $stmt = $pdo->prepare("DELETE FROM `groups` WHERE id=?");
      $stmt->execute([$id]);

      header('Location: groups.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر الحذف.';
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = 'تمت إضافة المجموعة بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل المجموعة بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف المجموعة بنجاح.';

/* Fetch list */
$groups = $pdo->query("
  SELECT 
    g.id, g.name, g.grade_id, g.center_id, g.created_at,
    gr.name AS grade_name,
    c.name AS center_name
  FROM `groups` g
  INNER JOIN grades gr ON gr.id = g.grade_id
  INNER JOIN centers c ON c.id = g.center_id
  ORDER BY g.id DESC
")->fetchAll();
$totalGroups = count($groups);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT id, name, grade_id, center_id FROM `groups` WHERE id=? LIMIT 1");
  $stmt->execute([$editId]);
  $editRow = $stmt->fetch() ?: null;
}

/* Sidebar menu */
$menu = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],

  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php'],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php'],

  ['key' => 'grades', 'label' => 'الصفوف الدراسية', 'icon' => '🏫', 'href' => 'grades.php'],
  ['key' => 'centers', 'label' => 'السناتر', 'icon' => '🏢', 'href' => 'centers.php'],
  ['key' => 'groups', 'label' => 'المجموعات', 'icon' => '👥', 'href' => 'groups.php', 'active' => true],
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
  <title>المجموعات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/groups.css">
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
      <section class="groups-hero">
        <div class="groups-hero-title">
          <h1>👥 المجموعات</h1>
        </div>

        <div class="groups-metrics">
          <div class="metric">
            <div class="metric-ico">👥</div>
            <div class="metric-meta">
              <div class="metric-label">عدد المجموعات</div>
              <div class="metric-val"><?php echo number_format($totalGroups); ?></div>
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
            <h2><?php echo $editRow ? 'تعديل مجموعة' : 'إضافة مجموعة جديدة'; ?></h2>
          </div>

          <?php if ($editRow): ?>
            <a class="btn ghost" href="groups.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="groups-form" autocomplete="off">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">اسم المجموعة</span>
            <input class="input2" name="name" required value="<?php echo $editRow ? h($editRow['name']) : ''; ?>" placeholder="مثال: مجموعة الأحد 6 مساءً" />
          </label>

          <label class="field">
            <span class="label">الصف الدراسي</span>
            <select class="input2 groups-select" name="grade_id" required>
              <option value="0">— اختر الصف —</option>
              <?php foreach ($gradesList as $g): ?>
                <?php $gid = (int)$g['id']; ?>
                <option value="<?php echo $gid; ?>" <?php echo ($editRow && (int)$editRow['grade_id'] === $gid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$g['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>

            <?php if (!$gradesList): ?>
              <div class="groups-hint">لا يوجد صفوف حالياً — من فضلك أضف صف أولاً من صفحة "الصفوف الدراسية".</div>
            <?php endif; ?>
          </label>

          <label class="field">
            <span class="label">السنتر</span>
            <select class="input2 groups-select" name="center_id" required>
              <option value="0">— اختر السنتر —</option>
              <?php foreach ($centersList as $c): ?>
                <?php $cid = (int)$c['id']; ?>
                <option value="<?php echo $cid; ?>" <?php echo ($editRow && (int)$editRow['center_id'] === $cid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$c['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>

            <?php if (!$centersList): ?>
              <div class="groups-hint">لا يوجد سناتر حالياً — من فضلك أضف سنتر أولاً من صفحة "السناتر".</div>
            <?php endif; ?>
          </label>

          <div class="form-actions">
            <button class="btn" type="submit" <?php echo ((!$gradesList || !$centersList) ? 'disabled' : ''); ?>>
              <?php echo $editRow ? 'حفظ التعديل' : 'إضافة المجموعة'; ?>
            </button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة المجموعات</h2>
          </div>

          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalGroups); ?></span>
          </div>
        </div>

        <div class="table-wrap scroll-pro">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>اسم المجموعة</th>
                <th>الصف الدراسي</th>
                <th>السنتر</th>
                <th>تاريخ الإضافة</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$groups): ?>
                <tr><td colspan="6" style="text-align:center">لا يوجد مجموعات بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($groups as $g): ?>
                <tr>
                  <td data-label="#"><?php echo (int)$g['id']; ?></td>
                  <td data-label="اسم المجموعة"><?php echo h((string)$g['name']); ?></td>
                  <td data-label="الصف الدراسي"><?php echo h((string)$g['grade_name']); ?></td>
                  <td data-label="السنتر"><?php echo h((string)$g['center_name']); ?></td>
                  <td data-label="تاريخ الإضافة"><?php echo h((string)$g['created_at']); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <a class="link" href="groups.php?edit=<?php echo (int)$g['id']; ?>">تعديل</a>

                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذه المجموعة؟');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
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