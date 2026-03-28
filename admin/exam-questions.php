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
   Upload helpers (images)
   - دعم جميع الصور وكل الأحجام
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

  $mime = strtolower(trim((string)$info['mime']));
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

function normalize_int($v, int $min, int $max): int {
  $n = (int)$v;
  if ($n < $min) $n = $min;
  if ($n > $max) $n = $max;
  return $n;
}
const CHOICES_FALLBACK_MAX = 300;
const CHOICES_MIN_LIMIT = 50;
const CHOICES_MIN_INPUT_VARS = 150;
const CHOICES_FORM_OVERHEAD_VARS = 100;
const CHOICES_FIELDS_PER_ENTRY = 3;
function max_choices_count(): int {
  $maxInputVars = (int)ini_get('max_input_vars');
  // Each rendered choice can submit roughly 3 fields (content, file, correctness),
  // so keep some headroom for the rest of the form and derive a practical limit
  // from the server's max_input_vars instead of hard-coding the old 10-choice cap.
  if ($maxInputVars <= 0) return CHOICES_FALLBACK_MAX;
  return max(CHOICES_MIN_LIMIT, (int)floor(max(CHOICES_MIN_INPUT_VARS, $maxInputVars - CHOICES_FORM_OVERHEAD_VARS) / CHOICES_FIELDS_PER_ENTRY));
}
function normalize_degree($v): float {
  $n = (float)$v;
  if ($n < 0) $n = 0;
  if ($n > 1000000) $n = 1000000;
  return (float)number_format($n, 2, '.', '');
}
$choicesMaxCount = max(2, max_choices_count());

/* =========================
   Required bank
   ========================= */
$bankId = (int)($_GET['bank_id'] ?? ($_POST['bank_id'] ?? 0));
if ($bankId <= 0) {
  http_response_code(400);
  exit('bank_id is required');
}

