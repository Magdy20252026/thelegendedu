<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/wallet_transactions.php';

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
   محافظات مصر (قائمة ثابتة)
   ========================= */
$governorates = [
  'القاهرة','الجيزة','الإسكندرية','الدقهلية','البحر الأحمر','البحيرة','الفيوم','الغربية',
  'الإسماعيلية','المنوفية','المنيا','القليوبية','الوادي الجديد','السويس','اسوان','اسيوط',
  'بني سويف','بورسعيد','دمياط','الشرقية','جنوب سيناء','كفر الشيخ','مطروح','الأقصر',
  'قنا','شمال سيناء','سوهاج'
];

/* =========================
   Helpers - Validation
   ========================= */
function is_arabic_name_3plus(string $name): bool {
  $name = trim(preg_replace('/\s+/u', ' ', $name));
  if ($name === '') return false;

  // حروف عربية + مسافات فقط
  if (!preg_match('/^[\p{Arabic}\s]+$/u', $name)) return false;

  $parts = array_values(array_filter(explode(' ', $name), fn($p) => trim($p) !== ''));
  return count($parts) >= 3;
}

function normalize_phone(string $p): string {
  $p = trim($p);
  // نحافظ على + والأرقام فقط (اختياري)
  $p = preg_replace('/[^\d\+]/', '', $p);
  return $p;
}

