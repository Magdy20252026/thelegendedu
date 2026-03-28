<?php
// students/api/redeem_code_api.php
// ✅ Redeem access codes with support for:
// 1) access_codes + access_code_redemptions (normal flow)
// 2) legacy course_codes / lecture_codes WITHOUT inserting into access_codes (avoids CONSTRAINT_1 check failure)
//    and records usage into legacy_code_redemptions (single-use via is_used)

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/student_auth.php';
require __DIR__ . '/../inc/access_control.php';

no_cache_headers();
student_require_login();

$studentId = (int)($_SESSION['student_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

$code            = trim((string)($_POST['code'] ?? ''));
$targetCourseId  = (int)($_POST['target_course_id'] ?? 0);
$targetLectureId = (int)($_POST['target_lecture_id'] ?? 0);

if ($code === '') {
  echo json_encode(['ok' => false, 'message' => 'من فضلك أدخل الكود.'], JSON_UNESCAPED_UNICODE);
  exit;
}

/** course_codes/lecture_codes expires_at is DATE => treat as end-of-day */
function date_to_eod(?string $ymd): ?string {
  $d = trim((string)$ymd);
  if ($d === '') return null;
  return $d . ' 23:59:59';
}

function safeRollback(PDO $pdo): void {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
}

function ensure_legacy_redemptions_table(PDO $pdo): void {
  // You already created this table, but keep this for safety (no harm)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS legacy_code_redemptions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      code VARCHAR(64) NOT NULL,
      legacy_table ENUM('course_codes','lecture_codes') NOT NULL,
      legacy_id BIGINT UNSIGNED NOT NULL,
      student_id BIGINT UNSIGNED NOT NULL,
      redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_code_student (code, student_id),
      KEY idx_student (student_id),
      KEY idx_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

function legacy_already_used_by_student(PDO $pdo, string $code, int $studentId): bool {
  $stmt = $pdo->prepare("SELECT 1 FROM legacy_code_redemptions WHERE code=? AND student_id=? LIMIT 1");
  $stmt->execute([$code, $studentId]);
  return (bool)$stmt->fetchColumn();
}

try {
  $pdo->beginTransaction();

  // ------------------------------------------------------------
  // (1) First try normal access_codes flow (works for codes already there)
  // ------------------------------------------------------------
  $stmt = $pdo->prepare("
    SELECT id, type, course_id, lecture_id, is_active, max_uses, used_count, expires_at
    FROM access_codes
    WHERE code = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$code]);
  $ac = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($ac) {
    if ((int)$ac['is_active'] !== 1) throw new RuntimeException('الكود غير مفعل.');

    if (!empty($ac['expires_at'])) {
      $expiresAt = strtotime((string)$ac['expires_at']);
      if ($expiresAt !== false && $expiresAt < time()) throw new RuntimeException('انتهت صلاحية هذا الكود.');
    }

    $maxUses   = $ac['max_uses'] !== null ? (int)$ac['max_uses'] : null;
    $usedCount = (int)$ac['used_count'];
    if ($maxUses !== null && $usedCount >= $maxUses) throw new RuntimeException('تم استهلاك هذا الكود بالكامل.');

    $codeId = (int)$ac['id'];

    // prevent same student
    $stmt = $pdo->prepare("SELECT 1 FROM access_code_redemptions WHERE code_id=? AND student_id=? LIMIT 1");
    $stmt->execute([$codeId, $studentId]);
    if ($stmt->fetchColumn()) throw new RuntimeException('أنت استخدمت هذا الكود من قبل.');

    $type = (string)$ac['type'];

    if ($type === 'course') {
      $courseId = (int)($ac['course_id'] ?? 0);
      $isGlobal = ($courseId <= 0);

      if ($isGlobal) {
        if ($targetCourseId <= 0) {
          safeRollback($pdo);
          $stmtC = $pdo->prepare("SELECT id, name FROM courses ORDER BY name ASC");
          $stmtC->execute();
          $courses = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
          echo json_encode([
            'ok'          => false,
            'needs_target'=> true,
            'target_type' => 'course',
            'message'     => 'هذا الكود عام — اختر الكورس الذي تريد فتحه.',
            'courses'     => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']], $courses),
          ], JSON_UNESCAPED_UNICODE);
          exit;
        }
        $courseId = $targetCourseId;
      }

      $stmt = $pdo->prepare("SELECT access_type FROM courses WHERE id=? LIMIT 1");
      $stmt->execute([$courseId]);
      $accessType = (string)($stmt->fetchColumn() ?: '');
      if ($accessType === '') throw new RuntimeException('الكورس غير موجود.');
      if ($accessType === 'attendance') throw new RuntimeException('هذا الكورس يفتح بالحضور فقط ولا يمكن تفعيله بالكود.');

      if (student_has_course_access($pdo, $studentId, $courseId)) {
        safeRollback($pdo);
        echo json_encode(['ok' => true, 'already' => true, 'message' => 'أنت بالفعل مشترك في هذا الكورس.', 'course_id' => $courseId], JSON_UNESCAPED_UNICODE);
        exit;
      }

      $pdo->prepare("INSERT INTO access_code_redemptions (code_id, student_id) VALUES (?, ?)")
          ->execute([$codeId, $studentId]);

      $pdo->prepare("UPDATE access_codes SET used_count = used_count + 1 WHERE id=?")
          ->execute([$codeId]);

      $pdo->prepare("
        INSERT INTO student_course_enrollments (student_id, course_id, access_type)
        VALUES (?, ?, 'code')
        ON DUPLICATE KEY UPDATE access_type='code'
      ")->execute([$studentId, $courseId]);

      $pdo->commit();
      echo json_encode(['ok' => true, 'message' => 'تم تفعيل الكورس بنجاح.', 'course_id' => $courseId], JSON_UNESCAPED_UNICODE);
      exit;

    } elseif ($type === 'lecture') {
      $lectureId = (int)($ac['lecture_id'] ?? 0);
      $isGlobal  = ($lectureId <= 0);

      if ($isGlobal) {
        if ($targetLectureId <= 0) {
          safeRollback($pdo);
          echo json_encode([
            'ok'          => false,
            'needs_target'=> true,
            'target_type' => 'lecture',
            'message'     => 'هذا الكود عام — يجب تحديد المحاضرة من صفحة الكورس.',
          ], JSON_UNESCAPED_UNICODE);
          exit;
        }
        $lectureId = $targetLectureId;
      }

      $courseId = lecture_get_course_id($pdo, $lectureId);
      if ($courseId <= 0) throw new RuntimeException('المحاضرة غير موجودة.');

      $stmt = $pdo->prepare("SELECT access_type FROM courses WHERE id=? LIMIT 1");
      $stmt->execute([$courseId]);
      $courseAccessType = (string)($stmt->fetchColumn() ?: '');
      if ($courseAccessType === 'attendance') throw new RuntimeException('هذه المحاضرة تفتح بالحضور فقط ولا يمكن تفعيلها بالكود.');

      if (student_has_lecture_access($pdo, $studentId, $lectureId)) {
        safeRollback($pdo);
        echo json_encode(['ok' => true, 'already' => true, 'message' => 'أنت بالفعل لديك صلاحية هذه المحاضرة.', 'lecture_id' => $lectureId], JSON_UNESCAPED_UNICODE);
        exit;
      }

      $pdo->prepare("INSERT INTO access_code_redemptions (code_id, student_id) VALUES (?, ?)")
          ->execute([$codeId, $studentId]);

      $pdo->prepare("UPDATE access_codes SET used_count = used_count + 1 WHERE id=?")
          ->execute([$codeId]);

      $pdo->prepare("
        INSERT INTO student_lecture_enrollments
          (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
        VALUES
          (?, ?, ?, 'code', NULL, ?)
        ON DUPLICATE KEY UPDATE access_type='code', lecture_code_id=VALUES(lecture_code_id)
      ")->execute([$studentId, $lectureId, $courseId, $codeId]);

      $pdo->commit();
      echo json_encode(['ok' => true, 'message' => 'تم تفعيل المحاضرة بنجاح.', 'lecture_id' => $lectureId], JSON_UNESCAPED_UNICODE);
      exit;
    }

    throw new RuntimeException('نوع كود غير مدعوم.');
  }

  // ------------------------------------------------------------
  // (2) Legacy fallback: course_codes / lecture_codes
  //     ✅ DOES NOT insert into access_codes (avoids CONSTRAINT_1)
  //     ✅ Records in legacy_code_redemptions
  // ------------------------------------------------------------
  safeRollback($pdo);
  ensure_legacy_redemptions_table($pdo);
  $pdo->beginTransaction();

  if (legacy_already_used_by_student($pdo, $code, $studentId)) {
    throw new RuntimeException('أنت استخدمت هذا الكود من قبل.');
  }

  // ---- course_codes
  $stmt = $pdo->prepare("SELECT * FROM course_codes WHERE code=? LIMIT 1 FOR UPDATE");
  $stmt->execute([$code]);
  $cc = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($cc) {
    if ((int)($cc['is_used'] ?? 0) === 1) throw new RuntimeException('تم استهلاك هذا الكود بالكامل.');

    $expiresAt = date_to_eod($cc['expires_at'] ?? null);
    if ($expiresAt && strtotime($expiresAt) < time()) throw new RuntimeException('انتهت صلاحية هذا الكود.');

    $courseId = (int)($cc['course_id'] ?? 0);
    $isGlobal = ((int)($cc['is_global'] ?? 0) === 1);

    if ($isGlobal) {
      if ($targetCourseId <= 0) {
        safeRollback($pdo);
        $stmtC = $pdo->prepare("SELECT id, name FROM courses ORDER BY name ASC");
        $stmtC->execute();
        $courses = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
          'ok'          => false,
          'needs_target'=> true,
          'target_type' => 'course',
          'message'     => 'هذا الكود عام — اختر الكورس الذي تريد فتحه.',
          'courses'     => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']], $courses),
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
      $courseId = $targetCourseId;
    } else {
      if ($courseId <= 0) throw new RuntimeException('الكود غير صالح.');
    }

    $stmt = $pdo->prepare("SELECT access_type FROM courses WHERE id=? LIMIT 1");
    $stmt->execute([$courseId]);
    $accessType = (string)($stmt->fetchColumn() ?: '');
    if ($accessType === '') throw new RuntimeException('الكورس غير موجود.');
    if ($accessType === 'attendance') throw new RuntimeException('هذا الكورس يفتح بالحضور فقط ولا يمكن تفعيله بالكود.');

    if (student_has_course_access($pdo, $studentId, $courseId)) {
      safeRollback($pdo);
      echo json_encode(['ok' => true, 'already' => true, 'message' => 'أنت بالفعل مشترك في هذا الكورس.', 'course_id' => $courseId], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // enroll
    $pdo->prepare("
      INSERT INTO student_course_enrollments (student_id, course_id, access_type)
      VALUES (?, ?, 'code')
      ON DUPLICATE KEY UPDATE access_type='code'
    ")->execute([$studentId, $courseId]);

    // record redemption (legacy)
    $pdo->prepare("
      INSERT INTO legacy_code_redemptions (code, legacy_table, legacy_id, student_id)
      VALUES (?, 'course_codes', ?, ?)
    ")->execute([$code, (int)$cc['id'], $studentId]);

    // mark used
    $pdo->prepare("
      UPDATE course_codes
      SET is_used=1, used_by_student_id=?, used_at=NOW()
      WHERE id=? AND is_used=0
    ")->execute([$studentId, (int)$cc['id']]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => 'تم تفعيل الكورس بنجاح.', 'course_id' => $courseId], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ---- lecture_codes
  $stmt = $pdo->prepare("SELECT * FROM lecture_codes WHERE code=? LIMIT 1 FOR UPDATE");
  $stmt->execute([$code]);
  $lc = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($lc) {
    if ((int)($lc['is_used'] ?? 0) === 1) throw new RuntimeException('تم استهلاك هذا الكود بالكامل.');

    $expiresAt = date_to_eod($lc['expires_at'] ?? null);
    if ($expiresAt && strtotime($expiresAt) < time()) throw new RuntimeException('انتهت صلاحية هذا الكود.');

    $lectureId = (int)($lc['lecture_id'] ?? 0);
    $isGlobal  = ((int)($lc['is_global'] ?? 0) === 1);

    if ($isGlobal) {
      if ($targetLectureId <= 0) {
        safeRollback($pdo);
        echo json_encode([
          'ok'          => false,
          'needs_target'=> true,
          'target_type' => 'lecture',
          'message'     => 'هذا الكود عام — يجب تحديد المحاضرة من صفحة الكورس.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
      $lectureId = $targetLectureId;
    } else {
      if ($lectureId <= 0) throw new RuntimeException('الكود غير صالح.');
    }

    $courseId = lecture_get_course_id($pdo, $lectureId);
    if ($courseId <= 0) throw new RuntimeException('المحاضرة غير موجودة.');

    $stmt = $pdo->prepare("SELECT access_type FROM courses WHERE id=? LIMIT 1");
    $stmt->execute([$courseId]);
    $courseAccessType = (string)($stmt->fetchColumn() ?: '');
    if ($courseAccessType === 'attendance') throw new RuntimeException('هذه المحاضرة تفتح بالحضور فقط ولا يمكن تفعيلها بالكود.');

    if (student_has_lecture_access($pdo, $studentId, $lectureId)) {
      safeRollback($pdo);
      echo json_encode(['ok' => true, 'already' => true, 'message' => 'أنت بالفعل لديك صلاحية هذه المحاضرة.', 'lecture_id' => $lectureId], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // enroll lecture
    $pdo->prepare("
      INSERT INTO student_lecture_enrollments
        (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
      VALUES
        (?, ?, ?, 'code', NULL, NULL)
      ON DUPLICATE KEY UPDATE access_type='code'
    ")->execute([$studentId, $lectureId, $courseId]);

    // record redemption (legacy)
    $pdo->prepare("
      INSERT INTO legacy_code_redemptions (code, legacy_table, legacy_id, student_id)
      VALUES (?, 'lecture_codes', ?, ?)
    ")->execute([$code, (int)$lc['id'], $studentId]);

    // mark used
    $pdo->prepare("
      UPDATE lecture_codes
      SET is_used=1, used_by_student_id=?, used_at=NOW()
      WHERE id=? AND is_used=0
    ")->execute([$studentId, (int)$lc['id']]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => 'تم تفعيل المحاضرة بنجاح.', 'lecture_id' => $lectureId], JSON_UNESCAPED_UNICODE);
    exit;
  }

  throw new RuntimeException('الكود غير صحيح.');

} catch (Throwable $e) {
  safeRollback($pdo);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
