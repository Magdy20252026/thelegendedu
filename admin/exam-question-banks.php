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
   Ensure tables exist (idempotent)
   ========================= */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS exam_question_banks (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    grade_id INT(10) UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    created_by_admin_id INT(10) UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_grade_bank (grade_id, name),
    KEY idx_bank_grade (grade_id),
    CONSTRAINT fk_eqb_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS exam_questions (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_id INT(10) UNSIGNED NOT NULL,
    degree DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    correction_type ENUM('single','double') NOT NULL DEFAULT 'single',
    question_kind ENUM('text','image','text_image') NOT NULL DEFAULT 'text',
    question_text LONGTEXT DEFAULT NULL,
    question_image_path VARCHAR(255) DEFAULT NULL,
    choices_count INT(10) UNSIGNED NOT NULL DEFAULT 4,
    choices_kind ENUM('text','image','text_image') NOT NULL DEFAULT 'text',
    correct_choices_count INT(10) UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_q_bank (bank_id),
    CONSTRAINT fk_eq_bank FOREIGN KEY (bank_id) REFERENCES exam_question_banks(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS exam_question_choices (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id INT(10) UNSIGNED NOT NULL,
    choice_index INT(10) UNSIGNED NOT NULL,
    choice_text LONGTEXT DEFAULT NULL,
    choice_image_path VARCHAR(255) DEFAULT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_question_choice_index (question_id, choice_index),
    KEY idx_choice_question (question_id),
    KEY idx_choice_correct (question_id, is_correct),
    CONSTRAINT fk_eqc_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

/* =========================
   Grades list
   ========================= */
$gradesList = $pdo->query("SELECT id, name FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll();
$gradesMap = [];
foreach ($gradesList as $g) $gradesMap[(int)$g['id']] = (string)$g['name'];

/* =========================
   CRUD - Banks
   ========================= */
$success = null;
$error = null;

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $name = trim((string)($_POST['name'] ?? ''));
  $gradeId = (int)($_POST['grade_id'] ?? 0);

  if ($name === '') $error = 'من فضلك اكتب اسم بنك أسئلة الامتحان.';
  elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) $error = 'من فضلك اختر الصف الدراسي.';
  else {
    try {
      $stmt = $pdo->prepare("INSERT INTO exam_question_banks (grade_id, name, created_by_admin_id) VALUES (?, ?, ?)");
      $stmt->execute([$gradeId, $name, $adminId > 0 ? $adminId : null]);

      header('Location: exam-question-banks.php?added=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر الإضافة (ربما اسم البنك مكرر داخل نفس الصف).';
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);
  $name = trim((string)($_POST['name'] ?? ''));
  $gradeId = (int)($_POST['grade_id'] ?? 0);

  if ($id <= 0) $error = 'طلب غير صالح.';
  elseif ($name === '') $error = 'اسم بنك أسئلة الامتحان مطلوب.';
  elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) $error = 'من فضلك اختر الصف الدراسي.';
  else {
    try {
      $stmt = $pdo->prepare("UPDATE exam_question_banks SET grade_id=?, name=? WHERE id=?");
      $stmt->execute([$gradeId, $name, $id]);

      header('Location: exam-question-banks.php?updated=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر التعديل (ربما اسم البنك مكرر داخل نفس الصف).';
    }
  }
}

/* DELETE */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) $error = 'طلب غير صالح.';
  else {
    try {
      $stmt = $pdo->prepare("DELETE FROM exam_question_banks WHERE id=?");
      $stmt->execute([$id]);

      header('Location: exam-question-banks.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف بنك أسئلة الامتحان.';
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = '✅ تمت إضافة بنك أسئلة الامتحان بنجاح.';
if (isset($_GET['updated'])) $success = '💾 تم تعديل بنك أسئلة الامتحان بنجاح.';
if (isset($_GET['deleted'])) $success = '🗑️ تم حذف بنك أسئلة الامتحان بنجاح.';

/* =========================
   List banks + question counts
   ========================= */
$banks = $pdo->query("
  SELECT
    b.*,
    g.name AS grade_name,
    (SELECT COUNT(*) FROM exam_questions q WHERE q.bank_id = b.id) AS questions_count
  FROM exam_question_banks b
  INNER JOIN grades g ON g.id = b.grade_id
  ORDER BY b.id DESC
")->fetchAll();

$totalBanks = count($banks);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT id, grade_id, name FROM exam_question_banks WHERE id=? LIMIT 1");
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
  ['key' => 'exam_questions', 'label' => 'أسئلة الامتحانات', 'icon' => '❔', 'href' => 'exam-question-banks.php', 'active' => true],

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
  <title>بنوك أسئلة الامتحانات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/assignment-question-banks.css">
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
      <section class="aqb-hero">
        <div class="aqb-hero-title">
          <h1>🧠 بنوك أسئلة الامتحانات</h1>
        </div>

        <div class="aqb-metrics">
          <div class="metric">
            <div class="metric-ico">🧠</div>
            <div class="metric-meta">
              <div class="metric-label">عدد البنوك</div>
              <div class="metric-val"><?php echo number_format($totalBanks); ?></div>
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
            <h2><?php echo $editRow ? 'تعديل بنك أسئلة' : 'إضافة بنك أسئلة جديد'; ?></h2>
          </div>
          <?php if ($editRow): ?>
            <a class="btn ghost" href="exam-question-banks.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="aqb-form" autocomplete="off">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">اسم بنك أسئلة الامتحان</span>
            <input class="input2" name="name" required
              value="<?php echo $editRow ? h((string)$editRow['name']) : ''; ?>"
              placeholder="مثال: بنك امتحان الوحدة الأولى" />
          </label>

          <label class="field">
            <span class="label">الصف الدراسي</span>
            <select class="input2 select-pro" name="grade_id" required>
              <option value="0">— اختر الصف —</option>
              <?php foreach ($gradesList as $g): ?>
                <?php $gid = (int)$g['id']; ?>
                <option value="<?php echo $gid; ?>" <?php echo ($editRow && (int)$editRow['grade_id'] === $gid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$g['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$gradesList): ?>
              <div class="aqb-hint">لا يوجد صفوف — أضف صف أولاً من صفحة الصفوف الدراسية.</div>
            <?php endif; ?>
          </label>

          <div class="form-actions">
            <button class="btn" type="submit" <?php echo (!$gradesList ? 'disabled' : ''); ?>>
              <?php echo $editRow ? '💾 حفظ التعديل' : '➕ إضافة البنك'; ?>
            </button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة بنوك أسئلة الامتحانات</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalBanks); ?></span>
          </div>
        </div>

        <div class="table-wrap scroll-pro">
          <table class="table aqb-table">
            <thead>
              <tr>
                <th>#</th>
                <th>اسم البنك</th>
                <th>الصف الدراسي</th>
                <th>عدد الأسئلة</th>
                <th>أضيف بتاريخ</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$banks): ?>
                <tr><td colspan="6" style="text-align:center">لا يوجد بنوك بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($banks as $b): ?>
                <tr>
                  <td data-label="#"><?php echo (int)$b['id']; ?></td>
                  <td data-label="اسم البنك"><?php echo h((string)$b['name']); ?></td>
                  <td data-label="الصف"><?php echo h((string)$b['grade_name']); ?></td>
                  <td data-label="عدد الأسئلة">
                    <span class="tagx blue">❓ <?php echo number_format((int)$b['questions_count']); ?></span>
                  </td>
                  <td data-label="تاريخ الإضافة"><?php echo h((string)$b['created_at']); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <a class="link info" href="exam-questions.php?bank_id=<?php echo (int)$b['id']; ?>">➕ إدارة الأسئلة</a>
                    <a class="link" href="exam-question-banks.php?edit=<?php echo (int)$b['id']; ?>">✏️ تعديل</a>

                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا البنك؟ سيتم حذف كل الأسئلة داخله.');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                      <button class="link danger" type="submit">🗑️ حذف</button>
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