function fetch_grades(PDO $pdo): array {
  return $pdo->query("SELECT id, name FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll();
}
function fetch_centers(PDO $pdo): array {
  return $pdo->query("SELECT id, name FROM centers ORDER BY name ASC")->fetchAll();
}

$gradesList = fetch_grades($pdo);
$centersList = fetch_centers($pdo);

$gradesMap = [];
foreach ($gradesList as $g) $gradesMap[(int)$g['id']] = (string)$g['name'];

$centersMap = [];
foreach ($centersList as $c) $centersMap[(int)$c['id']] = (string)$c['name'];

/* =========================
   Export Excel (بدون مكتبات) => .xls (HTML Table)
   ========================= */
if (isset($_GET['export']) && $_GET['export'] === '1') {
  $q = trim((string)($_GET['q'] ?? ''));

  $where = "1=1";
  $params = [];

  if ($q !== '') {
    $where = "(s.full_name LIKE ? OR s.student_phone LIKE ? OR s.parent_phone LIKE ? OR s.barcode LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
  }

  $stmt = $pdo->prepare("
    SELECT
      s.id,
      s.full_name,
      s.student_phone,
      s.parent_phone,
      gr.name AS grade_name,
      s.wallet_balance,
      s.governorate,
      s.status,
      c.name AS center_name,
      g.name AS group_name,
      s.barcode,
      s.created_at
    FROM students s
    INNER JOIN grades gr ON gr.id = s.grade_id
    LEFT JOIN centers c ON c.id = s.center_id
    LEFT JOIN `groups` g ON g.id = s.group_id
    WHERE {$where}
    ORDER BY s.id DESC
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  $filename = 'الطلاب_' . date('Y-m-d_H-i') . '.xls';

  header("Content-Type: application/vnd.ms-excel; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
  header("Pragma: no-cache");
  header("Expires: 0");

  echo "\xEF\xBB\xBF"; // BOM UTF-8

  ?>
  <!doctype html>
  <html lang="ar" dir="rtl">
  <head>
    <meta charset="utf-8">
    <title>تصدير الطلاب</title>
    <style>
      table{border-collapse:collapse; width:100%}
      th,td{border:1px solid #ccc; padding:8px; font-family:Tahoma, Arial}
      th{background:#f2f2f2}
    </style>
  </head>
  <body>
    <table>
      <thead>
        <tr>
          <th>م</th>
          <th>اسم الطالب</th>
          <th>رقم هاتف الطالب</th>
          <th>رقم ولي الأمر</th>
          <th>الصف الدراسي</th>
          <th>رصيد المحفظة</th>
          <th>المحافظة</th>
          <th>حالة الطالب</th>
          <th>اسم السنتر</th>
          <th>اسم المجموعة</th>
          <th>باركود الطالب</th>
          <th>تاريخ الإضافة</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=0; foreach ($rows as $r): $i++; ?>
          <tr>
            <td><?php echo $i; ?></td>
            <td><?php echo h((string)$r['full_name']); ?></td>
            <td><?php echo h((string)$r['student_phone']); ?></td>
            <td><?php echo h((string)($r['parent_phone'] ?? '')); ?></td>
            <td><?php echo h((string)$r['grade_name']); ?></td>
            <td><?php echo h((string)$r['wallet_balance']); ?></td>
            <td><?php echo h((string)$r['governorate']); ?></td>
            <td><?php echo h((string)$r['status']); ?></td>
            <td><?php echo h((string)($r['center_name'] ?? '')); ?></td>
            <td><?php echo h((string)($r['group_name'] ?? '')); ?></td>
            <td><?php echo h((string)($r['barcode'] ?? '')); ?></td>
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
   CRUD - Students
   ========================= */
$success = null;
$error = null;

function fetch_groups_filtered(PDO $pdo, int $gradeId, int $centerId): array {
  $stmt = $pdo->prepare("
    SELECT g.id, g.name
    FROM `groups` g
    WHERE g.grade_id = ? AND g.center_id = ?
    ORDER BY g.id DESC
  ");
  $stmt->execute([$gradeId, $centerId]);
  return $stmt->fetchAll();
}

function fetch_student_summary(PDO $pdo, int $studentId): ?array {
  if ($studentId <= 0) return null;

  $stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.student_phone, s.barcode, g.name AS grade_name
    FROM students s
    INNER JOIN grades g ON g.id = s.grade_id
    WHERE s.id=?
    LIMIT 1
  ");
  $stmt->execute([$studentId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $fullName = trim((string)($_POST['full_name'] ?? ''));
  $studentPhone = normalize_phone((string)($_POST['student_phone'] ?? ''));
  $parentPhone = normalize_phone((string)($_POST['parent_phone'] ?? ''));
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $governorate = trim((string)($_POST['governorate'] ?? ''));
  $status = (string)($_POST['status'] ?? 'اونلاين');

  $centerId = (int)($_POST['center_id'] ?? 0);
  $groupId = (int)($_POST['group_id'] ?? 0);
  $barcode = trim((string)($_POST['barcode'] ?? ''));

  $password = (string)($_POST['password'] ?? '');

  if (!is_arabic_name_3plus($fullName)) {
    $error = 'اسم الطالب يجب أن يكون ثلاثي (3 كلمات أو أكثر) وباللغة العربية.';
  } elseif ($studentPhone === '') {
    $error = 'رقم هاتف الطالب مطلوب.';
  } elseif ($password === '') {
    $error = 'كلمة السر مطلوبة.';
  } elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) {
    $error = 'من فضلك اختر الصف الدراسي.';
  } elseif ($governorate === '' || !in_array($governorate, $governorates, true)) {
    $error = 'من فضلك اختر المحافظة.';
  } elseif (!in_array($status, ['اونلاين','سنتر'], true)) {
    $error = 'حالة الطالب غير صحيحة.';
  } else {
    // مركز/مجموعة/باركود عند السنتر
    if ($status === 'سنتر') {
      if ($centerId <= 0 || !isset($centersMap[$centerId])) {
        $error = 'من فضلك اختر السنتر.';
      } else {
        $groupsList = fetch_groups_filtered($pdo, $gradeId, $centerId);
        $groupsMap = [];
        foreach ($groupsList as $g) $groupsMap[(int)$g['id']] = true;

        if ($groupId <= 0 || !isset($groupsMap[$groupId])) {
          $error = 'من فضلك اختر المجموعة (مرتبطة بالصف الدراسي والسنتر المختار).';
        } elseif ($barcode === '') {
          $error = 'باركود الحضور مطلوب عند اختيار "سنتر".';
        }
      }
    } else {
      $centerId = 0;
      $groupId = 0;
      $barcode = '';
    }

    if (!$error) {
      try {
        // تأكيد عدم تكرار رقم الطالب
        $stmt = $pdo->prepare("SELECT id FROM students WHERE student_phone=? LIMIT 1");
        $stmt->execute([$studentPhone]);
        if ($stmt->fetch()) {
          $error = 'رقم هاتف الطالب مسجل من قبل ولا يمكن تكراره.';
        } else {
          // تأكيد عدم تكرار الباركود لو سنتر
          if ($status === 'سنتر') {
            $stmt = $pdo->prepare("SELECT id FROM students WHERE barcode=? LIMIT 1");
            $stmt->execute([$barcode]);
            if ($stmt->fetch()) {
              $error = 'باركود الطالب مسجل من قبل ولا يمكن تكراره.';
            }
          }
        }

        if (!$error) {
          $hash = password_hash($password, PASSWORD_DEFAULT);

          $stmt = $pdo->prepare("
            INSERT INTO students
              (full_name, student_phone, parent_phone, grade_id, governorate, status, center_id, group_id, barcode, wallet_balance, password_hash, password_plain)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
          ");

          $stmt->execute([
            $fullName,
            $studentPhone,
            ($parentPhone !== '' ? $parentPhone : null),
            $gradeId,
            $governorate,
            $status,
            ($status === 'سنتر' ? $centerId : null),
            ($status === 'سنتر' ? $groupId : null),
            ($status === 'سنتر' ? $barcode : null),
            $hash,
            $password
          ]);

          header('Location: students.php?added=1');
          exit;
        }
      } catch (Throwable $e) {
        $error = 'تعذر إضافة الطالب (تحقق من البيانات: رقم الهاتف/الباركود ربما مكرر).';
      }
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);

  $fullName = trim((string)($_POST['full_name'] ?? ''));
  $studentPhone = normalize_phone((string)($_POST['student_phone'] ?? ''));
  $parentPhone = normalize_phone((string)($_POST['parent_phone'] ?? ''));
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $governorate = trim((string)($_POST['governorate'] ?? ''));
  $status = (string)($_POST['status'] ?? 'اونلاين');

  $centerId = (int)($_POST['center_id'] ?? 0);
  $groupId = (int)($_POST['group_id'] ?? 0);
  $barcode = trim((string)($_POST['barcode'] ?? ''));

  if ($id <= 0) {
    $error = 'طلب غير صالح.';
  } elseif (!is_arabic_name_3plus($fullName)) {
    $error = 'اسم الطالب يجب أن يكون ثلاثي (3 كلمات أو أكثر) وباللغة العربية.';
  } elseif ($studentPhone === '') {
    $error = 'رقم هاتف الطالب مطلوب.';
  } elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) {
    $error = 'من فضلك اختر الصف الدراسي.';
  } elseif ($governorate === '' || !in_array($governorate, $governorates, true)) {
    $error = 'من فضلك اختر المحافظة.';
  } elseif (!in_array($status, ['اونلاين','سنتر'], true)) {
    $error = 'حالة الطالب غير صحيحة.';
  } else {
    if ($status === 'سنتر') {
      if ($centerId <= 0 || !isset($centersMap[$centerId])) {
        $error = 'من فضلك اختر السنتر.';
      } else {
        $groupsList = fetch_groups_filtered($pdo, $gradeId, $centerId);
        $groupsMap = [];
        foreach ($groupsList as $g) $groupsMap[(int)$g['id']] = true;

        if ($groupId <= 0 || !isset($groupsMap[$groupId])) {
          $error = 'من فضلك اختر المجموعة (مرتبطة بالصف الدراسي والسنتر المختار).';
        } elseif ($barcode === '') {
          $error = 'باركود الحضور مطلوب عند اختيار "سنتر".';
        }
      }
    } else {
      $centerId = 0;
      $groupId = 0;
      $barcode = '';
    }

    if (!$error) {
      try {
        // تأكيد وجود الطالب
        $stmt = $pdo->prepare("SELECT id FROM students WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
          $error = 'الطالب غير موجود.';
        }

        // عدم تكرار رقم الطالب (مع استثناء نفس id)
        if (!$error) {
          $stmt = $pdo->prepare("SELECT id FROM students WHERE student_phone=? AND id<>? LIMIT 1");
          $stmt->execute([$studentPhone, $id]);
          if ($stmt->fetch()) $error = 'رقم هاتف الطالب مسجل من قبل ولا يمكن تكراره.';
        }

        // عدم تكرار الباركود (مع استثناء نفس id)
        if (!$error && $status === 'سنتر') {
          $stmt = $pdo->prepare("SELECT id FROM students WHERE barcode=? AND id<>? LIMIT 1");
          $stmt->execute([$barcode, $id]);
          if ($stmt->fetch()) $error = 'باركود الطالب مسجل من قبل ولا يمكن تكراره.';
        }

        if (!$error) {
          $stmt = $pdo->prepare("
            UPDATE students
            SET full_name=?,
                student_phone=?,
                parent_phone=?,
                grade_id=?,
                governorate=?,
                status=?,
                center_id=?,
                group_id=?,
                barcode=?
            WHERE id=?
          ");

          $stmt->execute([
            $fullName,
            $studentPhone,
            ($parentPhone !== '' ? $parentPhone : null),
            $gradeId,
            $governorate,
            $status,
            ($status === 'سنتر' ? $centerId : null),
            ($status === 'سنتر' ? $groupId : null),
            ($status === 'سنتر' ? $barcode : null),
            $id
          ]);

          header('Location: students.php?updated=1');
          exit;
        }
      } catch (Throwable $e) {
        $error = 'تعذر تعديل الطالب.';
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
      $stmt = $pdo->prepare("DELETE FROM students WHERE id=?");
      $stmt->execute([$id]);

      header('Location: students.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف الطالب.';
    }
  }
}

/* Wallet Adjust (simple) */
if (($_POST['action'] ?? '') === 'wallet_add' || ($_POST['action'] ?? '') === 'wallet_sub') {
  $id = (int)($_POST['id'] ?? 0);
  $amount = (float)($_POST['amount'] ?? 0);

  if ($id <= 0) $error = 'طلب غير صالح.';
  elseif ($amount <= 0) $error = 'المبلغ يجب أن يكون أكبر من صفر.';
  else {
    try {
      wallet_transactions_ensure_table($pdo);
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("SELECT wallet_balance FROM students WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$id]);
      $row = $stmt->fetch();

      if (!$row) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'الطالب غير موجود.';
      } elseif (($_POST['action'] ?? '') === 'wallet_add') {
        $stmt = $pdo->prepare("UPDATE students SET wallet_balance = wallet_balance + ? WHERE id=?");
        $stmt->execute([$amount, $id]);

        wallet_transactions_record($pdo, [
          'student_id'        => $id,
          'transaction_type'  => 'credit',
          'amount'            => $amount,
          'description'       => 'إضافة رصيد بواسطة الإدارة',
          'reference_type'    => 'admin_adjustment',
        ]);

        $pdo->commit();
        header('Location: students.php?wallet=1');
        exit;
      } else {
        $balance = (float)$row['wallet_balance'];
        if ($amount > $balance) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $error = 'لا يمكن خصم مبلغ أكبر من رصيد محفظة الطالب.';
        } else {
          $stmt = $pdo->prepare("UPDATE students SET wallet_balance = wallet_balance - ? WHERE id=?");
          $stmt->execute([$amount, $id]);

          wallet_transactions_record($pdo, [
            'student_id'        => $id,
            'transaction_type'  => 'debit',
            'amount'            => $amount,
            'description'       => 'خصم رصيد بواسطة الإدارة',
            'reference_type'    => 'admin_adjustment',
          ]);

          $pdo->commit();
          header('Location: students.php?wallet=1');
          exit;
        }
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $error = 'تعذر تحديث رصيد المحفظة.';
    }
  }
}

/* Change password */
if (($_POST['action'] ?? '') === 'change_password') {
  $id = (int)($_POST['id'] ?? 0);
  $newPass = (string)($_POST['new_password'] ?? '');

  if ($id <= 0) $error = 'طلب غير صالح.';
  elseif ($newPass === '') $error = 'كلمة السر الجديدة مطلوبة.';
  else {
    try {
      $hash = password_hash($newPass, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("UPDATE students SET password_hash=?, password_plain=? WHERE id=?");
      $stmt->execute([$hash, $newPass, $id]);
      header('Location: students.php?pass=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر تغيير كلمة السر.';
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = 'تمت إضافة الطالب بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل بيانات الطالب بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف الطالب بنجاح.';
if (isset($_GET['wallet'])) $success = 'تم تحديث رصيد محفظة الطالب.';
if (isset($_GET['pass'])) $success = 'تم تغيير كلمة سر الطالب بنجاح.';

/* Search + List */
$q = trim((string)($_GET['q'] ?? ''));

$where = "1=1";
$params = [];
if ($q !== '') {
  $where = "(s.full_name LIKE ? OR s.student_phone LIKE ? OR s.parent_phone LIKE ? OR s.barcode LIKE ?)";
  $like = '%' . $q . '%';
  $params = [$like, $like, $like, $like];
}

$stmt = $pdo->prepare("
  SELECT
    s.*,
    gr.name AS grade_name,
    c.name AS center_name,
    g.name AS group_name
  FROM students s
  INNER JOIN grades gr ON gr.id = s.grade_id
  LEFT JOIN centers c ON c.id = s.center_id
  LEFT JOIN `groups` g ON g.id = s.group_id
  WHERE {$where}
  ORDER BY s.id DESC
");
$stmt->execute($params);
$students = $stmt->fetchAll();
$totalStudents = count($students);

$studentCoursesId = (int)($_GET['student_courses'] ?? 0);
$studentCoursesRow = fetch_student_summary($pdo, $studentCoursesId);
$studentCourses = [];
if ($studentCoursesRow) {
  $stmt = $pdo->prepare("
    SELECT
      c.id,
      c.name,
      c.access_type AS course_access_type,
      sce.access_type AS enrollment_access_type,
      sce.created_at
    FROM student_course_enrollments sce
    INNER JOIN courses c ON c.id = sce.course_id
    WHERE sce.student_id = ?
    ORDER BY sce.created_at DESC, c.name ASC
  ");
  $stmt->execute([$studentCoursesId]);
  $studentCourses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$studentLecturesId = (int)($_GET['student_lectures'] ?? 0);
$studentLecturesRow = fetch_student_summary($pdo, $studentLecturesId);
$studentLectures = [];
if ($studentLecturesRow) {
  $stmt = $pdo->prepare("
    SELECT *
    FROM (
      SELECT
        l.id,
        l.name,
        c.name AS course_name,
        sle.access_type,
        sle.created_at
      FROM student_lecture_enrollments sle
      INNER JOIN lectures l ON l.id = sle.lecture_id
      INNER JOIN courses c ON c.id = sle.course_id
      WHERE sle.student_id = ?

      UNION

      SELECT
        l.id,
        l.name,
        c.name AS course_name,
        CONCAT('course:', sce.access_type) AS access_type,
        sce.created_at
      FROM student_course_enrollments sce
      INNER JOIN courses c ON c.id = sce.course_id
      INNER JOIN lectures l ON l.course_id = c.id
      WHERE sce.student_id = ?
    ) lecture_rows
    ORDER BY created_at DESC, name ASC
  ");
  $stmt->execute([$studentLecturesId, $studentLecturesId]);
  $studentLectures = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* Edit mode (opens modal) */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM students WHERE id=? LIMIT 1");
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
  ['key' => 'students', 'label' => 'الطلاب', 'icon' => '🧑‍🎓', 'href' => 'students.php', 'active' => true],

  ['key' => 'courses', 'label' => 'الكورسات', 'icon' => '📚', 'href' => 'courses.php'],
  ['key' => 'lectures', 'label' => 'المحاضرات', 'icon' => '🧑‍🏫', 'href' => 'lectures.php'],
  ['key' => 'videos', 'label' => 'الفيديوهات', 'icon' => '🎥', 'href' => 'videos.php'],

  ['key' => 'pdfs', 'label' => 'ملفات PDF', 'icon' => '📑', 'href' => 'pdfs.php'],
  ['key' => 'course_codes', 'label' => 'اكواد الكورسات', 'icon' => '🎟️', 'href' => 'course-codes.php'],
  ['key' => 'lecture_codes', 'label' => 'اكواد المحاضرات', 'icon' => '🧾', 'href' => 'lecture-codes.php'],
  ['key' => 'assignment_questions', 'label' => 'أسئلة الواجبات', 'icon' => '🗂️', 'href' => 'assignment-question-banks.php'],
  ['key' => 'assignments', 'label' => 'الواجبات', 'icon' => '📌', 'href' => 'assignments.php'],
  ['key' => 'exams', 'label' => 'الامتحانات', 'icon' => '🧠', 'href' => 'exams.php'],
  ['key' => 'exam_questions', 'label' => 'أسئلة الامتحانات', 'icon' => '❔', 'href' => 'exam-question-banks.php'],
  ['key' => 'student_notifications', 'label' => 'اشعارات الطلاب', 'icon' => '🔔', 'href' => 'student-notifications.php'],

  ['key' => 'attendance', 'label' => 'حضور الطلاب', 'icon' => '🧾', 'href' => 'attendance.php'],
  ['key' => 'facebook', 'label' => 'فيس بوك المنصة', 'icon' => '📘', 'href' => 'platform-posts.php'],
  ['key' => 'chat', 'label' => 'شات الطلاب', 'icon' => '💬', 'href' => 'student-conversations.php'],

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
  <title>الطلاب - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/students.css">
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
      <section class="students-hero">
        <div class="students-hero-title">
          <h1>🧑‍🎓 الطلاب</h1>
        </div>

        <div class="students-metrics">
          <div class="metric">
            <div class="metric-ico">🧑‍🎓</div>
            <div class="metric-meta">
              <div class="metric-label">عدد الطلاب</div>
              <div class="metric-val"><?php echo number_format($totalStudents); ?></div>
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
            <span class="cardx-badge">🔎</span>
            <h2>بحث</h2>
          </div>

          <div class="cardx-actions">
            <a class="btn ghost" href="students.php?export=1<?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>">⬇️ تصدير Excel</a>
            <button class="btn" type="button" id="openAdd">➕ إضافة طالب</button>
          </div>
        </div>

        <form method="get" class="students-search" autocomplete="off">
          <label class="field" style="margin:0">
            <span class="label">كلمة البحث</span>
            <input class="input2" name="q" value="<?php echo h($q); ?>" placeholder="مثال: أحمد / 010... / باركود..." />
          </label>
          <div class="form-actions">
            <button class="btn" type="submit">بحث</button>
            <a class="btn ghost" href="students.php">مسح</a>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة الطلاب</h2>
          </div>

          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalStudents); ?></span>
          </div>
        </div>

        <?php if ($studentCoursesRow): ?>
          <div class="students-manage-card">
            <div class="cardx-head">
              <div class="cardx-title">
                <span class="cardx-badge">📚</span>
                <h2>كورسات الطالب: <?php echo h((string)$studentCoursesRow['full_name']); ?></h2>
              </div>
              <div class="cardx-actions">
                <a class="btn ghost" href="students.php">إغلاق</a>
              </div>
            </div>

            <div class="students-manage-meta">
              <span class="pillx">📱 <?php echo h((string)$studentCoursesRow['student_phone']); ?></span>
              <span class="pillx">🏫 <?php echo h((string)$studentCoursesRow['grade_name']); ?></span>
              <span class="pillx">🆔 <?php echo h((string)($studentCoursesRow['barcode'] ?: ('STD-' . (int)$studentCoursesRow['id']))); ?></span>
            </div>

            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>الكورس</th>
                    <th>نوع الكورس</th>
                    <th>نوع الاشتراك</th>
                    <th>تاريخ الاشتراك</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$studentCourses): ?>
                    <tr><td colspan="5" style="text-align:center">لا يوجد كورسات مشترك بها هذا الطالب.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($studentCourses as $idx => $courseRow): ?>
                    <tr>
                      <td><?php echo (int)($idx + 1); ?></td>
                      <td><?php echo h((string)$courseRow['name']); ?></td>
                      <td><?php echo h((string)$courseRow['course_access_type']); ?></td>
                      <td><?php echo h((string)$courseRow['enrollment_access_type']); ?></td>
                      <td><?php echo h((string)$courseRow['created_at']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($studentLecturesRow): ?>
          <div class="students-manage-card">
            <div class="cardx-head">
              <div class="cardx-title">
                <span class="cardx-badge">🧑‍🏫</span>
                <h2>محاضرات الطالب: <?php echo h((string)$studentLecturesRow['full_name']); ?></h2>
              </div>
              <div class="cardx-actions">
                <a class="btn ghost" href="students.php">إغلاق</a>
              </div>
            </div>

            <div class="students-manage-meta">
              <span class="pillx">📱 <?php echo h((string)$studentLecturesRow['student_phone']); ?></span>
              <span class="pillx">🏫 <?php echo h((string)$studentLecturesRow['grade_name']); ?></span>
              <span class="pillx">🆔 <?php echo h((string)($studentLecturesRow['barcode'] ?: ('STD-' . (int)$studentLecturesRow['id']))); ?></span>
            </div>

            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>المحاضرة</th>
                    <th>الكورس</th>
                    <th>نوع الاشتراك</th>
                    <th>تاريخ الاشتراك</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$studentLectures): ?>
                    <tr><td colspan="5" style="text-align:center">لا يوجد محاضرات مشترك بها هذا الطالب.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($studentLectures as $idx => $lectureRow): ?>
                    <tr>
                      <td><?php echo (int)($idx + 1); ?></td>
                      <td><?php echo h((string)$lectureRow['name']); ?></td>
                      <td><?php echo h((string)$lectureRow['course_name']); ?></td>
                      <td><?php echo h((string)$lectureRow['access_type']); ?></td>
                      <td><?php echo h((string)$lectureRow['created_at']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <div class="table-wrap scroll-pro">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>اسم الطالب</th>
                <th>رقم الطالب</th>
                <th>رقم ولي الأمر</th>
                <th>الصف الدراسي</th>
                <th>رصيد المحفظة</th>
                <th>المحافظة</th>
                <th>الحالة</th>
                <th>السنتر</th>
                <th>المجموعة</th>
                <th>الباركود</th>
                <th>كلمة السر</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$students): ?>
                <tr><td colspan="13" style="text-align:center">لا يوجد طلاب بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($students as $s): ?>
                <tr>
                  <td data-label="#"><?php echo (int)$s['id']; ?></td>
                  <td data-label="اسم الطالب"><?php echo h((string)$s['full_name']); ?></td>
                  <td data-label="رقم الطالب"><?php echo h((string)$s['student_phone']); ?></td>
                  <td data-label="رقم ولي الأمر"><?php echo h((string)($s['parent_phone'] ?? '')); ?></td>
                  <td data-label="الصف الدراسي"><?php echo h((string)$s['grade_name']); ?></td>
                  <td data-label="رصيد المحفظة"><?php echo h((string)$s['wallet_balance']); ?></td>
                  <td data-label="المحافظة"><?php echo h((string)$s['governorate']); ?></td>
                  <td data-label="الحالة"><?php echo h((string)$s['status']); ?></td>
                  <td data-label="السنتر"><?php echo h((string)($s['center_name'] ?? '')); ?></td>
                  <td data-label="المجموعة"><?php echo h((string)($s['group_name'] ?? '')); ?></td>
                  <td data-label="الباركود"><?php echo h((string)($s['barcode'] ?? '')); ?></td>
                  <td data-label="كلمة السر"><?php echo h((string)($s['password_plain'] ?? '')); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <a class="link" href="students.php?edit=<?php echo (int)$s['id']; ?>">✏️ تعديل</a>

                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الطالب؟');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                      <button class="link danger" type="submit">🗑️ حذف</button>
                    </form>

                    <button class="link wallet add" type="button"
                      data-wallet="add" data-id="<?php echo (int)$s['id']; ?>" data-name="<?php echo h((string)$s['full_name']); ?>">
                      ➕ إضافة رصيد
                    </button>

                    <button class="link wallet sub" type="button"
                      data-wallet="sub" data-id="<?php echo (int)$s['id']; ?>" data-name="<?php echo h((string)$s['full_name']); ?>">
                      ➖ خصم رصيد
                    </button>

                    <button class="link pass" type="button"
                      data-pass="1" data-id="<?php echo (int)$s['id']; ?>" data-name="<?php echo h((string)$s['full_name']); ?>">
                      🔑 كلمة السر
                    </button>

                    <!-- ✅ NEW: Devices -->
                    <button class="link devices" type="button"
                      data-student-id="<?php echo (int)$s['id']; ?>"
                      data-student-name="<?php echo h((string)$s['full_name']); ?>">
                      📱 أجهزة الطالب
                    </button>

                    <a class="link info" href="students.php?student_courses=<?php echo (int)$s['id']; ?>">📚 الكورسات</a>
                    <a class="link info" href="students.php?student_lectures=<?php echo (int)$s['id']; ?>">🧑‍🏫 المحاضرات</a>
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

  <!-- Modal: Add/Edit Student -->
  <div class="modal" id="studentModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="إضافة/تعديل طالب">
      <div class="modal-head">
        <div class="modal-title">
          <div class="badge">🧑‍🎓</div>
          <div>
            <h3 id="modalTitle"><?php echo $editRow ? 'تعديل طالب' : 'إضافة طالب جديد'; ?></h3>
            <p>املأ البيانات ثم اضغط حفظ. عند الحفظ سيتم تفريغ الحقول تلقائيًا.</p>
          </div>
        </div>
        <button class="modal-close" type="button" id="closeModal" aria-label="إغلاق">✖</button>
      </div>

      <form method="post" class="students-form" autocomplete="off">
        <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
        <?php if ($editRow): ?>
          <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
        <?php endif; ?>

        <label class="field">
          <span class="label">اسم الطالب (ثلاثي أو أكثر بالعربي)</span>
          <input class="input2" name="full_name" required
            value="<?php echo $editRow ? h((string)$editRow['full_name']) : ''; ?>"
            placeholder="مثال: محمد أحمد علي">
        </label>

        <label class="field">
          <span class="label">رقم هاتف الطالب (لا يتكرر)</span>
          <input class="input2" name="student_phone" required inputmode="numeric" pattern="[0-9]*"
            value="<?php echo $editRow ? h((string)$editRow['student_phone']) : ''; ?>"
            placeholder="مثال: 010xxxxxxxx">
        </label>

        <label class="field">
          <span class="label">رقم ولي الأمر (يسمح بالتكرار)</span>
          <input class="input2" name="parent_phone" inputmode="numeric" pattern="[0-9]*"
            value="<?php echo ($editRow && !empty($editRow['parent_phone'])) ? h((string)$editRow['parent_phone']) : ''; ?>"
            placeholder="مثال: 010xxxxxxxx">
        </label>

        <label class="field">
          <span class="label">الصف الدراسي</span>
          <select class="input2 select-pro" name="grade_id" id="gradeSelect" required>
            <option value="0">— اختر الصف —</option>
            <?php foreach ($gradesList as $g): ?>
              <?php $gid = (int)$g['id']; ?>
              <option value="<?php echo $gid; ?>" <?php echo ($editRow && (int)$editRow['grade_id'] === $gid) ? 'selected' : ''; ?>>
                <?php echo h((string)$g['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span class="label">المحافظة</span>
          <select class="input2 select-pro" name="governorate" required>
            <option value="">— اختر المحافظة —</option>
            <?php foreach ($governorates as $gov): ?>
              <option value="<?php echo h($gov); ?>" <?php echo ($editRow && (string)$editRow['governorate'] === $gov) ? 'selected' : ''; ?>>
                <?php echo h($gov); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span class="label">حالة الطالب</span>
          <select class="input2 select-pro" name="status" id="statusSelect" required>
            <option value="اونلاين" <?php echo ($editRow && (string)$editRow['status'] === 'اونلاين') ? 'selected' : ''; ?>>اونلاين</option>
            <option value="سنتر" <?php echo ($editRow && (string)$editRow['status'] === 'سنتر') ? 'selected' : ''; ?>>سنتر</option>
          </select>
        </label>

        <div class="center-fields" id="centerFields">
          <label class="field">
            <span class="label">السنتر</span>
            <select class="input2 select-pro" name="center_id" id="centerSelect">
              <option value="0">— اختر السنتر —</option>
              <?php foreach ($centersList as $c): ?>
                <?php $cid = (int)$c['id']; ?>
                <option value="<?php echo $cid; ?>" <?php echo ($editRow && (int)$editRow['center_id'] === $cid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$c['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="field">
            <span class="label">المجموعة (حسب الصف + السنتر)</span>
            <select class="input2 select-pro" name="group_id" id="groupSelect">
              <option value="0">— اختر المجموعة —</option>
            </select>
            <div class="hint">بعد اختيار الصف والسنتر ستظهر المجموعات المطابقة.</div>
          </label>

          <label class="field">
            <span class="label">باركود الحضور (إجباري للسنتر)</span>
            <input class="input2" name="barcode" id="barcodeInput"
              value="<?php echo ($editRow && !empty($editRow['barcode'])) ? h((string)$editRow['barcode']) : ''; ?>"
              placeholder="مثال: 123456789">
          </label>
        </div>

        <?php if (!$editRow): ?>
          <label class="field">
            <span class="label">كلمة السر</span>
            <input class="input2" name="password" type="password" required placeholder="••••••••">
          </label>
        <?php else: ?>
          <div class="hint-wide">لتغيير كلمة السر استخدم زر "🔑 كلمة السر" من جدول الطلاب.</div>
        <?php endif; ?>

        <div class="form-actions">
          <button class="btn" type="submit"><?php echo $editRow ? '💾 حفظ التعديل' : '➕ إضافة الطالب'; ?></button>
          <a class="btn ghost" href="students.php">إلغاء</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: Wallet -->
  <div class="modal" id="walletModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="تعديل رصيد الطالب">
      <div class="modal-head">
        <div class="modal-title">
          <div class="badge">💳</div>
          <div>
            <h3 id="walletTitle">تعديل الرصيد</h3>
            <p id="walletSub">أدخل المبلغ ثم حفظ.</p>
          </div>
        </div>
        <button class="modal-close" type="button" data-close="wallet">✖</button>
      </div>

      <form method="post" class="mini-form" autocomplete="off">
        <input type="hidden" name="action" id="walletAction" value="wallet_add">
        <input type="hidden" name="id" id="walletStudentId" value="0">

        <label class="field">
          <span class="label">المبلغ</span>
          <input class="input2" type="number" step="0.01" min="0.01" name="amount" required placeholder="مثال: 50">
        </label>

        <div class="form-actions">
          <button class="btn" type="submit">✅ حفظ</button>
          <button class="btn ghost" type="button" data-close="wallet">إلغاء</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: Password -->
  <div class="modal" id="passModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="تغيير كلمة سر الطالب">
      <div class="modal-head">
        <div class="modal-title">
          <div class="badge">🔑</div>
          <div>
            <h3 id="passTitle">تغيير كلمة السر</h3>
            <p>اكتب كلمة السر الجديدة ثم حفظ.</p>
          </div>
        </div>
        <button class="modal-close" type="button" data-close="pass">✖</button>
      </div>

      <form method="post" class="mini-form" autocomplete="off">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="id" id="passStudentId" value="0">

        <label class="field">
          <span class="label">كلمة السر الجديدة</span>
          <input class="input2" type="password" name="new_password" required placeholder="••••••••">
        </label>

        <div class="form-actions">
          <button class="btn" type="submit">✅ حفظ</button>
          <button class="btn ghost" type="button" data-close="pass">إلغاء</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ✅ Modal: Devices -->
  <div class="modal" id="devicesModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="أجهزة الطالب">
      <div class="modal-head">
        <div class="modal-title">
          <div class="badge">📱</div>
          <div>
            <h3 id="devicesTitle">أجهزة الطالب</h3>
            <p>الحساب مرتبط بجهاز واحد فقط. احذف الجهاز للسماح بتسجيل جهاز جديد.</p>
          </div>
        </div>
        <button class="modal-close" type="button" data-close="devices">✖</button>
      </div>

      <div style="padding: 10px 2px;">
        <div id="devicesLoading" class="hint-wide">جارٍ تحميل بيانات الجهاز...</div>
        <div id="devicesError" class="hint-wide" style="display:none;color:#8e1d28;font-weight:1000;"></div>

        <div class="table-wrap scroll-pro" id="devicesTableWrap" style="display:none;">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>اسم الجهاز</th>
                <th>IP أول مرة</th>
                <th>أول دخول</th>
                <th>آخر دخول</th>
                <th>الحالة</th>
              </tr>
            </thead>
            <tbody id="devicesTbody"></tbody>
          </table>
        </div>

        <div style="padding:12px 12px;">
          <button class="btn danger" type="button" id="btnDeleteStudentDevice">🗑️ مسح الجهاز والسماح بتغيير الجهاز</button>
        </div>
      </div>
    </div>
  </div>

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

      // ===== Modal Add/Edit
      const studentModal = document.getElementById('studentModal');
      const openAdd = document.getElementById('openAdd');
      const closeModal = document.getElementById('closeModal');

      function openStudentModal() {
        studentModal.classList.add('open');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
      }
      function closeStudentModal() {
        studentModal.classList.remove('open');
        backdrop.classList.add('show');
        document.body.style.overflow = '';
        backdrop.classList.remove('show');
      }

      if (openAdd) openAdd.addEventListener('click', openStudentModal);
      if (closeModal) closeModal.addEventListener('click', closeStudentModal);

      // فتح تلقائي لو edit mode
      const isEdit = <?php echo $editRow ? 'true' : 'false'; ?>;
      if (isEdit) openStudentModal();

      // ===== Center fields toggle + group fetch (AJAX)
      const statusSelect = document.getElementById('statusSelect');
      const centerFields = document.getElementById('centerFields');

      const gradeSelect = document.getElementById('gradeSelect');
      const centerSelect = document.getElementById('centerSelect');
      const groupSelect = document.getElementById('groupSelect');
      const barcodeInput = document.getElementById('barcodeInput');

      function toggleCenterFields() {
        const st = statusSelect ? statusSelect.value : 'اونلاين';
        const show = (st === 'سنتر');
        centerFields.style.display = show ? '' : 'none';

        if (barcodeInput) barcodeInput.required = show;
        if (centerSelect) centerSelect.required = show;
        if (groupSelect) groupSelect.required = show;
      }

      async function fetchGroups() {
        const gradeId = parseInt(gradeSelect.value || '0', 10);
        const centerId = parseInt(centerSelect.value || '0', 10);

        groupSelect.innerHTML = '<option value="0">— اختر المجموعة —</option>';

        if (!gradeId || !centerId) return;

        try {
          const url = 'students_groups_api.php?grade_id=' + encodeURIComponent(gradeId) + '&center_id=' + encodeURIComponent(centerId);
          const res = await fetch(url, { credentials: 'same-origin' });
          const data = await res.json();

          if (!data || !Array.isArray(data.groups)) return;

          data.groups.forEach(g => {
            const opt = document.createElement('option');
            opt.value = String(g.id);
            opt.textContent = g.name;
            groupSelect.appendChild(opt);
          });

          const selectedGroupId = <?php echo $editRow ? (int)$editRow['group_id'] : 0; ?>;
          if (selectedGroupId > 0) {
            groupSelect.value = String(selectedGroupId);
          }
        } catch (e) {}
      }

      if (statusSelect) statusSelect.addEventListener('change', toggleCenterFields);
      if (gradeSelect) gradeSelect.addEventListener('change', fetchGroups);
      if (centerSelect) centerSelect.addEventListener('change', fetchGroups);

      toggleCenterFields();
      fetchGroups();

      // ===== Wallet modal
      const walletModal = document.getElementById('walletModal');
      const walletTitle = document.getElementById('walletTitle');
      const walletAction = document.getElementById('walletAction');
      const walletStudentId = document.getElementById('walletStudentId');

      function openWalletModal(kind, id, name) {
        walletModal.classList.add('open');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';

        walletTitle.textContent = (kind === 'add' ? '➕ إضافة رصيد: ' : '➖ خصم رصيد: ') + name;
        walletAction.value = (kind === 'add' ? 'wallet_add' : 'wallet_sub');
        walletStudentId.value = String(id);
      }
      function closeWalletModal() { walletModal.classList.remove('open'); }

      document.querySelectorAll('button.wallet').forEach(btn => {
        btn.addEventListener('click', () => {
          openWalletModal(btn.dataset.wallet, btn.dataset.id, btn.dataset.name || '');
        });
      });

      document.querySelectorAll('[data-close="wallet"]').forEach(btn => {
        btn.addEventListener('click', () => closeWalletModal());
      });

      // ===== Pass modal
      const passModal = document.getElementById('passModal');
      const passTitle = document.getElementById('passTitle');
      const passStudentId = document.getElementById('passStudentId');

      function openPassModal(id, name) {
        passModal.classList.add('open');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';

        passTitle.textContent = '🔑 تغيير كلمة السر: ' + (name || '');
        passStudentId.value = String(id);
      }
      function closePassModal() { passModal.classList.remove('open'); }

      document.querySelectorAll('button.pass').forEach(btn => {
        btn.addEventListener('click', () => {
          openPassModal(btn.dataset.id, btn.dataset.name || '');
        });
      });

      document.querySelectorAll('[data-close="pass"]').forEach(btn => {
        btn.addEventListener('click', () => closePassModal());
      });

      // ===== Devices modal (ONE device)
      const devicesModal = document.getElementById('devicesModal');
      const devicesTitle = document.getElementById('devicesTitle');
      const devicesLoading = document.getElementById('devicesLoading');
      const devicesError = document.getElementById('devicesError');
      const devicesTableWrap = document.getElementById('devicesTableWrap');
      const devicesTbody = document.getElementById('devicesTbody');
      const btnDeleteStudentDevice = document.getElementById('btnDeleteStudentDevice');

      let currentStudentIdForDevices = 0;

      function openDevicesModal(studentId, name) {
        currentStudentIdForDevices = parseInt(studentId || '0', 10) || 0;

        devicesModal.classList.add('open');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';

        devicesTitle.textContent = '📱 جهاز الطالب: ' + (name || '');
        loadStudentDevice();
      }
      function closeDevicesModal() { devicesModal.classList.remove('open'); }

      async function loadStudentDevice() {
        devicesLoading.style.display = '';
        devicesError.style.display = 'none';
        devicesTableWrap.style.display = 'none';
        devicesTbody.innerHTML = '';

        if (!currentStudentIdForDevices) {
          devicesLoading.style.display = 'none';
          devicesError.style.display = '';
          devicesError.textContent = 'student_id غير صالح';
          return;
        }

        try {
          const url = 'student_devices_api.php?student_id=' + encodeURIComponent(currentStudentIdForDevices);
          const res = await fetch(url, { credentials: 'same-origin' });
          const data = await res.json();

          if (!data || !data.ok) throw new Error('api_error');

          const devices = Array.isArray(data.devices) ? data.devices : [];
          devicesLoading.style.display = 'none';
          devicesTableWrap.style.display = '';

          if (!devices.length) {
            devicesTbody.innerHTML = '<tr><td colspan="6" style="text-align:center">لا يوجد جهاز مسجل لهذا الطالب (سيسجل أول جهاز عند أول تسجيل دخول).</td></tr>';
            return;
          }

          const d = devices[0]; // جهاز واحد فقط
          const statusTxt = (parseInt(d.is_active || 0, 10) === 1) ? 'مفعل' : 'غير مفعل';

          devicesTbody.innerHTML = `
            <tr>
              <td>1</td>
              <td>${(d.device_label || '')}</td>
              <td>${(d.ip_first || '')}</td>
              <td>${(d.first_login_at || '')}</td>
              <td>${(d.last_login_at || '')}</td>
              <td>${statusTxt}</td>
            </tr>
          `;
        } catch (e) {
          devicesLoading.style.display = 'none';
          devicesError.style.display = '';
          devicesError.textContent = 'تعذر تحميل بيانات الجهاز.';
        }
      }

      async function deleteStudentDevice() {
        if (!currentStudentIdForDevices) return;
        if (!confirm('هل أنت متأكد؟ سيتم حذف الجهاز الحالي والسماح للطالب بتسجيل جهاز جديد عند الدخول القادم.')) return;

        try {
          const fd = new FormData();
          fd.append('student_id', String(currentStudentIdForDevices));

          const res = await fetch('student_devices_delete.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          });
          const data = await res.json();

          if (!data || !data.ok) throw new Error('delete_failed');

          loadStudentDevice();
          alert('تم مسح الجهاز. الآن الطالب يستطيع تسجيل الدخول من جهاز جديد (وسيتم تثبيته كجهاز وحيد).');
        } catch (e) {
          alert('تعذر مسح الجهاز.');
        }
      }

      document.querySelectorAll('button.devices').forEach(btn => {
        btn.addEventListener('click', () => {
          openDevicesModal(btn.dataset.studentId, btn.dataset.studentName || '');
        });
      });

      btnDeleteStudentDevice && btnDeleteStudentDevice.addEventListener('click', deleteStudentDevice);

      document.querySelectorAll('[data-close="devices"]').forEach(btn => {
        btn.addEventListener('click', () => closeDevicesModal());
      });

      // Backdrop closes all modals
      backdrop.addEventListener('click', () => {
        closeStudentModal();
        closeWalletModal();
        closePassModal();
        closeDevicesModal();
      });

      // ESC closes modals
      window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          closeStudentModal();
          closeWalletModal();
          closePassModal();
          closeDevicesModal();
        }
      });
    })();
  </script>
</body>
</html>
