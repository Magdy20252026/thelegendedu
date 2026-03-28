<?php
// students/api/buy_course_wallet_api.php
// JSON API for buying a course with wallet balance.

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/student_auth.php';
require __DIR__ . '/../inc/access_control.php';
require_once __DIR__ . '/../inc/wallet_transactions.php';

no_cache_headers();
student_require_login();

$studentId = (int)($_SESSION['student_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

$courseId = (int)($_POST['course_id'] ?? 0);
if ($courseId <= 0) {
  echo json_encode(['ok' => false, 'message' => 'معرف الكورس غير صحيح.'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if (student_has_course_access($pdo, $studentId, $courseId)) {
    echo json_encode(['ok' => true, 'already' => true, 'message' => 'أنت بالفعل مشترك في هذا الكورس.', 'course_id' => $courseId], JSON_UNESCAPED_UNICODE);
    exit;
  }

  wallet_transactions_ensure_table($pdo);

  $stmt = $pdo->prepare("SELECT name, price, price_discount, buy_type, discount_end, access_type FROM courses WHERE id=? LIMIT 1");
  $stmt->execute([$courseId]);
  $c = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$c) throw new RuntimeException('الكورس غير موجود.');
  if ((string)($c['access_type'] ?? '') !== 'buy') throw new RuntimeException('هذا الكورس ليس للبيع بالمحفظة.');

  $price = (float)($c['price'] ?? 0);
  if (($c['buy_type'] ?? '') === 'discount' && !empty($c['price_discount'])) {
    $end = !empty($c['discount_end']) ? strtotime($c['discount_end'] . ' 23:59:59') : null;
    if ($end === null || $end >= time()) $price = (float)$c['price_discount'];
  }

  if ($price <= 0) throw new RuntimeException('هذا الكورس غير متاح للشراء بالمحفظة.');

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("SELECT wallet_balance FROM students WHERE id=? LIMIT 1 FOR UPDATE");
  $stmt->execute([$studentId]);
  $balance = (float)($stmt->fetchColumn() ?? 0);

  if ($balance < $price) throw new RuntimeException('رصيد المحفظة غير كافٍ. رصيدك الحالي: ' . number_format($balance, 2) . ' جنيه، سعر الكورس: ' . number_format($price, 2) . ' جنيه.');

  $stmt = $pdo->prepare("UPDATE students SET wallet_balance = wallet_balance - ? WHERE id=?");
  $stmt->execute([$price, $studentId]);

  $stmt = $pdo->prepare("
    INSERT INTO student_course_enrollments (student_id, course_id, access_type)
    VALUES (?, ?, 'buy')
    ON DUPLICATE KEY UPDATE access_type='buy'
  ");
  $stmt->execute([$studentId, $courseId]);

  $stmt = $pdo->prepare("SELECT id FROM student_course_enrollments WHERE student_id=? AND course_id=? LIMIT 1");
  $stmt->execute([$studentId, $courseId]);
  $enrollmentId = (int)($stmt->fetchColumn() ?? 0);

  $walletTransaction = [
    'student_id'        => $studentId,
    'transaction_type'  => 'course_purchase',
    'amount'            => $price,
    'description'       => wallet_transaction_course_description((string)($c['name'] ?? ''), $courseId),
    'related_course_id' => $courseId,
    'reference_type'    => 'course_enrollment',
  ];
  if ($enrollmentId > 0) $walletTransaction['reference_id'] = $enrollmentId;
  wallet_transactions_record($pdo, $walletTransaction);

  $pdo->commit();

  // Return updated wallet balance
  $stmtW = $pdo->prepare("SELECT wallet_balance FROM students WHERE id=? LIMIT 1");
  $stmtW->execute([$studentId]);
  $newBalance = (float)($stmtW->fetchColumn() ?? 0);

  echo json_encode([
    'ok'          => true,
    'message'     => 'تم شراء الكورس بنجاح.',
    'course_id'   => $courseId,
    'new_balance' => $newBalance,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
