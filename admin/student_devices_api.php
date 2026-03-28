<?php
// admin/student_devices_api.php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'invalid_student_id'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT id, device_label, user_agent, ip_first, first_login_at, last_login_at, is_active
    FROM student_devices
    WHERE student_id=?
    ORDER BY is_active DESC, id DESC
  ");
  $stmt->execute([$studentId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode(['ok' => true, 'devices' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'db_error'], JSON_UNESCAPED_UNICODE);
}