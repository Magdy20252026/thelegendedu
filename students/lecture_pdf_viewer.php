<?php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require __DIR__ . '/inc/student_auth.php';
require __DIR__ . '/inc/access_control.php';

no_cache_headers();
$pdfId = (int)($_GET['pdf_id'] ?? 0);
if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$studentId = (int)($_SESSION['student_id'] ?? 0);
$pdfAccessToken = trim((string)($_GET['access_token'] ?? ''));
if ($pdfAccessToken !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $pdfAccessToken) !== 1) {
  $pdfAccessToken = '';
}
if ($studentId <= 0 && $pdfId > 0) {
  $studentId = student_verify_pdf_access_token($pdfAccessToken, $pdfId);
}

if ($studentId <= 0 || $pdfId <= 0) {
  header('Location: account.php?page=platform_courses');
  exit;
}

$stmt = $pdo->prepare("
  SELECT p.id, p.title, p.file_path, p.lecture_id, l.name AS lecture_name, c.id AS course_id, c.name AS course_name
  FROM pdfs p
  INNER JOIN lectures l ON l.id = p.lecture_id
  INNER JOIN courses c ON c.id = l.course_id
  WHERE p.id=?
  LIMIT 1
");
$stmt->execute([$pdfId]);
$pdf = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$pdf || !student_has_lecture_access($pdo, $studentId, (int)$pdf['lecture_id'])) {
  header('Location: account.php?page=platform_courses');
  exit;
}

$absolutePath = student_resolve_pdf_absolute_path((string)($pdf['file_path'] ?? ''));
if ($absolutePath === '') {
  header('Location: account_lecture.php?lecture_id=' . (int)($pdf['lecture_id'] ?? 0));
  exit;
}

// نمرر توكنًا قصير العمر حتى ينجح الفتح الأصلي داخل التطبيق حتى لو تأخر نقل الجلسة من WebView.
$pdfAccessToken = student_create_pdf_access_token($studentId, $pdfId);
$pdfBaseSrc = 'lecture_pdf.php?pdf_id=' . (int)$pdfId;
$pdfDirectSrc = $pdfBaseSrc;
if ($pdfAccessToken !== '') {
  $pdfDirectSrc .= '&access_token=' . rawurlencode($pdfAccessToken);
}
$pdfFrameSrc = $pdfDirectSrc;

$stmt = $pdo->prepare("
  SELECT s.full_name, s.wallet_balance, s.barcode
  FROM students s
  WHERE s.id=?
  LIMIT 1
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$student) {
  header('Location: logout.php');
  exit;
}

$row = get_platform_settings_row($pdo);
$platformName = trim((string)($row['platform_name'] ?? 'منصتي التعليمية'));
if ($platformName === '') $platformName = 'منصتي التعليمية';
$logoDb = trim((string)($row['platform_logo'] ?? ''));
$logoUrl = $logoDb !== '' ? student_public_asset_url($logoDb) : null;

$studentName = (string)($student['full_name'] ?? ($_SESSION['student_name'] ?? ''));
$wallet = (float)($student['wallet_balance'] ?? 0);
$cssVer = (string)@filemtime(__DIR__ . '/assets/css/account.css');
if ($cssVer === '' || $cssVer === '0') $cssVer = (string)time();
$lecCssVer = (string)@filemtime(__DIR__ . '/assets/css/account-lecture.css');
if ($lecCssVer === '' || $lecCssVer === '0') $lecCssVer = (string)time();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="stylesheet" href="assets/css/account.css?v=<?php echo h($cssVer); ?>">
  <link rel="stylesheet" href="assets/css/account-lecture.css?v=<?php echo h($lecCssVer); ?>">

  <style>
    .pill{padding:10px 12px;border:1px solid var(--border);border-radius:14px;font-weight:900}
  </style>

  <title>عارض الملف - <?php echo h((string)($pdf['title'] ?? 'PDF')); ?></title>
</head>
<body>

