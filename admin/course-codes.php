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
   Helpers
   ========================= */
function valid_date_ymd(string $d): bool {
  if ($d === '') return true; // optional
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
  [$y,$m,$day] = array_map('intval', explode('-', $d));
  return checkdate($m, $day, $y);
}

function normalize_int($v, int $min, int $max): int {
  $n = (int)$v;
  if ($n < $min) $n = $min;
  if ($n > $max) $n = $max;
  return $n;
}

function generate_code(int $len = 12): string {
  // بدون حروف قد تسبب لخبطة (O,0,I,1)
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $out = '';
  for ($i=0; $i<$len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
  }
  // شكل أجمل: XXXX-XXXX-XXXX (لو len=12)
  if ($len === 12) {
    $out = substr($out,0,4) . '-' . substr($out,4,4) . '-' . substr($out,8,4);
  }
  return $out;
}

/* =========================
   ✅ Ensure table exists (حتى لو SQL dump القديم لا يحتوي عليه)
   ========================= */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS course_codes (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    is_global TINYINT(1) NOT NULL DEFAULT 0,
    course_id INT(10) UNSIGNED DEFAULT NULL,
    expires_at DATE DEFAULT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    used_by_student_id INT(10) UNSIGNED DEFAULT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_by_admin_id INT(10) UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_code (code),
    KEY idx_course (course_id),
    KEY idx_used (is_used),
    KEY idx_expires (expires_at),
    CONSTRAINT fk_course_codes_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

/* =========================
   Courses list
   ========================= */
$coursesList = $pdo->query("SELECT id, name FROM courses ORDER BY id DESC")->fetchAll();
$coursesMap = [];
foreach ($coursesList as $c) $coursesMap[(int)$c['id']] = (string)$c['name'];

/* =========================
   Export Excel (.xls) (HTML table) - Arabic headers
   ========================= */
if (isset($_GET['export']) && $_GET['export'] === '1') {
  $q = trim((string)($_GET['q'] ?? ''));
  $filterUsed = (string)($_GET['used'] ?? 'all'); // all|0|1

  $where = "1=1";
  $params = [];

  if ($q !== '') {
    $where .= " AND (cc.code LIKE ?)";
    $params[] = '%' . $q . '%';
  }
  if ($filterUsed === '0' || $filterUsed === '1') {
    $where .= " AND cc.is_used = ?";
    $params[] = (int)$filterUsed;
  }

  $stmt = $pdo->prepare("
    SELECT
      cc.*,
      c.name AS course_name
    FROM course_codes cc
    LEFT JOIN courses c ON c.id = cc.course_id
    WHERE {$where}
    ORDER BY cc.id DESC
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  $filename = 'اكواد_الكورسات_' . date('Y-m-d_H-i') . '.xls';

  header("Content-Type: application/vnd.ms-excel; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
  header("Pragma: no-cache");
  header("Expires: 0");

  echo "\xEF\xBB\xBF"; // BOM UTF-8 for Arabic

  ?>
  <!doctype html>
  <html lang="ar" dir="rtl">
  <head>
    <meta charset="utf-8">
    <title>تصدير أكواد الكورسات</title>
    <style>
      table{border-collapse:collapse; width:100%}
      th,td{border:1px solid #ccc; padding:8px; font-family:Tahoma, Arial; font-size:14px}
      th{background:#f2f2f2}
      .ltr{direction:ltr; text-align:left}
    </style>
  </head>
  <body>
    <table>
      <thead>
        <tr>
          <th>م</th>
          <th>الكود</th>
          <th>النوع</th>
          <th>الكورس</th>
          <th>تاريخ الانتهاء</th>
          <th>الحالة</th>
          <th>تاريخ الاستخدام</th>
          <th>أضيف بتاريخ</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=0; foreach ($rows as $r): $i++; ?>
          <tr>
            <td><?php echo $i; ?></td>
            <td class="ltr"><?php echo h((string)$r['code']); ?></td>
            <td><?php echo ((int)$r['is_global'] === 1) ? 'عام (يفتح كل الكورسات)' : 'مخصص لكورس'; ?></td>
            <td><?php echo ((int)$r['is_global'] === 1) ? '—' : h((string)($r['course_name'] ?? '')); ?></td>
            <td><?php echo h((string)($r['expires_at'] ?? '')); ?></td>
            <td><?php echo ((int)$r['is_used'] === 1) ? 'مستخدم' : 'غير مستخدم'; ?></td>
            <td><?php echo h((string)($r['used_at'] ?? '')); ?></td>
            <td><?php echo h((string)$r['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  exit;
}

/* =========================
   CRUD - Generate / Delete
   ========================= */
$success = null;
$error = null;

/* Generate codes */
if (($_POST['action'] ?? '') === 'generate') {
  $mode = (string)($_POST['mode'] ?? 'course'); // global|course
  $courseId = (int)($_POST['course_id'] ?? 0);
  $count = normalize_int($_POST['count'] ?? 1, 1, 5000);

  $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

  if (!in_array($mode, ['global','course'], true)) {
    $error = 'نوع الكود غير صحيح.';
  } elseif (!valid_date_ymd($expiresAt)) {
    $error = 'تاريخ الانتهاء غير صحيح. استخدم (سنة-شهر-يوم).';
  } elseif ($mode === 'course' && ($courseId <= 0 || !isset($coursesMap[$courseId]))) {
    $error = 'من فضلك اختر كورس صحيح.';
  } else {
    $isGlobal = ($mode === 'global') ? 1 : 0;
    $courseIdDb = $isGlobal ? null : $courseId;
    $expiresDb = ($expiresAt !== '') ? $expiresAt : null;

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("
        INSERT INTO course_codes (code, is_global, course_id, expires_at, created_by_admin_id)
        VALUES (?, ?, ?, ?, ?)
      ");

      $created = 0;
      $maxTries = $count * 10;

      for ($i=0; $i<$count; $i++) {
        $tries = 0;
        while (true) {
          $tries++;
          if ($tries > $maxTries) {
            throw new RuntimeException('تعذر توليد أكواد فريدة بعد محاولات كثيرة.');
          }

          $code = generate_code(12);

          try {
            $stmt->execute([$code, $isGlobal, $courseIdDb, $expiresDb, $adminId > 0 ? $adminId : null]);

            // ✅ Also insert into access_codes so students can redeem via redeem modal
            $expiresAtDt = ($expiresDb !== null) ? ($expiresDb . ' 23:59:59') : null;
            $stmtAC = $pdo->prepare("
              INSERT IGNORE INTO access_codes (code, type, course_id, lecture_id, is_active, max_uses, used_count, expires_at)
              VALUES (?, 'course', ?, NULL, 1, 1, 0, ?)
            ");
            $stmtAC->execute([$code, $courseIdDb, $expiresAtDt]);

            $created++;
            break;
          } catch (PDOException $e) {
            // duplicate code: retry
            if (strpos((string)$e->getMessage(), 'uniq_code') !== false) continue;
            throw $e;
          }
        }
      }

      $pdo->commit();
      header('Location: course-codes.php?generated=1');
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $error = 'تعذر توليد الأكواد.';
    }
  }
}

/* Delete single code */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } else {
    try {
      $stmt = $pdo->prepare("DELETE FROM course_codes WHERE id=?");
      $stmt->execute([$id]);
      header('Location: course-codes.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف الكود.';
    }
  }
}

/* Messages */
if (isset($_GET['generated'])) $success = '✅ تم توليد الأكواد بنجاح.';
if (isset($_GET['deleted'])) $success = '🗑️ تم حذف الكود بنجاح.';

/* =========================
   List + filters
   ========================= */
$q = trim((string)($_GET['q'] ?? ''));
$filterUsed = (string)($_GET['used'] ?? 'all'); // all|0|1

$where = "1=1";
$params = [];

if ($q !== '') {
  $where .= " AND (cc.code LIKE ?)";
  $params[] = '%' . $q . '%';
}
if ($filterUsed === '0' || $filterUsed === '1') {
  $where .= " AND cc.is_used = ?";
  $params[] = (int)$filterUsed;
}

$stmt = $pdo->prepare("
  SELECT
    cc.*,
    c.name AS course_name
  FROM course_codes cc
  LEFT JOIN courses c ON c.id = cc.course_id
  WHERE {$where}
  ORDER BY cc.id DESC
");
$stmt->execute($params);
$codes = $stmt->fetchAll();
$totalCodes = count($codes);

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
  ['key' => 'course_codes', 'label' => 'اكواد الكورسات', 'icon' => '🎟️', 'href' => 'course-codes.php', 'active' => true],

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
  <title>اكواد الكورسات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/course-codes.css">
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
      <section class="codes-hero">
        <div class="codes-hero-title">
          <h1>🎟️ أكواد الكورسات</h1>
        </div>

        <div class="codes-metrics">
          <div class="metric">
            <div class="metric-ico">🎟️</div>
            <div class="metric-meta">
              <div class="metric-label">إجمالي الأكواد</div>
              <div class="metric-val"><?php echo number_format($totalCodes); ?></div>
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
            <span class="cardx-badge">➕</span>
            <h2>توليد أكواد جديدة</h2>
          </div>
        </div>

        <form method="post" class="codes-form" autocomplete="off" id="genForm">
          <input type="hidden" name="action" value="generate">

          <label class="field">
            <span class="label">نوع الكود</span>
            <select class="input2 select-pro" name="mode" id="modeSelect" required>
              <option value="course">🎯 كود لكورس محدد</option>
              <option value="global">🌍 كود عام (يفتح كل الكورسات)</option>
            </select>
          </label>

          <label class="field" id="courseField">
            <span class="label">اسم الكورس</span>
            <select class="input2 select-pro" name="course_id" id="courseSelect">
              <option value="0">— اختر الكورس —</option>
              <?php foreach ($coursesList as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>">
                  <?php echo h((string)$c['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$coursesList): ?>
              <div class="codes-hint">لا يوجد كورسات حالياً — أضف كورس أولاً.</div>
            <?php endif; ?>
          </label>

          <label class="field">
            <span class="label">عدد الأكواد</span>
            <input class="input2" type="number" min="1" max="5000" step="1" name="count" value="10" required>
          </label>

          <label class="field">
            <span class="label">تاريخ انتهاء الصلاحية</span>
            <input class="input2" type="date" name="expires_at" id="expiresInput">
          </label>

          <div class="form-actions" style="grid-column:1 / -1;">
            <button class="btn" type="submit" <?php echo (!$coursesList ? 'disabled' : ''); ?>>⚡ توليد الأكواد</button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">🔎</span>
            <h2>بحث وتصدير</h2>
          </div>

          <div class="cardx-actions">
            <a class="btn ghost" href="course-codes.php?export=1<?php
              echo $q !== '' ? '&q=' . urlencode($q) : '';
              echo ($filterUsed !== 'all' ? '&used=' . urlencode($filterUsed) : '');
            ?>">⬇️ تصدير Excel</a>
          </div>
        </div>

        <form method="get" class="codes-search" autocomplete="off">
          <label class="field" style="margin:0">
            <span class="label">بحث بالكود</span>
            <input class="input2" name="q" value="<?php echo h($q); ?>" placeholder="مثال: ABCD-....">
          </label>

          <label class="field" style="margin:0">
            <span class="label">الحالة</span>
            <select class="input2 select-pro" name="used">
              <option value="all" <?php echo ($filterUsed === 'all') ? 'selected' : ''; ?>>الكل</option>
              <option value="0" <?php echo ($filterUsed === '0') ? 'selected' : ''; ?>>غير مستخدم</option>
              <option value="1" <?php echo ($filterUsed === '1') ? 'selected' : ''; ?>>مستخدم</option>
            </select>
          </label>

          <div class="form-actions" style="margin:0">
            <button class="btn" type="submit">بحث</button>
            <a class="btn ghost" href="course-codes.php">مسح</a>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة الأكواد</h2>
          </div>

          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalCodes); ?></span>
          </div>
        </div>

        <div class="table-wrap scroll-pro">
          <table class="table codes-table">
            <thead>
              <tr>
                <th>#</th>
                <th>الكود</th>
                <th>النوع</th>
                <th>الكورس</th>
                <th>الانتهاء</th>
                <th>الحالة</th>
                <th>أضيف بتاريخ</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$codes): ?>
                <tr><td colspan="8" style="text-align:center">لا يوجد أكواد بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($codes as $cc): ?>
                <?php
                  $isGlobal = ((int)$cc['is_global'] === 1);
                  $isUsed = ((int)$cc['is_used'] === 1);
                  $expires = (string)($cc['expires_at'] ?? '');
                ?>
                <tr>
                  <td data-label="#"><?php echo (int)$cc['id']; ?></td>

                  <td data-label="الكود" class="code-cell">
                    <span class="code-text" dir="ltr"><?php echo h((string)$cc['code']); ?></span>
                    <button type="button" class="link info copy-btn"
                      data-copy="<?php echo h((string)$cc['code']); ?>">📋 نسخ</button>
                  </td>

                  <td data-label="النوع">
                    <?php if ($isGlobal): ?>
                      <span class="tagx purple">🌍 عام</span>
                    <?php else: ?>
                      <span class="tagx green">🎯 كورس</span>
                    <?php endif; ?>
                  </td>

                  <td data-label="الكورس">
                    <?php echo $isGlobal ? '—' : h((string)($cc['course_name'] ?? '')); ?>
                  </td>

                  <td data-label="الانتهاء">
                    <?php echo $expires !== '' ? h($expires) : '—'; ?>
                  </td>

                  <td data-label="الحالة">
                    <?php if ($isUsed): ?>
                      <span class="tagx red">✅ مستخدم</span>
                    <?php else: ?>
                      <span class="tagx orange">⏳ غير مستخدم</span>
                    <?php endif; ?>
                  </td>

                  <td data-label="أضيف بتاريخ"><?php echo h((string)$cc['created_at']); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الكود؟');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$cc['id']; ?>">
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
        themeSwitch.checked = (mode === 'dark') || (mode === 'auto' && osPrefersDark());
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
      backdrop && backdrop.addEventListener('click', (e) => {
        e.preventDefault();
        closeSidebar();
      });
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);

      // Mode UI: show/hide course select
      const modeSelect = document.getElementById('modeSelect');
      const courseField = document.getElementById('courseField');
      const courseSelect = document.getElementById('courseSelect');

      function syncMode() {
        const mode = modeSelect ? modeSelect.value : 'course';
        const isGlobal = (mode === 'global');
        courseField.style.display = isGlobal ? 'none' : '';
        if (courseSelect) courseSelect.required = !isGlobal;
        if (isGlobal && courseSelect) courseSelect.value = '0';
      }
      modeSelect && modeSelect.addEventListener('change', syncMode);
      syncMode();

      // Copy buttons
      document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          const text = btn.getAttribute('data-copy') || '';
          if (!text) return;

          try {
            await navigator.clipboard.writeText(text);
            btn.textContent = '✅ تم النسخ';
            setTimeout(() => btn.textContent = '📋 نسخ', 1100);
          } catch (e) {
            // fallback
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            btn.textContent = '✅ تم النسخ';
            setTimeout(() => btn.textContent = '📋 نسخ', 1100);
          }
        });
      });
    })();
  </script>
</body>
</html>