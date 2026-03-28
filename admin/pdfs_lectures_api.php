<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
  echo json_encode(['ok' => false, 'lectures' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt = $pdo->prepare("
  SELECT id, name
  FROM lectures
  WHERE course_id=?
  ORDER BY id DESC
");
$stmt->execute([$courseId]);
$lectures = $stmt->fetchAll();

echo json_encode(['ok' => true, 'lectures' => $lectures], JSON_UNESCAPED_UNICODE);