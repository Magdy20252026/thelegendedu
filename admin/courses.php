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
   Upload helpers (مثل grades.php)
   ========================= */
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
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

function normalize_money($v): float {
  $v = (float)$v;
  if ($v < 0) $v = 0;
  if ($v > 100000000) $v = 100000000;
  return $v;
}
function valid_date_ymd(string $d): bool {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
  [$y,$m,$day] = array_map('intval', explode('-', $d));
  return checkdate($m, $day, $y);
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

function course_enrollment_access_type(string $courseAccessType): string {
  return in_array($courseAccessType, ['buy', 'free', 'attendance', 'code'], true) ? $courseAccessType : 'attendance';
}

function lecture_enrollment_access_type_for_course(string $courseAccessType): string {
  if ($courseAccessType === 'free') return 'free';
  return 'attendance';
}

/* =========================
   Data lists
   ========================= */
$gradesList = $pdo->query("SELECT id, name FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll();
$gradesMap = [];
foreach ($gradesList as $g) $gradesMap[(int)$g['id']] = (string)$g['name'];

/* =========================
   CRUD - Courses
   ========================= */
$success = null;
$error = null;

$uploadDirAbs = __DIR__ . '/uploads/courses';
$uploadDirRel = 'uploads/courses';
ensure_dir($uploadDirAbs);

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $name = trim((string)($_POST['name'] ?? ''));
  $details = trim((string)($_POST['details'] ?? ''));

  $gradeId = (int)($_POST['grade_id'] ?? 0);

  // ✅✅ إضافة free
  $accessType = (string)($_POST['access_type'] ?? 'attendance'); // attendance | buy | free
  $buyType = (string)($_POST['buy_type'] ?? 'none'); // none | discount

  $priceBase = normalize_money($_POST['price_base'] ?? 0);
  $priceDiscount = normalize_money($_POST['price_discount'] ?? 0);
  $discountEnd = trim((string)($_POST['discount_end'] ?? ''));

  if ($name === '') {
    $error = 'من فضلك اكتب اسم الكورس.';
  } elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) {
    $error = 'من فضلك اختر الصف الدراسي.';
  } elseif (!in_array($accessType, ['attendance','buy','free'], true)) {
    $error = 'نوع الوصول غير صحيح.';
  } else {

    // ✅ قواعد accessType
    if ($accessType === 'buy') {
      if (!in_array($buyType, ['none','discount'], true)) {
        $error = 'نوع الشراء غير صحيح.';
      } elseif ($priceBase <= 0) {
        $error = 'من فضلك اكتب السعر الأساسي للكورس.';
      } elseif ($buyType === 'discount') {
        if ($priceDiscount <= 0) {
          $error = 'من فضلك اكتب سعر الكورس بعد الخصم.';
        } elseif ($priceDiscount >= $priceBase) {
          $error = 'سعر الخصم يجب أن يكون أقل من السعر الأساسي.';
        } elseif ($discountEnd === '' || !valid_date_ymd($discountEnd)) {
          $error = 'من فضلك اختر تاريخ انتهاء الخصم (سنة-شهر-يوم).';
        }
      } else {
        $priceDiscount = 0;
        $discountEnd = '';
      }

    } elseif ($accessType === 'free') {
      // ✅ مجاني => السعر = 0 ولا يوجد buy fields
      $buyType = null;
      $priceBase = 0;
      $priceDiscount = 0;
      $discountEnd = '';

    } else { // attendance
      $buyType = null;
      $priceBase = 0;
      $priceDiscount = 0;
      $discountEnd = '';
    }

    // image
    $imagePath = null;
    if (!$error && !empty($_FILES['image']['name'])) {
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
        $stmt = $pdo->prepare("
          INSERT INTO courses
            (name, image_path, details, grade_id, access_type, buy_type, price, price_base, price_discount, discount_end)
          VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // ✅ priceActual:
        // - buy: base أو discount
        // - free: 0
        // - attendance: NULL (أو 0) => هنخليه NULL مثل السابق
        $priceActual = null;
        if ($accessType === 'buy') {
          $priceActual = ($buyType === 'discount') ? $priceDiscount : $priceBase;
        } elseif ($accessType === 'free') {
          $priceActual = 0;
        }

        $stmt->execute([
          $name,
          $imagePath,
          ($details !== '' ? $details : null),
          $gradeId,
          $accessType,
          ($accessType === 'buy' ? $buyType : null),
          $priceActual,
          ($accessType === 'buy' ? $priceBase : null),
          ($accessType === 'buy' && $buyType === 'discount' ? $priceDiscount : null),
          ($accessType === 'buy' && $buyType === 'discount' ? $discountEnd : null),
        ]);

        header('Location: courses.php?added=1');
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر الإضافة (ربما اسم الكورس مكرر).';
      }
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);

  $name = trim((string)($_POST['name'] ?? ''));
  $details = trim((string)($_POST['details'] ?? ''));

  $gradeId = (int)($_POST['grade_id'] ?? 0);

  // ✅✅ إضافة free
  $accessType = (string)($_POST['access_type'] ?? 'attendance'); // attendance | buy | free
  $buyType = (string)($_POST['buy_type'] ?? 'none');

  $priceBase = normalize_money($_POST['price_base'] ?? 0);
  $priceDiscount = normalize_money($_POST['price_discount'] ?? 0);

  $discountEnd = trim((string)($_POST['discount_end'] ?? ''));

  $keepOld = (($_POST['keep_old_image'] ?? '1') === '1');

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } elseif ($name === '') {
    $error = 'اسم الكورس مطلوب.';
  } elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) {
    $error = 'من فضلك اختر الصف الدراسي.';
  } elseif (!in_array($accessType, ['attendance','buy','free'], true)) {
    $error = 'نوع الوصول غير صحيح.';
  } else {
    // fetch old
    $stmt = $pdo->prepare("SELECT image_path FROM courses WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) {
      $error = 'الكورس غير موجود.';
    } else {

      if ($accessType === 'buy') {
        if (!in_array($buyType, ['none','discount'], true)) {
          $error = 'نوع الشراء غير صحيح.';
        } elseif ($priceBase <= 0) {
          $error = 'من فضلك اكتب السعر الأساسي للكورس.';
        } elseif ($buyType === 'discount') {
          if ($priceDiscount <= 0) {
            $error = 'من فضلك اكتب سعر الكورس بعد الخصم.';
          } elseif ($priceDiscount >= $priceBase) {
            $error = 'سعر الخصم يجب أن يكون أقل من السعر الأساسي.';
          } elseif ($discountEnd === '' || !valid_date_ymd($discountEnd)) {
            $error = 'من فضلك اختر تاريخ انتهاء الخصم (سنة-شهر-يوم).';
          }
        } else {
          $priceDiscount = 0;
          $discountEnd = '';
        }

      } elseif ($accessType === 'free') {
        // ✅ مجاني => السعر = 0 ولا يوجد buy fields
        $buyType = null;
        $priceBase = 0;
        $priceDiscount = 0;
        $discountEnd = '';

      } else { // attendance
        $buyType = null;
        $priceBase = 0;
        $priceDiscount = 0;
        $discountEnd = '';
      }

      $imagePath = $old['image_path'] ?? null;

      // image update/delete
      if (!$error && !empty($_FILES['image']['name'])) {
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
          $priceActual = null;
          if ($accessType === 'buy') {
            $priceActual = ($buyType === 'discount') ? $priceDiscount : $priceBase;
          } elseif ($accessType === 'free') {
            $priceActual = 0;
          }

          $stmt = $pdo->prepare("
            UPDATE courses
            SET name=?,
                image_path=?,
                details=?,
                grade_id=?,
                access_type=?,
                buy_type=?,
                price=?,
                price_base=?,
                price_discount=?,
                discount_end=?
            WHERE id=?
          ");
          $stmt->execute([
            $name,
            $imagePath,
            ($details !== '' ? $details : null),
            $gradeId,
            $accessType,
            ($accessType === 'buy' ? $buyType : null),
            $priceActual,
            ($accessType === 'buy' ? $priceBase : null),
            ($accessType === 'buy' && $buyType === 'discount' ? $priceDiscount : null),
            ($accessType === 'buy' && $buyType === 'discount' ? $discountEnd : null),
            $id
          ]);

          header('Location: courses.php?updated=1');
          exit;
        } catch (Throwable $e) {
          $error = 'تعذر التعديل (ربما اسم الكورس مكرر).';
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
      $stmt = $pdo->prepare("SELECT image_path FROM courses WHERE id=? LIMIT 1");
      $stmt->execute([$id]);
      $row = $stmt->fetch();

      if ($row && !empty($row['image_path'])) {
        $abs = __DIR__ . '/' . $row['image_path'];
        if (is_file($abs)) @unlink($abs);
      }

      $stmt = $pdo->prepare("DELETE FROM courses WHERE id=?");
      $stmt->execute([$id]);

      header('Location: courses.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر الحذف.';
    }
  }
}

if (($_POST['action'] ?? '') === 'add_student') {
  $courseIdForStudent = (int)($_POST['course_id'] ?? 0);
  $studentCode = trim((string)($_POST['student_code'] ?? ''));

  if ($courseIdForStudent <= 0) {
    $error = 'الكورس المطلوب غير صالح.';
  } elseif ($studentCode === '') {
    $error = 'من فضلك اكتب كود الطالب.';
  } else {
    $stmt = $pdo->prepare("SELECT id, grade_id, access_type FROM courses WHERE id=? LIMIT 1");
    $stmt->execute([$courseIdForStudent]);
    $courseRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $studentRow = find_student_by_code($pdo, $studentCode);

    if (!$courseRow) {
      $error = 'الكورس غير موجود.';
    } elseif (!$studentRow) {
      $error = 'لم يتم العثور على طالب بهذا الكود.';
    } elseif ((int)($studentRow['grade_id'] ?? 0) !== (int)($courseRow['grade_id'] ?? 0)) {
      $error = 'هذا الطالب لا يتبع نفس الصف الدراسي الخاص بالكورس.';
    } else {
      try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
          INSERT IGNORE INTO student_course_enrollments (student_id, course_id, access_type)
          VALUES (?, ?, ?)
        ");
        $stmt->execute([
          (int)$studentRow['id'],
          $courseIdForStudent,
          course_enrollment_access_type((string)($courseRow['access_type'] ?? 'attendance')),
        ]);

        $stmt = $pdo->prepare("
          INSERT IGNORE INTO student_lecture_enrollments (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
          SELECT ?, l.id, l.course_id, ?, NULL, NULL
          FROM lectures l
          WHERE l.course_id = ?
        ");
        $stmt->execute([
          (int)$studentRow['id'],
          lecture_enrollment_access_type_for_course((string)($courseRow['access_type'] ?? 'attendance')),
          $courseIdForStudent,
        ]);

        $pdo->commit();
        header('Location: courses.php?student_added=1&course_students=' . $courseIdForStudent);
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'تعذر إضافة الطالب إلى الكورس.';
      }
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = 'تمت إضافة الكورس بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل الكورس بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف الكورس بنجاح.';
if (isset($_GET['student_added'])) $success = 'تم اشتراك الطالب في الكورس وكل محاضراته بنجاح.';

/* Fetch list */
$courses = $pdo->query("
  SELECT
    c.*,
    gr.name AS grade_name
  FROM courses c
  INNER JOIN grades gr ON gr.id = c.grade_id
  ORDER BY c.id DESC
")->fetchAll();
$totalCourses = count($courses);

$courseStudentsId = (int)($_GET['course_students'] ?? 0);
$courseStudentsRow = null;
$courseStudents = [];
if ($courseStudentsId > 0) {
  foreach ($courses as $courseItem) {
    if ((int)$courseItem['id'] === $courseStudentsId) {
      $courseStudentsRow = $courseItem;
      break;
    }
  }

  if ($courseStudentsRow) {
    $stmt = $pdo->prepare("
      SELECT
        s.id,
        s.full_name,
        s.student_phone,
        s.barcode,
        sce.access_type,
        sce.created_at
      FROM student_course_enrollments sce
      INNER JOIN students s ON s.id = sce.student_id
      WHERE sce.course_id = ?
      ORDER BY sce.created_at DESC, s.full_name ASC
    ");
    $stmt->execute([$courseStudentsId]);
    $courseStudents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM courses WHERE id=? LIMIT 1");
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

  ['key' => 'courses', 'label' => 'الكورسات', 'icon' => '📚', 'href' => 'courses.php', 'active' => true], // ✅✅ (التعديل المطلوب)
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
  <title>الكورسات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/courses.css">
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
      <section class="courses-hero">
        <div class="courses-hero-title">
          <h1>📚 الكورسات</h1>
        </div>

        <div class="courses-metrics">
          <div class="metric">
            <div class="metric-ico">📚</div>
            <div class="metric-meta">
              <div class="metric-label">عدد الكورسات</div>
              <div class="metric-val"><?php echo number_format($totalCourses); ?></div>
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
            <h2><?php echo $editRow ? 'تعديل كورس' : 'إضافة كورس جديد'; ?></h2>
          </div>

          <?php if ($editRow): ?>
            <a class="btn ghost" href="courses.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="courses-form" enctype="multipart/form-data" autocomplete="off" id="courseForm">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">اسم الكورس</span>
            <input class="input2" name="name" required value="<?php echo $editRow ? h((string)$editRow['name']) : ''; ?>" placeholder="مثال: كورس الكيمياء" />
          </label>

          <label class="field">
            <span class="label">الصف الدراسي</span>
            <select class="input2 courses-select" name="grade_id" required>
              <option value="0">— اختر الصف —</option>
              <?php foreach ($gradesList as $g): ?>
                <?php $gid = (int)$g['id']; ?>
                <option value="<?php echo $gid; ?>" <?php echo ($editRow && (int)$editRow['grade_id'] === $gid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$g['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$gradesList): ?>
              <div class="courses-hint">لا يوجد صفوف حالياً — من فضلك أضف صف أولاً من صفحة "الصفوف الدراسية".</div>
            <?php endif; ?>
          </label>

          <label class="field">
            <span class="label">نوع الوصول</span>
            <?php $access = $editRow ? (string)$editRow['access_type'] : 'attendance'; ?>
            <select class="input2 courses-select" name="access_type" id="accessType" required>
              <option value="attendance" <?php echo ($access === 'attendance') ? 'selected' : ''; ?>>يفتح بالحضور</option>
              <option value="buy" <?php echo ($access === 'buy') ? 'selected' : ''; ?>>يمكن شراء الكورس</option>
              <!-- ✅ جديد -->
              <option value="free" <?php echo ($access === 'free') ? 'selected' : ''; ?>>مجاني</option>
            </select>
          </label>

          <label class="field" id="buyTypeField">
            <span class="label">الشراء</span>
            <?php $buyType = $editRow ? (string)($editRow['buy_type'] ?? 'none') : 'none'; ?>
            <select class="input2 courses-select" name="buy_type" id="buyType">
              <option value="none" <?php echo ($buyType === 'none') ? 'selected' : ''; ?>>بدون خصم</option>
              <option value="discount" <?php echo ($buyType === 'discount') ? 'selected' : ''; ?>>بخصم</option>
            </select>
          </label>

          <label class="field" id="priceBaseField">
            <span class="label">السعر الأساسي (قبل الخصم)</span>
            <input class="input2" type="number" step="0.01" min="0" name="price_base" id="priceBaseInput"
              value="<?php echo $editRow && $editRow['price_base'] !== null ? h((string)$editRow['price_base']) : ''; ?>"
              placeholder="مثال: 250">
          </label>

          <label class="field" id="priceDiscountField">
            <span class="label">السعر بعد الخصم</span>
            <input class="input2" type="number" step="0.01" min="0" name="price_discount" id="priceDiscountInput"
              value="<?php echo $editRow && $editRow['price_discount'] !== null ? h((string)$editRow['price_discount']) : ''; ?>"
              placeholder="مثال: 200">
          </label>

          <label class="field" id="discountEndField">
            <span class="label">تاريخ انتهاء الخصم</span>
            <input class="input2" type="date" name="discount_end" id="discountEndInput"
              value="<?php echo ($editRow && !empty($editRow['discount_end'])) ? h((string)$editRow['discount_end']) : ''; ?>">
            <div class="courses-hint">التاريخ يظهر (يوم-شهر-سنة) حسب متصفح الجهاز.</div>
          </label>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">تفاصيل الكورس</span>
            <textarea class="textarea2" name="details" placeholder="اكتب تفاصيل الكورس..."><?php echo $editRow ? h((string)($editRow['details'] ?? '')) : ''; ?></textarea>
          </label>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">صورة الكورس (اختياري)</span>
            <input class="input2" type="file" name="image" accept="image/*" />
          </label>

          <?php if ($editRow): ?>
            <div class="courses-old">
              <div class="courses-old-preview">
                <?php if (!empty($editRow['image_path'])): ?>
                  <img src="<?php echo h((string)$editRow['image_path']); ?>" alt="صورة الكورس">
                <?php else: ?>
                  <div style="font-weight:1000; color:var(--muted)">بدون صورة</div>
                <?php endif; ?>
              </div>

              <label class="check">
                <input type="checkbox" name="keep_old_image" value="1" checked>
                <span>الاحتفاظ بالصورة الحالية إن لم أرفع صورة جديدة</span>
              </label>

              <div class="courses-hint">
                إذا أردت حذف الصورة بدون رفع صورة جديدة: أزل علامة "الاحتفاظ بالصورة الحالية" ثم احفظ.
              </div>
            </div>
          <?php endif; ?>

          <div class="form-actions">
            <button class="btn" type="submit"><?php echo $editRow ? '💾 حفظ التعديل' : '➕ إضافة الكورس'; ?></button>
          </div>
        </form>
      </section>

      <?php if ($courseStudentsRow): ?>
        <section class="cardx" style="margin-top:12px;">
          <div class="cardx-head">
            <div class="cardx-title">
              <span class="cardx-badge">🧑‍🎓</span>
              <h2>طلاب الكورس: <?php echo h((string)$courseStudentsRow['name']); ?></h2>
            </div>
            <div class="cardx-actions">
              <a class="btn ghost" href="courses.php">إغلاق</a>
            </div>
          </div>

          <div class="course-manage-grid">
            <div class="course-manage-card">
              <div class="course-manage-title">📘 بيانات الكورس</div>
              <div class="course-manage-list">
                <div><b>الصف الدراسي:</b> <?php echo h((string)$courseStudentsRow['grade_name']); ?></div>
                <div><b>نوع الوصول:</b> <?php echo h((string)$courseStudentsRow['access_type']); ?></div>
              </div>
            </div>

            <div class="course-manage-card">
              <div class="course-manage-title">➕ إضافة طالب للكورس</div>
              <form method="post" class="course-manage-form">
                <input type="hidden" name="action" value="add_student">
                <input type="hidden" name="course_id" value="<?php echo (int)$courseStudentsRow['id']; ?>">
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
                <?php if (!$courseStudents): ?>
                  <tr><td colspan="6" style="text-align:center">لا يوجد طلاب مشتركين في هذا الكورس بعد.</td></tr>
                <?php endif; ?>
                <?php foreach ($courseStudents as $idx => $studentRow): ?>
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
            <h2>قائمة الكورسات</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalCourses); ?></span>
          </div>
        </div>

        <div class="course-grid">
          <?php if (!$courses): ?>
            <div style="padding:14px; color: var(--muted); font-weight:900;">لا يوجد كورسات بعد.</div>
          <?php endif; ?>

          <?php foreach ($courses as $c): ?>
            <?php
              $isBuy = ((string)$c['access_type'] === 'buy');
              $isDiscount = ($isBuy && (string)($c['buy_type'] ?? '') === 'discount');
              $isFree = ((string)$c['access_type'] === 'free');
            ?>
            <article class="course-card">
              <div class="course-cover">
                <?php if (!empty($c['image_path'])): ?>
                  <img src="<?php echo h((string)$c['image_path']); ?>" alt="<?php echo h((string)$c['name']); ?>">
                <?php else: ?>
                  <div class="course-cover-fallback">📚</div>
                <?php endif; ?>
              </div>

              <div class="course-body">
                <div class="course-name"><?php echo h((string)$c['name']); ?></div>

                <div class="course-badges">
                  <span class="badgex purple">🏫 <?php echo h((string)$c['grade_name']); ?></span>

                  <?php if ($isFree): ?>
                    <span class="badgex green">🆓 مجاني</span>

                  <?php elseif ($isBuy): ?>
                    <span class="badgex green">🛒 شراء</span>

                    <?php if ($isDiscount): ?>
                      <span class="badgex orange">💰 قبل: <?php echo h((string)$c['price_base']); ?></span>
                      <span class="badgex orange">🏷️ بعد: <?php echo h((string)$c['price_discount']); ?></span>
                      <span class="badgex orange">⏳ حتى <?php echo h((string)$c['discount_end']); ?></span>
                    <?php else: ?>
                      <span class="badgex orange">💰 السعر: <?php echo h((string)$c['price_base']); ?></span>
                    <?php endif; ?>

                  <?php else: ?>
                    <span class="badgex green">✅ بالحضور</span>
                  <?php endif; ?>
                </div>

                <?php if (!empty($c['details'])): ?>
                  <div class="course-meta">
                    <?php echo nl2br(h((string)$c['details'])); ?>
                  </div>
                <?php else: ?>
                  <div class="course-meta">بدون تفاصيل.</div>
                <?php endif; ?>

                <div class="course-actions">
                  <a class="link info" href="courses.php?edit=<?php echo (int)$c['id']; ?>">✏️ تعديل</a>

                  <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الكورس؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                    <button class="link danger" type="submit">🗑️ حذف</button>
                  </form>

                  <span class="link warn" title="قريبًا">📑 تفاصيل الكورس</span>
                  <span class="link warn" title="قريبًا">🧑‍🏫 المحاضرات</span>
                  <a class="link warn" href="courses.php?course_students=<?php echo (int)$c['id']; ?>">🧑‍🎓 الطلاب</a>
                  <a class="link warn" href="courses.php?course_students=<?php echo (int)$c['id']; ?>">➕ إضافة طالب</a>
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

      // ===== Dynamic fields (buy/discount/free)
      const accessType = document.getElementById('accessType');
      const buyTypeField = document.getElementById('buyTypeField');

      const priceBaseField = document.getElementById('priceBaseField');
      const priceDiscountField = document.getElementById('priceDiscountField');
      const discountEndField = document.getElementById('discountEndField');

      const buyType = document.getElementById('buyType');
      const priceBaseInput = document.getElementById('priceBaseInput');
      const priceDiscountInput = document.getElementById('priceDiscountInput');
      const discountEndInput = document.getElementById('discountEndInput');

      function syncBuyUI() {
        const mode = accessType ? accessType.value : 'attendance';
        const isBuy = (mode === 'buy');
        const isFree = (mode === 'free');

        // buy fields only when buy
        buyTypeField.style.display = isBuy ? '' : 'none';
        priceBaseField.style.display = isBuy ? '' : 'none';

        if (priceBaseInput) priceBaseInput.required = isBuy;

        const isDiscount = isBuy && buyType && buyType.value === 'discount';
        priceDiscountField.style.display = isDiscount ? '' : 'none';
        discountEndField.style.display = isDiscount ? '' : 'none';

        if (priceDiscountInput) priceDiscountInput.required = isDiscount;
        if (discountEndInput) discountEndInput.required = isDiscount;

        // تنظيف الحقول عند free/attendance
        if (!isBuy) {
          if (buyType) buyType.value = 'none';
          if (priceBaseInput) priceBaseInput.value = '';
          if (priceDiscountInput) priceDiscountInput.value = '';
          if (discountEndInput) discountEndInput.value = '';
        } else if (!isDiscount) {
          if (priceDiscountInput) priceDiscountInput.value = '';
          if (discountEndInput) discountEndInput.value = '';
        }

        // ملاحظة: isFree لا يحتاج أي حقول
        void(isFree);
      }

      if (accessType) accessType.addEventListener('change', syncBuyUI);
      if (buyType) buyType.addEventListener('change', syncBuyUI);
      syncBuyUI();
    })();
  </script>
</body>
</html>
