<?php
require_once __DIR__ . '/path_helpers.php';

function student_assessment_type_config(string $type): ?array {
  $type = strtolower(trim($type));

  if ($type === 'exam') {
    return [
      'type' => 'exam',
      'label' => 'الامتحان',
      'plural_label' => 'الامتحانات',
      'icon' => '🧠',
      'page' => 'exams',
      'assessment_table' => 'exams',
      'assessment_pk' => 'id',
      'attempt_table' => 'exam_attempts',
      'attempt_fk' => 'exam_id',
      'attempt_questions_table' => 'exam_attempt_questions',
      'attempt_answers_table' => 'exam_attempt_answers',
      'question_table' => 'exam_questions',
      'choice_table' => 'exam_question_choices',
      'bank_table' => 'exam_question_banks',
    ];
  }

  if ($type === 'assignment') {
    return [
      'type' => 'assignment',
      'label' => 'الواجب',
      'plural_label' => 'الواجبات',
      'icon' => '📝',
      'page' => 'assignments',
      'assessment_table' => 'assignments',
      'assessment_pk' => 'id',
      'attempt_table' => 'assignment_attempts',
      'attempt_fk' => 'assignment_id',
      'attempt_questions_table' => 'assignment_attempt_questions',
      'attempt_answers_table' => 'assignment_attempt_answers',
      'question_table' => 'assignment_questions',
      'choice_table' => 'assignment_question_choices',
      'bank_table' => 'assignment_question_banks',
    ];
  }

  return null;
}

