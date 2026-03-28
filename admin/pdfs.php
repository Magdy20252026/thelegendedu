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
   ✅ Helpers - PDF upload
   - دعم أي PDF
   - بدون حد حجم هنا (يعتمد على إعدادات السيرفر: upload_max_filesize / post_max_size)
   ========================= */
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}
function random_filename(string $ext): string {
  return bin2hex(random_bytes(16)) . '.' . $ext;
}
function normalize_upload_error(int $code): string {
  $errors = [
    UPLOAD_ERR_INI_SIZE => 'حجم الملف أكبر من الحد المسموح في السيرفر.',
    UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من الحد المسموح.',
    UPLOAD_ERR_PARTIAL => 'تم رفع الملف ب��كل غير كامل.',
    UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف.',
    UPLOAD_ERR_NO_TMP_DIR => 'مجلد مؤقت مفقود في السيرفر.',
    UPLOAD_ERR_CANT_WRITE => 'تعذر كتابة الملف على السيرفر.',
    UPLOAD_ERR_EXTENSION => 'تم منع رفع الملف بسبب امتداد غير مسموح على السيرفر.',
  ];
  return $errors[$code] ?? 'خطأ غير معروف أثناء رفع الملف.';
}
function is_pdf_file(string $tmpPath): bool {
  // MIME (أفضل جهد)
  $mime = '';
  if (function_exists('finfo_open')) {
    $f = @finfo_open(FILEINFO_MIME_TYPE);
    if ($f) {
      $mime = (string)@finfo_file($f, $tmpPath);
      @finfo_close($f);
    }
  }
  if ($mime === 'application/pdf') return true;

  // Fallback: signature %PDF
  $fh = @fopen($tmpPath, 'rb');
  if (!$fh) return false;
  $head = (string)@fread($fh, 4);
  @fclose($fh);
  return ($head === '%PDF');
}

function format_bytes(int $bytes): string {
  if ($bytes < 1024) return $bytes . ' B';
  $kb = $bytes / 1024;
  if ($kb < 1024) return number_format($kb, 2) . ' KB';
  $mb = $kb / 1024;
  if ($mb < 1024) return number_format($mb, 2) . ' MB';
  $gb = $mb / 1024;
  return number_format($gb, 2) . ' GB';
}

