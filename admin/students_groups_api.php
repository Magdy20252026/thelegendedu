<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

$gradeId = (int)($_GET['grade_id'] ?? 0);
$centerId = (int)($_GET['center_id'] ?? 0);

if ($gradeId <= 0 || $centerId <= 0) {
  echo json_encode(['ok' => false, 'groups' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

$stmt = $pdo->prepare("
  SELECT id, name
  FROM `groups`
  WHERE grade_id=? AND center_id=?
  ORDER BY id DESC
");
$stmt->execute([$gradeId, $centerId]);
$groups = $stmt->fetchAll();

echo json_encode(['ok' => true, 'groups' => $groups], JSON_UNESCAPED_UNICODE);