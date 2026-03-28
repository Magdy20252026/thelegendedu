<?php
// admin/student_devices_delete.php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$studentId = (int)($_POST['student_id'] ?? 0);
if ($studentId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'invalid_student_id'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // ✅ جهاز واحد فقط: نحذف كل أجهزة الطالب (فعليًا سيكون صف واحد، لكن نحذف أي بقايا)
  $stmt = $pdo->prepare("DELETE FROM student_devices WHERE student_id=?");
  $stmt->execute([$studentId]);

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'db_error'], JSON_UNESCAPED_UNICODE);
}