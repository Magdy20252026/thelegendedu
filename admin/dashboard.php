<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();

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

$platformName = (string)($rowSettings['platform_name'] ?? ($settings['platform_name'] ?? 'منصتي التعليمية'));
$logo = (string)($rowSettings['platform_logo'] ?? ($settings['platform_logo'] ?? ''));

if ($logo === '') $logo = null;

function h(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/* =========================
   ✅ صلاحيات المشرف: الأزرار + الإحصائيات
   ========================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'مشرف');

$allowedMenuKeys = get_allowed_menu_keys($pdo, $adminId, $adminRole);
$allowedWidgetKeys = get_allowed_widget_keys($pdo, $adminId, $adminRole);

/* =========================
   بيانات الإحصائيات الفعلية المتاحة حالياً
   ========================= */
// المستخدمين (حقيقي)
$userCountRow = $pdo->query("SELECT COUNT(*) AS c FROM admins")->fetch();
$userCount = (int)($userCountRow['c'] ?? 0);

// نشط / معلق (حقيقي)
$activeCountRow = $pdo->query("SELECT COUNT(*) AS c FROM admins WHERE is_active=1")->fetch();
$activeUsers = (int)($activeCountRow['c'] ?? 0);

$suspendedCountRow = $pdo->query("SELECT COUNT(*) AS c FROM admins WHERE is_active=0")->fetch();
$suspendedUsers = (int)($suspendedCountRow['c'] ?? 0);

// ✅ الصفوف الدراسية (حقيقي)
$gradesCountRow = $pdo->query("SELECT COUNT(*) AS c FROM grades")->fetch();
$gradesCount = (int)($gradesCountRow['c'] ?? 0);

// ✅ السناتر (حقيقي)
$centersCountRow = $pdo->query("SELECT COUNT(*) AS c FROM centers")->fetch();
$centersCount = (int)($centersCountRow['c'] ?? 0);

// ✅ المجموعات (حقيقي)
$groupsCountRow = $pdo->query("SELECT COUNT(*) AS c FROM `groups`")->fetch();
$groupsCount = (int)($groupsCountRow['c'] ?? 0);

// ✅ الطلاب (حقيقي)  ✅✅ (التعديل المطلوب)
$studentsCountRow = $pdo->query("SELECT COUNT(*) AS c FROM students")->fetch();
$studentsCount = (int)($studentsCountRow['c'] ?? 0);

// ✅ الكورسات (حقيقي)  ✅✅ (التعديل المطلوب)
$coursesCountRow = $pdo->query("SELECT COUNT(*) AS c FROM courses")->fetch();
$coursesCount = (int)($coursesCountRow['c'] ?? 0);

// ✅ المحاضرات (حقيقي) ✅✅ (التعديل المطلوب)
$lecturesCountRow = $pdo->query("SELECT COUNT(*) AS c FROM lectures")->fetch();
$lecturesCount = (int)($lecturesCountRow['c'] ?? 0);

// ✅✅ الفيديوهات (حقيقي) (التعديل المطلوب)
$videosCountRow = $pdo->query("SELECT COUNT(*) AS c FROM videos")->fetch();
$videosCount = (int)($videosCountRow['c'] ?? 0);

// ✅✅ ملفات PDF (حقيقي) (التعديل المطلوب)
$pdfsCountRow = $pdo->query("SELECT COUNT(*) AS c FROM pdfs")->fetch();
$pdfsCount = (int)($pdfsCountRow['c'] ?? 0);

/* =========================
   ✅✅ المطلوب: عدد اكواد الكورسات (حقيقي)
   ========================= */
$courseCodesCountRow = $pdo->query("SELECT COUNT(*) AS c FROM course_codes")->fetch();
$courseCodesCount = (int)($courseCodesCountRow['c'] ?? 0);

/* =========================
   ✅✅ المطلوب: عدد اكواد المحاضرات (حقيقي)
   ========================= */
$lectureCodesCountRow = $pdo->query("SELECT COUNT(*) AS c FROM lecture_codes")->fetch();
$lectureCodesCount = (int)($lectureCodesCountRow['c'] ?? 0);

/* =========================
   ✅✅ المطلوب: عدد أسئلة الواجبات (حقيقي)
   ========================= */
$assignmentQuestionsCountRow = $pdo->query("SELECT COUNT(*) AS c FROM assignment_questions")->fetch();
$assignmentQuestionsCount = (int)($assignmentQuestionsCountRow['c'] ?? 0);

/* =========================
   ✅✅ المطلوب: عدد أسئلة الامتحانات (حقيقي)
   ========================= */
$examQuestionsCountRow = $pdo->query("SELECT COUNT(*) AS c FROM exam_questions")->fetch();
$examQuestionsCount = (int)($examQuestionsCountRow['c'] ?? 0);

/* =========================
   ✅✅ المطلوب: عدد الواجبات (حقيقي)  ✅✅✅ (التعديل المطلوب)
   ========================= */
$assignmentsCountRow = $pdo->query("SELECT COUNT(*) AS c FROM assignments")->fetch();
$assignmentsCount = (int)($assignmentsCountRow['c'] ?? 0);

/* =========================
   ✅✅ المطلوب: عدد الامتحانات (حقيقي)
   ========================= */
$examsCountRow = $pdo->query("SELECT COUNT(*) AS c FROM exams")->fetch();
$examsCount = (int)($examsCountRow['c'] ?? 0);

/* =========================
   إحصائيات (مع key لكل بطاقة)
   باقي الإحصائيات = 0 لحين وجود جداولها
   ========================= */
$stats = [
  ['key' => 'users_count', 'title' => 'عدد المستخدمين', 'value' => $userCount, 'icon' => '👤'],
  ['key' => 'active_users', 'title' => 'المستخدمين النشط', 'value' => $activeUsers, 'icon' => '🟢'],
  ['key' => 'suspended_users', 'title' => 'المستخدمين المعلّق', 'value' => $suspendedUsers, 'icon' => '⏸️'],

  ['key' => 'grades_count', 'title' => 'عدد الصفوف الدراسية', 'value' => $gradesCount, 'icon' => '🏫'],
  ['key' => 'centers_count', 'title' => 'عدد السناتر', 'value' => $centersCount, 'icon' => '🏢'],
  ['key' => 'groups_count', 'title' => 'عدد المجموعات', 'value' => $groupsCount, 'icon' => '👥'],
  ['key' => 'students_count', 'title' => 'عدد الطلاب', 'value' => $studentsCount, 'icon' => '🧑‍🎓'], // ✅✅ (التعديل المطلوب)
  ['key' => 'courses_count', 'title' => 'عدد الكورسات', 'value' => $coursesCount, 'icon' => '📚'], // ✅✅ (التعديل المطلوب)
  ['key' => 'lectures_count', 'title' => 'عدد المحاضرات', 'value' => $lecturesCount, 'icon' => '🧑‍🏫'], // ✅✅ (التعديل المطلوب)

  // ✅✅ المطلوب: عدد الفيديوهات الحقيقي
  ['key' => 'videos_count', 'title' => 'عدد الفيديوهات', 'value' => $videosCount, 'icon' => '🎥'], // ✅✅ (التعديل المطلوب)

  // ✅✅ المطلوب: عداد PDF الحقيقي
  ['key' => 'pdfs_count', 'title' => 'عدد ملفات PDF', 'value' => $pdfsCount, 'icon' => '📑'],

  // ✅✅ المطلوب: ربط "عدد اكواد الكورسات" بعدد الأكواد المسجل فعلياً
  ['key' => 'course_codes', 'title' => 'عدد اكواد الكورسات', 'value' => $courseCodesCount, 'icon' => '🎟'],

  // ✅✅ المطلوب: ربط "عدد اكواد المحاضرات" بعدد الأكواد المسجل فعلياً
  ['key' => 'lecture_codes', 'title' => 'عدد اكواد المحاضرات', 'value' => $lectureCodesCount, 'icon' => '🧾'],

  // ✅✅ المطلوب: ربط إحصائية عدد أسئلة الواجبات بعدد الأسئلة المسجل فعلياً
  ['key' => 'assignment_questions', 'title' => 'عدد أسئلة الواجبات', 'value' => $assignmentQuestionsCount, 'icon' => '🗒️'],

  // ✅✅✅ التعديل المطلوب: ربط "عدد الواجبات" بعدد الواجبات الفعلي
  ['key' => 'assignments', 'title' => 'عدد الواجبات', 'value' => $assignmentsCount, 'icon' => '📌'],

  // ✅✅ التعديل المطلوب: ربط "عدد الامتحانات" بعدد الامتحانات الفعلي
  ['key' => 'exams', 'title' => 'عدد الامتحانات', 'value' => $examsCount, 'icon' => '🧠'],

  // ✅✅ التعديل المطلوب: ربط عدد أسئلة الامتحان بعدد الأسئلة الفعلي
  ['key' => 'exam_questions', 'title' => 'عدد أسئلة الامتحان', 'value' => $examQuestionsCount, 'icon' => '❔'],
];

/* ✅ فلترة الإحصائيات للمشرف حسب allowed widgets */
if ($adminRole !== 'مدير') {
  $filteredStats = [];
  foreach ($stats as $s) {
    $k = (string)($s['key'] ?? '');
    if ($k === '') continue;
    if (widget_allowed($allowedWidgetKeys, $k)) $filteredStats[] = $s;
  }
  $stats = $filteredStats;
}

/* =========================
   القائمة (مع key) + فلترة للأزرار
   ========================= */
$menu = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php', 'active' => true],

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

    if ($key === 'logout') {
      $filtered[] = $it;
      continue;
    }

    if (menu_allowed($allowedMenuKeys, $key)) {
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
  <title>لوحة التحكم - <?php echo h($platformName); ?></title>

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
      <div class="page-head">
        <h1>📊 إحصائيات لوحة التحكم</h1>
      </div>

      <section class="stats" aria-label="الإحصائيات">
        <?php foreach ($stats as $s): ?>
          <article class="stat-card">
            <div class="stat-icon" aria-hidden="true"><?php echo $s['icon']; ?></div>
            <div class="stat-body">
              <div class="stat-title"><?php echo h($s['title']); ?></div>
              <div class="stat-value"><?php echo number_format((int)$s['value']); ?></div>
            </div>
          </article>
        <?php endforeach; ?>
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
