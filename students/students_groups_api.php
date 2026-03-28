<?php
// ✅ Alias API used by register.php + admin/students.php scripts
require __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=utf-8');

$gradeId = (int)($_GET['grade_id'] ?? 0);
$centerId = (int)($_GET['center_id'] ?? 0);

if ($gradeId <= 0 || $centerId <= 0) {
  echo json_encode(['groups' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT id, name
    FROM `groups`
    WHERE grade_id = ? AND center_id = ?
    ORDER BY id DESC
  ");
  $stmt->execute([$gradeId, $centerId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $out = [];
  foreach ($rows as $r) {
    $out[] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => (string)($r['name'] ?? ''),
    ];
  }

  echo json_encode(['groups' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['groups' => []], JSON_UNESCAPED_UNICODE);
}