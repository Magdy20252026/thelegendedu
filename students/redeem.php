<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/student_auth.php';
require __DIR__ . '/inc/access_control.php';

no_cache_headers();
student_require_login();

if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$studentId = (int)($_SESSION['student_id'] ?? 0);

$msg = '';
$err = '';
$needsCourseSelect = false;
$coursesList = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = trim((string)($_POST['code'] ?? ''));
  $targetCourseId  = (int)($_POST['target_course_id'] ?? 0);
  $targetLectureId = (int)($_POST['target_lecture_id'] ?? 0);

  if ($code === '') {
    $err = 'من فضلك أدخل الكود.';
  } else {
    try {
      $pdo->beginTransaction();

      // Lock code row to avoid race conditions
      $stmt = $pdo->prepare("
        SELECT id, type, course_id, lecture_id, is_active, max_uses, used_count, expires_at
        FROM access_codes
        WHERE code = ?
        LIMIT 1
        FOR UPDATE
      ");
      $stmt->execute([$code]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$row) throw new RuntimeException('الكود غير صحيح.');
      if ((int)$row['is_active'] !== 1) throw new RuntimeException('الكود غير مفعل.');

      if (!empty($row['expires_at'])) {
        $expiresAt = strtotime((string)$row['expires_at']);
        if ($expiresAt !== false && $expiresAt < time()) throw new RuntimeException('انتهت صلاحية هذا الكود.');
      }

      $maxUses = $row['max_uses'] !== null ? (int)$row['max_uses'] : null;
      $usedCount = (int)$row['used_count'];
      if ($maxUses !== null && $usedCount >= $maxUses) throw new RuntimeException('تم استهلاك هذا الكود بالكامل.');

      $codeId = (int)$row['id'];
      $type = (string)$row['type'];

      // Prevent redeem same code by same student
      $stmt = $pdo->prepare("SELECT 1 FROM access_code_redemptions WHERE code_id=? AND student_id=? LIMIT 1");
      $stmt->execute([$codeId, $studentId]);
      if ($stmt->fetchColumn()) throw new RuntimeException('أنت استخدمت هذا الكود من قبل.');

      if ($type === 'course') {
        $courseId = (int)($row['course_id'] ?? 0);
        $isGlobal = ($courseId <= 0);

        // Global code: needs target_course_id
        if ($isGlobal) {
          if ($targetCourseId <= 0) {
            $pdo->rollBack();
            $needsCourseSelect = true;
            $stmtC = $pdo->prepare("SELECT id, name FROM courses ORDER BY name ASC");
            $stmtC->execute();
            $coursesList = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } else {
            $courseId = $targetCourseId;
          }
        }

        if (!$needsCourseSelect) {
          $stmt = $pdo->prepare("SELECT id FROM courses WHERE id=? LIMIT 1");
          $stmt->execute([$courseId]);
          if (!$stmt->fetchColumn()) throw new RuntimeException('الكورس غير موجود.');

          if (student_has_course_access($pdo, $studentId, $courseId)) {
            $pdo->rollBack();
            $msg = 'أنت بالفعل مشترك في هذا الكورس.';
          } else {
            $stmt = $pdo->prepare("INSERT INTO access_code_redemptions (code_id, student_id) VALUES (?, ?)");
            $stmt->execute([$codeId, $studentId]);

            $stmt = $pdo->prepare("UPDATE access_codes SET used_count = used_count + 1 WHERE id=?");
            $stmt->execute([$codeId]);

            $stmt = $pdo->prepare("
              INSERT INTO student_course_enrollments (student_id, course_id, access_type)
              VALUES (?, ?, 'code')
              ON DUPLICATE KEY UPDATE access_type='code'
            ");
            $stmt->execute([$studentId, $courseId]);

            $pdo->commit();
            $msg = 'تم تفعيل الكورس بنجاح، وتم فتح جميع محاضراته.';
          }
        }

      } elseif ($type === 'lecture') {
        $lectureId = (int)($row['lecture_id'] ?? 0);
        $isGlobal  = ($lectureId <= 0);

        // Global lecture code: needs target_lecture_id
        if ($isGlobal) {
          if ($targetLectureId > 0) {
            $lectureId = $targetLectureId;
          } else {
            throw new RuntimeException('هذا الكود عام للمحاضرات — يجب استخدامه من صفحة المحاضرة مباشرة.');
          }
        }

        $courseId = lecture_get_course_id($pdo, $lectureId);
        if ($courseId <= 0) throw new RuntimeException('المحاضرة غير موجودة.');

        if (student_has_course_access($pdo, $studentId, $courseId)) {
          $pdo->rollBack();
          $msg = 'أنت مشترك في الكورس بالفعل، كل المحاضرات مفتوحة.';
        } else {
          if (student_has_lecture_access($pdo, $studentId, $lectureId)) {
            $pdo->rollBack();
            $msg = 'أنت بالفعل لديك صلاحية هذه المحاضرة.';
          } else {
            $stmt = $pdo->prepare("INSERT INTO access_code_redemptions (code_id, student_id) VALUES (?, ?)");
            $stmt->execute([$codeId, $studentId]);

            $stmt = $pdo->prepare("UPDATE access_codes SET used_count = used_count + 1 WHERE id=?");
            $stmt->execute([$codeId]);

            $stmt = $pdo->prepare("
              INSERT INTO student_lecture_enrollments
                (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
              VALUES
                (?, ?, ?, 'code', NULL, ?)
              ON DUPLICATE KEY UPDATE access_type='code', lecture_code_id=VALUES(lecture_code_id)
            ");
            $stmt->execute([$studentId, $lectureId, $courseId, $codeId]);

            $pdo->commit();
            $msg = 'تم تفعيل المحاضرة بنجاح.';
          }
        }

      } else {
        throw new RuntimeException('نوع كود غير مدعوم.');
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800;900;1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/site.css">
  <title>تفعيل كود</title>
  <style>
    body{padding:18px 0 40px;line-height:1.8}
    .redeem-shell{max-width:680px;margin:0 auto;padding:0 16px}
    .redeem-card{padding:18px}
    .box{padding:12px;border:1px solid var(--border);border-radius:12px;margin:12px 0;background:var(--card-bg);color:var(--text)}
    .ok{background:var(--success-soft-bg);border-color:var(--success-soft-border);color:var(--success)}
    .bad{background:var(--danger-soft-bg);border-color:var(--danger-soft-border);color:var(--danger)}
    .warn{background:var(--warning-soft-bg);border-color:var(--warning-soft-border);color:var(--warning)}
    .box label{display:block;margin-bottom:8px;font-weight:900}
    .box a{color:var(--primary);text-decoration:none;font-weight:700}
    .box a:hover{text-decoration:underline;text-underline-offset:4px}
  </style>
</head>
<body>
  <div class="redeem-shell">
    <section class="card redeem-card">
      <h2 class="h1">تفعيل كود</h2>
      <p class="muted">يمكنك إدخال كود الاشتراك هنا وسيتم تطبيق الألوان المناسبة حسب الثيم الحالي.</p>

      <?php if ($msg): ?><div class="box ok"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if ($err): ?><div class="box bad"><?php echo h($err); ?></div><?php endif; ?>

      <?php if ($needsCourseSelect && !empty($coursesList)): ?>
        <div class="box warn">
          <p style="margin-top:0;">🎓 هذا الكود عام — اختر الكورس الذي تريد فتحه:</p>
          <form method="post" class="box" style="margin:0;">
            <input type="hidden" name="code" value="<?php echo h((string)($_POST['code'] ?? '')); ?>">
            <select name="target_course_id" required class="ui-select" style="margin-bottom:8px;">
              <option value="">-- اختر الكورس --</option>
              <?php foreach ($coursesList as $cv): ?>
                <option value="<?php echo (int)$cv['id']; ?>"><?php echo h((string)$cv['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="ui-btn ui-btn--success">✅ تفعيل الكورس</button>
          </form>
        </div>
      <?php elseif (!$msg): ?>
        <form method="post" class="box">
          <label>أدخل كود الاشتراك:</label>
          <input name="code" required placeholder="مثال: XXXX-XXXX-XXXX" value="<?php echo h((string)($_POST['code'] ?? '')); ?>" class="ui-input" dir="ltr">
          <button type="submit" class="ui-btn ui-btn--solid" style="margin-top:10px;">تفعيل</button>
        </form>
      <?php else: ?>
        <form method="post" class="box">
          <label>تفعيل كود آخر:</label>
          <input name="code" required placeholder="مثال: XXXX-XXXX-XXXX" class="ui-input" dir="ltr">
          <button type="submit" class="ui-btn ui-btn--solid" style="margin-top:10px;">تفعيل</button>
        </form>
      <?php endif; ?>

      <p style="margin-bottom:0;"><a href="account.php">⬅️ رجوع للحساب</a></p>
    </section>
  </div>
  <script src="assets/js/theme.js"></script>
</body>
</html>
