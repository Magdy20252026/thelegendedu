<?php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require __DIR__ . '/inc/student_auth.php';
require __DIR__ . '/inc/access_control.php';

no_cache_headers();
$pdfId = (int)($_GET['pdf_id'] ?? 0);
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId <= 0) {
  $studentId = student_verify_pdf_access_token((string)($_GET['access_token'] ?? ''), $pdfId);
}

if ($studentId <= 0 || $pdfId <= 0) {
  http_response_code(403);
  exit('Access denied.');
}

$stmt = $pdo->prepare("
  SELECT id, lecture_id, title, file_path
  FROM pdfs
  WHERE id=?
  LIMIT 1
");
$stmt->execute([$pdfId]);
$pdf = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$pdf || !student_has_lecture_access($pdo, $studentId, (int)$pdf['lecture_id'])) {
  http_response_code(403);
  exit('Access denied.');
}

$absolutePath = student_resolve_pdf_absolute_path((string)($pdf['file_path'] ?? ''));
if ($absolutePath === '') {
  http_response_code(404);
  exit('PDF not found.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . (string)filesize($absolutePath));
$downloadName = preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string)($pdf['title'] ?? ('lecture-' . $pdfId)));
if (!is_string($downloadName) || trim($downloadName, '-') === '') $downloadName = 'lecture-' . $pdfId;
header('Content-Disposition: inline; filename="' . $downloadName . '.pdf"');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");

readfile($absolutePath);
exit;
