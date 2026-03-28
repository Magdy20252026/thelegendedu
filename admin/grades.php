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
   Upload helpers
   ========================= */
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }
}

function random_filename(string $ext): string {
  return bin2hex(random_bytes(16)) . '.' . $ext;
}

function detect_image_extension(string $tmpPath): ?string {
  $info = @getimagesize($tmpPath);
  if (!$info || empty($info['mime'])) return null;

  $mime = strtolower(trim($info['mime']));
  $map = [
    'image/jpeg' => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/bmp' => 'bmp',
    'image/x-ms-bmp' => 'bmp',
    'image/svg+xml' => 'svg',
    'image/tiff' => 'tif',
    'image/x-icon' => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
    'image/avif' => 'avif',
  ];

  return $map[$mime] ?? null;
}

function normalize_upload_error(int $code): string {
  $errors = [
    UPLOAD_ERR_INI_SIZE => 'حجم الملف أكبر من الحد المسموح في السيرفر.',
    UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من الحد المسموح.',
    UPLOAD_ERR_PARTIAL => 'تم رفع الملف بشكل غير كامل.',
    UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف.',
    UPLOAD_ERR_NO_TMP_DIR => 'مجلد مؤقت مفقود في السيرفر.',
    UPLOAD_ERR_CANT_WRITE => 'تعذر كتابة الملف على السيرفر.',
    UPLOAD_ERR_EXTENSION => 'تم منع رفع الملف بسبب امتداد غير مسموح على السيرفر.',
  ];
  return $errors[$code] ?? 'خطأ غير معروف أثناء رفع الملف.';
}

/* =========================
   Helpers للترتيب
   - عند إدخال ترتيب X: نحرك باقي الصفوف لتفادي التكرار
   ========================= */
function normalize_sort(int $v): int {
  if ($v < 1) return 1;
  if ($v > 1000000) return 1000000;
  return $v;
}

function shift_sort_orders(PDO $pdo, int $newSort, int $excludeId = 0): void {
  // ✅ ازاحة: كل صف sort_order >= newSort يزيد +1 (مع استثناء الصف الحالي في التعديل)
  if ($excludeId > 0) {
    $stmt = $pdo->prepare("UPDATE grades SET sort_order = sort_order + 1 WHERE sort_order >= ? AND id <> ?");
    $stmt->execute([$newSort, $excludeId]);
  } else {
    $stmt = $pdo->prepare("UPDATE grades SET sort_order = sort_order + 1 WHERE sort_order >= ?");
    $stmt->execute([$newSort]);
  }
}

function next_sort(PDO $pdo): int {
  $row = $pdo->query("SELECT COALESCE(MAX(sort_order),0) AS m FROM grades")->fetch();
  return (int)($row['m'] ?? 0) + 1;
}

/* =========================
   CRUD
   ========================= */
$success = null;
$error = null;