$stmt = $pdo->prepare("
  SELECT b.*, g.name AS grade_name
  FROM exam_question_banks b
  INNER JOIN grades g ON g.id=b.grade_id
  WHERE b.id=? LIMIT 1
");
$stmt->execute([$bankId]);
$bank = $stmt->fetch();
if (!$bank) {
  http_response_code(404);
  exit('Bank not found');
}

$uploadDirAbs = __DIR__ . '/uploads/exam_questions';
$uploadDirRel = 'uploads/exam_questions';
ensure_dir($uploadDirAbs);

/* =========================
   CRUD - Questions
   ========================= */
$success = null;
$error = null;

/* DELETE question */
if (($_POST['action'] ?? '') === 'delete_question') {
  $qid = (int)($_POST['question_id'] ?? 0);
  if ($qid <= 0) $error = 'طلب غير صالح.';
  else {
    try {
      // delete stored images (question + choices)
      $stmt = $pdo->prepare("SELECT question_image_path FROM exam_questions WHERE id=? AND bank_id=? LIMIT 1");
      $stmt->execute([$qid, $bankId]);
      $row = $stmt->fetch();
      if ($row && !empty($row['question_image_path'])) {
        $abs = __DIR__ . '/' . $row['question_image_path'];
        if (is_file($abs)) @unlink($abs);
      }

      $stmt = $pdo->prepare("SELECT choice_image_path FROM exam_question_choices WHERE question_id=?");
      $stmt->execute([$qid]);
      $imgs = $stmt->fetchAll();
      foreach ($imgs as $im) {
        if (!empty($im['choice_image_path'])) {
          $abs = __DIR__ . '/' . $im['choice_image_path'];
          if (is_file($abs)) @unlink($abs);
        }
      }

      $stmt = $pdo->prepare("DELETE FROM exam_questions WHERE id=? AND bank_id=?");
      $stmt->execute([$qid, $bankId]);

      header("Location: exam-questions.php?bank_id={$bankId}&deleted=1");
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف السؤال.';
    }
  }
}

/* CREATE / UPDATE question */
if (($_POST['action'] ?? '') === 'save_question') {
  $questionId = (int)($_POST['question_id'] ?? 0); // 0 => create
  $isEdit = ($questionId > 0);

  $degree = normalize_degree($_POST['degree'] ?? 1);
  $correctionType = (string)($_POST['correction_type'] ?? 'single'); // single|double
  $questionKind = (string)($_POST['question_kind'] ?? 'text'); // text|image|text_image
  $questionText = trim((string)($_POST['question_text'] ?? ''));

  $choicesCount = normalize_int($_POST['choices_count'] ?? 4, 2, max_choices_count());
  $choicesKind = (string)($_POST['choices_kind'] ?? 'text'); // text|image|text_image
  $correctChoicesCount = normalize_int($_POST['correct_choices_count'] ?? 1, 1, 2);

  $correctIndices = $_POST['correct_choices'] ?? [];
  if (!is_array($correctIndices)) $correctIndices = [];
  $correctIndices = array_values(array_unique(array_map('intval', $correctIndices)));
  sort($correctIndices);

  if (!in_array($correctionType, ['single','double'], true)) $error = 'نوع التصحيح غير صحيح.';
  elseif (!in_array($questionKind, ['text','image','text_image'], true)) $error = 'نوع السؤال غير صحيح.';
  elseif (!in_array($choicesKind, ['text','image','text_image'], true)) $error = 'نوع الاختيارات غير صحيح.';
  elseif (!in_array($correctChoicesCount, [1,2], true)) $error = 'عدد الإجابات الصحيحة غير صحيح.';
  elseif ($correctionType === 'double' && $correctChoicesCount !== 2) $error = 'عند اختيار "إجابتين صحيحتين" يجب أن يكون عدد الإجابات الصحيحة = 2.';
  elseif ($correctionType === 'single' && $correctChoicesCount !== 1) $error = 'عند اختيار "إجابة واحدة" يجب أن يكون عدد الإجابات الصحيحة = 1.';
  else {
    if ($questionKind === 'text' && $questionText === '') $error = 'من فضلك اكتب نص السؤال.';
    if ($questionKind === 'text_image' && $questionText === '') $error = 'من فضلك اكتب نص السؤال.';
  }

  if (!$error) {
    if (count($correctIndices) !== $correctChoicesCount) {
      $error = 'من فضلك اختر عدد الإجابات الصحيحة المحدد.';
    } else {
      foreach ($correctIndices as $ci) {
        if ($ci < 1 || $ci > $choicesCount) {
          $error = 'تم اختيار إجابة صحيحة خارج نطاق الاختيارات.';
          break;
        }
      }
    }
  }

  // fetch old paths if edit
  $oldQuestionImage = null;
  $oldChoiceImages = []; // index => path
  if (!$error && $isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE id=? AND bank_id=? LIMIT 1");
    $stmt->execute([$questionId, $bankId]);
    $oldQ = $stmt->fetch();
    if (!$oldQ) $error = 'السؤال غير موجود.';
    else $oldQuestionImage = (string)($oldQ['question_image_path'] ?? '');

    $stmt = $pdo->prepare("SELECT choice_index, choice_image_path FROM exam_question_choices WHERE question_id=?");
    $stmt->execute([$questionId]);
    foreach ($stmt->fetchAll() as $r) {
      $oldChoiceImages[(int)$r['choice_index']] = (string)($r['choice_image_path'] ?? '');
    }
  }

  // upload question image if needed
  $questionImagePath = $oldQuestionImage ?: null;

  if (!$error && in_array($questionKind, ['image','text_image'], true)) {
    $hasNew = !empty($_FILES['question_image']['name']);
    if (!$isEdit && !$hasNew) {
      $error = 'من فضلك اختر صورة للسؤال.';
    } elseif ($hasNew) {
      if (($_FILES['question_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error((int)($_FILES['question_image']['error'] ?? 0));
      } else {
        $tmp = (string)$_FILES['question_image']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) $error = 'ملف صورة السؤال غير صالح.';
        else {
          $newName = random_filename($ext);
          $destAbs = $uploadDirAbs . '/' . $newName;
          if (!move_uploaded_file($tmp, $destAbs)) $error = 'تعذر حفظ صورة السؤال على السيرفر.';
          else {
            if ($isEdit && !empty($oldQuestionImage)) {
              $abs = __DIR__ . '/' . $oldQuestionImage;
              if (is_file($abs)) @unlink($abs);
            }
            $questionImagePath = $uploadDirRel . '/' . $newName;
          }
        }
      }
    }
  } else {
    if ($isEdit && !empty($oldQuestionImage)) {
      $abs = __DIR__ . '/' . $oldQuestionImage;
      if (is_file($abs)) @unlink($abs);
    }
    $questionImagePath = null;
  }

  // validate choices content before DB write
  $choices = [];
  if (!$error) {
    for ($i = 1; $i <= $choicesCount; $i++) {
      $t = trim((string)($_POST['choice_text'][$i] ?? ''));
      $oldImg = $oldChoiceImages[$i] ?? '';
      $imgPath = $oldImg !== '' ? $oldImg : null;

      $needsText = in_array($choicesKind, ['text','text_image'], true);
      $needsImage = in_array($choicesKind, ['image','text_image'], true);

      $fileKey = "choice_image_{$i}";
      $hasNewChoiceImg = !empty($_FILES[$fileKey]['name']);

      if ($needsImage) {
        if (!$isEdit && !$hasNewChoiceImg) {
          $error = "من فضلك اختر صورة للاختيار رقم {$i}.";
          break;
        }
        if ($hasNewChoiceImg) {
          if (($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = normalize_upload_error((int)($_FILES[$fileKey]['error'] ?? 0));
            break;
          }
          $tmp = (string)$_FILES[$fileKey]['tmp_name'];
          $ext = detect_image_extension($tmp);
          if ($ext === null) {
            $error = "ملف صورة الاختيار رقم {$i} غير صالح.";
            break;
          }
          $newName = random_filename($ext);
          $destAbs = $uploadDirAbs . '/' . $newName;
          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = "تعذر حفظ صورة الاختيار رقم {$i}.";
            break;
          }
          if ($isEdit && !empty($oldImg)) {
            $abs = __DIR__ . '/' . $oldImg;
            if (is_file($abs)) @unlink($abs);
          }
          $imgPath = $uploadDirRel . '/' . $newName;
        }
      } else {
        if ($isEdit && !empty($oldImg)) {
          $abs = __DIR__ . '/' . $oldImg;
          if (is_file($abs)) @unlink($abs);
        }
        $imgPath = null;
      }

      if ($needsText && $t === '') {
        $error = "من فضلك اكتب نص الاختيار رقم {$i}.";
        break;
      }

      $choices[] = [
        'index' => $i,
        'text' => ($needsText ? ($t !== '' ? $t : null) : null),
        'image_path' => ($needsImage ? $imgPath : null),
        'is_correct' => in_array($i, $correctIndices, true) ? 1 : 0,
      ];
    }
  }

  // DB write
  if (!$error) {
    try {
      $pdo->beginTransaction();

      if (!$isEdit) {
        $stmt = $pdo->prepare("
          INSERT INTO exam_questions
            (bank_id, degree, correction_type, question_kind, question_text, question_image_path,
             choices_count, choices_kind, correct_choices_count)
          VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
          $bankId,
          $degree,
          $correctionType,
          $questionKind,
          ($questionKind === 'text' ? $questionText : ($questionText !== '' ? $questionText : null)),
          $questionImagePath,
          $choicesCount,
          $choicesKind,
          $correctChoicesCount,
        ]);
        $newQuestionId = (int)$pdo->lastInsertId();

        $stmtC = $pdo->prepare("
          INSERT INTO exam_question_choices
            (question_id, choice_index, choice_text, choice_image_path, is_correct)
          VALUES
            (?, ?, ?, ?, ?)
        ");
        foreach ($choices as $ch) {
          $stmtC->execute([
            $newQuestionId,
            (int)$ch['index'],
            $ch['text'],
            $ch['image_path'],
            (int)$ch['is_correct'],
          ]);
        }

      } else {
        $stmt = $pdo->prepare("
          UPDATE exam_questions
          SET degree=?,
              correction_type=?,
              question_kind=?,
              question_text=?,
              question_image_path=?,
              choices_count=?,
              choices_kind=?,
              correct_choices_count=?
          WHERE id=? AND bank_id=?
        ");
        $stmt->execute([
          $degree,
          $correctionType,
          $questionKind,
          ($questionKind === 'text' ? $questionText : ($questionText !== '' ? $questionText : null)),
          $questionImagePath,
          $choicesCount,
          $choicesKind,
          $correctChoicesCount,
          $questionId,
          $bankId
        ]);

        $stmt = $pdo->prepare("DELETE FROM exam_question_choices WHERE question_id=?");
        $stmt->execute([$questionId]);

        $stmtC = $pdo->prepare("
          INSERT INTO exam_question_choices
            (question_id, choice_index, choice_text, choice_image_path, is_correct)
          VALUES
            (?, ?, ?, ?, ?)
        ");
        foreach ($choices as $ch) {
          $stmtC->execute([
            $questionId,
            (int)$ch['index'],
            $ch['text'],
            $ch['image_path'],
            (int)$ch['is_correct'],
          ]);
        }
      }

      $pdo->commit();

      header("Location: exam-questions.php?bank_id={$bankId}&saved=1");
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $error = 'تعذر حفظ السؤال.';
    }
  }
}

/* Messages */
if (isset($_GET['saved'])) $success = '✅ تم حفظ السؤال بنجاح.';
if (isset($_GET['deleted'])) $success = '🗑️ تم حذف السؤال بنجاح.';

/* =========================
   Fetch list
   ========================= */
$questions = $pdo->prepare("
  SELECT
    q.*,
    (SELECT COUNT(*) FROM exam_question_choices c WHERE c.question_id = q.id) AS choices_actual
  FROM exam_questions q
  WHERE q.bank_id=?
  ORDER BY q.id DESC
");
$questions->execute([$bankId]);
$questions = $questions->fetchAll();

$totalQuestions = count($questions);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editQuestion = null;
$editChoices = [];
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE id=? AND bank_id=? LIMIT 1");
  $stmt->execute([$editId, $bankId]);
  $editQuestion = $stmt->fetch() ?: null;

  if ($editQuestion) {
    $stmt = $pdo->prepare("SELECT * FROM exam_question_choices WHERE question_id=? ORDER BY choice_index ASC");
    $stmt->execute([(int)$editQuestion['id']]);
    $editChoices = $stmt->fetchAll();
  }
}

/* =========================
   Preview API (AJAX)
   ========================= */
if (($_GET['action'] ?? '') === 'preview_json') {
  header('Content-Type: application/json; charset=utf-8');

  $qid = (int)($_GET['question_id'] ?? 0);
  if ($qid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'question_id is required'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE id=? AND bank_id=? LIMIT 1");
  $stmt->execute([$qid, $bankId]);
  $q = $stmt->fetch();
  if (!$q) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Question not found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $stmt = $pdo->prepare("SELECT * FROM exam_question_choices WHERE question_id=? ORDER BY choice_index ASC");
  $stmt->execute([(int)$q['id']]);
  $choices = $stmt->fetchAll();

  $correct = [];
  foreach ($choices as $c) {
    if ((int)$c['is_correct'] === 1) $correct[] = (int)$c['choice_index'];
  }

  echo json_encode([
    'ok' => true,
    'question' => $q,
    'choices' => $choices,
    'correct' => $correct
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* =========================
   Sidebar menu
   ========================= */
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

function correction_label(string $t): string {
  return $t === 'double'
    ? '✅✅ اختيار إجابتين صحيحتين (نصف درجة لو 1 صحيح + 1 خطأ)'
    : '✅ اختيار إجابة واحدة (درجة كاملة)';
}
function kind_label(string $k): string {
  if ($k === 'image') return '🖼️ صورة';
  if ($k === 'text_image') return '📝🖼️ نص + صورة';
  return '📝 نص';
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>أسئلة بنك: <?php echo h((string)$bank['name']); ?> - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/assignment-questions.css">
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
      <a class="back-btn" href="exam-question-banks.php">🧠 الرجوع للبنوك</a>

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
      <section class="aq-hero">
        <div class="aq-hero-title">
          <h1>🧾 أسئلة بنك الامتحان: <?php echo h((string)$bank['name']); ?></h1>
          <p>🏫 الصف: <?php echo h((string)$bank['grade_name']); ?></p>
        </div>

        <div class="aq-metrics">
          <div class="metric">
            <div class="metric-ico">❓</div>
            <div class="metric-meta">
              <div class="metric-label">عدد الأسئلة</div>
              <div class="metric-val"><?php echo number_format($totalQuestions); ?></div>
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
            <span class="cardx-badge"><?php echo $editQuestion ? '✏️ تعديل' : '➕ إضافة'; ?></span>
            <h2><?php echo $editQuestion ? 'تعديل سؤال' : 'إضافة سؤال جديد'; ?></h2>
          </div>

          <?php if ($editQuestion): ?>
            <a class="btn ghost" href="exam-questions.php?bank_id=<?php echo (int)$bankId; ?>">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="aq-form" enctype="multipart/form-data" autocomplete="off" id="qForm">
          <input type="hidden" name="action" value="save_question">
          <input type="hidden" name="bank_id" value="<?php echo (int)$bankId; ?>">
          <?php if ($editQuestion): ?>
            <input type="hidden" name="question_id" value="<?php echo (int)$editQuestion['id']; ?>">
          <?php endif; ?>

          <label class="field">
            <span class="label">درجة السؤال</span>
            <input class="input2" type="number" step="0.01" min="0" name="degree" required
              value="<?php echo $editQuestion ? h((string)$editQuestion['degree']) : '1.00'; ?>">
          </label>

          <label class="field">
            <span class="label">نوع التصحيح</span>
            <?php $ct = $editQuestion ? (string)$editQuestion['correction_type'] : 'single'; ?>
            <select class="input2 select-pro" name="correction_type" id="correctionType" required>
              <option value="single" <?php echo $ct === 'single' ? 'selected' : ''; ?>>✅ إجابة واحدة (درجة كاملة)</option>
              <option value="double" <?php echo $ct === 'double' ? 'selected' : ''; ?>>✅✅ إجابتين (نصف درجة لو 1 صحيح + 1 خطأ)</option>
            </select>
          </label>

          <label class="field">
            <span class="label">نوع السؤال</span>
            <?php $qk = $editQuestion ? (string)$editQuestion['question_kind'] : 'text'; ?>
            <select class="input2 select-pro" name="question_kind" id="questionKind" required>
              <option value="text" <?php echo $qk === 'text' ? 'selected' : ''; ?>>📝 نص</option>
              <option value="image" <?php echo $qk === 'image' ? 'selected' : ''; ?>>🖼️ صورة</option>
              <option value="text_image" <?php echo $qk === 'text_image' ? 'selected' : ''; ?>>📝🖼️ نص + صورة</option>
            </select>
          </label>

          <label class="field" style="grid-column:1 / -1;" id="questionTextField">
            <span class="label">نص السؤال</span>
            <textarea class="textarea2" name="question_text" id="questionText" placeholder="اكتب السؤال هنا..."><?php
              echo $editQuestion ? h((string)($editQuestion['question_text'] ?? '')) : '';
            ?></textarea>
          </label>

          <label class="field" style="grid-column:1 / -1;" id="questionImageField">
            <span class="label">صورة السؤا��</span>
            <input class="input2" type="file" name="question_image" accept="image/*">
            <div class="aq-hint">يدعم كل أنواع الصور وكل الأحجام.</div>

            <?php if ($editQuestion && !empty($editQuestion['question_image_path'])): ?>
              <div class="img-prev">
                <div class="img-prev-title">الصورة الحالية:</div>
                <img src="<?php echo h((string)$editQuestion['question_image_path']); ?>" alt="question image">
              </div>
            <?php endif; ?>
          </label>

          <div class="aq-split" style="grid-column:1 / -1;"></div>

          <label class="field">
            <span class="label">عدد الاختيارات</span>
            <?php $cc = $editQuestion ? (int)$editQuestion['choices_count'] : 4; ?>
            <input class="input2" type="number" min="2" max="<?php echo h((string)$choicesMaxCount); ?>" step="1" name="choices_count" id="choicesCount" required value="<?php echo (int)$cc; ?>">
          </label>

          <label class="field">
            <span class="label">نوع الاختيارات</span>
            <?php $ck = $editQuestion ? (string)$editQuestion['choices_kind'] : 'text'; ?>
            <select class="input2 select-pro" name="choices_kind" id="choicesKind" required>
              <option value="text" <?php echo $ck === 'text' ? 'selected' : ''; ?>>📝 نص</option>
              <option value="image" <?php echo $ck === 'image' ? 'selected' : ''; ?>>🖼️ صورة</option>
              <option value="text_image" <?php echo $ck === 'text_image' ? 'selected' : ''; ?>>📝🖼️ نص + صورة</option>
            </select>
          </label>

          <label class="field">
            <span class="label">عدد الإجابات الصحيحة</span>
            <?php $coc = $editQuestion ? (int)$editQuestion['correct_choices_count'] : 1; ?>
            <select class="input2 select-pro" name="correct_choices_count" id="correctChoicesCount" required>
              <option value="1" <?php echo $coc === 1 ? 'selected' : ''; ?>>✅ واحدة</option>
              <option value="2" <?php echo $coc === 2 ? 'selected' : ''; ?>>✅✅ اثنين</option>
            </select>
          </label>

          <section class="choices-box" style="grid-column:1 / -1;">
            <div class="choices-head">
              <div>
                <div class="choices-title">🎯 اختيارات السؤال</div>
              </div>
              <button type="button" class="btn ghost" id="rebuildChoices">🔄 تحديث العرض</button>
            </div>

            <div class="choices-grid" id="choicesGrid"></div>

            <div class="aq-hint" style="margin-top:10px;">
              يمكنك إضافة أكثر من 10 اختيارات للسؤال الواحد حتى <?php echo h((string)$choicesMaxCount); ?> اختيارًا حسب إعدادات السيرفر.
            </div>
          </section>

          <div class="form-actions" style="grid-column:1 / -1;">
            <button class="btn" type="submit">💾 حفظ السؤال</button>
          </div>
        </form>
      </section>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة الأسئلة</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalQuestions); ?></span>
          </div>
        </div>

        <div class="table-wrap scroll-pro">
          <table class="table aq-table">
            <thead>
              <tr>
                <th>#</th>
                <th>الدرجة</th>
                <th>التصحيح</th>
                <th>نوع السؤال</th>
                <th>نوع الاختيارات</th>
                <th>الصحيح</th>
                <th>أضيف بتاريخ</th>
                <th>إجراءات</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$questions): ?>
                <tr><td colspan="8" style="text-align:center">لا يوجد أسئلة بعد.</td></tr>
              <?php endif; ?>

              <?php foreach ($questions as $q): ?>
                <tr>
                  <td data-label="#"><?php echo (int)$q['id']; ?></td>
                  <td data-label="الدرجة"><span class="tagx green">🎯 <?php echo h((string)$q['degree']); ?></span></td>
                  <td data-label="التصحيح"><?php echo h(correction_label((string)$q['correction_type'])); ?></td>
                  <td data-label="نوع السؤال"><?php echo h(kind_label((string)$q['question_kind'])); ?></td>
                  <td data-label="نوع الاختيارات"><?php echo h(kind_label((string)$q['choices_kind'])); ?></td>
                  <td data-label="عدد الصحيح"><span class="tagx purple">✅ <?php echo (int)$q['correct_choices_count']; ?></span></td>
                  <td data-label="أضيف بتاريخ"><?php echo h((string)$q['created_at']); ?></td>

                  <td data-label="إجراءات" class="actions">
                    <button class="link info js-preview" type="button" data-qid="<?php echo (int)$q['id']; ?>">👁️ معاينة</button>
                    <a class="link" href="exam-questions.php?bank_id=<?php echo (int)$bankId; ?>&edit=<?php echo (int)$q['id']; ?>">✏️ تعديل</a>

                    <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا السؤال؟');">
                      <input type="hidden" name="action" value="delete_question">
                      <input type="hidden" name="bank_id" value="<?php echo (int)$bankId; ?>">
                      <input type="hidden" name="question_id" value="<?php echo (int)$q['id']; ?>">
                      <button class="link danger" type="submit">🗑️ حذف</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>

            </tbody>
          </table>
        </div>
      </section>

      <!-- ✅ Modal preview overlay -->
      <div class="aq-modal" id="previewModal" aria-hidden="true">
        <div class="aq-modal__overlay" id="previewOverlay"></div>

        <div class="aq-modal__card" role="dialog" aria-modal="true" aria-label="معاينة السؤال">
          <div class="aq-modal__head">
            <div class="aq-modal__title">
              <div class="badge">👁️</div>
              <div>
                <h3 style="margin:0">معاينة السؤال مثل الطالب</h3>
                <p id="previewMeta" style="margin:6px 0 0; color: var(--muted); font-weight: 900;">...</p>
              </div>
            </div>
            <button class="aq-modal__close" type="button" id="previewClose" aria-label="إغلاق">✖</button>
          </div>

          <div class="aq-modal__body">
            <div class="student-q">
              <div class="student-q-head">❓ السؤال</div>
              <div class="student-q-text" id="previewQuestionText" style="display:none;"></div>
              <div class="student-q-img" id="previewQuestionImg" style="display:none;">
                <img id="previewQuestionImgEl" src="" alt="question image">
              </div>
            </div>

            <div class="student-choices">
              <div class="student-q-head">✅ اختر الإجابة</div>

              <form class="student-form" onsubmit="return false;" id="previewForm">
                <div class="student-hint" id="previewHint"></div>

                <div class="student-choices-grid" id="previewChoicesGrid"></div>

                <div class="student-actions">
                  <button class="btn" type="button" id="previewSubmit">✅ إرسال</button>
                  <button class="btn ghost" type="button" id="previewReset">🧹 مسح</button>
                </div>

                <div class="student-result" id="previewResult" style="display:none;"></div>
              </form>
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
      window.addEventListener('resize', syncInitial);

      // ===== Form dynamic UI
      const questionKind = document.getElementById('questionKind');
      const questionTextField = document.getElementById('questionTextField');
      const questionImageField = document.getElementById('questionImageField');
      const questionText = document.getElementById('questionText');

      const correctionType = document.getElementById('correctionType');
      const correctChoicesCount = document.getElementById('correctChoicesCount');

      const choicesCount = document.getElementById('choicesCount');
      const choicesKind = document.getElementById('choicesKind');

      const choicesGrid = document.getElementById('choicesGrid');
      const rebuildBtn = document.getElementById('rebuildChoices');

      const EDIT_CHOICES = <?php echo json_encode($editChoices, JSON_UNESCAPED_UNICODE); ?>;
      const CHOICES_MAX = <?php echo json_encode($choicesMaxCount, JSON_UNESCAPED_UNICODE); ?>;

      function syncQuestionUI() {
        const k = questionKind ? questionKind.value : 'text';
        const needsText = (k === 'text' || k === 'text_image');
        const needsImage = (k === 'image' || k === 'text_image');

        questionTextField.style.display = needsText ? '' : 'none';
        questionImageField.style.display = needsImage ? '' : 'none';

        if (questionText) questionText.required = needsText;
      }

      function syncCorrectionUI() {
        const t = correctionType ? correctionType.value : 'single';
        if (correctChoicesCount) {
          correctChoicesCount.value = (t === 'double') ? '2' : '1';
        }
      }

      function buildChoices() {
        if (!choicesGrid) return;
        let count = choicesCount ? parseInt(choicesCount.value || '4', 10) : 4;
        if (!Number.isFinite(count)) count = 4;
        count = Math.max(2, Math.min(CHOICES_MAX, count));
        if (choicesCount && String(count) !== choicesCount.value) choicesCount.value = String(count);
        const kind = choicesKind ? choicesKind.value : 'text';
        const correctNeed = correctChoicesCount ? parseInt(correctChoicesCount.value || '1', 10) : 1;
        const isMulti = (correctNeed === 2);

        const needsText = (kind === 'text' || kind === 'text_image');
        const needsImage = (kind === 'image' || kind === 'text_image');

        const map = {};
        if (Array.isArray(EDIT_CHOICES)) {
          EDIT_CHOICES.forEach(c => { map[parseInt(c.choice_index,10)] = c; });
        }

        const oldCorrect = Array.from(document.querySelectorAll('input[name="correct_choices[]"]:checked'))
          .map(i => parseInt(i.value || '0', 10))
          .filter(n => n > 0);

        choicesGrid.innerHTML = '';
        for (let i=1; i<=count; i++) {
          const old = map[i] || null;

          const card = document.createElement('div');
          card.className = 'choice-card';

          const head = document.createElement('div');
          head.className = 'choice-head';
          head.innerHTML = `
            <div class="choice-title">🧩 اختيار #${i}</div>
            <label class="choice-correct">
              <input type="${isMulti ? 'checkbox' : 'radio'}" name="correct_choices[]" value="${i}">
              <span>✅ صحيح</span>
            </label>
          `;

          const body = document.createElement('div');
          body.className = 'choice-body';

          if (needsText) {
            const ta = document.createElement('textarea');
            ta.className = 'textarea2';
            ta.name = `choice_text[${i}]`;
            ta.placeholder = 'اكتب نص الاختيار...';
            ta.required = true;
            ta.value = old && old.choice_text ? String(old.choice_text) : '';
            body.appendChild(ta);
          } else {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = `choice_text[${i}]`;
            hidden.value = '';
            body.appendChild(hidden);
          }

          if (needsImage) {
            const wrap = document.createElement('div');
            wrap.className = 'choice-img-field';
            wrap.innerHTML = `
              <div class="choice-img-label">🖼️ صورة الاختيار</div>
              <input class="input2" type="file" name="choice_image_${i}" accept="image/*">
              <div class="aq-hint">يدعم كل أنواع الصور.</div>
            `;
            body.appendChild(wrap);

            if (old && old.choice_image_path) {
              const prev = document.createElement('div');
              prev.className = 'img-prev';
              prev.innerHTML = `
                <div class="img-prev-title">الصورة الحالية:</div>
                <img src="${String(old.choice_image_path)}" alt="choice image">
              `;
              body.appendChild(prev);
            }
          }

          card.appendChild(head);
          card.appendChild(body);
          choicesGrid.appendChild(card);
        }

        const correctFromDB = [];
        if (Array.isArray(EDIT_CHOICES) && EDIT_CHOICES.length) {
          EDIT_CHOICES.forEach(c => { if (parseInt(c.is_correct,10) === 1) correctFromDB.push(parseInt(c.choice_index,10)); });
        }
        const setCorrect = oldCorrect.length ? oldCorrect : correctFromDB;

        setCorrect.forEach(idx => {
          const el = choicesGrid.querySelector(`input[name="correct_choices[]"][value="${idx}"]`);
          if (el) el.checked = true;
        });

        choicesGrid.querySelectorAll('input[name="correct_choices[]"]').forEach(inp => {
          inp.addEventListener('change', () => {
            const checked = Array.from(choicesGrid.querySelectorAll('input[name="correct_choices[]"]:checked'));
            if (checked.length > correctNeed) {
              inp.checked = false;
            }
          });
        });
      }

      questionKind && questionKind.addEventListener('change', syncQuestionUI);
      correctionType && correctionType.addEventListener('change', () => {
        syncCorrectionUI();
        buildChoices();
      });
      correctChoicesCount && correctChoicesCount.addEventListener('change', buildChoices);
      choicesCount && choicesCount.addEventListener('change', buildChoices);
      choicesKind && choicesKind.addEventListener('change', buildChoices);
      rebuildBtn && rebuildBtn.addEventListener('click', buildChoices);

      syncQuestionUI();
      syncCorrectionUI();
      buildChoices();

      // =========================
      // ✅ Preview modal
      // =========================
      const previewModal = document.getElementById('previewModal');
      const previewOverlay = document.getElementById('previewOverlay');
      const previewClose = document.getElementById('previewClose');

      const previewMeta = document.getElementById('previewMeta');
      const previewHint = document.getElementById('previewHint');

      const previewQuestionText = document.getElementById('previewQuestionText');
      const previewQuestionImg = document.getElementById('previewQuestionImg');
      const previewQuestionImgEl = document.getElementById('previewQuestionImgEl');

      const previewChoicesGrid = document.getElementById('previewChoicesGrid');
      const previewSubmit = document.getElementById('previewSubmit');
      const previewReset = document.getElementById('previewReset');
      const previewResult = document.getElementById('previewResult');

      let PREVIEW_DATA = null;

      function openPreviewModal() {
        if (!previewModal) return;
        previewModal.classList.add('open');
        previewModal.setAttribute('aria-hidden', 'false');
      }
      function closePreviewModal() {
        if (!previewModal) return;
        previewModal.classList.remove('open');
        previewModal.setAttribute('aria-hidden', 'true');
      }

      function nl2brSafe(s) {
        return String(s || '').replace(/\n/g, '<br>');
      }

      function renderPreview(data) {
        PREVIEW_DATA = data;
        const q = data.question || {};
        const choices = Array.isArray(data.choices) ? data.choices : [];
        const correct = Array.isArray(data.correct) ? data.correct : [];

        const DEG = parseFloat(q.degree || '0');
        const NEED = parseInt(q.correct_choices_count || '1', 10);
        const isMulti = (NEED === 2);

        previewMeta.textContent = `🎯 الدرجة: ${DEG.toFixed(2)} • ${q.correction_type === 'double' ? '✅✅ إجابتين' : '✅ إجابة واحدة'}`;
        previewHint.textContent = isMulti ? 'اختر إجابتين.' : 'اختر إجابة واحدة.';

        const qKind = String(q.question_kind || 'text');
        const qText = String(q.question_text || '');
        const qImg = String(q.question_image_path || '');

        if (qKind !== 'image' && qText.trim() !== '') {
          previewQuestionText.style.display = '';
          previewQuestionText.innerHTML = nl2brSafe(qText);
        } else {
          previewQuestionText.style.display = 'none';
          previewQuestionText.innerHTML = '';
        }

        if ((qKind === 'image' || qKind === 'text_image') && qImg.trim() !== '') {
          previewQuestionImg.style.display = '';
          previewQuestionImgEl.src = qImg;
        } else {
          previewQuestionImg.style.display = 'none';
          previewQuestionImgEl.src = '';
        }

        previewChoicesGrid.innerHTML = '';
        const cKind = String(q.choices_kind || 'text');
        const needsText = (cKind === 'text' || cKind === 'text_image');
        const needsImage = (cKind === 'image' || cKind === 'text_image');

        choices.forEach(ch => {
          const idx = parseInt(ch.choice_index || '0', 10);
          const cText = String(ch.choice_text || '');
          const cImg = String(ch.choice_image_path || '');

          const label = document.createElement('label');
          label.className = 'student-choice';
          label.setAttribute('data-idx', String(idx));

          label.innerHTML = `
            <input type="${isMulti ? 'checkbox' : 'radio'}" name="ans" value="${idx}">
            <span class="student-choice-body">
              <span class="student-choice-index">#${idx}</span>
              ${needsText && cText.trim() !== '' ? `<span class="student-choice-text">${nl2brSafe(cText)}</span>` : ``}
              ${needsImage && cImg.trim() !== '' ? `<span class="student-choice-img"><img src="${cImg}" alt="choice image"></span>` : ``}
            </span>
          `;
          previewChoicesGrid.appendChild(label);
        });

        if (previewResult) {
          previewResult.style.display = 'none';
          previewResult.className = 'student-result';
          previewResult.textContent = '';
        }

        previewChoicesGrid.querySelectorAll('.student-choice').forEach(el => {
          el.classList.remove('is-correct');
          el.classList.remove('is-wrong');
          el.classList.remove('is-reveal-correct');
        });
      }

      async function fetchPreview(qid) {
        const url = `exam-questions.php?bank_id=<?php echo (int)$bankId; ?>&action=preview_json&question_id=${encodeURIComponent(qid)}`;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        if (!json || !json.ok) throw new Error((json && json.error) ? json.error : 'Preview failed');
        return json;
      }

      document.addEventListener('click', async (e) => {
        const btn = e.target && e.target.closest ? e.target.closest('.js-preview') : null;
        if (!btn) return;

        e.preventDefault();

        const qid = btn.getAttribute('data-qid');
        if (!qid) return;

        try {
          btn.disabled = true;
          const data = await fetchPreview(qid);
          renderPreview(data);
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

      if (previewSubmit && previewResult) {
        previewSubmit.addEventListener('click', () => {
          if (!PREVIEW_DATA) return;

          const q = PREVIEW_DATA.question || {};
          const correct = Array.isArray(PREVIEW_DATA.correct) ? PREVIEW_DATA.correct : [];

          const NEED = parseInt(q.correct_choices_count || '1', 10);
          const DEG = parseFloat(q.degree || '0');

          const inputs = Array.from(previewChoicesGrid.querySelectorAll('input[name="ans"]'));
          const picked = inputs.filter(i => i.checked).map(i => parseInt(i.value, 10));

          previewChoicesGrid.querySelectorAll('.student-choice').forEach(el => {
            el.classList.remove('is-correct');
            el.classList.remove('is-wrong');
            el.classList.remove('is-reveal-correct');
          });

          if (picked.length !== NEED) {
            previewResult.style.display = '';
            previewResult.className = 'student-result warn';
            previewResult.textContent = (NEED === 2) ? 'من فضلك اختر إجابتين.' : 'من فضلك اختر إجابة واحدة.';
            return;
          }

          picked.forEach(idx => {
            const el = previewChoicesGrid.querySelector(`.student-choice[data-idx="${idx}"]`);
            if (!el) return;
            if (correct.includes(idx)) el.classList.add('is-correct');
            else el.classList.add('is-wrong');
          });

          correct.forEach(idx => {
            const el = previewChoicesGrid.querySelector(`.student-choice[data-idx="${idx}"]`);
            if (!el) return;
            if (!el.classList.contains('is-correct')) el.classList.add('is-reveal-correct');
          });

          let score = 0;
          if (NEED === 1) {
            score = (picked.length === 1 && correct.includes(picked[0])) ? DEG : 0;
          } else {
            let correctCount = 0;
            picked.forEach(p => { if (correct.includes(p)) correctCount++; });
            if (correctCount === 2) score = DEG;
            else if (correctCount === 1) score = DEG / 2;
            else score = 0;
          }

          previewResult.style.display = '';
          previewResult.className = 'student-result ok';
          previewResult.textContent = '✅ نتيجة تجريبية: درجتك = ' + score.toFixed(2) + ' من ' + DEG.toFixed(2);
        });
      }

      if (previewReset && previewResult) {
        previewReset.addEventListener('click', () => {
          previewChoicesGrid.querySelectorAll('input[name="ans"]').forEach(i => i.checked = false);
          previewResult.style.display = 'none';
          previewChoicesGrid.querySelectorAll('.student-choice').forEach(el => {
            el.classList.remove('is-correct');
            el.classList.remove('is-wrong');
            el.classList.remove('is-reveal-correct');
          });
        });
      }
    })();
  </script>
</body>
</html>
