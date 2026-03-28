<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/../inc/platform_features.php';
require __DIR__ . '/../students/inc/assessments.php';

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
  CREATE TABLE IF NOT EXISTS assignments (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    grade_id INT(10) UNSIGNED NOT NULL,
    bank_id INT(10) UNSIGNED NOT NULL,
    duration_minutes INT(10) UNSIGNED NOT NULL DEFAULT 10,
    questions_total INT(10) UNSIGNED NOT NULL DEFAULT 10,
    questions_per_student INT(10) UNSIGNED NOT NULL DEFAULT 10,
    created_by_admin_id INT(10) UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assign_grade (grade_id),
    KEY idx_assign_bank (bank_id),
    CONSTRAINT fk_assign_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_bank FOREIGN KEY (bank_id) REFERENCES assignment_question_banks(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
student_assessment_ensure_attempt_tables($pdo);

/* =========================
   Lists
   ========================= */
$gradesList = $pdo->query("SELECT id, name FROM grades ORDER BY sort_order ASC, id ASC")->fetchAll();
$gradesMap = [];
foreach ($gradesList as $g) $gradesMap[(int)$g['id']] = (string)$g['name'];

// all banks (we'll filter in UI by grade)
$banksList = $pdo->query("
  SELECT b.id, b.grade_id, b.name, g.name AS grade_name,
         (SELECT COUNT(*) FROM assignment_questions q WHERE q.bank_id=b.id) AS bank_questions_count
  FROM assignment_question_banks b
  INNER JOIN grades g ON g.id=b.grade_id
  ORDER BY b.id DESC
")->fetchAll();
$banksByGrade = [];
foreach ($banksList as $b) {
  $gid = (int)$b['grade_id'];
  if (!isset($banksByGrade[$gid])) $banksByGrade[$gid] = [];
  $banksByGrade[$gid][] = $b;
}

/* =========================
   Helpers
   ========================= */
function normalize_int($v, int $min, int $max): int {
  $n = (int)$v;
  if ($n < $min) $n = $min;
  if ($n > $max) $n = $max;
  return $n;
}

function fetch_bank_questions_count(PDO $pdo, int $bankId): int {
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM assignment_questions WHERE bank_id=?");
  $stmt->execute([$bankId]);
  $row = $stmt->fetch();
  return (int)($row['c'] ?? 0);
}

function assignment_fetch_preview_payload(PDO $pdo, int $assignmentId): array {
  // get assignment + bank
  $stmt = $pdo->prepare("
    SELECT a.*,
           g.name AS grade_name,
           b.name AS bank_name
    FROM assignments a
    INNER JOIN grades g ON g.id=a.grade_id
    INNER JOIN assignment_question_banks b ON b.id=a.bank_id
    WHERE a.id=? LIMIT 1
  ");
  $stmt->execute([$assignmentId]);
  $a = $stmt->fetch();
  if (!$a) return ['ok' => false, 'error' => 'Assignment not found'];

  $bankId = (int)$a['bank_id'];
  $totalInBank = fetch_bank_questions_count($pdo, $bankId);

  $questionsTotal = (int)$a['questions_total'];
  $perStudent = (int)$a['questions_per_student'];

  if ($questionsTotal > $totalInBank) $questionsTotal = $totalInBank;
  if ($perStudent > $questionsTotal) $perStudent = $questionsTotal;
  if ($perStudent < 1) $perStudent = 1;

  // Random sample of questions (per preview)
  // Note: ORDER BY RAND is fine for moderate size. If huge banks, optimize later.
  $stmt = $pdo->prepare("
    SELECT *
    FROM assignment_questions
    WHERE bank_id=?
    ORDER BY RAND()
    LIMIT {$perStudent}
  ");
  $stmt->execute([$bankId]);
  $qs = $stmt->fetchAll();

  $qIds = array_map(fn($r) => (int)$r['id'], $qs);
  $choicesByQ = [];

  if ($qIds) {
    $in = implode(',', array_fill(0, count($qIds), '?'));
    $stmt = $pdo->prepare("
      SELECT *
      FROM assignment_question_choices
      WHERE question_id IN ($in)
      ORDER BY question_id ASC, choice_index ASC
    ");
    $stmt->execute($qIds);
    $choices = $stmt->fetchAll();
    foreach ($choices as $c) {
      $qid = (int)$c['question_id'];
      if (!isset($choicesByQ[$qid])) $choicesByQ[$qid] = [];
      $choicesByQ[$qid][] = $c;
    }
  }

  // attach choices + correct indices
  $outQuestions = [];
  foreach ($qs as $q) {
    $qid = (int)$q['id'];
    $ch = $choicesByQ[$qid] ?? [];

    $correct = [];
    foreach ($ch as $c) {
      if ((int)$c['is_correct'] === 1) $correct[] = (int)$c['choice_index'];
    }
    sort($correct);

    $outQuestions[] = [
      'q' => $q,
      'choices' => $ch,
      'correct' => $correct
    ];
  }

  return [
    'ok' => true,
    'assignment' => $a,
    'bank_total' => $totalInBank,
    'questions_total' => $questionsTotal,
    'questions_per_student' => $perStudent,
    'questions' => $outQuestions,
  ];
}

/* =========================
   CRUD
   ========================= */
$success = null;
$error = null;

/* CREATE / UPDATE */
if (($_POST['action'] ?? '') === 'save_assignment') {
  $id = (int)($_POST['id'] ?? 0);
  $isEdit = ($id > 0);

  $name = trim((string)($_POST['name'] ?? ''));
  $gradeId = (int)($_POST['grade_id'] ?? 0);
  $bankId = (int)($_POST['bank_id'] ?? 0);

  $duration = normalize_int($_POST['duration_minutes'] ?? 10, 1, 1000000);
  $questionsTotal = normalize_int($_POST['questions_total'] ?? 10, 1, 1000000);
  $questionsPerStudent = normalize_int($_POST['questions_per_student'] ?? 10, 1, 1000000);

  if ($name === '') $error = 'من فضلك اكتب اسم الواجب.';
  elseif ($gradeId <= 0 || !isset($gradesMap[$gradeId])) $error = 'من ف��لك اختر الصف الدراسي.';
  else {
    // validate bank belongs to grade + counts
    $stmt = $pdo->prepare("SELECT id, grade_id FROM assignment_question_banks WHERE id=? LIMIT 1");
    $stmt->execute([$bankId]);
    $bank = $stmt->fetch();

    if (!$bank) $error = 'من فضلك اختر بنك أسئلة صحيح.';
    elseif ((int)$bank['grade_id'] !== $gradeId) $error = 'بنك الأسئلة لا يتبع الصف الدراسي المختار.';
    else {
      $bankCount = fetch_bank_questions_count($pdo, $bankId);
      if ($bankCount <= 0) {
        $error = 'بنك الأسئلة المختار لا يحتوي على أسئلة. من فضلك أضف أسئلة أولاً.';
      } else {
        if ($questionsTotal > $bankCount) $error = 'عدد أسئلة الواجب لا يمكن أن يكون أ��بر من عدد أسئلة البنك.';
        elseif ($questionsPerStudent > $questionsTotal) $error = 'عدد أسئلة الواجب للطالب يجب ألا يزيد عن عدد أسئلة الواجب.';
      }
    }
  }

  if (!$error) {
    try {
      if (!$isEdit) {
        $stmt = $pdo->prepare("
          INSERT INTO assignments
            (name, grade_id, bank_id, duration_minutes, questions_total, questions_per_student, created_by_admin_id)
          VALUES
            (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
          $name, $gradeId, $bankId, $duration, $questionsTotal, $questionsPerStudent,
          $adminId > 0 ? $adminId : null
        ]);
        header('Location: assignments.php?added=1');
        exit;
      } else {
        $stmt = $pdo->prepare("
          UPDATE assignments
          SET name=?, grade_id=?, bank_id=?, duration_minutes=?, questions_total=?, questions_per_student=?
          WHERE id=?
        ");
        $stmt->execute([
          $name, $gradeId, $bankId, $duration, $questionsTotal, $questionsPerStudent, $id
        ]);
        header('Location: assignments.php?updated=1');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'تعذر حفظ الواجب.';
    }
  }
}

/* DELETE */
if (($_POST['action'] ?? '') === 'delete_assignment') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) $error = 'طلب غير صالح.';
  else {
    try {
      $stmt = $pdo->prepare("DELETE FROM assignments WHERE id=?");
      $stmt->execute([$id]);
      header('Location: assignments.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف الواجب.';
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = '✅ تمت إضافة الواجب بنجاح.';
if (isset($_GET['updated'])) $success = '💾 تم تعديل الواجب بنجاح.';
if (isset($_GET['deleted'])) $success = '🗑️ تم حذف الواجب بنجاح.';

/* =========================
   Preview JSON API (AJAX)
   ========================= */
if (($_GET['action'] ?? '') === 'preview_json') {
  header('Content-Type: application/json; charset=utf-8');

  $aid = (int)($_GET['assignment_id'] ?? 0);
  if ($aid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'assignment_id is required'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $payload = assignment_fetch_preview_payload($pdo, $aid);
  if (empty($payload['ok'])) {
    http_response_code(404);
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function assignment_fetch_result_rows(PDO $pdo, int $assignmentId): array {
  $stmt = $pdo->prepare("
    SELECT a.id, a.name, a.grade_id, g.name AS grade_name
    FROM assignments a
    INNER JOIN grades g ON g.id = a.grade_id
    WHERE a.id = ?
    LIMIT 1
  ");
  $stmt->execute([$assignmentId]);
  $assignment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$assignment) return ['assignment' => null, 'rows' => [], 'solved' => [], 'unsolved' => []];

  $stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.student_phone, s.barcode,
           att.status AS attempt_status, att.score, att.max_score, att.started_at, att.submitted_at
    FROM students s
    LEFT JOIN (
      SELECT aa.*
      FROM assignment_attempts aa
      INNER JOIN (
        SELECT student_id, MAX(id) AS max_id
        FROM assignment_attempts
        WHERE assignment_id = ?
        GROUP BY student_id
      ) latest ON latest.max_id = aa.id
    ) att ON att.student_id = s.id
    WHERE s.grade_id = ? AND s.is_active = 1
    ORDER BY s.full_name ASC
  ");
  $stmt->execute([$assignmentId, (int)$assignment['grade_id']]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $solved = [];
  $unsolved = [];
  foreach ($rows as &$row) {
    $status = (string)($row['attempt_status'] ?? '');
    $row['status_label'] = platform_attempt_status_label($status);
    $row['score_text'] = ($status === 'submitted' || $status === 'expired')
      ? ((float)($row['score'] ?? 0) . ' / ' . (float)($row['max_score'] ?? 0))
      : '—';
    if (in_array($status, ['submitted', 'expired'], true)) $solved[] = $row;
    else $unsolved[] = $row;
  }
  unset($row);

  return ['assignment' => $assignment, 'rows' => $rows, 'solved' => $solved, 'unsolved' => $unsolved];
}

function assignment_export_result_rows(string $filename, string $title, array $rows): void {
  header("Content-Type: application/vnd.ms-excel; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
  header("Pragma: no-cache");
  header("Expires: 0");
  echo "\xEF\xBB\xBF";
  ?>
  <!doctype html>
  <html lang="ar" dir="rtl">
  <head>
    <meta charset="utf-8">
    <title><?php echo h($title); ?></title>
    <style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;font-family:Tahoma,Arial}th{background:#f2f2f2}</style>
  </head>
  <body>
    <table>
      <thead>
        <tr>
          <th>م</th>
          <th>اسم الطالب</th>
          <th>الهاتف</th>
          <th>الباركود</th>
          <th>الحالة</th>
          <th>الدرجة</th>
          <th>تاريخ البدء</th>
          <th>تاريخ التسليم</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $row): ?>
          <tr>
            <td><?php echo (int)$i + 1; ?></td>
            <td><?php echo h((string)$row['full_name']); ?></td>
            <td><?php echo h((string)($row['student_phone'] ?? '')); ?></td>
            <td><?php echo h((string)($row['barcode'] ?? '')); ?></td>
            <td><?php echo h((string)$row['status_label']); ?></td>
            <td><?php echo h((string)$row['score_text']); ?></td>
            <td><?php echo h((string)($row['started_at'] ?? '')); ?></td>
            <td><?php echo h((string)($row['submitted_at'] ?? '')); ?></td>
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
   Fetch assignments list
   ========================= */
$assignments = $pdo->query("
  SELECT
    a.*,
    g.name AS grade_name,
    b.name AS bank_name,
    (SELECT COUNT(*) FROM assignment_questions q WHERE q.bank_id=a.bank_id) AS bank_questions_count
  FROM assignments a
  INNER JOIN grades g ON g.id=a.grade_id
  INNER JOIN assignment_question_banks b ON b.id=a.bank_id
  ORDER BY a.id DESC
")->fetchAll();
$totalAssignments = count($assignments);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id=? LIMIT 1");
  $stmt->execute([$editId]);
  $editRow = $stmt->fetch() ?: null;
}

$resultsAssignmentId = (int)($_GET['results_assignment'] ?? 0);
$resultsView = (string)($_GET['results_view'] ?? 'solved');
if (!in_array($resultsView, ['solved', 'unsolved'], true)) $resultsView = 'solved';
$resultsPayload = ['assignment' => null, 'rows' => [], 'solved' => [], 'unsolved' => []];
if ($resultsAssignmentId > 0) {
  $resultsPayload = assignment_fetch_result_rows($pdo, $resultsAssignmentId);
  if (!empty($_GET['export_results']) && !empty($resultsPayload['assignment'])) {
    $exportType = (string)$_GET['export_results'];
    if (in_array($exportType, ['solved', 'unsolved'], true)) {
      $rows = $exportType === 'solved' ? $resultsPayload['solved'] : $resultsPayload['unsolved'];
      $filename = ($exportType === 'solved' ? 'طلاب_قاموا_بحل_الواجب_' : 'طلاب_لم_يحلوا_الواجب_') . date('Y-m-d_H-i') . '.xls';
      assignment_export_result_rows($filename, 'نتائج الواجب', $rows);
    }
  }
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
  ['key' => 'assignments', 'label' => 'الواجبات', 'icon' => '📌', 'href' => 'assignments.php', 'active' => true],

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
  <title>الواجبات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/assignments.css">
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
      <section class="as-hero">
        <div class="as-hero-title">
          <h1>📌 الواجبات</h1>
        </div>

        <div class="as-metrics">
          <div class="metric">
            <div class="metric-ico">📌</div>
            <div class="metric-meta">
              <div class="metric-label">عدد الواجبات</div>
              <div class="metric-val"><?php echo number_format($totalAssignments); ?></div>
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
            <h2><?php echo $editRow ? 'تعديل واجب' : 'إضافة واجب جديد'; ?></h2>
          </div>
          <?php if ($editRow): ?>
            <a class="btn ghost" href="assignments.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="as-form" autocomplete="off" id="assignmentForm">
          <input type="hidden" name="action" value="save_assignment">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">اسم الواجب</span>
            <input class="input2" name="name" required
              value="<?php echo $editRow ? h((string)$editRow['name']) : ''; ?>"
              placeholder="مثال: واجب الوحدة الأولى - كيمياء" />
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
            <?php if (!$gradesList): ?>
              <div class="as-hint">لا يوجد صفوف — أضف صف أولاً من "الصفوف الدراسية".</div>
            <?php endif; ?>
          </label>

          <label class="field">
            <span class="label">بنك الأسئلة (حسب الصف)</span>
            <select class="input2 select-pro" name="bank_id" id="bankSelect" required>
              <option value="0">— اختر بنك الأسئلة —</option>
            </select>
          </label>

          <label class="field">
            <span class="label">وقت حل الواجب (دقيقة)</span>
            <input class="input2" type="number" min="1" step="1" name="duration_minutes" required
              value="<?php echo $editRow ? (int)$editRow['duration_minutes'] : 10; ?>">
          </label>

          <label class="field">
            <span class="label">عدد أسئلة الواجب (من البنك)</span>
            <input class="input2" type="number" min="1" step="1" name="questions_total" id="questionsTotal" required
              value="<?php echo $editRow ? (int)$editRow['questions_total'] : 10; ?>">
            <div class="as-hint">لا يمكن أن يزيد عن عدد أسئلة بنك الأسئلة.</div>
          </label>

          <label class="field">
            <span class="label">عدد أسئلة الواجب للطالب</span>
            <input class="input2" type="number" min="1" step="1" name="questions_per_student" id="questionsPerStudent" required
              value="<?php echo $editRow ? (int)$editRow['questions_per_student'] : 10; ?>">
            <div class="as-hint">سحب عشوائي لأسئلة مختلفة لكل طالب (نماذج).</div>
          </label>

          <div class="form-actions" style="grid-column:1 / -1;">
            <button class="btn" type="submit" <?php echo (!$gradesList ? 'disabled' : ''); ?>>
              <?php echo $editRow ? '💾 حفظ التعديل' : '➕ إضافة الواجب'; ?>
            </button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة الواجبات</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalAssignments); ?></span>
          </div>
        </div>

        <div class="table-wrap scroll-pro">
          <table class="table as-table">
            <thead>
              <tr>
                <th>#</th>
                <th>اسم الواجب</th>
                <th>الصف</th>
                <th>البنك</th>
                <th>الوقت</th>
                <th>إجمالي/للطالب</th>
                <th>أضيف بتاريخ</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$assignments): ?>
                <tr><td colspan="8" style="text-align:center">لا يوجد واجبات بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($assignments as $a): ?>
                <tr>
                  <td data-label="#"><?php echo (int)$a['id']; ?></td>
                  <td data-label="اسم الواجب"><?php echo h((string)$a['name']); ?></td>
                  <td data-label="الصف"><span class="tagx purple">🏫 <?php echo h((string)$a['grade_name']); ?></span></td>
                  <td data-label="البنك">
                    <span class="tagx blue">🗂️ <?php echo h((string)$a['bank_name']); ?></span>
                    <div class="mini">❓ في البنك: <?php echo number_format((int)$a['bank_questions_count']); ?></div>
                  </td>
                  <td data-label="الوقت"><span class="tagx orange">⏱️ <?php echo (int)$a['duration_minutes']; ?> دقيقة</span></td>
                  <td data-label="إجمالي/للطالب">
                    <span class="tagx green">📌 <?php echo (int)$a['questions_total']; ?></span>
                    <span class="tagx red">🧑‍🎓 <?php echo (int)$a['questions_per_student']; ?></span>
                  </td>
                  <td data-label="أضيف بتاريخ"><?php echo h((string)$a['created_at']); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <button class="link info js-preview" type="button" data-aid="<?php echo (int)$a['id']; ?>">👁️ معاينة</button>
                    <a class="link" href="assignments.php?edit=<?php echo (int)$a['id']; ?>">✏️ تعديل</a>
                    <a class="link info" href="assignments.php?results_assignment=<?php echo (int)$a['id']; ?>&results_view=solved">📊 طلاب قاموا بالحل</a>
                    <a class="link info" href="assignments.php?results_assignment=<?php echo (int)$a['id']; ?>&results_view=unsolved">📭 طلاب لم يقوموا بالحل</a>

                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الواجب؟');">
                      <input type="hidden" name="action" value="delete_assignment">
                      <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                      <button class="link danger" type="submit">🗑️ حذف</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>

            </tbody>
          </table>
        </div>
      </section>

      <?php if (!empty($resultsPayload['assignment'])): ?>
        <?php
          $assignmentInfo = $resultsPayload['assignment'];
          $resultRows = ($resultsView === 'solved') ? $resultsPayload['solved'] : $resultsPayload['unsolved'];
          $resultTitle = ($resultsView === 'solved') ? 'طلاب قاموا بالحل' : 'طلاب لم يقوموا بالحل';
        ?>
        <section class="cardx" style="margin-top:12px;">
          <div class="cardx-head">
            <div class="cardx-title">
              <span class="cardx-badge">📊</span>
              <h2><?php echo h($resultTitle); ?> — <?php echo h((string)$assignmentInfo['name']); ?></h2>
            </div>
            <div class="cardx-actions">
              <span class="pillx">🏫 <?php echo h((string)$assignmentInfo['grade_name']); ?></span>
              <a class="btn ghost" href="assignments.php?results_assignment=<?php echo (int)$resultsAssignmentId; ?>&results_view=solved&export_results=solved">⬇️ Excel طلاب قاموا بالحل</a>
              <a class="btn ghost" href="assignments.php?results_assignment=<?php echo (int)$resultsAssignmentId; ?>&results_view=unsolved&export_results=unsolved">⬇️ Excel طلاب لم يقوموا بالحل</a>
            </div>
          </div>

          <div class="table-wrap scroll-pro">
            <table class="table as-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>اسم الطالب</th>
                  <th>الهاتف</th>
                  <th>الباركود</th>
                  <th>الحالة</th>
                  <th>الدرجة</th>
                  <th>تاريخ البدء</th>
                  <th>تاريخ التسليم</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$resultRows): ?>
                  <tr><td colspan="8" style="text-align:center">لا يوجد طلاب ضمن هذه القائمة حالياً.</td></tr>
                <?php endif; ?>
                <?php foreach ($resultRows as $idx => $row): ?>
                  <tr>
                    <td><?php echo (int)$idx + 1; ?></td>
                    <td><?php echo h((string)$row['full_name']); ?></td>
                    <td><?php echo h((string)($row['student_phone'] ?? '')); ?></td>
                    <td><?php echo h((string)($row['barcode'] ?? '')); ?></td>
                    <td><?php echo h((string)$row['status_label']); ?></td>
                    <td><?php echo h((string)$row['score_text']); ?></td>
                    <td><?php echo h((string)($row['started_at'] ?? '')); ?></td>
                    <td><?php echo h((string)($row['submitted_at'] ?? '')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <!-- ✅ Preview modal -->
      <div class="as-modal" id="previewModal" aria-hidden="true">
        <div class="as-modal__overlay" id="previewOverlay"></div>

        <div class="as-modal__card" role="dialog" aria-modal="true" aria-label="معاينة الواجب">
          <div class="as-modal__head">
            <div class="as-modal__title">
              <div class="badge">👁️</div>
              <div>
                <h3 style="margin:0">معاينة الواجب مثل الطالب</h3>
                <p id="previewMeta" style="margin:6px 0 0; color: var(--muted); font-weight: 900;">...</p>
              </div>
            </div>

            <div class="as-modal__right">
              <div class="timer-pill" id="previewTimer">⏱️ --:--</div>
              <button class="as-modal__close" type="button" id="previewClose" aria-label="إغلاق">✖</button>
            </div>
          </div>

          <div class="as-modal__body">
            <div class="as-student">
              <div class="as-student__head">
                <div class="as-student__title">📌 الأسئلة</div>
                <div class="as-student__sub" id="previewHint">...</div>
              </div>

              <!-- ✅ Navigation: numbers + next/prev -->
              <div class="as-nav" id="previewNav" style="display:none;">
                <div class="as-nav__nums" id="previewNums"></div>
                <div class="as-nav__actions">
                  <button class="btn ghost" type="button" id="previewPrev">⬅ السابق</button>
                  <button class="btn ghost" type="button" id="previewNext">التالي ➡</button>
                </div>
              </div>

              <div class="as-questions" id="previewQuestions"></div>

              <div class="as-actions">
                <button class="btn" type="button" id="previewSubmit">✅ إنهاء وتسليم</button>
                <button class="btn ghost" type="button" id="previewReset">🧹 مسح</button>
              </div>

              <div class="as-result" id="previewResult" style="display:none;"></div>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>

  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <script>
    (function () {
      const root = document.body;

      // Theme (same pattern)
      const themeSwitch = document.getElementById('themeSwitch');
      const stored = localStorage.getItem('admin_theme') || 'auto';
      function osPrefersDark() { return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches; }
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
        sidebar.classList.add('open'); backdrop.classList.add('show'); document.body.style.overflow = 'hidden';
      }
      function closeSidebar() {
        if (!isMobile()) return;
        sidebar.classList.remove('open'); backdrop.classList.remove('show'); document.body.style.overflow = '';
      }
      function syncInitial() {
        if (isMobile()) closeSidebar();
        else { sidebar.classList.remove('open'); backdrop.classList.remove('show'); document.body.style.overflow = ''; }
      }
      syncInitial();
      burger && burger.addEventListener('click', (e) => {
        e.preventDefault(); if (!isMobile()) return;
        if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
      });
      backdrop && backdrop.addEventListener('click', (e) => { e.preventDefault(); closeSidebar(); });
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);

      // =========================
      // Grade -> Bank filtering
      // =========================
      const BANKS_BY_GRADE = <?php echo json_encode($banksByGrade, JSON_UNESCAPED_UNICODE); ?>;

      const gradeSelect = document.getElementById('gradeSelect');
      const bankSelect = document.getElementById('bankSelect');
      const bankMeta = document.getElementById('bankMeta');

      const questionsTotal = document.getElementById('questionsTotal');
      const questionsPerStudent = document.getElementById('questionsPerStudent');

      const EDIT_ROW = <?php echo json_encode($editRow ?: null, JSON_UNESCAPED_UNICODE); ?>;

      function fillBanks(gradeId) {
        if (!bankSelect) return;

        bankSelect.innerHTML = '<option value="0">— اختر بنك الأسئلة —</option>';

        const list = BANKS_BY_GRADE[String(gradeId)] || BANKS_BY_GRADE[gradeId] || [];
        if (!list.length) {
          bankMeta.textContent = 'لا توجد بنوك أسئلة لهذا الصف.';
          return;
        }

        list.forEach(b => {
          const opt = document.createElement('option');
          opt.value = String(b.id);
          opt.textContent = '🗂️ ' + String(b.name) + ' (❓ ' + String(b.bank_questions_count) + ')';
          opt.setAttribute('data-count', String(b.bank_questions_count || 0));
          bankSelect.appendChild(opt);
        });

        bankMeta.textContent = 'اختر بنك الأسئلة (مرتبط بنفس الصف).';
      }

      function syncBankCountHints() {
        if (!bankSelect) return;
        const opt = bankSelect.options[bankSelect.selectedIndex];
        const count = opt ? parseInt(opt.getAttribute('data-count') || '0', 10) : 0;

        if (count > 0) {
          if (questionsTotal) {
            questionsTotal.max = String(count);
            if (parseInt(questionsTotal.value || '0', 10) > count) questionsTotal.value = String(count);
          }
          if (questionsPerStudent) {
            const tot = parseInt(questionsTotal.value || '0', 10) || 1;
            questionsPerStudent.max = String(tot);
            if (parseInt(questionsPerStudent.value || '0', 10) > tot) questionsPerStudent.value = String(tot);
          }
          bankMeta.textContent = `❓ عدد أسئلة البنك: ${count}`;
        }
      }

      gradeSelect && gradeSelect.addEventListener('change', () => {
        const gid = parseInt(gradeSelect.value || '0', 10);
        fillBanks(gid);
      });

      bankSelect && bankSelect.addEventListener('change', syncBankCountHints);
      questionsTotal && questionsTotal.addEventListener('change', () => {
        const tot = parseInt(questionsTotal.value || '1', 10);
        if (questionsPerStudent) {
          questionsPerStudent.max = String(tot);
          if (parseInt(questionsPerStudent.value || '0', 10) > tot) questionsPerStudent.value = String(tot);
        }
      });

      // init (edit mode)
      (function initBanks() {
        const gid = gradeSelect ? parseInt(gradeSelect.value || '0', 10) : 0;
        if (gid > 0) {
          fillBanks(gid);

          if (EDIT_ROW && EDIT_ROW.bank_id) {
            bankSelect.value = String(EDIT_ROW.bank_id);
            syncBankCountHints();
          }
        }
      })();

      // =========================
      // Preview modal
      // =========================
      const previewModal = document.getElementById('previewModal');
      const previewOverlay = document.getElementById('previewOverlay');
      const previewClose = document.getElementById('previewClose');

      const previewMeta = document.getElementById('previewMeta');
      const previewHint = document.getElementById('previewHint');
      const previewTimer = document.getElementById('previewTimer');

      const previewNav = document.getElementById('previewNav');
      const previewNums = document.getElementById('previewNums');
      const previewPrev = document.getElementById('previewPrev');
      const previewNext = document.getElementById('previewNext');

      const previewQuestions = document.getElementById('previewQuestions');
      const previewSubmit = document.getElementById('previewSubmit');
      const previewReset = document.getElementById('previewReset');
      const previewResult = document.getElementById('previewResult');

      let PREVIEW = null;
      let TIMER = { endAt: 0, t: null };

      // ✅ current question index (0-based)
      let CUR_Q = 0;

      function openPreviewModal() {
        if (!previewModal) return;
        previewModal.classList.add('open');
        previewModal.setAttribute('aria-hidden', 'false');
      }
      function closePreviewModal() {
        if (!previewModal) return;
        previewModal.classList.remove('open');
        previewModal.setAttribute('aria-hidden', 'true');
        stopTimer();
      }

      function stopTimer() {
        if (TIMER.t) clearInterval(TIMER.t);
        TIMER.t = null;
        TIMER.endAt = 0;
        if (previewTimer) previewTimer.textContent = '⏱️ --:--';
      }

      function startTimer(minutes) {
        stopTimer();
        const ms = minutes * 60 * 1000;
        TIMER.endAt = Date.now() + ms;

        function tick() {
          const left = Math.max(0, TIMER.endAt - Date.now());
          const s = Math.floor(left / 1000);
          const mm = String(Math.floor(s / 60)).padStart(2, '0');
          const ss = String(s % 60).padStart(2, '0');
          if (previewTimer) previewTimer.textContent = '⏱️ ' + mm + ':' + ss;

          if (left <= 0) {
            stopTimer();
            // auto submit
            previewSubmit && previewSubmit.click();
          }
        }
        tick();
        TIMER.t = setInterval(tick, 1000);
      }

      function nl2brSafe(s) { return String(s || '').replace(/\n/g, '<br>'); }

      function kindLabel(k) {
        if (k === 'image') return '🖼️ صورة';
        if (k === 'text_image') return '📝🖼️ نص + صورة';
        return '📝 نص';
      }

      function setActiveQuestion(nextIdx) {
        if (!PREVIEW || !previewQuestions) return;

        const qs = Array.isArray(PREVIEW.questions) ? PREVIEW.questions : [];
        if (!qs.length) return;

        const max = qs.length - 1;
        CUR_Q = Math.max(0, Math.min(max, parseInt(nextIdx, 10) || 0));

        const cards = Array.from(previewQuestions.querySelectorAll('.as-q'));
        cards.forEach((card, idx) => {
          if (idx === CUR_Q) card.classList.remove('is-hidden');
          else card.classList.add('is-hidden');
        });

        if (previewNums) {
          previewNums.querySelectorAll('.qnum').forEach((b, idx) => {
            b.classList.toggle('active', idx === CUR_Q);
          });
        }

        // disable prev/next
        if (previewPrev) previewPrev.disabled = (CUR_Q <= 0);
        if (previewNext) previewNext.disabled = (CUR_Q >= max);
      }

      function buildNav(qCount) {
        if (!previewNav || !previewNums) return;

        previewNums.innerHTML = '';
        for (let i = 0; i < qCount; i++) {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = 'qnum';
          b.textContent = String(i + 1);
          b.addEventListener('click', () => setActiveQuestion(i));
          previewNums.appendChild(b);
        }

        previewNav.style.display = (qCount > 0) ? '' : 'none';
      }

      function renderPreview(payload) {
        PREVIEW = payload;
        CUR_Q = 0;

        const a = payload.assignment || {};
        const qs = Array.isArray(payload.questions) ? payload.questions : [];

        previewMeta.textContent = `📌 ${a.name} • 🏫 ${a.grade_name} • 🗂️ ${a.bank_name} • ⏱️ ${a.duration_minutes} دقيقة`;
        previewHint.textContent = `عدد الأسئلة للطالب: ${payload.questions_per_student} من إجمالي ${payload.questions_total} (في البنك: ${payload.bank_total}).`;

        if (previewResult) {
          previewResult.style.display = 'none';
          previewResult.className = 'as-result';
          previewResult.textContent = '';
        }

        if (previewQuestions) previewQuestions.innerHTML = '';

        buildNav(qs.length);

        qs.forEach((item, idx) => {
          const q = item.q || {};
          const choices = Array.isArray(item.choices) ? item.choices : [];
          const need = parseInt(q.correct_choices_count || '1', 10);
          const isMulti = (need === 2);

          const qCard = document.createElement('article');
          qCard.className = 'as-q' + (idx === 0 ? '' : ' is-hidden');
          qCard.setAttribute('data-qid', String(q.id));

          const head = document.createElement('div');
          head.className = 'as-q__head';
          head.innerHTML = `
            <div class="as-q__no">❓ سؤال ${idx+1} / ${qs.length}</div>
            <div class="as-q__meta">
              <span class="pill">🎯 الدرجة: ${parseFloat(q.degree || '0').toFixed(2)}</span>
              <span class="pill ${isMulti ? 'purple' : 'green'}">${isMulti ? '✅✅ اختر إجابتين' : '✅ اختر إجابة واحدة'}</span>
              <span class="pill blue">${kindLabel(String(q.question_kind || 'text'))}</span>
            </div>
          `;

          const body = document.createElement('div');
          body.className = 'as-q__body';

          const qKind = String(q.question_kind || 'text');
          const qText = String(q.question_text || '');
          const qImg = String(q.question_image_path || '');

          if (qKind !== 'image' && qText.trim() !== '') {
            const div = document.createElement('div');
            div.className = 'as-q__text';
            div.innerHTML = nl2brSafe(qText);
            body.appendChild(div);
          }
          if ((qKind === 'image' || qKind === 'text_image') && qImg.trim() !== '') {
            const div = document.createElement('div');
            div.className = 'as-q__img';
            div.innerHTML = `<img src="${qImg}" alt="question">`;
            body.appendChild(div);
          }

          const grid = document.createElement('div');
          grid.className = 'as-choices';

          const cKind = String(q.choices_kind || 'text');
          const needsText = (cKind === 'text' || cKind === 'text_image');
          const needsImage = (cKind === 'image' || cKind === 'text_image');

          choices.forEach(ch => {
            const cIdx = parseInt(ch.choice_index || '0', 10);
            const cText = String(ch.choice_text || '');
            const cImg = String(ch.choice_image_path || '');

            const label = document.createElement('label');
            label.className = 'as-choice';
            label.setAttribute('data-idx', String(cIdx));
            label.innerHTML = `
              <input type="${isMulti ? 'checkbox' : 'radio'}" name="ans_${q.id}" value="${cIdx}">
              <span class="as-choice__body">
                <span class="as-choice__idx">#${cIdx}</span>
                ${needsText && cText.trim() !== '' ? `<span class="as-choice__text">${nl2brSafe(cText)}</span>` : ``}
                ${needsImage && cImg.trim() !== '' ? `<span class="as-choice__img"><img src="${cImg}" alt="choice"></span>` : ``}
              </span>
            `;
            grid.appendChild(label);
          });

          // limit picks if multi
          if (isMulti) {
            grid.addEventListener('change', () => {
              const picked = Array.from(grid.querySelectorAll(`input[name="ans_${q.id}"]:checked`));
              if (picked.length > 2) {
                const last = picked[picked.length - 1];
                last.checked = false;
              }
            });
          }

          body.appendChild(grid);

          const foot = document.createElement('div');
          foot.className = 'as-q__foot';
          foot.innerHTML = `<div class="mini">🧠 نوع التصحيح: ${String(q.correction_type || 'single') === 'double' ? 'إجابتين' : 'إجابة واحدة'}</div>`;

          qCard.appendChild(head);
          qCard.appendChild(body);
          qCard.appendChild(foot);

          previewQuestions.appendChild(qCard);
        });

        // init active question UI
        setActiveQuestion(0);

        // start timer
        startTimer(parseInt(a.duration_minutes || '0', 10) || 1);
      }

      async function fetchPreview(assignmentId) {
        const url = `assignments.php?action=preview_json&assignment_id=${encodeURIComponent(assignmentId)}`;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        if (!json || !json.ok) throw new Error((json && json.error) ? json.error : 'Preview failed');
        return json;
      }

      document.addEventListener('click', async (e) => {
        const btn = e.target && e.target.closest ? e.target.closest('.js-preview') : null;
        if (!btn) return;

        e.preventDefault();
        const aid = btn.getAttribute('data-aid');
        if (!aid) return;

        try {
          btn.disabled = true;
          const payload = await fetchPreview(aid);
          renderPreview(payload);
          openPreviewModal();
        } catch (err) {
          alert('تعذر فتح المعاينة: ' + (err && err.message ? err.message : ''));
        } finally {
          btn.disabled = false;
        }
      }, { passive: false });

      previewClose && previewClose.addEventListener('click', closePreviewModal);
      previewOverlay && previewOverlay.addEventListener('click', closePreviewModal);

      window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          if (previewModal && previewModal.classList.contains('open')) closePreviewModal();
        }
      });

      function clearMarks() {
        if (!previewQuestions) return;
        previewQuestions.querySelectorAll('.as-choice').forEach(el => {
          el.classList.remove('is-correct');
          el.classList.remove('is-wrong');
          el.classList.remove('is-reveal-correct');
        });
      }

      function computeScore() {
        if (!PREVIEW) return { score: 0, total: 0, warn: '' };

        const qs = Array.isArray(PREVIEW.questions) ? PREVIEW.questions : [];
        let total = 0;
        let score = 0;

        for (const item of qs) {
          const q = item.q || {};
          const correct = Array.isArray(item.correct) ? item.correct : [];
          const need = parseInt(q.correct_choices_count || '1', 10);
          const deg = parseFloat(q.degree || '0');

          total += deg;

          const inputs = Array.from(document.querySelectorAll(`input[name="ans_${q.id}"]`));
          const picked = inputs.filter(i => i.checked).map(i => parseInt(i.value, 10));

          if (picked.length !== need) {
            return { score: 0, total, warn: (need === 2 ? 'من فضلك أكمل اختيار إجابتين في كل سؤال.' : 'من فضلك اختر إجابة واحدة في كل سؤال.') };
          }

          if (need === 1) {
            score += (correct.includes(picked[0]) ? deg : 0);
          } else {
            let correctCount = 0;
            picked.forEach(p => { if (correct.includes(p)) correctCount++; });
            if (correctCount === 2) score += deg;
            else if (correctCount === 1) score += (deg / 2);
          }
        }

        return { score, total, warn: '' };
      }

      function revealCorrection() {
        if (!PREVIEW || !previewQuestions) return;

        const qs = Array.isArray(PREVIEW.questions) ? PREVIEW.questions : [];

        qs.forEach(item => {
          const q = item.q || {};
          const correct = Array.isArray(item.correct) ? item.correct : [];

          const picked = Array.from(document.querySelectorAll(`input[name="ans_${q.id}"]`))
            .filter(i => i.checked)
            .map(i => parseInt(i.value, 10));

          // ✅ 1) لو الطالب اختار إجابة: نلوّنها صح/غلط
          picked.forEach(idx => {
            const el = previewQuestions.querySelector(`.as-q[data-qid="${q.id}"] .as-choice[data-idx="${idx}"]`);
            if (!el) return;
            if (correct.includes(idx)) el.classList.add('is-correct');
            else el.classList.add('is-wrong');
          });

          // ✅ 2) نظهر الإجابات الصحيحة (حتى لو لم تُختَر) بدَش أخضر
          correct.forEach(idx => {
            const el = previewQuestions.querySelector(`.as-q[data-qid="${q.id}"] .as-choice[data-idx="${idx}"]`);
            if (!el) return;
            if (!el.classList.contains('is-correct')) el.classList.add('is-reveal-correct');
          });

          // ✅ 3) لو الطالب لم يختار بعض الإجابات وكانت خاطئة (غير صحيحة) لن نلونها (طبيعي)
          // المطلوب "كشف الصحيحة والخاطئة" = اختيارات الطالب الخاطئة تُلوّن أحمر + الصحيحة أخضر + إظهار الصحيح غير المختار dashed
        });
      }

      previewSubmit && previewSubmit.addEventListener('click', () => {
        if (!PREVIEW) return;

        clearMarks();

        const res = computeScore();
        if (res.warn) {
          previewResult.style.display = '';
          previewResult.className = 'as-result warn';
          previewResult.textContent = '⚠️ ' + res.warn;
          return;
        }

        revealCorrection();

        previewResult.style.display = '';
        previewResult.className = 'as-result ok';
        previewResult.textContent = `✅ نتيجة تجريبية: درجتك = ${res.score.toFixed(2)} من ${res.total.toFixed(2)} — تم كشف إجاباتك الصحيحة والخاطئة + إظهار الإجابات الصحيحة.`;

        stopTimer();
      });

      previewReset && previewReset.addEventListener('click', () => {
        if (!previewQuestions) return;
        previewQuestions.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(i => i.checked = false);
        clearMarks();
        if (previewResult) previewResult.style.display = 'none';
        setActiveQuestion(0);
      });

      // ✅ Prev/Next buttons
      previewPrev && previewPrev.addEventListener('click', () => setActiveQuestion(CUR_Q - 1));
      previewNext && previewNext.addEventListener('click', () => setActiveQuestion(CUR_Q + 1));

    })();
  </script>
</body>
</html>
