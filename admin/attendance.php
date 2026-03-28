<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/../inc/platform_features.php';

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
platform_features_ensure_tables($pdo);
$settings = get_platform_settings($pdo);
$platformName = (string)($rowSettings['platform_name'] ?? ($settings['platform_name'] ?? 'منصتي التعليمية'));
$logo = (string)($rowSettings['platform_logo'] ?? ($settings['platform_logo'] ?? ''));
if ($logo === '') $logo = null;

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function menu_visible(array $allowedKeys, string $key, string $role): bool {
  if ($role === 'مدير') return true;
  if ($key === 'logout') return true;
  return menu_allowed($allowedKeys, $key);
}
function attendance_fetch_session_students(PDO $pdo, array $session): array {
  $sessionId = (int)($session['id'] ?? 0);
  $gradeId = (int)($session['grade_id'] ?? 0);
  $centerId = (int)($session['center_id'] ?? 0);
  $groupId = (int)($session['group_id'] ?? 0);
  if ($sessionId <= 0 || $gradeId <= 0 || $groupId <= 0) return [];

  $lastAssignment = null;
  $lastExam = null;
  $stmt = $pdo->prepare('SELECT id, name FROM assignments WHERE grade_id=? ORDER BY created_at DESC, id DESC LIMIT 1');
  $stmt->execute([$gradeId]);
  $lastAssignment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  $stmt = $pdo->prepare('SELECT id, name FROM exams WHERE grade_id=? ORDER BY created_at DESC, id DESC LIMIT 1');
  $stmt->execute([$gradeId]);
  $lastExam = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

  $whereParts = [
    's.group_id = ?',
    's.grade_id = ?',
    "s.status = 'سنتر'",
  ];
  $params = [$groupId, $gradeId];
  if ($centerId > 0) {
    $whereParts[] = 's.center_id = ?';
    $params[] = $centerId;
  }
  $studentsWhereSql = implode(' AND ', $whereParts);
  $stmt = $pdo->prepare(" 
    SELECT s.id, s.full_name, s.student_phone, s.parent_phone, s.barcode, s.status,
           c.name AS center_name, g.name AS group_name,
           ar.attendance_status, ar.scan_method, ar.scanned_at
    FROM students s
    INNER JOIN `groups` g ON g.id = s.group_id
    LEFT JOIN centers c ON c.id = s.center_id
    LEFT JOIN attendance_records ar ON ar.student_id = s.id AND ar.session_id = ?
    WHERE {$studentsWhereSql}
    ORDER BY s.full_name ASC
  ");
  array_unshift($params, $sessionId);
  $stmt->execute($params);
  $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$students) return [];

  $studentIds = array_values(array_map(fn($r) => (int)$r['id'], $students));
  $assignMap = [];
  $examMap = [];
  if ($lastAssignment && $studentIds) {
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $params = array_merge([(int)$lastAssignment['id']], $studentIds);
    $stmt = $pdo->prepare(" 
      SELECT aa.* FROM assignment_attempts aa
      WHERE aa.assignment_id = ? AND aa.student_id IN ($in)
      ORDER BY aa.id DESC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rowAtt) {
      $sid = (int)$rowAtt['student_id'];
      if (!isset($assignMap[$sid])) $assignMap[$sid] = $rowAtt;
    }
  }
  if ($lastExam && $studentIds) {
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $params = array_merge([(int)$lastExam['id']], $studentIds);
    $stmt = $pdo->prepare(" 
      SELECT ea.* FROM exam_attempts ea
      WHERE ea.exam_id = ? AND ea.student_id IN ($in)
      ORDER BY ea.id DESC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rowAtt) {
      $sid = (int)$rowAtt['student_id'];
      if (!isset($examMap[$sid])) $examMap[$sid] = $rowAtt;
    }
  }

  foreach ($students as &$studentRow) {
    $sid = (int)$studentRow['id'];
    $assignAttempt = $assignMap[$sid] ?? null;
    $examAttempt = $examMap[$sid] ?? null;
    $studentRow['is_present'] = (($studentRow['attendance_status'] ?? '') === 'present');
    $studentRow['attendance_text'] = $studentRow['is_present'] ? 'حاضر' : 'غائب';
    $studentRow['assignment_name'] = (string)($lastAssignment['name'] ?? 'لا يوجد واجب مضاف');
    $studentRow['assignment_status'] = platform_attempt_status_label((string)($assignAttempt['status'] ?? ''));
    $studentRow['assignment_score_text'] = $assignAttempt ? ((float)$assignAttempt['score'] . ' / ' . (float)$assignAttempt['max_score']) : '—';
    $studentRow['exam_name'] = (string)($lastExam['name'] ?? 'لا يوجد امتحان مضاف');
    $studentRow['exam_status'] = platform_attempt_status_label((string)($examAttempt['status'] ?? ''));
    $studentRow['exam_score_text'] = $examAttempt ? ((float)$examAttempt['score'] . ' / ' . (float)$examAttempt['max_score']) : '—';
  }
  unset($studentRow);

  return $students;
}
function attendance_course_enrollment_access_type(string $courseAccessType): string {
  return in_array($courseAccessType, ['buy', 'free', 'attendance', 'code'], true) ? $courseAccessType : 'attendance';
}
function attendance_lecture_enrollment_access_type(string $courseAccessType): string {
  return $courseAccessType === 'free' ? 'free' : 'attendance';
}
function attendance_student_matches_session(PDO $pdo, array $session, int $studentId): bool {
  $gradeId = (int)($session['grade_id'] ?? 0);
  $groupId = (int)($session['group_id'] ?? 0);
  $centerId = (int)($session['center_id'] ?? 0);
  if ($studentId <= 0 || $gradeId <= 0 || $groupId <= 0) return false;

  $stmt = $pdo->prepare("
    SELECT 1
    FROM students
    WHERE id = ?
      AND group_id = ?
      AND grade_id = ?
      AND status = ?
      AND (? <= 0 OR center_id = ?)
    LIMIT 1
  ");
  $stmt->execute([$studentId, $groupId, $gradeId, 'سنتر', $centerId, $centerId]);
  return (bool)$stmt->fetchColumn();
}
function attendance_auto_enroll_student(PDO $pdo, array $session, int $studentId): void {
  $studentId = (int)$studentId;
  $courseId = (int)($session['course_id'] ?? 0);
  $lectureId = (int)($session['lecture_id'] ?? 0);
  if ($studentId <= 0 || empty($session['is_open']) || ($courseId <= 0 && $lectureId <= 0)) return;
  if (!attendance_student_matches_session($pdo, $session, $studentId)) return;

  try {
    if ($courseId > 0) {
      $stmt = $pdo->prepare('SELECT id, access_type FROM courses WHERE id=? LIMIT 1');
      $stmt->execute([$courseId]);
      $courseRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$courseRow) return;

      $pdo->beginTransaction();

      $stmt = $pdo->prepare("
        INSERT IGNORE INTO student_course_enrollments (student_id, course_id, access_type)
        VALUES (?, ?, ?)
      ");
      $stmt->execute([
        $studentId,
        $courseId,
        attendance_course_enrollment_access_type((string)($courseRow['access_type'] ?? 'attendance')),
      ]);

      $stmt = $pdo->prepare("
        INSERT IGNORE INTO student_lecture_enrollments (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
        SELECT ?, l.id, l.course_id, ?, NULL, NULL
        FROM lectures l
        WHERE l.course_id = ?
      ");
      $stmt->execute([
        $studentId,
        attendance_lecture_enrollment_access_type((string)($courseRow['access_type'] ?? 'attendance')),
        $courseId,
      ]);

      $pdo->commit();
      return;
    }

    $stmt = $pdo->prepare("
      SELECT l.id, l.course_id, c.access_type AS course_access_type
      FROM lectures l
      INNER JOIN courses c ON c.id = l.course_id
      WHERE l.id=?
      LIMIT 1
    ");
    $stmt->execute([$lectureId]);
    $lectureRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$lectureRow) return;
    $courseIdFromLecture = (int)($lectureRow['course_id'] ?? 0);
    $courseAccessType = (string)($lectureRow['course_access_type'] ?? 'attendance');
    if ($courseIdFromLecture <= 0) return;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      INSERT IGNORE INTO student_course_enrollments (student_id, course_id, access_type)
      VALUES (?, ?, ?)
    ");
    $stmt->execute([
      $studentId,
      $courseIdFromLecture,
      attendance_course_enrollment_access_type($courseAccessType),
    ]);

    $stmt = $pdo->prepare("
      INSERT IGNORE INTO student_lecture_enrollments
        (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
      VALUES (?, ?, ?, ?, NULL, NULL)
    ");
    $stmt->execute([
      $studentId,
      $lectureId,
      $courseIdFromLecture,
      attendance_lecture_enrollment_access_type($courseAccessType),
    ]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Attendance auto-enrollment failed: ' . $e->getMessage());
  }
}
function attendance_export_excel(string $filename, array $rows, string $type): void {
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Pragma: no-cache');
  header('Expires: 0');
  echo "\xEF\xBB\xBF";
  ?>
  <!doctype html>
  <html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>تصدير حضور الطلاب</title><style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;font-family:Tahoma,Arial}th{background:#f2f2f2}</style></head><body>
  <table>
    <thead><tr><th>م</th><th>اسم الطالب</th><th>الباركود</th><th>هاتف الطالب</th><th>السنتر</th><th>المجموعة</th><th>الحالة</th><th>آخر واجب</th><th>حالة الواجب</th><th>درجة الواجب</th><th>آخر امتحان</th><th>حالة الامتحان</th><th>درجة الامتحان</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $i => $row): ?>
        <tr>
          <td><?php echo (int)$i + 1; ?></td>
          <td><?php echo h((string)$row['full_name']); ?></td>
          <td><?php echo h((string)($row['barcode'] ?? '')); ?></td>
          <td><?php echo h((string)($row['student_phone'] ?? '')); ?></td>
          <td><?php echo h((string)($row['center_name'] ?? '')); ?></td>
          <td><?php echo h((string)($row['group_name'] ?? '')); ?></td>
          <td><?php echo h($type === 'present' ? 'حاضر' : 'غائب'); ?></td>
          <td><?php echo h((string)($row['assignment_name'] ?? '')); ?></td>
          <td><?php echo h((string)($row['assignment_status'] ?? '')); ?></td>
          <td><?php echo h((string)($row['assignment_score_text'] ?? '—')); ?></td>
          <td><?php echo h((string)($row['exam_name'] ?? '')); ?></td>
          <td><?php echo h((string)($row['exam_status'] ?? '')); ?></td>
          <td><?php echo h((string)($row['exam_score_text'] ?? '—')); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </body></html>
  <?php
  exit;
}
function attendance_render_table(array $rows, int $sessionId, int $highlightedStudentId, string $emptyMessage): void {
  if (!$rows) {
    echo '<div class="empty">' . h($emptyMessage) . '</div>';
    return;
  }
  ?>
  <div class="att-tableWrap">
    <table class="table att-table">
      <thead><tr><th>الطالب</th><th>الباركود</th><th>الهاتف</th><th>الحالة</th><th>آخر واجب</th><th>حالة الواجب</th><th>درجة الواجب</th><th>آخر امتحان</th><th>حالة الامتحان</th><th>درجة الامتحان</th><th>إجراء</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr <?php echo ($highlightedStudentId === (int)$row['id']) ? 'class="is-highlighted"' : ''; ?>>
            <td data-label="الطالب"><strong><?php echo h((string)$row['full_name']); ?></strong><div class="mini"><?php echo h((string)($row['center_name'] ?? '')); ?> / <?php echo h((string)($row['group_name'] ?? '')); ?></div></td>
            <td data-label="الباركود"><?php echo h((string)($row['barcode'] ?? '')); ?></td>
            <td data-label="الهاتف"><?php echo h((string)($row['student_phone'] ?? '')); ?></td>
            <td data-label="الحالة" class="<?php echo !empty($row['is_present']) ? 'badge-present' : 'badge-absent'; ?>"><?php echo h((string)$row['attendance_text']); ?></td>
            <td data-label="آخر واجب"><?php echo h((string)$row['assignment_name']); ?></td>
            <td data-label="حالة الواجب"><?php echo h((string)$row['assignment_status']); ?></td>
            <td data-label="درجة الواجب"><?php echo h((string)$row['assignment_score_text']); ?></td>
            <td data-label="آخر امتحان"><?php echo h((string)$row['exam_name']); ?></td>
            <td data-label="حالة الامتحان"><?php echo h((string)$row['exam_status']); ?></td>
            <td data-label="درجة الامتحان"><?php echo h((string)$row['exam_score_text']); ?></td>
            <td data-label="إجراء" class="actions">
              <div class="table-actions">
                <form method="post"><input type="hidden" name="action" value="mark_manual"><input type="hidden" name="session_id" value="<?php echo $sessionId; ?>"><input type="hidden" name="student_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="attendance_status" value="present"><button class="btn ghost" type="submit">✅ حاضر</button></form>
                <form method="post"><input type="hidden" name="action" value="mark_manual"><input type="hidden" name="session_id" value="<?php echo $sessionId; ?>"><input type="hidden" name="student_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="attendance_status" value="absent"><button class="btn danger" type="submit">❌ غائب</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'مشرف');
$allowedMenuKeys = get_allowed_menu_keys($pdo, $adminId, $adminRole);
$success = null;
$error = null;

$gradesList = $pdo->query('SELECT id, name FROM grades ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$centersList = $pdo->query('SELECT id, name FROM centers ORDER BY name ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$groupsList = $pdo->query("SELECT g.id, g.name, g.grade_id, g.center_id, gr.name AS grade_name, c.name AS center_name FROM `groups` g INNER JOIN grades gr ON gr.id=g.grade_id INNER JOIN centers c ON c.id=g.center_id ORDER BY g.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$coursesList = $pdo->query('SELECT c.id, c.name, c.grade_id, g.name AS grade_name FROM courses c INNER JOIN grades g ON g.id=c.grade_id ORDER BY c.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$lecturesList = $pdo->query('SELECT l.id, l.name, l.course_id, c.name AS course_name FROM lectures l INNER JOIN courses c ON c.id=l.course_id ORDER BY l.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$groupsMap = [];
$gradeCenterIdsMap = [];
foreach ($groupsList as $groupRow) {
  $groupId = (int)($groupRow['id'] ?? 0);
  $groupGradeId = (int)($groupRow['grade_id'] ?? 0);
  $groupCenterId = (int)($groupRow['center_id'] ?? 0);
  if ($groupId > 0) $groupsMap[$groupId] = $groupRow;
  if ($groupGradeId > 0 && $groupCenterId > 0) {
    $gradeCenterIdsMap[$groupGradeId][$groupCenterId] = true;
  }
}
$centerGradeIdsTextMap = [];
foreach ($centersList as $centerRow) {
  $centerId = (int)($centerRow['id'] ?? 0);
  if ($centerId <= 0) continue;
  $centerGradeIds = [];
  foreach ($gradeCenterIdsMap as $gradeKey => $centerIds) {
    if (!empty($centerIds[$centerId])) $centerGradeIds[] = (int)$gradeKey;
  }
  $centerGradeIdsTextMap[$centerId] = implode(',', $centerGradeIds);
}

if (($_POST['action'] ?? '') === 'create_session') {
  $title = trim((string)($_POST['title'] ?? ''));
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $centerId = (int)($_POST['center_id'] ?? 0);
  $groupId = (int)($_POST['group_id'] ?? 0);
  $courseId = (int)($_POST['course_id'] ?? 0);
  $lectureId = (int)($_POST['lecture_id'] ?? 0);
  $attendanceDate = trim((string)($_POST['attendance_date'] ?? date('Y-m-d')));
  if ($title === '') $error = 'اسم المحاضرة / الجلسة مطلوب.';
  elseif ($gradeId <= 0 || $groupId <= 0) $error = 'اختر الصف الدراسي والمجموعة أولاً.';
  elseif (!isset($groupsMap[$groupId])) $error = 'المجموعة المختارة غير متاحة.';
  else {
    if ((int)($groupsMap[$groupId]['grade_id'] ?? 0) !== $gradeId) {
      $error = 'المجموعة المختارة غير مسجلة داخل الصف الدراسي المحدد.';
    } else {
      $groupCenterId = (int)($groupsMap[$groupId]['center_id'] ?? 0);
      if ($centerId > 0 && $groupCenterId > 0 && $centerId !== $groupCenterId) {
        $error = 'السنتر المحدد لا يتبع المجموعة المختارة.';
      } elseif ($centerId <= 0 && $groupCenterId > 0) {
        $centerId = $groupCenterId;
      }
    }
  }
  if (!$error) {
    try {
      $stmt = $pdo->prepare('INSERT INTO attendance_sessions (title, grade_id, center_id, group_id, course_id, lecture_id, attendance_date, is_open, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
      $stmt->execute([$title, $gradeId, ($centerId > 0 ? $centerId : null), $groupId, ($courseId > 0 ? $courseId : null), ($lectureId > 0 ? $lectureId : null), $attendanceDate !== '' ? $attendanceDate : date('Y-m-d'), $adminId]);
      $newId = (int)$pdo->lastInsertId();
      header('Location: attendance.php?session_id=' . $newId . '&created=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر فتح جلسة الحضور الآن.';
    }
  }
}

if (($_POST['action'] ?? '') === 'close_session') {
  $sessionId = (int)($_POST['session_id'] ?? 0);
  if ($sessionId > 0) {
    $pdo->prepare('UPDATE attendance_sessions SET is_open=0 WHERE id=?')->execute([$sessionId]);
    header('Location: attendance.php?closed=1');
    exit;
  }
}

if (($_POST['action'] ?? '') === 'scan_barcode') {
  $sessionId = (int)($_POST['session_id'] ?? 0);
  $barcode = trim((string)($_POST['barcode'] ?? ''));
  $scanMethod = (string)($_POST['scan_method'] ?? 'barcode');
  $stmt = $pdo->prepare('SELECT * FROM attendance_sessions WHERE id=? LIMIT 1');
  $stmt->execute([$sessionId]);
  $sessionRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$sessionRow || empty($sessionRow['is_open'])) {
    $error = 'جلسة الحضور غير متاحة.';
  } elseif ($barcode === '') {
    $error = 'مرر باركود الطالب أولاً.';
  } else {
    $whereParts = ['barcode=?', 'group_id=?', 'grade_id=?', "status='سنتر'"];
    $params = [$barcode, (int)$sessionRow['group_id'], (int)$sessionRow['grade_id']];
    if ((int)($sessionRow['center_id'] ?? 0) > 0) {
      $whereParts[] = 'center_id = ?';
      $params[] = (int)$sessionRow['center_id'];
    }
    $studentWhereSql = implode(' AND ', $whereParts);
    $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE {$studentWhereSql} LIMIT 1");
    $stmt->execute($params);
    $studentRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$studentRow) {
      $error = 'الباركود غير مرتبط بطالب سنتر داخل هذه المجموعة.';
    } else {
      $stmt = $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, attendance_status, scan_method, scanned_at) VALUES (?, ?, 'present', ?, NOW()) ON DUPLICATE KEY UPDATE attendance_status='present', scan_method=VALUES(scan_method), scanned_at=NOW()");
      $stmt->execute([$sessionId, (int)$studentRow['id'], in_array($scanMethod, ['barcode', 'camera', 'manual'], true) ? $scanMethod : 'barcode']);
      attendance_auto_enroll_student($pdo, $sessionRow, (int)$studentRow['id']);
      header('Location: attendance.php?session_id=' . $sessionId . '&scanned=1&student=' . (int)$studentRow['id']);
      exit;
    }
  }
}

if (($_POST['action'] ?? '') === 'mark_manual') {
  $sessionId = (int)($_POST['session_id'] ?? 0);
  $studentId = (int)($_POST['student_id'] ?? 0);
  $attendanceStatus = (string)($_POST['attendance_status'] ?? 'present');
  if ($sessionId > 0 && $studentId > 0 && in_array($attendanceStatus, ['present', 'absent'], true)) {
    $stmt = $pdo->prepare('SELECT * FROM attendance_sessions WHERE id=? LIMIT 1');
    $stmt->execute([$sessionId]);
    $sessionRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare('INSERT INTO attendance_records (session_id, student_id, attendance_status, scan_method, scanned_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attendance_status=VALUES(attendance_status), scan_method=VALUES(scan_method), scanned_at=NOW()');
    $stmt->execute([$sessionId, $studentId, $attendanceStatus, 'manual']);
    if ($attendanceStatus === 'present' && $sessionRow) {
      attendance_auto_enroll_student($pdo, $sessionRow, $studentId);
    }
    header('Location: attendance.php?session_id=' . $sessionId . '&updated=1');
    exit;
  }
}

if (isset($_GET['created'])) $success = 'تم فتح جلسة الحضور بنجاح.';
if (isset($_GET['closed'])) $success = 'تم إغلاق جلسة الحضور.';
if (isset($_GET['scanned'])) $success = 'تم تسجيل حضور الطالب بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تحديث حالة الطالب.';

$sessions = $pdo->query(" 
  SELECT s.*, gr.name AS grade_name, c.name AS center_name, g.name AS group_name, co.name AS course_name, l.name AS lecture_name
  FROM attendance_sessions s
  INNER JOIN grades gr ON gr.id = s.grade_id
  INNER JOIN `groups` g ON g.id = s.group_id
  LEFT JOIN centers c ON c.id = s.center_id
  LEFT JOIN courses co ON co.id = s.course_id
  LEFT JOIN lectures l ON l.id = s.lecture_id
  ORDER BY s.id DESC
  LIMIT 60
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedSessionId = (int)($_GET['session_id'] ?? 0);
if ($selectedSessionId <= 0 && !empty($sessions)) {
  foreach ($sessions as $sessionItem) {
    if ((int)($sessionItem['is_open'] ?? 0) === 1) {
      $selectedSessionId = (int)$sessionItem['id'];
      break;
    }
  }
}
$selectedSession = null;
foreach ($sessions as $sessionItem) {
  if ((int)$sessionItem['id'] === $selectedSessionId) { $selectedSession = $sessionItem; break; }
}
$attendanceRows = $selectedSession ? attendance_fetch_session_students($pdo, $selectedSession) : [];
$presentRows = array_values(array_filter($attendanceRows, fn($r) => !empty($r['is_present'])));
$absentRows = array_values(array_filter($attendanceRows, fn($r) => empty($r['is_present'])));
$highlightedStudentId = (int)($_GET['student'] ?? 0);

if ($selectedSession && isset($_GET['export'])) {
  $type = (string)$_GET['export'];
  if (in_array($type, ['present', 'absent'], true)) {
    $rows = ($type === 'present') ? $presentRows : $absentRows;
    $filename = ($type === 'present' ? 'كشف_الحضور_' : 'كشف_الغياب_') . date('Y-m-d_H-i') . '.xls';
    attendance_export_excel($filename, $rows, $type);
  }
}

$menu = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],
  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php'],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php'],
  ['key' => 'grades', 'label' => 'الصفوف الدراسية', 'icon' => '🏫', 'href' => 'grades.php'],
  ['key' => 'centers', 'label' => 'السناتر', 'icon' => '🏢', 'href' => 'centers.php'],
  ['key' => 'groups', 'label' => 'المجموعات', 'icon' => '👥', 'href' => 'groups.php'],
  ['key' => 'students', 'label' => 'الطلاب', 'icon' => '🧑‍🎓', 'href' => 'students.php'],
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
  ['key' => 'attendance', 'label' => 'حضور الطلاب', 'icon' => '🧾', 'href' => 'attendance.php', 'active' => true],
  ['key' => 'facebook', 'label' => 'فيس بوك المنصة', 'icon' => '📘', 'href' => 'platform-posts.php'],
  ['key' => 'chat', 'label' => 'شات الطلاب', 'icon' => '💬', 'href' => 'student-conversations.php'],
  ['key' => 'settings', 'label' => 'الإعدادات', 'icon' => '⚙️', 'href' => 'settings.php'],
  ['key' => 'logout', 'label' => 'تسجيل الخروج', 'icon' => '🚪', 'href' => 'logout.php', 'danger' => true],
];
if ($adminRole !== 'مدير') {
  $menu = array_values(array_filter($menu, fn($item) => menu_visible($allowedMenuKeys, (string)($item['key'] ?? ''), $adminRole)));
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>حضور الطلاب - <?php echo h($platformName); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css"><link rel="stylesheet" href="assets/css/centers.css">
  <style>
    .att-grid{display:grid;grid-template-columns:minmax(320px,420px) minmax(0,1fr);gap:16px;align-items:start}.att-grid>*,.att-card{min-width:0}.att-card{background:var(--panel);border:1px solid var(--line);border-radius:22px;padding:18px;box-shadow:0 12px 28px rgba(0,0,0,.08)}
    .att-form{display:grid;gap:12px}.att-form input,.att-form select{width:100%;border:1px solid var(--line);border-radius:14px;padding:12px;background:var(--panel);color:var(--text);font:inherit}
    .att-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.att-summary{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0;align-items:center}.pill{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 12px;border-radius:999px;background:rgba(59,130,246,.08);font-weight:1000;border:1px solid transparent}
    .pill-action{cursor:pointer;font:inherit;color:inherit;border-color:var(--line);background:rgba(239,68,68,.08)}.pill-action:hover{filter:brightness(1.02)}.pill-present{background:rgba(34,197,94,.12)}
    .scan-box{display:grid;gap:10px}.scan-controls{display:flex;gap:10px;flex-wrap:wrap;align-items:stretch}.scan-controls>*{min-width:0}.scan-video{width:100%;border-radius:18px;border:1px solid var(--line);background:#111;display:none}.session-list{display:grid;gap:12px;margin-top:12px}.session-item{display:block;text-decoration:none;color:inherit;padding:14px;border-radius:16px;border:1px solid var(--line);background:rgba(15,23,42,.04)}.session-item.is-active{border-color:var(--brand);background:rgba(59,130,246,.08)}
    #barcodeInput{flex:1 1 240px;min-width:0 !important;width:100%}.attendance-section{display:grid;gap:12px;margin-top:16px}.attendance-section[hidden]{display:none !important}.att-section-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.att-section-title{margin:0;font-size:20px}.att-panel-close{margin-inline-start:auto}
    .att-tableWrap{overflow:auto;max-width:100%;border:1px solid var(--line);border-radius:18px}.att-table{min-width:960px}.att-table td,.att-table th{white-space:nowrap}.att-table tr.is-highlighted{background:rgba(34,197,94,.08)}.badge-present{color:#16a34a;font-weight:1000}.badge-absent{color:#dc2626;font-weight:1000}.mini{color:var(--muted);font-weight:900}.table-actions{display:flex;gap:8px;flex-wrap:wrap}.empty{padding:20px;border:1px dashed var(--line);border-radius:18px;color:var(--muted);font-weight:900;text-align:center}
    @media (max-width:1200px){.att-grid{grid-template-columns:1fr}}@media (max-width:720px){.att-row,.scan-controls{grid-template-columns:1fr;display:grid}.att-summary{flex-direction:column;align-items:stretch}.pill,.pill-action,.att-summary .btn,.scan-controls .btn{width:100%}.att-section-title{font-size:18px}.table td.actions .table-actions{flex-direction:column}.table td.actions .table-actions form,.table td.actions .table-actions .btn{width:100%}}
  </style>
</head>
<body class="app" data-theme="auto">
<div class="bg" aria-hidden="true"><div class="bg-grad"></div><div class="bg-noise"></div></div>
<header class="topbar"><button class="burger" id="burger" type="button" aria-label="فتح القائمة">☰</button><div class="brand"><?php if (!empty($logo)) : ?><img class="brand-logo" src="<?php echo h($logo); ?>" alt="Logo"><?php else: ?><div class="brand-fallback" aria-hidden="true"></div><?php endif; ?><div class="brand-text"><div class="brand-name"><?php echo h($platformName); ?></div><div class="brand-sub">لوحة التحكم</div></div></div><div class="top-actions"><a class="back-btn" href="dashboard.php">🏠 الرجوع للوحة التحكم</a><div class="theme-emoji" title="تبديل الوضع"><span class="emoji">🌞</span><label class="emoji-switch"><input id="themeSwitch" type="checkbox" /><span class="emoji-slider"></span></label><span class="emoji">🌚</span></div></div></header>
<div class="layout">
  <aside class="sidebar" id="sidebar" aria-label="القائمة الجانبية"><div class="sidebar-head"><div class="sidebar-title">🧭 التنقل</div></div><nav class="nav"><?php foreach ($menu as $item): ?><?php $cls='nav-item'; if(!empty($item['active'])) $cls.=' active'; if(!empty($item['danger'])) $cls.=' danger'; ?><a class="<?php echo $cls; ?>" href="<?php echo h((string)$item['href']); ?>"><span class="nav-icon"><?php echo h((string)$item['icon']); ?></span><span class="nav-label"><?php echo h((string)$item['label']); ?></span></a><?php endforeach; ?></nav></aside>
  <main class="main">
    <div class="page-head"><h1>🧾 حضور الطلاب</h1><p class="muted">افتح جلسة حضور مرتبطة بالصف والسنتر والمجموعة، ثم سجل الحضور بالباركود أو يدويًا، وصدّر كشوف الحضور والغياب كملف Excel حقيقي بامتداد XLS.</p></div>
    <?php if ($success): ?><div class="alert success"><?php echo h($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?php echo h($error); ?></div><?php endif; ?>

    <div class="att-grid">
      <div class="att-card">
        <h3 style="margin-top:0;">➕ فتح جلسة حضور جديدة</h3>
        <form method="post" class="att-form">
          <input type="hidden" name="action" value="create_session">
          <input type="text" name="title" placeholder="مثال: حصة الثلاثاء 1" required>
          <div class="att-row">
            <select name="grade_id" id="attendanceGradeSelect" required><option value="">اختر الصف الدراسي</option><?php foreach ($gradesList as $g): ?><option value="<?php echo (int)$g['id']; ?>"><?php echo h((string)$g['name']); ?></option><?php endforeach; ?></select>
            <select name="center_id" id="attendanceCenterSelect"><option value="">السنتر (تلقائي من المجموعة أو اختياري)</option><?php foreach ($centersList as $c): ?><option value="<?php echo (int)$c['id']; ?>" data-grade-ids="<?php echo h((string)($centerGradeIdsTextMap[(int)$c['id']] ?? '')); ?>"><?php echo h((string)$c['name']); ?></option><?php endforeach; ?></select>
          </div>
          <div class="att-row">
            <select name="group_id" id="attendanceGroupSelect" required><option value="">اختر المجموعة</option><?php foreach ($groupsList as $g): ?><option value="<?php echo (int)$g['id']; ?>" data-grade-id="<?php echo (int)$g['grade_id']; ?>" data-center-id="<?php echo (int)$g['center_id']; ?>"><?php echo h((string)$g['name']); ?> — <?php echo h((string)$g['grade_name']); ?> / <?php echo h((string)$g['center_name']); ?></option><?php endforeach; ?></select>
            <input type="date" name="attendance_date" value="<?php echo h(date('Y-m-d')); ?>">
          </div>
          <div class="att-row">
            <select name="course_id"><option value="">فتح كورس محدد (اختياري)</option><?php foreach ($coursesList as $course): ?><option value="<?php echo (int)$course['id']; ?>"><?php echo h((string)$course['name']); ?> — <?php echo h((string)$course['grade_name']); ?></option><?php endforeach; ?></select>
            <select name="lecture_id"><option value="">فتح محاضرة محددة (اختياري)</option><?php foreach ($lecturesList as $lecture): ?><option value="<?php echo (int)$lecture['id']; ?>"><?php echo h((string)$lecture['name']); ?> — <?php echo h((string)$lecture['course_name']); ?></option><?php endforeach; ?></select>
          </div>
          <button class="btn" type="submit">🚀 فتح جلسة الحضور</button>
        </form>

        <h3 style="margin:20px 0 10px;">🗂️ الجلسات المفتوحة والسابقة</h3>
        <div class="session-list">
          <?php if (!$sessions): ?><div class="empty">لا توجد جلسات حضور حتى الآن.</div><?php endif; ?>
          <?php foreach ($sessions as $sessionItem): ?>
            <?php $active = ((int)$sessionItem['id'] === $selectedSessionId); ?>
            <a class="session-item <?php echo $active ? 'is-active' : ''; ?>" href="attendance.php?session_id=<?php echo (int)$sessionItem['id']; ?>">
              <div style="font-weight:1000;"><?php echo h((string)$sessionItem['title']); ?></div>
              <div class="mini">🏫 <?php echo h((string)$sessionItem['grade_name']); ?> • 🏢 <?php echo h((string)($sessionItem['center_name'] ?? '—')); ?> • 👥 <?php echo h((string)$sessionItem['group_name']); ?></div>
              <div class="mini">📚 <?php echo h((string)($sessionItem['course_name'] ?? 'غير مرتبط بكورس')); ?> • 🧑‍🏫 <?php echo h((string)($sessionItem['lecture_name'] ?? 'غير مرتبط بمحاضرة')); ?></div>
              <div class="mini">🗓️ <?php echo h((string)$sessionItem['attendance_date']); ?> — <?php echo !empty($sessionItem['is_open']) ? 'مفتوحة' : 'مغلقة'; ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="att-card">
        <?php if (!$selectedSession): ?>
          <div class="empty">اختر جلسة حضور من القائمة أو أنشئ جلسة جديدة لبدء تسجيل الطلاب.</div>
        <?php else: ?>
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start;">
            <div>
              <h3 style="margin:0;"><?php echo h((string)$selectedSession['title']); ?></h3>
              <div class="mini">🏫 <?php echo h((string)$selectedSession['grade_name']); ?> • 🏢 <?php echo h((string)($selectedSession['center_name'] ?? '—')); ?> • 👥 <?php echo h((string)$selectedSession['group_name']); ?></div>
              <div class="mini">📚 <?php echo h((string)($selectedSession['course_name'] ?? 'غير مرتبط بكورس')); ?> • 🧑‍🏫 <?php echo h((string)($selectedSession['lecture_name'] ?? 'غير مرتبط بمحاضرة')); ?></div>
            </div>
            <?php if (!empty($selectedSession['is_open'])): ?>
              <form method="post"><input type="hidden" name="action" value="close_session"><input type="hidden" name="session_id" value="<?php echo (int)$selectedSession['id']; ?>"><button class="btn danger" type="submit">🔒 إغلاق الجلسة</button></form>
            <?php endif; ?>
          </div>

          <div class="att-summary">
            <span class="pill pill-present">✅ الحاضرون: <?php echo count($presentRows); ?></span>
            <button class="pill pill-action" type="button" id="absentToggle" aria-expanded="false" aria-controls="absentStudentsPanel">❌ الغائبون: <?php echo count($absentRows); ?></button>
            <a class="btn ghost" href="attendance.php?session_id=<?php echo (int)$selectedSession['id']; ?>&export=present">⬇️ كشف الحضور Excel</a>
            <a class="btn ghost" href="attendance.php?session_id=<?php echo (int)$selectedSession['id']; ?>&export=absent">⬇️ كشف الغياب Excel</a>
          </div>

          <?php if (!empty($selectedSession['is_open'])): ?>
          <div class="scan-box">
            <form method="post" id="scanForm">
              <input type="hidden" name="action" value="scan_barcode">
              <input type="hidden" name="session_id" value="<?php echo (int)$selectedSession['id']; ?>">
              <input type="hidden" name="scan_method" id="scanMethod" value="barcode">
              <div class="scan-controls">
                <input id="barcodeInput" type="text" name="barcode" placeholder="مرر الباركود هنا ثم Enter" style="flex:1;min-width:240px;border:1px solid var(--line);border-radius:14px;padding:12px;background:var(--panel);color:var(--text);font:inherit;" autofocus>
                <button class="btn" type="submit">📷/🧾 تسجيل الحضور</button>
                <button class="btn ghost" type="button" id="toggleCamera">📱 تشغيل كاميرا الموبايل</button>
              </div>
            </form>
            <video class="scan-video" id="cameraPreview" playsinline muted></video>
            <div class="mini">يدعم قارئ الباركود المتصل بالكمبيوتر مباشرة عبر مربع الإدخال، كما يحاول استخدام كاميرا الموبايل إذا كان المتصفح يدعم BarcodeDetector.</div>
          </div>
          <?php endif; ?>

          <section class="attendance-section">
            <div class="att-section-head">
              <h4 class="att-section-title">✅ الطلاب الحاضرون</h4>
              <p class="mini" style="margin:0;">يظهر هذا الجدول أسفل الباركود للطلاب الذين تم تسجيل حضورهم فقط.</p>
            </div>
            <?php attendance_render_table($presentRows, (int)$selectedSession['id'], $highlightedStudentId, $attendanceRows ? 'لم يتم تسجيل أي طالب حاضر بعد.' : 'لا يوجد طلاب سنتر مسجلون داخل هذه المجموعة.'); ?>
          </section>

          <section class="attendance-section" id="absentStudentsPanel" hidden>
            <div class="att-section-head">
              <h4 class="att-section-title">❌ الطلاب الغائبون</h4>
              <button class="btn ghost att-panel-close" type="button" id="closeAbsentPanel">إغلاق جدول الغياب</button>
            </div>
            <?php attendance_render_table($absentRows, (int)$selectedSession['id'], $highlightedStudentId, $attendanceRows ? 'لا يوجد طلاب غائبون حالياً.' : 'لا يوجد طلاب سنتر مسجلون داخل هذه المجموعة.'); ?>
          </section>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<div class="backdrop" id="backdrop" aria-hidden="true"></div>
<script>
(function () {
  const root = document.body;
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

  const burger = document.getElementById('burger');
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('backdrop');

  function isMobile() {
    return window.matchMedia && window.matchMedia('(max-width:980px)').matches;
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
    if (isMobile()) {
      closeSidebar();
    } else {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
      document.body.style.overflow = '';
    }
  }

  syncInitial();
  burger && burger.addEventListener('click', (e) => {
    e.preventDefault();
    if (sidebar.classList.contains('open')) closeSidebar();
    else openSidebar();
  });
  backdrop && backdrop.addEventListener('click', closeSidebar);
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebar();
  });
  window.addEventListener('resize', syncInitial);

  const gradeSelect = document.getElementById('attendanceGradeSelect');
  const centerSelect = document.getElementById('attendanceCenterSelect');
  const groupSelect = document.getElementById('attendanceGroupSelect');

  function syncAttendanceOptions() {
    const gradeValue = gradeSelect && gradeSelect.value ? gradeSelect.value : '';
    const centerValue = centerSelect && centerSelect.value ? centerSelect.value : '';

    if (centerSelect) {
      Array.from(centerSelect.options).forEach((option, index) => {
        if (index === 0) return;
        const grades = (option.dataset.gradeIds || '').split(',').filter(Boolean);
        option.hidden = !!gradeValue && !grades.includes(gradeValue);
      });
      if (centerSelect.selectedOptions.length && centerSelect.selectedOptions[0] && centerSelect.selectedOptions[0].hidden) {
        centerSelect.value = '';
      }
    }

    if (groupSelect) {
      Array.from(groupSelect.options).forEach((option, index) => {
        if (index === 0) return;
        const optionGrade = option.dataset.gradeId || '';
        const optionCenter = option.dataset.centerId || '';
        option.hidden = (!!gradeValue && optionGrade !== gradeValue) || (!!centerValue && optionCenter !== centerValue);
      });
      if (groupSelect.selectedOptions.length && groupSelect.selectedOptions[0] && groupSelect.selectedOptions[0].hidden) {
        groupSelect.value = '';
      }
    }
  }

  gradeSelect && gradeSelect.addEventListener('change', () => {
    if (centerSelect) centerSelect.value = '';
    syncAttendanceOptions();
  });
  centerSelect && centerSelect.addEventListener('change', syncAttendanceOptions);
  groupSelect && groupSelect.addEventListener('change', () => {
    const selectedOption = groupSelect.selectedOptions[0];
    if (!selectedOption || !centerSelect) return;
    const groupCenterId = selectedOption.dataset.centerId || '';
    if (groupCenterId) centerSelect.value = groupCenterId;
    syncAttendanceOptions();
  });
  syncAttendanceOptions();

  const barcodeInput = document.getElementById('barcodeInput');
  const toggleCamera = document.getElementById('toggleCamera');
  const cameraPreview = document.getElementById('cameraPreview');
  const scanMethod = document.getElementById('scanMethod');
  const scanForm = document.getElementById('scanForm');
  let mediaStream = null;
  let detector = null;
  let scanTimer = null;

  if (barcodeInput) barcodeInput.focus();

  async function stopCamera() {
    if (scanTimer) {
      clearInterval(scanTimer);
      scanTimer = null;
    }
    if (mediaStream) {
      mediaStream.getTracks().forEach((track) => track.stop());
      mediaStream = null;
    }
    if (cameraPreview) {
      cameraPreview.pause();
      cameraPreview.srcObject = null;
      cameraPreview.style.display = 'none';
    }
    if (toggleCamera) toggleCamera.textContent = '📱 تشغيل كاميرا الموبايل';
  }

  async function startCamera() {
    if (!cameraPreview || !toggleCamera) return;
    if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
      alert('الكاميرا غير مدعومة في هذا المتصفح.');
      return;
    }
    if (!('BarcodeDetector' in window)) {
      alert('المتصفح لا يدعم BarcodeDetector. استخدم قارئ الباركود أو الإدخال اليدوي.');
      return;
    }

    detector = new BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'qr_code'] });
    mediaStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    cameraPreview.srcObject = mediaStream;
    cameraPreview.style.display = 'block';
    await cameraPreview.play();
    toggleCamera.textContent = '⛔ إيقاف الكاميرا';
    scanTimer = setInterval(async () => {
      if (!detector || !cameraPreview) return;
      try {
        const barcodes = await detector.detect(cameraPreview);
        if (barcodes && barcodes.length) {
          const raw = (barcodes[0].rawValue || '').trim();
          if (raw && barcodeInput) {
            barcodeInput.value = raw;
            if (scanMethod) scanMethod.value = 'camera';
            stopCamera();
            if (scanForm) scanForm.submit();
          }
        }
      } catch (err) {}
    }, 800);
  }

  if (toggleCamera) {
    toggleCamera.addEventListener('click', async () => {
      if (mediaStream) {
        await stopCamera();
        return;
      }
      try {
        await startCamera();
      } catch (err) {
        await stopCamera();
        alert('تعذر تشغيل الكاميرا الآن.');
      }
    });
  }

  barcodeInput && barcodeInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && scanMethod) scanMethod.value = 'barcode';
  });

  const absentToggle = document.getElementById('absentToggle');
  const absentPanel = document.getElementById('absentStudentsPanel');
  const closeAbsentPanel = document.getElementById('closeAbsentPanel');

  function setAbsentPanel(open) {
    if (!absentPanel || !absentToggle) return;
    absentPanel.hidden = !open;
    absentToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) absentPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  absentToggle && absentToggle.addEventListener('click', () => {
    setAbsentPanel(absentPanel ? absentPanel.hidden : false);
  });
  closeAbsentPanel && closeAbsentPanel.addEventListener('click', () => setAbsentPanel(false));
})();
</script>
</body>
</html>