$uploadDirAbs = __DIR__ . '/uploads/grades';
$uploadDirRel = 'uploads/grades';
ensure_dir($uploadDirAbs);

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $name = trim($_POST['name'] ?? '');
  $sortOrderInput = (int)($_POST['sort_order'] ?? 0);

  if ($name === '') {
    $error = 'من فضلك اكتب اسم الصف الدراسي.';
  } else {
    $imagePath = null;

    if (!empty($_FILES['image']['name'])) {
      if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error((int)($_FILES['image']['error'] ?? 0));
      } else {
        $tmp = (string)$_FILES['image']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'الملف المرفوع ليس صورة صحيحة.';
        } else {
          $newName = random_filename($ext);
          $destAbs = $uploadDirAbs . '/' . $newName;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ الصورة على السيرفر.';
          } else {
            $imagePath = $uploadDirRel . '/' . $newName;
          }
        }
      }
    }

    if (!$error) {
      try {
        // ✅ لو ترك الترتيب فارغ/0 -> ضعه آخر شيء
        $sortOrder = $sortOrderInput > 0 ? normalize_sort($sortOrderInput) : next_sort($pdo);

        $pdo->beginTransaction();

        // ✅ لو اختار ترتيب محدد -> ازاحة باقي الصفوف
        if ($sortOrderInput > 0) {
          shift_sort_orders($pdo, $sortOrder, 0);
        }

        $stmt = $pdo->prepare("INSERT INTO grades (name, image_path, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$name, $imagePath, $sortOrder]);

        $pdo->commit();

        header('Location: grades.php?added=1');
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'تعذر الإضافة (ربما اسم الصف مكرر).';
      }
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $keepOld = ($_POST['keep_old_image'] ?? '1') === '1';
  $sortOrderInput = (int)($_POST['sort_order'] ?? 0);

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } elseif ($name === '') {
    $error = 'اسم الصف الدراسي مطلوب.';
  } else {
    $stmt = $pdo->prepare("SELECT image_path, sort_order FROM grades WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $old = $stmt->fetch();

    if (!$old) {
      $error = 'الصف غير موجود.';
    } else {
      $imagePath = $old['image_path'] ?? null;
      $oldSort = (int)($old['sort_order'] ?? 0);

      // صورة
      if (!empty($_FILES['image']['name'])) {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
          $error = normalize_upload_error((int)($_FILES['image']['error'] ?? 0));
        } else {
          $tmp = (string)$_FILES['image']['tmp_name'];
          $ext = detect_image_extension($tmp);
          if ($ext === null) {
            $error = 'الملف المرفوع ليس صورة صحيحة.';
          } else {
            $newName = random_filename($ext);
            $destAbs = $uploadDirAbs . '/' . $newName;

            if (!move_uploaded_file($tmp, $destAbs)) {
              $error = 'تعذر حفظ الصورة على السيرفر.';
            } else {
              if (!empty($imagePath)) {
                $oldAbs = __DIR__ . '/' . $imagePath;
                if (is_file($oldAbs)) @unlink($oldAbs);
              }
              $imagePath = $uploadDirRel . '/' . $newName;
            }
          }
        }
      } else {
        if (!$keepOld) {
          if (!empty($imagePath)) {
            $oldAbs = __DIR__ . '/' . $imagePath;
            if (is_file($oldAbs)) @unlink($oldAbs);
          }
          $imagePath = null;
        }
      }

      if (!$error) {
        try {
          $pdo->beginTransaction();

          // ✅ تعديل الترتيب
          $sortOrder = $sortOrderInput > 0 ? normalize_sort($sortOrderInput) : $oldSort;

          // لو غير الترتيب فعلاً: ازاحة باقي الصفوف
          if ($sortOrderInput > 0 && $sortOrder !== $oldSort) {
            shift_sort_orders($pdo, $sortOrder, $id);
          }

          $stmt = $pdo->prepare("UPDATE grades SET name=?, image_path=?, sort_order=? WHERE id=?");
          $stmt->execute([$name, $imagePath, $sortOrder, $id]);

          $pdo->commit();

          header('Location: grades.php?updated=1');
          exit;
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $error = 'تعذر التعديل (ربما اسم الصف مكرر).';
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
    $stmt = $pdo->prepare("SELECT image_path FROM grades WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if ($row && !empty($row['image_path'])) {
      $abs = __DIR__ . '/' . $row['image_path'];
      if (is_file($abs)) @unlink($abs);
    }

    $stmt = $pdo->prepare("DELETE FROM grades WHERE id=?");
    $stmt->execute([$id]);

    header('Location: grades.php?deleted=1');
    exit;
  }
}

/* Messages */
if (isset($_GET['added'])) $success = 'تمت إضافة الصف الدراسي بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل الصف الدراسي بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف الصف الدراسي بنجاح.';

/* List (✅ حسب sort_order) */
$grades = $pdo->query("SELECT id, name, image_path, sort_order, created_at FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll();
$totalGrades = count($grades);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT id, name, image_path, sort_order FROM grades WHERE id=? LIMIT 1");
  $stmt->execute([$editId]);
  $editRow = $stmt->fetch() ?: null;
}

/* Suggested next sort */
$suggestNext = next_sort($pdo);

/* Sidebar menu */
$menu = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],

  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php'],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php'],

  ['key' => 'grades', 'label' => 'الصفوف الدراسية', 'icon' => '🏫', 'href' => 'grades.php', 'active' => true],
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
  <title>الصفوف الدراسية - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/grades.css">
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
      <section class="grades-hero">
        <div class="grades-hero-title">
          <h1>🏫 الصفوف الدراسية</h1>
        </div>

        <div class="grades-metrics">
          <div class="metric">
            <div class="metric-ico">🏫</div>
            <div class="metric-meta">
              <div class="metric-label">عدد الصفوف</div>
              <div class="metric-val"><?php echo number_format($totalGrades); ?></div>
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
            <h2><?php echo $editRow ? 'تعديل صف دراسي' : 'إضافة صف دراسي جديد'; ?></h2>
          </div>

          <?php if ($editRow): ?>
            <a class="btn ghost" href="grades.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="grades-form" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">اسم الصف الدراسي</span>
            <input class="input2" name="name" required value="<?php echo $editRow ? h($editRow['name']) : ''; ?>" placeholder="مثال: الصف الأول الثانوي" />
          </label>

          <label class="field">
            <span class="label">الترتيب (رقم)</span>
            <input class="input2" type="number" name="sort_order" min="1"
              value="<?php echo $editRow ? (int)$editRow['sort_order'] : (int)$suggestNext; ?>"
              placeholder="مثال: 1">
          </label>

          <label class="field">
            <span class="label">صورة الصف (اختياري)</span>
            <input class="input2" type="file" name="image" accept="image/*" />
          </label>

          <?php if ($editRow): ?>
            <div class="grades-old">
              <div class="grades-old-preview">
                <?php if (!empty($editRow['image_path'])): ?>
                  <img src="<?php echo h($editRow['image_path']); ?>" alt="صورة الصف">
                <?php else: ?>
                  <div class="grades-noimg">بدون صورة</div>
                <?php endif; ?>
              </div>

              <label class="check">
                <input type="checkbox" name="keep_old_image" value="1" checked>
                <span>الاحتفاظ بالصورة الحالية إن لم أرفع صورة جديدة</span>
              </label>

              <div class="grades-hint">
                إذا أردت حذف الصورة بدون رفع صورة جديدة: أزل علامة "الاحتفاظ بالصورة الحالية" ثم احفظ.
              </div>
            </div>
          <?php endif; ?>

          <div class="form-actions">
            <button class="btn" type="submit"><?php echo $editRow ? 'حفظ التعديل' : 'إضافة الصف'; ?></button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة الصفوف الدراسية</h2>
          </div>

          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalGrades); ?></span>
          </div>
        </div>

        <div class="grades-grid">
          <?php if (!$grades): ?>
            <div class="grades-empty">لا يوجد صفوف بعد.</div>
          <?php endif; ?>

          <?php foreach ($grades as $g): ?>
            <article class="grade-card">
              <div class="grade-cover">
                <?php if (!empty($g['image_path'])): ?>
                  <img src="<?php echo h($g['image_path']); ?>" alt="<?php echo h($g['name']); ?>">
                <?php else: ?>
                  <div class="grade-cover-fallback">🏫</div>
                <?php endif; ?>
              </div>

              <div class="grade-body">
                <div class="grade-name"><?php echo h($g['name']); ?></div>
                <div class="grade-meta">ترتيب: <?php echo (int)$g['sort_order']; ?> • #<?php echo (int)$g['id']; ?></div>

                <div class="grade-actions">
                  <a class="link" href="grades.php?edit=<?php echo (int)$g['id']; ?>">تعديل</a>

                  <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الصف؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                    <button class="link danger" type="submit">حذف</button>
                  </form>
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