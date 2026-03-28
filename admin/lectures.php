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

function normalize_money($v): float {
  $v = (float)$v;
  if ($v < 0) $v = 0;
  if ($v > 100000000) $v = 100000000;
  return $v;
}

function find_student_by_code(PDO $pdo, string $code): ?array {
  $code = trim($code);
  if ($code === '') return null;

  $stmt = $pdo->prepare("
    SELECT id, full_name, barcode, grade_id, student_phone
    FROM students
    WHERE barcode = ?
       OR CONCAT('STD-', id) = ?
    LIMIT 1
  ");
  $stmt->execute([$code, $code]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function lecture_enrollment_access_type_for_admin(string $courseAccessType): string {
  return $courseAccessType === 'free' ? 'free' : 'attendance';
}

/* =========================
   Data lists - Courses
   ========================= */
$coursesList = $pdo->query("
  SELECT id, name, access_type, buy_type, price_base, price_discount, discount_end
  FROM courses
  ORDER BY id DESC
")->fetchAll();

$coursesMap = [];
foreach ($coursesList as $c) {
  $coursesMap[(int)$c['id']] = [
    'name' => (string)$c['name'],
    'access_type' => (string)$c['access_type'], // attendance | buy | free
  ];
}

/* =========================
   CRUD - Lectures
   ========================= */
$success = null;
$error = null;

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $name = trim((string)($_POST['name'] ?? ''));
  $details = trim((string)($_POST['details'] ?? ''));
  $courseId = (int)($_POST['course_id'] ?? 0);
  $price = normalize_money($_POST['price'] ?? 0);

  if ($name === '') {
    $error = 'من فضلك اكتب اسم المحاضرة.';
  } elseif ($courseId <= 0 || !isset($coursesMap[$courseId])) {
    $error = 'من فضلك اختر الكورس.';
  } else {
    $accessType = $coursesMap[$courseId]['access_type'];

    // ✅✅ المطلوب:
    // السعر يتحدد فقط لو الكورس شراء
    if ($accessType === 'buy') {
      if ($price <= 0) $error = 'من فضلك اكتب سعر المحاضرة.';
    } else {
      // attendance أو free => لا سعر للمحاضرة
      $price = 0;
    }

    if (!$error) {
      try {
        $stmt = $pdo->prepare("
          INSERT INTO lectures (course_id, name, details, price)
          VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
          $courseId,
          $name,
          ($details !== '' ? $details : null),
          ($accessType === 'buy' ? $price : null), // ✅ free/attendance => NULL
        ]);

        header('Location: lectures.php?added=1');
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر الإضافة (ربما اسم المحاضرة مكرر داخل نفس الكورس).';
      }
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);

  $name = trim((string)($_POST['name'] ?? ''));
  $details = trim((string)($_POST['details'] ?? ''));
  $courseId = (int)($_POST['course_id'] ?? 0);
  $price = normalize_money($_POST['price'] ?? 0);

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } elseif ($name === '') {
    $error = 'اسم المحاضرة مطلوب.';
  } elseif ($courseId <= 0 || !isset($coursesMap[$courseId])) {
    $error = 'من فضلك اختر الكورس.';
  } else {
    // تأكد أنها موجودة
    $stmt = $pdo->prepare("SELECT id FROM lectures WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
      $error = 'المحاضرة غير موجودة.';
    } else {
      $accessType = $coursesMap[$courseId]['access_type'];

      // ✅✅ المطلوب:
      // لو الكورس مجاني => لا سعر للمحاضرة (وإجبار price = NULL)
      if ($accessType === 'buy') {
        if ($price <= 0) $error = 'من فضلك اكتب سعر المحاضرة.';
      } else {
        $price = 0;
      }

      if (!$error) {
        try {
          $stmt = $pdo->prepare("
            UPDATE lectures
            SET course_id=?, name=?, details=?, price=?
            WHERE id=?
          ");
          $stmt->execute([
            $courseId,
            $name,
            ($details !== '' ? $details : null),
            ($accessType === 'buy' ? $price : null), // ✅ free/attendance => NULL
            $id
          ]);

          header('Location: lectures.php?updated=1');
          exit;
        } catch (Throwable $e) {
          $error = 'تعذر التعديل (ربما اسم المحاضرة مكرر داخل نفس الكورس).';
        }
      }
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
      $stmt = $pdo->prepare("DELETE FROM lectures WHERE id=?");
      $stmt->execute([$id]);
      header('Location: lectures.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر الحذف.';
    }
  }
}

if (($_POST['action'] ?? '') === 'add_student') {
  $lectureIdForStudent = (int)($_POST['lecture_id'] ?? 0);
  $studentCode = trim((string)($_POST['student_code'] ?? ''));

  if ($lectureIdForStudent <= 0) {
    $error = 'المحاضرة المطلوبة غير صالحة.';
  } elseif ($studentCode === '') {
    $error = 'من فضلك اكتب كود الطالب.';
  } else {
    $stmt = $pdo->prepare("
      SELECT l.id, l.course_id, c.grade_id, c.access_type AS course_access_type
      FROM lectures l
      INNER JOIN courses c ON c.id = l.course_id
      WHERE l.id=?
      LIMIT 1
    ");
    $stmt->execute([$lectureIdForStudent]);
    $lectureRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $studentRow = find_student_by_code($pdo, $studentCode);

    if (!$lectureRow) {
      $error = 'المحاضرة غير موجودة.';
    } elseif (!$studentRow) {
      $error = 'لم يتم العثور على طالب بهذا الكود.';
    } elseif ((int)($studentRow['grade_id'] ?? 0) !== (int)($lectureRow['grade_id'] ?? 0)) {
      $error = 'هذا الطالب لا يتبع نفس الصف الدراسي الخاص بالمحاضرة.';
    } else {
      try {
        $stmt = $pdo->prepare("
          INSERT IGNORE INTO student_lecture_enrollments
            (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
          VALUES (?, ?, ?, ?, NULL, NULL)
        ");
        $stmt->execute([
          (int)$studentRow['id'],
          $lectureIdForStudent,
          (int)$lectureRow['course_id'],
          lecture_enrollment_access_type_for_admin((string)($lectureRow['course_access_type'] ?? 'attendance')),
        ]);

        header('Location: lectures.php?student_added=1&lecture_students=' . $lectureIdForStudent);
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر إضافة الطالب إلى المحاضرة.';
      }
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = 'تمت إضافة المحاضرة بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل المحاضرة بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف المحاضرة بنجاح.';
if (isset($_GET['student_added'])) $success = 'تم اشتراك الطالب في المحاضرة بنجاح.';

/* Fetch list */
$lectures = $pdo->query("
  SELECT
    l.*,
    c.name AS course_name,
    c.grade_id AS course_grade_id,
    c.access_type AS course_access_type
  FROM lectures l
  INNER JOIN courses c ON c.id = l.course_id
  ORDER BY l.id DESC
")->fetchAll();
$totalLectures = count($lectures);

$lectureStudentsId = (int)($_GET['lecture_students'] ?? 0);
$lectureStudentsRow = null;
$lectureStudents = [];
if ($lectureStudentsId > 0) {
  foreach ($lectures as $lectureItem) {
    if ((int)$lectureItem['id'] === $lectureStudentsId) {
      $lectureStudentsRow = $lectureItem;
      break;
    }
  }

  if ($lectureStudentsRow) {
    $stmt = $pdo->prepare("
      SELECT *
      FROM (
        SELECT
          s.id,
          s.full_name,
          s.student_phone,
          s.barcode,
          sle.access_type,
          sle.created_at
        FROM student_lecture_enrollments sle
        INNER JOIN students s ON s.id = sle.student_id
        WHERE sle.lecture_id = ?

        UNION

        SELECT
          s.id,
          s.full_name,
          s.student_phone,
          s.barcode,
          CONCAT('course:', sce.access_type) AS access_type,
          sce.created_at
        FROM student_course_enrollments sce
        INNER JOIN students s ON s.id = sce.student_id
        WHERE sce.course_id = ?
      ) lecture_students
      ORDER BY created_at DESC, full_name ASC
    ");
    $stmt->execute([$lectureStudentsId, (int)$lectureStudentsRow['course_id']]);
    $lectureStudents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM lectures WHERE id=? LIMIT 1");
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
  ['key' => 'lectures', 'label' => 'المحاضرات', 'icon' => '🧑‍🏫', 'href' => 'lectures.php', 'active' => true], // ✅✅ (التعديل المطلوب)
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
  <title>المحاضرات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/lectures.css">
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
      <section class="lectures-hero">
        <div class="lectures-hero-title">
          <h1>🧑‍🏫 المحاضرات</h1>
        </div>

        <div class="lectures-metrics">
          <div class="metric">
            <div class="metric-ico">🧑‍🏫</div>
            <div class="metric-meta">
              <div class="metric-label">عدد المحاضرات</div>
              <div class="metric-val"><?php echo number_format($totalLectures); ?></div>
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
            <h2><?php echo $editRow ? 'تعديل محاضرة' : 'إضافة محاضرة جديدة'; ?></h2>
          </div>

          <?php if ($editRow): ?>
            <a class="btn ghost" href="lectures.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="lectures-form" autocomplete="off" id="lectureForm">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">اسم المحاضرة</span>
            <input class="input2" name="name" required
              value="<?php echo $editRow ? h((string)$editRow['name']) : ''; ?>"
              placeholder="مثال: المحاضرة الأولى - مقدمة" />
          </label>

          <label class="field">
            <span class="label">الكورس التابع له</span>
            <select class="input2 select-pro lecture-select" name="course_id" id="courseSelect" required>
              <option value="0">— اختر الكورس —</option>
              <?php foreach ($coursesList as $c): ?>
                <?php
                  $cid = (int)$c['id'];
                  $selected = ($editRow && (int)$editRow['course_id'] === $cid) ? 'selected' : '';
                  $access = (string)$c['access_type'];
                ?>
                <option
                  value="<?php echo $cid; ?>"
                  data-access="<?php echo h($access); ?>"
                  <?php echo $selected; ?>
                >
                  <?php echo h((string)$c['name']); ?>
                  <?php
                    // ✅✅ عرض نوع الوصول بشكل صحيح (شراء/حضور/مجاني)
                    if ($access === 'buy') echo ' (🛒 شراء)';
                    elseif ($access === 'free') echo ' (🆓 مجاني)';
                    else echo ' (✅ حضور)';
                  ?>
                </option>
              <?php endforeach; ?>
            </select>

            <?php if (!$coursesList): ?>
              <div class="lectures-hint">لا يوجد كورسات حالياً — من فضلك أضف كورس أولاً من صفحة "الكورسات".</div>
            <?php endif; ?>
          </label>

          <label class="field" id="priceField">
            <span class="label">سعر المحاضرة</span>
            <input class="input2" type="number" step="0.01" min="0" name="price" id="priceInput"
              value="<?php echo ($editRow && $editRow['price'] !== null) ? h((string)$editRow['price']) : ''; ?>"
              placeholder="مثال: 50" />
            <div class="lectures-hint">سيظهر هذا الحقل فقط إذا كان الكورس “شراء”.</div>
          </label>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">تفاصيل المحاضرة</span>
            <textarea class="textarea2" name="details" placeholder="اكتب تفاصيل المحاضرة..."><?php echo $editRow ? h((string)($editRow['details'] ?? '')) : ''; ?></textarea>
          </label>

          <div class="form-actions" style="grid-column:1 / -1;">
            <button class="btn" type="submit" <?php echo (!$coursesList ? 'disabled' : ''); ?>>
              <?php echo $editRow ? '💾 حفظ التعديل' : '➕ إضافة المحاضرة'; ?>
            </button>
          </div>
        </form>
      </section>

      <?php if ($lectureStudentsRow): ?>
        <section class="cardx" style="margin-top:12px;">
          <div class="cardx-head">
            <div class="cardx-title">
              <span class="cardx-badge">🧑‍🎓</span>
              <h2>طلاب المحاضرة: <?php echo h((string)$lectureStudentsRow['name']); ?></h2>
            </div>
            <div class="cardx-actions">
              <a class="btn ghost" href="lectures.php">إغلاق</a>
            </div>
          </div>

          <div class="lecture-manage-grid">
            <div class="lecture-manage-card">
              <div class="lecture-manage-title">📘 بيانات المحاضرة</div>
              <div class="lecture-manage-list">
                <div><b>الكورس:</b> <?php echo h((string)$lectureStudentsRow['course_name']); ?></div>
                <div><b>نوع الوصول:</b> <?php echo h((string)$lectureStudentsRow['course_access_type']); ?></div>
              </div>
            </div>

            <div class="lecture-manage-card">
              <div class="lecture-manage-title">➕ إضافة طالب للمحاضرة</div>
              <form method="post" class="lecture-manage-form">
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" name="lecture_id" value="<?php echo (int)$lectureStudentsRow['id']; ?>">
                <label class="field" style="margin:0;">
                  <span class="label">كود الطالب</span>
                  <input class="input2" name="student_code" placeholder="اكتب كود الطالب أو STD-ID" required>
                </label>
                <div class="form-actions">
                  <button class="btn" type="submit">➕ إضافة الطالب</button>
                </div>
              </form>
            </div>
          </div>

          <div class="table-wrap" style="padding:0 14px 14px;">
            <table class="table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>اسم الطالب</th>
                  <th>كود الطالب</th>
                  <th>رقم الطالب</th>
                  <th>نوع الاشتراك</th>
                  <th>تاريخ الاشتراك</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$lectureStudents): ?>
                  <tr><td colspan="6" style="text-align:center">لا يوجد طلاب مشتركين في هذه المحاضرة بعد.</td></tr>
                <?php endif; ?>
                <?php foreach ($lectureStudents as $idx => $studentRow): ?>
                  <tr>
                    <td><?php echo (int)($idx + 1); ?></td>
                    <td><?php echo h((string)$studentRow['full_name']); ?></td>
                    <td><?php echo h((string)($studentRow['barcode'] ?: ('STD-' . (int)$studentRow['id']))); ?></td>
                    <td><?php echo h((string)$studentRow['student_phone']); ?></td>
                    <td><?php echo h((string)$studentRow['access_type']); ?></td>
                    <td><?php echo h((string)$studentRow['created_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة المحاضرات</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalLectures); ?></span>
          </div>
        </div>

        <div class="lecture-grid">
          <?php if (!$lectures): ?>
            <div style="padding:14px; color: var(--muted); font-weight:900;">لا يوجد محاضرات بعد.</div>
          <?php endif; ?>

          <?php foreach ($lectures as $l): ?>
            <?php
              $courseAccess = (string)$l['course_access_type'];
              $isBuy = ($courseAccess === 'buy');
              $isFree = ($courseAccess === 'free');
            ?>
            <article class="lecture-card">
              <div class="lecture-head">
                <div class="lecture-badge">🧑‍🏫</div>
                <div class="lecture-title">
                  <div class="lecture-name"><?php echo h((string)$l['name']); ?></div>
                  <div class="lecture-sub">
                    📚 الكورس: <b><?php echo h((string)$l['course_name']); ?></b>
                    <?php if ($isBuy): ?>
                      <span class="tag buy">🛒 شراء</span>
                    <?php elseif ($isFree): ?>
                      <span class="tag attend">🆓 مجاني</span>
                    <?php else: ?>
                      <span class="tag attend">✅ حضور</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="lecture-body">
                <?php if (!empty($l['details'])): ?>
                  <div class="lecture-details"><?php echo nl2br(h((string)$l['details'])); ?></div>
                <?php else: ?>
                  <div class="lecture-details muted">بدون تفاصيل.</div>
                <?php endif; ?>

                <?php if ($isBuy): ?>
                  <div class="lecture-price">💰 السعر: <?php echo h((string)$l['price']); ?></div>
                <?php else: ?>
                  <div class="lecture-price muted">
                    <?php echo $isFree ? 'السعر غير مطلوب (كورس مجاني).' : 'السعر غير مطلوب (كورس بالحضور).'; ?>
                  </div>
                <?php endif; ?>

                <div class="lecture-actions">
                  <a class="link info" href="lectures.php?edit=<?php echo (int)$l['id']; ?>">✏️ تعديل</a>

                  <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذه المحاضرة؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$l['id']; ?>">
                    <button class="link danger" type="submit">🗑️ حذف</button>
                  </form>

                  <a class="link warn" href="lectures.php?lecture_students=<?php echo (int)$l['id']; ?>">🧑‍🎓 الطلاب</a>
                  <a class="link warn" href="lectures.php?lecture_students=<?php echo (int)$l['id']; ?>">➕ إضافة طالب</a>
                </div>
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
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);

      // Price field show/hide based on course access_type
      const courseSelect = document.getElementById('courseSelect');
      const priceField = document.getElementById('priceField');
      const priceInput = document.getElementById('priceInput');

      function syncPriceUI() {
        if (!courseSelect) return;
        const opt = courseSelect.options[courseSelect.selectedIndex];
        const access = opt ? (opt.getAttribute('data-access') || '') : '';
        const isBuy = (access === 'buy');

        // ✅✅ لو free أو attendance => اخفاء السعر وعدم السماح بتحديده
        priceField.style.display = isBuy ? '' : 'none';
        if (priceInput) priceInput.required = isBuy;

        if (!isBuy && priceInput) priceInput.value = '';
      }

      if (courseSelect) courseSelect.addEventListener('change', syncPriceUI);
      syncPriceUI();
    })();
  </script>
</body>
</html>