/* =========================
   ✅ Ensure DB table "pdfs" exists
   (لأن الـ SQL dump المرسل لا يحتوي عليها)
   ========================= */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS pdfs (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id INT(10) UNSIGNED NOT NULL,
    lecture_id INT(10) UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pdfs_course (course_id),
    KEY idx_pdfs_lecture (lecture_id),
    UNIQUE KEY uniq_pdf_lecture_title (lecture_id, title),
    CONSTRAINT fk_pdfs_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_pdfs_lecture FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

/* =========================
   Lists
   ========================= */
$coursesList = $pdo->query("SELECT id, name FROM courses ORDER BY id DESC")->fetchAll();
$coursesMap = [];
foreach ($coursesList as $c) $coursesMap[(int)$c['id']] = (string)$c['name'];

/* =========================
   CRUD - PDFs
   ========================= */
$success = null;
$error = null;

$uploadDirAbs = __DIR__ . '/uploads/pdfs';
$uploadDirRel = 'uploads/pdfs';
ensure_dir($uploadDirAbs);

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $title = trim((string)($_POST['title'] ?? ''));
  $courseId = (int)($_POST['course_id'] ?? 0);
  $lectureId = (int)($_POST['lecture_id'] ?? 0);

  if ($title === '') $error = 'من فضلك اكتب اسم ملف الـ PDF.';
  elseif ($courseId <= 0 || !isset($coursesMap[$courseId])) $error = 'من فضلك اختر الكورس.';
  elseif ($lectureId <= 0) $error = 'من فضلك اختر المحاضرة.';
  elseif (empty($_FILES['pdf']['name'])) $error = 'من فضلك اختر ملف PDF.';
  else {
    // تأكد أن المحاضرة تابعة للكورس
    $stmt = $pdo->prepare("SELECT id FROM lectures WHERE id=? AND course_id=? LIMIT 1");
    $stmt->execute([$lectureId, $courseId]);
    if (!$stmt->fetch()) $error = 'المحاضرة المختارة لا تتبع هذا الكورس.';
  }

  $filePath = null;
  $fileSize = 0;

  if (!$error) {
    if (($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $error = normalize_upload_error((int)($_FILES['pdf']['error'] ?? 0));
    } else {
      $tmp = (string)$_FILES['pdf']['tmp_name'];
      if (!is_pdf_file($tmp)) {
        $error = 'الملف المرفوع ليس PDF صحيح.';
      } else {
        $newName = random_filename('pdf');
        $destAbs = $uploadDirAbs . '/' . $newName;
        if (!move_uploaded_file($tmp, $destAbs)) {
          $error = 'تعذر حفظ ملف الـ PDF على السيرفر.';
        } else {
          $filePath = $uploadDirRel . '/' . $newName;
          $fileSize = (int)@filesize($destAbs);
          if ($fileSize < 0) $fileSize = 0;
        }
      }
    }
  }

  if (!$error) {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO pdfs (course_id, lecture_id, title, file_path, file_size_bytes)
        VALUES (?, ?, ?, ?, ?)
      ");
      $stmt->execute([$courseId, $lectureId, $title, $filePath, $fileSize]);

      header('Location: pdfs.php?added=1');
      exit;
    } catch (Throwable $e) {
      // لو فشل الإدخال: احذف الملف اللي اتخزن
      if (!empty($filePath)) {
        $abs = __DIR__ . '/' . $filePath;
        if (is_file($abs)) @unlink($abs);
      }
      $error = 'تعذر إضافة ملف PDF (ربما الاسم مكرر داخل نفس المحاضرة).';
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);
  $title = trim((string)($_POST['title'] ?? ''));
  $courseId = (int)($_POST['course_id'] ?? 0);
  $lectureId = (int)($_POST['lecture_id'] ?? 0);
  $keepOld = (($_POST['keep_old_pdf'] ?? '1') === '1');

  if ($id <= 0) $error = 'طلب غير صالح.';
  elseif ($title === '') $error = 'اسم ملف الـ PDF مطلوب.';
  elseif ($courseId <= 0 || !isset($coursesMap[$courseId])) $error = 'من فضلك اختر الكورس.';
  elseif ($lectureId <= 0) $error = 'من فضلك اختر المحاضرة.';
  else {
    // تأكد أن المحاضرة تابعة للكورس
    $stmt = $pdo->prepare("SELECT id FROM lectures WHERE id=? AND course_id=? LIMIT 1");
    $stmt->execute([$lectureId, $courseId]);
    if (!$stmt->fetch()) $error = 'المحاضرة المختارة لا تتبع هذا الكورس.';
  }

  // fetch old
  $oldPath = null;
  if (!$error) {
    $stmt = $pdo->prepare("SELECT file_path FROM pdfs WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) $error = 'ملف الـ PDF غير موجود.';
    else $oldPath = (string)$old['file_path'];
  }

  $newPath = $oldPath;
  $newSize = 0;

  // new file upload?
  if (!$error && !empty($_FILES['pdf']['name'])) {
    if (($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $error = normalize_upload_error((int)($_FILES['pdf']['error'] ?? 0));
    } else {
      $tmp = (string)$_FILES['pdf']['tmp_name'];
      if (!is_pdf_file($tmp)) {
        $error = 'الملف المرفوع ليس PDF صحيح.';
      } else {
        $newName = random_filename('pdf');
        $destAbs = $uploadDirAbs . '/' . $newName;

        if (!move_uploaded_file($tmp, $destAbs)) {
          $error = 'تعذر حفظ ملف الـ PDF على السيرفر.';
        } else {
          // delete old
          if (!empty($oldPath)) {
            $oldAbs = __DIR__ . '/' . $oldPath;
            if (is_file($oldAbs)) @unlink($oldAbs);
          }
          $newPath = $uploadDirRel . '/' . $newName;
          $newSize = (int)@filesize($destAbs);
          if ($newSize < 0) $newSize = 0;
        }
      }
    }
  } else {
    if (!$keepOld) {
      // user wants delete file (not recommended, but allow): set file required? we'll prevent
      // better: keepOld always true if no new file
      $keepOld = true;
    }
    if (!empty($newPath)) {
      $abs = __DIR__ . '/' . $newPath;
      $newSize = (int)@filesize($abs);
      if ($newSize < 0) $newSize = 0;
    }
  }

  if (!$error) {
    try {
      $stmt = $pdo->prepare("
        UPDATE pdfs
        SET course_id=?,
            lecture_id=?,
            title=?,
            file_path=?,
            file_size_bytes=?
        WHERE id=?
      ");
      $stmt->execute([$courseId, $lectureId, $title, $newPath, $newSize, $id]);

      header('Location: pdfs.php?updated=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر تعديل ملف PDF (ربما الاسم مكرر داخل نفس المحاضرة).';
    }
  }
}

/* DELETE */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) $error = 'طلب غير صالح.';
  else {
    try {
      $stmt = $pdo->prepare("SELECT file_path FROM pdfs WHERE id=? LIMIT 1");
      $stmt->execute([$id]);
      $row = $stmt->fetch();

      if ($row && !empty($row['file_path'])) {
        $abs = __DIR__ . '/' . $row['file_path'];
        if (is_file($abs)) @unlink($abs);
      }

      $stmt = $pdo->prepare("DELETE FROM pdfs WHERE id=?");
      $stmt->execute([$id]);

      header('Location: pdfs.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف ملف PDF.';
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = 'تمت إضافة ملف PDF بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل ملف PDF بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف ملف PDF بنجاح.';

/* Fetch list */
$pdfs = $pdo->query("
  SELECT
    p.*,
    c.name AS course_name,
    l.name AS lecture_name
  FROM pdfs p
  INNER JOIN courses c ON c.id = p.course_id
  INNER JOIN lectures l ON l.id = p.lecture_id
  ORDER BY p.id DESC
")->fetchAll();
$totalPdfs = count($pdfs);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM pdfs WHERE id=? LIMIT 1");
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
  ['key' => 'pdfs', 'label' => 'ملفات PDF', 'icon' => '📑', 'href' => 'pdfs.php', 'active' => true],

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

/* Preview modal: if preview=ID */
$previewId = (int)($_GET['preview'] ?? 0);
$preview = null;
if ($previewId > 0) {
  $stmt = $pdo->prepare("
    SELECT p.*, c.name AS course_name, l.name AS lecture_name
    FROM pdfs p
    INNER JOIN courses c ON c.id = p.course_id
    INNER JOIN lectures l ON l.id = p.lecture_id
    WHERE p.id=? LIMIT 1
  ");
  $stmt->execute([$previewId]);
  $preview = $stmt->fetch() ?: null;
}

?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>ملفات PDF - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/pdfs.css">
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
      <section class="pdfs-hero">
        <div class="pdfs-hero-title">
          <h1>📑 ملفات PDF</h1>
        </div>

        <div class="pdfs-metrics">
          <div class="metric">
            <div class="metric-ico">📑</div>
            <div class="metric-meta">
              <div class="metric-label">عدد ملفات PDF</div>
              <div class="metric-val"><?php echo number_format($totalPdfs); ?></div>
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
            <h2><?php echo $editRow ? 'تعديل PDF' : 'إضافة PDF جديد'; ?></h2>
          </div>

          <?php if ($editRow): ?>
            <a class="btn ghost" href="pdfs.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="pdfs-form" enctype="multipart/form-data" autocomplete="off" id="pdfForm">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">اسم PDF</span>
            <input class="input2" name="title" required
              value="<?php echo $editRow ? h((string)$editRow['title']) : ''; ?>"
              placeholder="مثال: ملخص المحاضرة الأولى" />
          </label>

          <label class="field">
            <span class="label">ملف PDF</span>
            <input class="input2" type="file" name="pdf" accept="application/pdf" <?php echo $editRow ? '' : 'required'; ?> />
          </label>

          <label class="field">
            <span class="label">الكورس</span>
            <select class="input2 select-pro" name="course_id" id="courseSelect" required>
              <option value="0">— اختر الكورس —</option>
              <?php foreach ($coursesList as $c): ?>
                <?php $cid = (int)$c['id']; ?>
                <option value="<?php echo $cid; ?>" <?php echo ($editRow && (int)$editRow['course_id'] === $cid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$c['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$coursesList): ?>
              <div class="pdfs-hint">لا يوجد كورسات — أضف كورس أولاً.</div>
            <?php endif; ?>
          </label>

          <label class="field">
            <span class="label">المحاضرة</span>
            <select class="input2 select-pro" name="lecture_id" id="lectureSelect" required>
              <option value="0">— اختر المحاضرة —</option>
            </select>
          </label>

          <?php if ($editRow): ?>
            <div class="pdfs-old" style="grid-column:1 / -1;">
              <div class="pdfs-old-meta">
                <div class="pdfs-old-title">الملف الحالي:</div>
                <div class="pdfs-old-path"><?php echo h((string)$editRow['file_path']); ?></div>
              </div>

              <label class="check">
                <input type="checkbox" name="keep_old_pdf" value="1" checked>
                <span>الاحتفاظ بالملف الحالي إن لم أرفع ملف جديد</span>
              </label>
            </div>
          <?php endif; ?>

          <div class="form-actions" style="grid-column:1 / -1;">
            <button class="btn" type="submit" <?php echo (!$coursesList ? 'disabled' : ''); ?>>
              <?php echo $editRow ? '💾 حفظ التعديل' : '➕ إضافة PDF'; ?>
            </button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة ملفات PDF</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalPdfs); ?></span>
          </div>
        </div>

        <div class="table-wrap scroll-pro">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>اسم PDF</th>
                <th>الكورس</th>
                <th>المحاضرة</th>
                <th>الحجم</th>
                <th>تاريخ الإضافة</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pdfs): ?>
                <tr><td colspan="7" style="text-align:center">لا يوجد ملفات PDF بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($pdfs as $p): ?>
                <tr>
                  <td data-label="#"><?php echo (int)$p['id']; ?></td>
                  <td data-label="اسم PDF"><?php echo h((string)$p['title']); ?></td>
                  <td data-label="الكورس"><?php echo h((string)$p['course_name']); ?></td>
                  <td data-label="المحاضرة"><?php echo h((string)$p['lecture_name']); ?></td>
                  <td data-label="الحجم"><?php echo h(format_bytes((int)$p['file_size_bytes'])); ?></td>
                  <td data-label="تاريخ الإضافة"><?php echo h((string)$p['created_at']); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <a class="link info" href="pdfs.php?preview=<?php echo (int)$p['id']; ?>">👁️ معاينة</a>
                    <a class="link" href="pdfs.php?edit=<?php echo (int)$p['id']; ?>">✏️ تعديل</a>

                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الـ PDF؟');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                      <button class="link danger" type="submit">🗑️ حذف</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <?php if ($preview): ?>
        <!-- ✅✅ تعديل المطلوب: إزالة الأزرار من نافذة المعاينة بالكامل
             + إزالة شريط أدوات عارض PDF الداخلي قدر الإمكان باستخدام #toolbar=0&navpanes=0&scrollbar=0 -->
        <div class="pdf-modal open" id="previewModal" aria-hidden="false">
          <div class="pdf-modal-card" role="dialog" aria-modal="true" aria-label="معاينة PDF">
            <div class="pdf-modal-head">
              <div class="pdf-modal-title">
                <div class="badge">📑</div>
                <div>
                  <h3>معاينة: <?php echo h((string)$preview['title']); ?></h3>
                  <p>📚 <?php echo h((string)$preview['course_name']); ?> • 🧑‍🏫 <?php echo h((string)$preview['lecture_name']); ?></p>
                </div>
              </div>

              <!-- زر واحد فقط للإغلاق -->
              <a class="pdf-modal-close" href="pdfs.php" aria-label="إغلاق">✖ إغلاق</a>
            </div>

            <div class="pdf-modal-body">
              <div class="pdf-player">
                <iframe
                  title="PDF Preview"
                  src="<?php
                    $src = (string)$preview['file_path'];
                    // محاولة إخفاء شريط الأدوات في المتصفح
                    $src .= (strpos($src, '?') === false ? '?' : '&') . 'toolbar=0&navpanes=0&scrollbar=0';
                    echo h($src);
                  ?>"
                  loading="lazy"
                ></iframe>
              </div>
            </div>
          </div>
        </div>
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
        // لو نافذة المعاينة مفتوحة، مجرد الرجوع للصفحة الأساسية
        closeSidebar();
      });

      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);

      // ===== Dependent lectures dropdown (reuse existing API like videos)
      const courseSelect = document.getElementById('courseSelect');
      const lectureSelect = document.getElementById('lectureSelect');

      const editLectureId = <?php echo $editRow ? (int)$editRow['lecture_id'] : 0; ?>;

      async function loadLectures(courseId) {
        if (!lectureSelect) return;
        lectureSelect.innerHTML = '<option value="0">— اختر المحاضرة —</option>';
        if (!courseId) return;

        try {
          const url = 'pdfs_lectures_api.php?course_id=' + encodeURIComponent(courseId);
          const res = await fetch(url, { credentials: 'same-origin' });
          const data = await res.json();
          if (!data || !Array.isArray(data.lectures)) return;

          data.lectures.forEach(l => {
            const opt = document.createElement('option');
            opt.value = String(l.id);
            opt.textContent = l.name;
            lectureSelect.appendChild(opt);
          });

          if (editLectureId > 0) {
            lectureSelect.value = String(editLectureId);
          }
        } catch (e) {
          // ignore
        }
      }

      if (courseSelect) {
        courseSelect.addEventListener('change', () => {
          loadLectures(parseInt(courseSelect.value || '0', 10));
        });

        const initialCourse = parseInt(courseSelect.value || '0', 10);
        loadLectures(initialCourse);
      }
    })();
  </script>
</body>
</html>