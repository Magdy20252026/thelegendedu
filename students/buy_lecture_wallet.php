<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/student_auth.php';
require __DIR__ . '/inc/access_control.php';
require_once __DIR__ . '/inc/wallet_transactions.php';

no_cache_headers();
student_require_login();

$studentId = (int)($_SESSION['student_id'] ?? 0);
$lectureId = (int)($_POST['lecture_id'] ?? $_GET['lecture_id'] ?? 0);

if ($lectureId <= 0) {
  http_response_code(400);
  exit('Invalid lecture_id');
}

try {
  // lecture info
  wallet_transactions_ensure_table($pdo);

  $stmt = $pdo->prepare("SELECT course_id, price, name FROM lectures WHERE id=? LIMIT 1");
  $stmt->execute([$lectureId]);
  $lec = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$lec) throw new RuntimeException('Lecture not found');

  $courseId = (int)$lec['course_id'];
  $price = (float)($lec['price'] ?? 0);
  if ($price <= 0) throw new RuntimeException('هذه المحاضرة غير متاحة للشراء بالمحفظة.');

  // if course enrolled => lecture already open
  if (student_has_course_access($pdo, $studentId, $courseId)) {
    header("Location: account_lecture.php?lecture_id=" . $lectureId);
    exit;
  }

  // if lecture already purchased
  if (student_has_lecture_access($pdo, $studentId, $lectureId)) {
    header("Location: account_lecture.php?lecture_id=" . $lectureId);
    exit;
  }

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("SELECT wallet_balance FROM students WHERE id=? LIMIT 1 FOR UPDATE");
  $stmt->execute([$studentId]);
  $balance = (float)($stmt->fetchColumn() ?? 0);
  if ($balance < $price) throw new RuntimeException('رصيد المحفظة غير كافٍ.');

  $stmt = $pdo->prepare("UPDATE students SET wallet_balance = wallet_balance - ? WHERE id=?");
  $stmt->execute([$price, $studentId]);

  $stmt = $pdo->prepare("
    INSERT INTO student_lecture_enrollments
      (student_id, lecture_id, course_id, access_type, paid_amount, lecture_code_id)
    VALUES
      (?, ?, ?, 'wallet', ?, NULL)
    ON DUPLICATE KEY UPDATE access_type='wallet', paid_amount=VALUES(paid_amount)
  ");
  $stmt->execute([$studentId, $lectureId, $courseId, $price]);

  $stmt = $pdo->prepare("SELECT id FROM student_lecture_enrollments WHERE student_id=? AND lecture_id=? LIMIT 1");
  $stmt->execute([$studentId, $lectureId]);
  $enrollmentId = (int)($stmt->fetchColumn() ?? 0);

  $walletTransaction = [
    'student_id'         => $studentId,
    'transaction_type'   => 'lecture_purchase',
    'amount'             => $price,
    'description'        => wallet_transaction_lecture_description((string)($lec['name'] ?? ''), $lectureId),
    'related_course_id'  => $courseId,
    'related_lecture_id' => $lectureId,
    'reference_type'     => 'lecture_enrollment',
  ];
  if ($enrollmentId > 0) $walletTransaction['reference_id'] = $enrollmentId;
  wallet_transactions_record($pdo, $walletTransaction);

  $pdo->commit();

  header("Location: account_lecture.php?lecture_id=" . $lectureId);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  exit('Error: ' . htmlspecialchars($e->getMessage()));
}
