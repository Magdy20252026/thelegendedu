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
   Ensure table exists (idempotent)
   ========================= */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS student_notifications (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    grade_id INT(10) UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    body LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_admin_id INT(10) UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_grade (grade_id),
    KEY idx_active (is_active),
    CONSTRAINT fk_student_notifications_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

/* =========================
   Grades list
   ========================= */
$gradesList = $pdo->query("SELECT id, name FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll();
$gradesMap = [];
foreach ($gradesList as $g) $gradesMap[(int)$g['id']] = (string)$g['name'];

/* =========================
   CRUD
   ========================= */
$success = null;
$error = null;

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $title = trim((string)($_POST['title'] ?? ''));
  $body = trim((string)($_POST['body'] ?? ''));
  $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

  if ($gradeId <= 0 || !isset($gradesMap[$gradeId])) $error = 'من فضلك اختر الصف الدراسي.';
  elseif ($title === '') $error = 'من فضلك اكتب عنوان الإشعار.';
  elseif ($body === '') $error = 'من فضلك اكتب نص الإشعار.';
  else {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO student_notifications (grade_id, title, body, is_active, created_by_admin_id)
        VALUES (?, ?, ?, ?, ?)
      ");
      $stmt->execute([$gradeId, $title, $body, $isActive, $adminId > 0 ? $adminId : null]);
      header('Location: student-notifications.php?added=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر إضافة الإشعار.';
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $title = trim((string)($_POST['title'] ?? ''));
  $body = trim((string)($_POST['body'] ?? ''));
  $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

  if ($id <= 0) $error = 'طلب غير صالح.';
  elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) $error = 'من فضلك اختر الصف الدراسي.';
  elseif ($title === '') $error = 'عنوان الإشعار مطلوب.';
  elseif ($body === '') $error = 'نص الإشعار مطلوب.';
  else {
    try {
      $stmt = $pdo->prepare("
        UPDATE student_notifications
        SET grade_id=?, title=?, body=?, is_active=?
        WHERE id=?
      ");
      $stmt->execute([$gradeId, $title, $body, $isActive, $id]);
      header('Location: student-notifications.php?updated=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر تعديل الإشعار.';
    }
  }
}

/* DELETE */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) $error = 'طلب غير صالح.';
  else {
    try {
      $stmt = $pdo->prepare("DELETE FROM student_notifications WHERE id=?");
      $stmt->execute([$id]);
      header('Location: student-notifications.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف الإشعار.';
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = '✅ تمت إضافة الإشعار بنجاح.';
if (isset($_GET['updated'])) $success = '💾 تم تعديل الإشعار بنجاح.';
if (isset($_GET['deleted'])) $success = '🗑️ تم حذف الإشعار بنجاح.';

/* =========================
   Fetch list
   ========================= */
$rows = $pdo->query("
  SELECT n.*, g.name AS grade_name
  FROM student_notifications n
  INNER JOIN grades g ON g.id = n.grade_id
  ORDER BY n.id DESC
")->fetchAll();
$totalRows = count($rows);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM student_notifications WHERE id=? LIMIT 1");
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
  ['key' => 'student_notifications', 'label' => 'اشعارات الطلاب', 'icon' => '🔔', 'href' => 'student-notifications.php', 'active' => true],

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
    if (menu_visible($allowedMenuKeys, $key, $adminRole)) $filtered[] = $it;
  }
  $menu = $filtered;
}

?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>اشعارات الطلاب - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/student-notifications.css">
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
      <section class="sn-hero">
        <div class="sn-hero-title">
          <h1>🔔 اشعارات الطلاب</h1>
        </div>

        <div class="sn-metrics">
          <div class="metric">
            <div class="metric-ico">🔔</div>
            <div class="metric-meta">
              <div class="metric-label">عدد الإشعارات</div>
              <div class="metric-val"><?php echo number_format($totalRows); ?></div>
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

      <section class="cardx sn-card" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge"><?php echo $editRow ? '✏️ تعديل' : '➕ إضافة'; ?></span>
            <h2><?php echo $editRow ? 'تعديل إشعار' : 'إضافة إشعار جديد'; ?></h2>
          </div>
          <?php if ($editRow): ?>
            <a class="btn ghost" href="student-notifications.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="sn-form" autocomplete="off">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">الصف الدراسي (المستهدف)</span>
            <select class="input2 select-pro sn-select" name="grade_id" required>
              <option value="0">— اختر الصف —</option>
              <?php foreach ($gradesList as $g): ?>
                <?php $gid = (int)$g['id']; ?>
                <option value="<?php echo $gid; ?>" <?php echo ($editRow && (int)$editRow['grade_id'] === $gid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$g['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$gradesList): ?>
              <div class="sn-hint">لا يوجد صفوف — أضف صف أولاً من صفحة الصفوف الدراسية.</div>
            <?php endif; ?>
          </label>

          <label class="field">
            <span class="label">حالة الإشعار</span>
            <?php $ia = $editRow ? ((int)$editRow['is_active'] === 1) : true; ?>
            <select class="input2 select-pro sn-select" name="is_active" required>
              <option value="1" <?php echo $ia ? 'selected' : ''; ?>>🟢 نشط</option>
              <option value="0" <?php echo !$ia ? 'selected' : ''; ?>>⚫ مخفي</option>
            </select>
            <div class="sn-hint">يمكنك إخفاء إشعار بدون حذفه.</div>
          </label>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">عنوان الإشعار</span>
            <input class="input2" name="title" required
              value="<?php echo $editRow ? h((string)$editRow['title']) : ''; ?>"
              placeholder="مثال: 📌 تنبيه هام بخصوص الحصة القادمة" />
          </label>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">نص الإشعار</span>
            <textarea class="textarea2 sn-textarea" name="body" required placeholder="اكتب تفاصيل الإشعار..."><?php
              echo $editRow ? h((string)$editRow['body']) : '';
            ?></textarea>
          </label>

          <div class="form-actions" style="grid-column:1 / -1;">
            <button class="btn sn-btn" type="submit" <?php echo (!$gradesList ? 'disabled' : ''); ?>>
              <?php echo $editRow ? '💾 حفظ التعديل' : '🚀 نشر الإشعار'; ?>
            </button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة الإشعارات</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalRows); ?></span>
          </div>
        </div>

        <div class="sn-list">
          <?php if (!$rows): ?>
            <div class="sn-empty">لا يوجد إشعارات بعد.</div>
          <?php endif; ?>

          <?php foreach ($rows as $n): ?>
            <?php $active = ((int)$n['is_active'] === 1); ?>
            <article class="sn-item <?php echo $active ? 'is-active' : 'is-muted'; ?>">
              <div class="sn-item__head">
                <div class="sn-item__title">
                  <div class="sn-badge"><?php echo $active ? '🟢' : '⚫'; ?></div>
                  <div>
                    <div class="sn-title"><?php echo h((string)$n['title']); ?></div>
                    <div class="sn-meta">
                      🏫 <?php echo h((string)$n['grade_name']); ?>
                      <span class="dot">•</span>
                      🕒 <?php echo h((string)$n['created_at']); ?>
                    </div>
                  </div>
                </div>

                <div class="sn-item__actions">
                  <a class="sn-link sn-link--edit" href="student-notifications.php?edit=<?php echo (int)$n['id']; ?>">✏️ تعديل</a>
                  <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الإشعار؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                    <button class="sn-link sn-link--delete" type="submit">🗑️ حذف</button>
                  </form>
                </div>
              </div>

              <div class="sn-body">
                <?php echo nl2br(h((string)$n['body'])); ?>
              </div>

              <div class="sn-item__foot">
                <span class="sn-pill <?php echo $active ? 'green' : 'gray'; ?>">
                  <?php echo $active ? '✅ مرئي للطلاب' : '🙈 مخفي عن الطلاب'; ?>
                </span>
                <span class="sn-pill blue">🎯 للصف: <?php echo h((string)$n['grade_name']); ?></span>
              </div>
            </article>
          <?php endforeach; ?>
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
        if (themeSwitch) themeSwitch.checked = (mode === 'dark') || (mode === 'auto' && osPrefersDark());
      }
      applyTheme(stored);

      themeSwitch && themeSwitch.addEventListener('change', () => applyTheme(themeSwitch.checked ? 'dark' : 'light'));
      if (stored === 'auto' && window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme('auto'));
      }

      // Sidebar overlay (mobile)
      const burger = document.getElementById('burger');
      const sidebar = document.getElementById('sidebar');
      const backdrop = document.getElementById('backdrop');

      function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 980px)').matches; }
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

      burger && burger.addEventListener('click', (e) => {
        e.preventDefault();
        if (!isMobile()) return;
        if (sidebar.classList.contains('open')) closeSidebar();
        else openSidebar();
      });
      backdrop && backdrop.addEventListener('click', (e) => { e.preventDefault(); closeSidebar(); });
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);
    })();
  </script>
</body>
</html>