function student_assessment_ensure_attempt_tables(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  $queries = [
    "
      CREATE TABLE IF NOT EXISTS assignment_attempts (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        assignment_id INT(10) UNSIGNED NOT NULL,
        student_id INT(10) UNSIGNED NOT NULL,
        started_at TIMESTAMP NULL DEFAULT NULL,
        submitted_at TIMESTAMP NULL DEFAULT NULL,
        duration_minutes INT(10) UNSIGNED NOT NULL,
        score DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        max_score DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
        PRIMARY KEY (id),
        KEY idx_assignment_id (assignment_id),
        KEY idx_student_id (student_id),
        KEY idx_status (status),
        CONSTRAINT fk_student_assignment_attempt_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_assignment_attempt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "
      CREATE TABLE IF NOT EXISTS assignment_attempt_questions (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        attempt_id INT(10) UNSIGNED NOT NULL,
        question_id INT(10) UNSIGNED NOT NULL,
        sort_order INT(10) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_attempt_id (attempt_id),
        KEY idx_question_id (question_id),
        CONSTRAINT fk_student_assignment_attempt_question_attempt FOREIGN KEY (attempt_id) REFERENCES assignment_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_assignment_attempt_question_question FOREIGN KEY (question_id) REFERENCES assignment_questions(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "
      CREATE TABLE IF NOT EXISTS assignment_attempt_answers (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        attempt_id INT(10) UNSIGNED NOT NULL,
        question_id INT(10) UNSIGNED NOT NULL,
        choice_id INT(10) UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY idx_attempt_id (attempt_id),
        KEY idx_question_id (question_id),
        KEY idx_choice_id (choice_id),
        CONSTRAINT fk_student_assignment_attempt_answer_attempt FOREIGN KEY (attempt_id) REFERENCES assignment_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_assignment_attempt_answer_question FOREIGN KEY (question_id) REFERENCES assignment_questions(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_assignment_attempt_answer_choice FOREIGN KEY (choice_id) REFERENCES assignment_question_choices(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "
      CREATE TABLE IF NOT EXISTS exam_attempts (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        exam_id INT(10) UNSIGNED NOT NULL,
        student_id INT(10) UNSIGNED NOT NULL,
        started_at TIMESTAMP NULL DEFAULT NULL,
        submitted_at TIMESTAMP NULL DEFAULT NULL,
        duration_minutes INT(10) UNSIGNED NOT NULL,
        score DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        max_score DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
        PRIMARY KEY (id),
        KEY idx_exam_id (exam_id),
        KEY idx_student_id (student_id),
        KEY idx_status (status),
        CONSTRAINT fk_student_exam_attempt_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_exam_attempt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "
      CREATE TABLE IF NOT EXISTS exam_attempt_questions (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        attempt_id INT(10) UNSIGNED NOT NULL,
        question_id INT(10) UNSIGNED NOT NULL,
        sort_order INT(10) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_attempt_id (attempt_id),
        KEY idx_question_id (question_id),
        CONSTRAINT fk_student_exam_attempt_question_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_exam_attempt_question_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
    "
      CREATE TABLE IF NOT EXISTS exam_attempt_answers (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        attempt_id INT(10) UNSIGNED NOT NULL,
        question_id INT(10) UNSIGNED NOT NULL,
        choice_id INT(10) UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY idx_attempt_id (attempt_id),
        KEY idx_question_id (question_id),
        KEY idx_choice_id (choice_id),
        CONSTRAINT fk_student_exam_attempt_answer_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_exam_attempt_answer_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_exam_attempt_answer_choice FOREIGN KEY (choice_id) REFERENCES exam_question_choices(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ",
  ];

  foreach ($queries as $sql) {
    $pdo->exec($sql);
  }

  $done = true;
}

function student_assessment_format_number(float $value): string {
  if (abs($value - round($value)) < 0.001) {
    return number_format($value, 0, '.', '');
  }

  return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function student_assessment_format_score_pair(float $score, float $max): string {
  return student_assessment_format_number($score) . ' / ' . student_assessment_format_number($max);
}

function student_assessment_media_url(?string $path): ?string {
  $path = trim((string)$path);
  if ($path === '') return null;
  return student_public_asset_url($path);
}

function student_assessment_fetch_item(PDO $pdo, int $gradeId, string $type, int $assessmentId): ?array {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $assessmentId <= 0 || $gradeId <= 0) return null;

  $sql = "
    SELECT a.*, g.name AS grade_name, b.name AS bank_name
    FROM {$cfg['assessment_table']} a
    INNER JOIN grades g ON g.id = a.grade_id
    INNER JOIN {$cfg['bank_table']} b ON b.id = a.bank_id
    WHERE a.id = ? AND a.grade_id = ?
    LIMIT 1
  ";

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$assessmentId, $gradeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function student_assessment_fetch_latest_attempt(PDO $pdo, string $type, int $assessmentId, int $studentId): ?array {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $assessmentId <= 0 || $studentId <= 0) return null;

  student_assessment_ensure_attempt_tables($pdo);

  $sql = "
    SELECT *
    FROM {$cfg['attempt_table']}
    WHERE {$cfg['attempt_fk']} = ? AND student_id = ?
    ORDER BY id DESC
    LIMIT 1
  ";

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$assessmentId, $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function student_assessment_attempt_answer_summary(PDO $pdo, string $type, int $attemptId): array {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $attemptId <= 0) {
    return [
      'question_count' => 0,
      'answered_count' => 0,
    ];
  }

  student_assessment_ensure_attempt_tables($pdo);

  try {
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM {$cfg['attempt_questions_table']}
      WHERE attempt_id = ?
    ");
    $stmt->execute([$attemptId]);
    $questionCount = max(0, (int)($stmt->fetchColumn() ?: 0));

    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT question_id) FROM {$cfg['attempt_answers_table']}
      WHERE attempt_id = ?
    ");
    $stmt->execute([$attemptId]);
    $answeredCount = max(0, (int)($stmt->fetchColumn() ?: 0));

    return [
      'question_count' => $questionCount,
      'answered_count' => $answeredCount,
    ];
  } catch (Throwable $e) {
    return [
      'question_count' => 0,
      'answered_count' => 0,
    ];
  }
}

function student_assessment_attempt_is_completed(PDO $pdo, string $type, ?array $attempt): bool {
  if (!$attempt || (string)($attempt['status'] ?? '') !== 'submitted') {
    return false;
  }

  $attemptId = (int)($attempt['id'] ?? 0);
  if ($attemptId <= 0) {
    return false;
  }

  $summary = student_assessment_attempt_answer_summary($pdo, $type, $attemptId);
  return $summary['question_count'] > 0 && $summary['answered_count'] >= $summary['question_count'];
}

function student_assessment_resolve_duration_minutes(int $attemptDurationMinutes, int $assessmentDurationMinutes): int {
  if ($attemptDurationMinutes > 0) return $attemptDurationMinutes;
  if ($assessmentDurationMinutes > 0) return $assessmentDurationMinutes;
  return 1;
}

function student_assessment_resolve_remaining_seconds(array $attempt, int $durationMinutes): int {
  $attemptStatus = (string)($attempt['status'] ?? '');
  if ($attemptStatus !== 'in_progress') return 0;

  $startedAtTs = isset($attempt['started_at_ts']) ? (int)$attempt['started_at_ts'] : 0;
  $nowTs = isset($attempt['db_now_ts']) ? (int)$attempt['db_now_ts'] : time();

  if ($startedAtTs <= 0 || $durationMinutes <= 0) {
    return 0;
  }

  return max(0, ($startedAtTs + ($durationMinutes * 60)) - $nowTs);
}

function student_assessment_normalize_attempt_timing(PDO $pdo, string $type, int $studentId, ?int $attemptId = null): void {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $studentId <= 0) return;

  student_assessment_ensure_attempt_tables($pdo);

  $sql = "
    UPDATE {$cfg['attempt_table']} att
    INNER JOIN {$cfg['assessment_table']} a
      ON a.{$cfg['assessment_pk']} = att.{$cfg['attempt_fk']}
    SET
      att.started_at = COALESCE(att.started_at, NOW()),
      att.duration_minutes = CASE
        WHEN att.duration_minutes > 0 THEN att.duration_minutes
        WHEN a.duration_minutes > 0 THEN a.duration_minutes
        ELSE 1
      END
    WHERE att.student_id = ?
      AND att.status = 'in_progress'
      AND (
        att.started_at IS NULL
        OR att.duration_minutes <= 0
      )
  ";

  $params = [$studentId];
  if ($attemptId !== null && $attemptId > 0) {
    $sql .= " AND att.id = ?";
    $params[] = $attemptId;
  }

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
  } catch (Throwable $e) {
    error_log('Failed to normalize assessment timing: ' . $e->getMessage());
  }
}

function student_assessment_expire_stale_attempts(PDO $pdo, string $type, int $studentId): void {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $studentId <= 0) return;

  student_assessment_ensure_attempt_tables($pdo);
  student_assessment_normalize_attempt_timing($pdo, $type, $studentId);

  $sql = "
    UPDATE {$cfg['attempt_table']}
    SET status = 'expired',
        submitted_at = COALESCE(submitted_at, NOW())
    WHERE student_id = ?
      AND status = 'in_progress'
      AND started_at IS NOT NULL
      AND DATE_ADD(started_at, INTERVAL duration_minutes MINUTE) <= NOW()
  ";

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$studentId]);
  } catch (Throwable $e) {
    // non-fatal
  }
}

function student_assessment_fetch_cards(PDO $pdo, int $studentId, int $gradeId, string $type): array {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $studentId <= 0 || $gradeId <= 0) return [];

  student_assessment_ensure_attempt_tables($pdo);
  student_assessment_expire_stale_attempts($pdo, $type, $studentId);

  $sql = "
    SELECT
      a.*,
      g.name AS grade_name,
      b.name AS bank_name,
      att.id AS attempt_id,
      att.started_at,
      att.submitted_at,
      att.duration_minutes AS attempt_duration_minutes,
      att.score,
      att.max_score,
      att.status AS attempt_status
    FROM {$cfg['assessment_table']} a
    INNER JOIN grades g ON g.id = a.grade_id
    INNER JOIN {$cfg['bank_table']} b ON b.id = a.bank_id
    LEFT JOIN {$cfg['attempt_table']} att
      ON att.id = (
        SELECT MAX(att2.id)
        FROM {$cfg['attempt_table']} att2
        WHERE att2.{$cfg['attempt_fk']} = a.id
          AND att2.student_id = ?
      )
    WHERE a.grade_id = ?
    ORDER BY a.id DESC
  ";

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$studentId, $gradeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }

  $cards = [];
  foreach ($rows as $row) {
    $attemptStatus = (string)($row['attempt_status'] ?? '');
    $statusKey = 'available';
    $statusLabel = 'متاح الآن';
    $actionLabel = 'ابدأ الآن';
    $statusIcon = '🟢';

    if ($attemptStatus === 'in_progress') {
      $statusKey = 'in_progress';
      $statusLabel = 'تم البدء ويمكنك الاستكمال';
      $actionLabel = 'استكمال الحل';
      $statusIcon = '🟡';
    } elseif ($attemptStatus === 'submitted') {
      $statusKey = 'submitted';
      $statusLabel = 'تم الحل';
      $actionLabel = 'مراجعة النتيجة';
      $statusIcon = '✅';
    } elseif ($attemptStatus === 'expired') {
      $statusKey = 'expired';
      $statusLabel = 'انتهى الوقت';
      $actionLabel = 'مراجعة النتيجة';
      $statusIcon = '⏱️';
    }

    $cards[] = [
      'assessment_id' => (int)($row['id'] ?? 0),
      'name' => (string)($row['name'] ?? ''),
      'grade_name' => (string)($row['grade_name'] ?? ''),
      'bank_name' => (string)($row['bank_name'] ?? ''),
      'duration_minutes' => (int)($row['duration_minutes'] ?? 0),
      'questions_total' => (int)($row['questions_total'] ?? 0),
      'questions_per_student' => (int)($row['questions_per_student'] ?? 0),
      'created_at' => (string)($row['created_at'] ?? ''),
      'attempt_id' => (int)($row['attempt_id'] ?? 0),
      'attempt_status' => $attemptStatus,
      'status_key' => $statusKey,
      'status_label' => $statusLabel,
      'status_icon' => $statusIcon,
      'action_label' => $actionLabel,
      'score' => (float)($row['score'] ?? 0),
      'max_score' => (float)($row['max_score'] ?? 0),
      'score_text' => student_assessment_format_score_pair((float)($row['score'] ?? 0), (float)($row['max_score'] ?? 0)),
      'started_at' => (string)($row['started_at'] ?? ''),
      'submitted_at' => (string)($row['submitted_at'] ?? ''),
      'href' => 'assessment.php?type=' . rawurlencode($type) . '&id=' . (int)($row['id'] ?? 0),
    ];
  }

  return $cards;
}

function student_assessment_cards_summary(array $cards): array {
  $available = 0;
  $earned = 0.0;
  $max = 0.0;

  foreach ($cards as $card) {
    $status = (string)($card['status_key'] ?? 'available');
    if (in_array($status, ['submitted', 'expired'], true)) {
      $earned += (float)($card['score'] ?? 0);
      $max += (float)($card['max_score'] ?? 0);
    } else {
      $available++;
    }
  }

  return [
    'available_count' => $available,
    'earned_score' => $earned,
    'max_score' => $max,
    'score_text' => student_assessment_format_score_pair($earned, $max),
  ];
}

function student_assessment_create_attempt(PDO $pdo, int $studentId, int $gradeId, string $type, int $assessmentId): array {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $studentId <= 0 || $gradeId <= 0 || $assessmentId <= 0) {
    return ['ok' => false, 'error' => 'بيانات غير صحيحة لبدء المحاولة.'];
  }

  student_assessment_ensure_attempt_tables($pdo);

  $item = student_assessment_fetch_item($pdo, $gradeId, $type, $assessmentId);
  if (!$item) {
    return ['ok' => false, 'error' => 'هذا ' . $cfg['label'] . ' غير متاح لصفك الدراسي.'];
  }

  $bankId = (int)($item['bank_id'] ?? 0);
  $questionsTotal = (int)($item['questions_total'] ?? 0);
  $perStudent = (int)($item['questions_per_student'] ?? 0);

  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$cfg['question_table']} WHERE bank_id = ?");
    $stmt->execute([$bankId]);
    $bankCount = (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'تعذر تحميل أسئلة ' . $cfg['label'] . '.'];
  }

  if ($bankCount <= 0) {
    return ['ok' => false, 'error' => 'لا توجد أسئلة مضافة لهذا ' . $cfg['label'] . ' بعد.'];
  }

  if ($questionsTotal > $bankCount) $questionsTotal = $bankCount;
  if ($perStudent > $questionsTotal) $perStudent = $questionsTotal;
  if ($perStudent < 1) $perStudent = min(1, $bankCount);
  if ($perStudent < 1) {
    return ['ok' => false, 'error' => 'عدد الأسئلة غير صالح.'];
  }

  try {
    $stmt = $pdo->prepare("
      SELECT *
      FROM {$cfg['question_table']}
      WHERE bank_id = ?
      ORDER BY RAND()
      LIMIT {$perStudent}
    ");
    $stmt->execute([$bankId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'تعذر تجهيز أسئلة ' . $cfg['label'] . '.'];
  }

  if (empty($questions)) {
    return ['ok' => false, 'error' => 'تعذر تجهيز أسئلة ' . $cfg['label'] . '.'];
  }

  $maxScore = 0.0;
  foreach ($questions as $question) {
    $maxScore += (float)($question['degree'] ?? 0);
  }

  $attemptDurationMinutes = max(1, (int)($item['duration_minutes'] ?? 0));

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      INSERT INTO {$cfg['attempt_table']}
        ({$cfg['attempt_fk']}, student_id, started_at, duration_minutes, score, max_score, status)
      VALUES
        (?, ?, NOW(), ?, 0, ?, 'in_progress')
    ");
    $stmt->execute([
      $assessmentId,
      $studentId,
      $attemptDurationMinutes,
      $maxScore,
    ]);

    $attemptId = (int)$pdo->lastInsertId();

    $stmtInsert = $pdo->prepare("
      INSERT INTO {$cfg['attempt_questions_table']} (attempt_id, question_id, sort_order)
      VALUES (?, ?, ?)
    ");

    foreach (array_values($questions) as $idx => $question) {
      $stmtInsert->execute([
        $attemptId,
        (int)($question['id'] ?? 0),
        $idx + 1,
      ]);
    }

    $pdo->commit();

    return ['ok' => true, 'attempt_id' => $attemptId];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok' => false, 'error' => 'تعذر بدء المحاولة الحالية.'];
  }
}

function student_assessment_fetch_attempt_payload(PDO $pdo, string $type, int $attemptId, int $studentId): ?array {
  $cfg = student_assessment_type_config($type);
  if (!$cfg || $attemptId <= 0 || $studentId <= 0) return null;

  student_assessment_ensure_attempt_tables($pdo);
  student_assessment_normalize_attempt_timing($pdo, $type, $studentId, $attemptId);

  try {
    $stmt = $pdo->prepare("
      SELECT
        att.*,
        a.name,
        a.grade_id,
        a.bank_id,
        a.duration_minutes AS assessment_duration_minutes,
        a.questions_total,
        a.questions_per_student,
        a.created_at AS assessment_created_at,
        UNIX_TIMESTAMP(att.started_at) AS started_at_ts,
        UNIX_TIMESTAMP(NOW()) AS db_now_ts,
        g.name AS grade_name,
        b.name AS bank_name
      FROM {$cfg['attempt_table']} att
      INNER JOIN {$cfg['assessment_table']} a ON a.id = att.{$cfg['attempt_fk']}
      INNER JOIN grades g ON g.id = a.grade_id
      INNER JOIN {$cfg['bank_table']} b ON b.id = a.bank_id
      WHERE att.id = ? AND att.student_id = ?
      LIMIT 1
    ");
    $stmt->execute([$attemptId, $studentId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) return null;

    $stmt = $pdo->prepare("
      SELECT aq.sort_order, q.*
      FROM {$cfg['attempt_questions_table']} aq
      INNER JOIN {$cfg['question_table']} q ON q.id = aq.question_id
      WHERE aq.attempt_id = ?
      ORDER BY aq.sort_order ASC, aq.id ASC
    ");
    $stmt->execute([$attemptId]);
    $questionRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($questionRows)) return null;

    $questionIds = array_values(array_filter(array_map(fn($row) => (int)($row['id'] ?? 0), $questionRows)));
    $choicesByQuestion = [];
    if (!empty($questionIds)) {
      $ph = implode(',', array_fill(0, count($questionIds), '?'));
      $stmt = $pdo->prepare("
        SELECT *
        FROM {$cfg['choice_table']}
        WHERE question_id IN ($ph)
        ORDER BY question_id ASC, choice_index ASC, id ASC
      ");
      $stmt->execute($questionIds);
      $choices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($choices as $choice) {
        $qid = (int)($choice['question_id'] ?? 0);
        if (!isset($choicesByQuestion[$qid])) $choicesByQuestion[$qid] = [];
        $choicesByQuestion[$qid][] = $choice;
      }
    }

    $stmt = $pdo->prepare("
      SELECT question_id, choice_id
      FROM {$cfg['attempt_answers_table']}
      WHERE attempt_id = ?
      ORDER BY question_id ASC, id ASC
    ");
    $stmt->execute([$attemptId]);
    $answerRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $selectedByQuestion = [];
    foreach ($answerRows as $answerRow) {
      $qid = (int)($answerRow['question_id'] ?? 0);
      if (!isset($selectedByQuestion[$qid])) $selectedByQuestion[$qid] = [];
      $selectedByQuestion[$qid][] = (int)($answerRow['choice_id'] ?? 0);
    }

    $questions = [];
    foreach ($questionRows as $questionRow) {
      $questionId = (int)($questionRow['id'] ?? 0);
      $choiceRows = $choicesByQuestion[$questionId] ?? [];
      $selectedChoiceIds = array_values(array_unique(array_map('intval', $selectedByQuestion[$questionId] ?? [])));
      sort($selectedChoiceIds);

      $selectedChoiceIndices = [];
      $correctChoiceIds = [];
      $correctChoiceIndices = [];
      foreach ($choiceRows as $choiceRow) {
        $choiceId = (int)($choiceRow['id'] ?? 0);
        $choiceIndex = (int)($choiceRow['choice_index'] ?? 0);
        if (in_array($choiceId, $selectedChoiceIds, true)) $selectedChoiceIndices[] = $choiceIndex;
        if ((int)($choiceRow['is_correct'] ?? 0) === 1) {
          $correctChoiceIds[] = $choiceId;
          $correctChoiceIndices[] = $choiceIndex;
        }
      }

      sort($selectedChoiceIndices);
      sort($correctChoiceIds);
      sort($correctChoiceIndices);

      $isCorrect = ($selectedChoiceIds === $correctChoiceIds);
      $degree = (float)($questionRow['degree'] ?? 0);

      $questions[] = [
        'q' => $questionRow,
        'choices' => $choiceRows,
        'selected_choice_ids' => $selectedChoiceIds,
        'selected_choice_indices' => $selectedChoiceIndices,
        'correct_choice_ids' => $correctChoiceIds,
        'correct_choice_indices' => $correctChoiceIndices,
        'is_correct' => $isCorrect,
        'question_score' => $isCorrect ? $degree : 0.0,
      ];
    }

    $attemptStatus = (string)($attempt['status'] ?? '');
    $startedAt = trim((string)($attempt['started_at'] ?? ''));
    $durationMinutes = (int)($attempt['duration_minutes'] ?? 0);
    $assessmentDurationMinutes = (int)($attempt['assessment_duration_minutes'] ?? 0);
    $startedAtTs = isset($attempt['started_at_ts']) ? (int)$attempt['started_at_ts'] : 0;
    $shouldResetStartedAt = ($attemptStatus === 'in_progress' && ($startedAt === '' || $startedAtTs <= 0));
    $resolvedDurationMinutes = student_assessment_resolve_duration_minutes($durationMinutes, $assessmentDurationMinutes);
    $shouldResetDuration = ($attemptStatus === 'in_progress' && $durationMinutes !== $resolvedDurationMinutes);

    if ($shouldResetStartedAt) {
      $startedAt = date('Y-m-d H:i:s');
      $attempt['started_at'] = $startedAt;
      $startedAtTs = time();
      $attempt['started_at_ts'] = $startedAtTs;
    }
    if ($shouldResetDuration) {
      $durationMinutes = $resolvedDurationMinutes;
      $attempt['duration_minutes'] = $durationMinutes;
    }
    if ($shouldResetStartedAt || $shouldResetDuration) {
      $sets = [];
      $params = [];
      if ($shouldResetStartedAt) {
        $sets[] = "started_at = ?";
        $params[] = $startedAt;
      }
      if ($shouldResetDuration) {
        $sets[] = "duration_minutes = ?";
        $params[] = $durationMinutes;
      }
      $params[] = $attemptId;
      $params[] = $studentId;

      try {
        $stmt = $pdo->prepare("
          UPDATE {$cfg['attempt_table']}
          SET " . implode(', ', $sets) . "
          WHERE id = ? AND student_id = ?
        ");
        $stmt->execute($params);
      } catch (Throwable $e) {
        error_log('Failed to persist normalized assessment timing; continuing with in-memory values.');
      }
    }

    $remainingSeconds = student_assessment_resolve_remaining_seconds($attempt, $durationMinutes);

    return [
      'config' => $cfg,
      'attempt' => $attempt,
      'questions' => $questions,
      'remaining_seconds' => $remainingSeconds,
      'is_finished' => ((string)($attempt['status'] ?? '') !== 'in_progress'),
    ];
  } catch (Throwable $e) {
    return null;
  }
}

function student_assessment_submit_attempt(PDO $pdo, string $type, int $attemptId, int $studentId, array $postedAnswers): array {
  $payload = student_assessment_fetch_attempt_payload($pdo, $type, $attemptId, $studentId);
  if (!$payload) {
    return ['ok' => false, 'error' => 'تعذر تحميل المحاولة الحالية.'];
  }

  $cfg = $payload['config'];
  $attempt = $payload['attempt'];
  if ((string)($attempt['status'] ?? '') !== 'in_progress') {
    return ['ok' => false, 'error' => 'تم تسليم هذه المحاولة بالفعل.'];
  }

  $allowedQuestions = [];
  $allowedChoices = [];
  $score = 0.0;
  $maxScore = 0.0;

  foreach ($payload['questions'] as $questionItem) {
    $question = $questionItem['q'];
    $questionId = (int)($question['id'] ?? 0);
    $allowedQuestions[$questionId] = [
      'degree' => (float)($question['degree'] ?? 0),
      'correct_choice_ids' => $questionItem['correct_choice_ids'],
    ];
    $maxScore += (float)($question['degree'] ?? 0);

    $allowedChoices[$questionId] = [];
    foreach ($questionItem['choices'] as $choice) {
      $allowedChoices[$questionId][(int)($choice['id'] ?? 0)] = true;
    }
  }

  $normalizedAnswers = [];
  foreach ($postedAnswers as $questionIdRaw => $answerValue) {
    $questionId = (int)$questionIdRaw;
    if (!isset($allowedQuestions[$questionId])) continue;

    $values = is_array($answerValue) ? $answerValue : [$answerValue];
    $choiceIds = [];
    foreach ($values as $value) {
      $choiceId = (int)$value;
      if ($choiceId <= 0) continue;
      if (!isset($allowedChoices[$questionId][$choiceId])) continue;
      $choiceIds[] = $choiceId;
    }

    $choiceIds = array_values(array_unique($choiceIds));
    sort($choiceIds);
    $normalizedAnswers[$questionId] = $choiceIds;
  }

  foreach ($allowedQuestions as $questionId => $meta) {
    $selected = $normalizedAnswers[$questionId] ?? [];
    $correct = $meta['correct_choice_ids'];
    sort($correct);
    if ($selected === $correct) {
      $score += (float)$meta['degree'];
    }
  }

  $isExpired = ((int)($payload['remaining_seconds'] ?? 0) <= 0);
  $status = $isExpired ? 'expired' : 'submitted';

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("DELETE FROM {$cfg['attempt_answers_table']} WHERE attempt_id = ?");
    $stmt->execute([$attemptId]);

    $stmtInsert = $pdo->prepare("
      INSERT INTO {$cfg['attempt_answers_table']} (attempt_id, question_id, choice_id)
      VALUES (?, ?, ?)
    ");

    foreach ($normalizedAnswers as $questionId => $choiceIds) {
      foreach ($choiceIds as $choiceId) {
        $stmtInsert->execute([$attemptId, $questionId, $choiceId]);
      }
    }

    $stmt = $pdo->prepare("
      UPDATE {$cfg['attempt_table']}
      SET score = ?, max_score = ?, status = ?, submitted_at = NOW()
      WHERE id = ? AND student_id = ?
    ");
    $stmt->execute([$score, $maxScore, $status, $attemptId, $studentId]);

    $pdo->commit();

    return ['ok' => true, 'status' => $status];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok' => false, 'error' => 'تعذر تسليم المحاولة الحالية.'];
  }
}