<header class="acc-topbar" role="banner">
  <div class="container">
    <div class="acc-topbar__bar">
      <div class="acc-topbar__right">
        <a class="acc-brand" href="account_lecture.php?lecture_id=<?php echo (int)($pdf['lecture_id'] ?? 0); ?>" aria-label="العودة إلى صفحة المحاضرة">
          <?php if ($logoUrl): ?>
            <img class="acc-brand__logo" src="<?php echo h($logoUrl); ?>" alt="Logo">
          <?php else: ?>
            <span class="acc-brand__logoFallback" aria-hidden="true"></span>
          <?php endif; ?>
          <span class="acc-brand__name"><?php echo h($platformName); ?></span>
        </a>

        <div class="acc-theme" data-theme-switch aria-label="تبديل الوضع">
          <button class="acc-theme__btn" type="button" data-theme="light" aria-label="لايت">☀</button>
          <button class="acc-theme__btn" type="button" data-theme="dark" aria-label="دارك">🌙</button>
          <span class="acc-theme__knob" aria-hidden="true"></span>
        </div>
      </div>

      <div class="acc-topbar__left">
        <a class="acc-btn acc-btn--ghost" href="account_lecture.php?lecture_id=<?php echo (int)($pdf['lecture_id'] ?? 0); ?>">⬅️ رجوع</a>

        <div class="acc-student" title="<?php echo h($studentName); ?>">
          <span aria-hidden="true">👤</span>
          <span class="acc-student__name"><?php echo h($studentName); ?></span>
        </div>

        <div class="acc-pill" title="رصيد المحفظة">
          <span aria-hidden="true">💳</span>
          <span><?php echo number_format($wallet, 2); ?> جنيه</span>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="acc-viewerPage">
  <div class="container">
    <section class="acc-card acc-viewerHero" aria-label="بيانات ملف PDF">
      <div class="acc-viewerHero__meta">
        <h1 class="acc-viewerHero__title">📑 <?php echo h((string)($pdf['title'] ?? 'ملف PDF')); ?></h1>
        <div class="acc-viewerHero__sub">
          المحاضرة: <b><?php echo h((string)($pdf['lecture_name'] ?? '')); ?></b>
          <br>
          الكورس: <b><?php echo h((string)($pdf['course_name'] ?? '')); ?></b>
        </div>
      </div>
    </section>

    <section class="acc-card acc-viewerFrameShell" aria-label="عارض ملف PDF">
      <div class="acc-pdfViewer">
        <iframe
          id="lecturePdfFrame"
          title="Lecture PDF Viewer"
          src="about:blank"
          data-pdf-src="<?php echo h($pdfFrameSrc); ?>"
          loading="lazy"
        ></iframe>
      </div>

      <div class="acc-playerNotice">📑 تم فتح الملف في صفحة مستقلة لسهولة القراءة داخل المنصة مع أدوات عارض الـ PDF كاملة.</div>
    </section>
  </div>
</main>

<script src="assets/js/theme.js"></script>
<script>
(function(){
  const pdfDirectUrl = <?php echo json_encode($pdfDirectSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const pdfFrame = document.getElementById('lecturePdfFrame');

  function toAbsoluteUrl(url) {
    try {
      return new URL(String(url || ''), window.location.href).toString();
    } catch(e) {
      return String(url || '');
    }
  }

  function loadEmbeddedPdfFallback() {
    if (!pdfFrame) return;
    const fallbackSrc = pdfFrame.getAttribute('data-pdf-src') || '';
    if (!fallbackSrc || pdfFrame.getAttribute('src') === fallbackSrc) return;
    pdfFrame.setAttribute('src', fallbackSrc);
  }

  function requestNativePdfOpen() {
    if (!window.StudentAppBridge || typeof window.StudentAppBridge.openProtectedPdf !== 'function') {
      loadEmbeddedPdfFallback();
      return;
    }
    try {
      window.StudentAppBridge.openProtectedPdf(toAbsoluteUrl(pdfDirectUrl));
      return;
    } catch(e) {
      if (window.console && typeof window.console.warn === 'function') {
        window.console.warn('تعذر فتح ملف PDF المحمي عبر StudentAppBridge داخل صفحة العارض.', e);
      }
    }
    loadEmbeddedPdfFallback();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', requestNativePdfOpen, {once:true});
  } else {
    requestNativePdfOpen();
  }
})();
</script>
</body>
</html>
