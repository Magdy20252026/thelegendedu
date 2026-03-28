<?php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require __DIR__ . '/inc/student_auth.php';
require __DIR__ . '/inc/access_control.php';

no_cache_headers();
student_require_login();

if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function to_money($v): string {
  if ($v === null || $v === '') return '0';
  return number_format((float)$v, 2);
}

/* platform settings (for header/footer) */
$row = get_platform_settings_row($pdo);
$platformName = trim((string)($row['platform_name'] ?? 'منصتي التعليمية'));
if ($platformName === '') $platformName = 'منصتي التعليمية';

/* ✅ show platform logo */
$logoDb = trim((string)($row['platform_logo'] ?? ''));
$logoUrl = null;
if ($logoDb !== '') $logoUrl = student_public_asset_url($logoDb);

/* footer */
$footerEnabled = (int)($row['footer_enabled'] ?? 1);

$footerLogoDb = trim((string)($row['footer_logo_path'] ?? ''));
$footerLogoUrl = null;
if ($footerLogoDb !== '') $footerLogoUrl = student_public_asset_url($footerLogoDb);

$footerSocialTitle = trim((string)($row['footer_social_title'] ?? 'السوشيال ميديا'));
$footerContactTitle = trim((string)($row['footer_contact_title'] ?? 'تواصل معنا'));
$footerPhone1 = trim((string)($row['footer_phone_1'] ?? ''));
$footerPhone2 = trim((string)($row['footer_phone_2'] ?? ''));
$footerRights = trim((string)($row['footer_rights_line'] ?? ''));
$footerDev = trim((string)($row['footer_developed_by_line'] ?? ''));

$footerSocials = [];
if ($footerEnabled === 1) {
  try {
    $footerSocials = $pdo->query("
      SELECT label, url, icon_path
      FROM platform_footer_social_links
      WHERE is_active=1
      ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $footerSocials = [];
  }
}

$hasFooter = ($footerEnabled === 1) && (
  $footerLogoUrl !== null ||
  $footerSocialTitle !== '' ||
  $footerContactTitle !== '' ||
  $footerPhone1 !== '' ||
  $footerPhone2 !== '' ||
  $footerRights !== '' ||
  $footerDev !== '' ||
  count($footerSocials) > 0
);

function footer_icon_svg(string $key): string {
  $key = strtolower(trim($key));
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm7.9 9h-3.2a15.7 15.7 0 0 0-1.2-5A8.1 8.1 0 0 1 19.9 11zM12 4c.8 1 1.7 2.8 2.2 7H9.8c.5-4.2 1.4-6 2.2-7zM4.1 13h3.2a15.7 15.7 0 0 0 1.2 5A8.1 8.1 0 0 1 4.1 13zm3.2-2H4.1A8.1 8.1 0 0 1 8.5 6a15.7 15.7 0 0 0-1.2 5zm2.5 2h4.4c-.5 4.2-1.4-6-2.2-7c-.8-1-1.7-2.8-2.2-7zm5.7 5a15.7 15.7 0 0 0 1.2-5h3.2a8.1 8.1 0 0 1-4.4 5z"/></svg>';
}

/* student */
$studentId = (int)($_SESSION['student_id'] ?? 0);
$stmt = $pdo->prepare("
  SELECT s.*, gr.name AS grade_name
  FROM students s
  INNER JOIN grades gr ON gr.id = s.grade_id
  WHERE s.id=?
  LIMIT 1
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
  header('Location: logout.php');
  exit;
}
$studentName = (string)($student['full_name'] ?? ($_SESSION['student_name'] ?? ''));
$wallet = (float)($student['wallet_balance'] ?? 0);

/* inputs */
$lectureId = (int)($_GET['lecture_id'] ?? 0);
if ($lectureId <= 0) {
  header('Location: account.php?page=platform_courses');
  exit;
}

/* lecture + course */
$lecture = null;
try {
  $stmt = $pdo->prepare("
    SELECT
      l.*,
      c.id AS course_id,
      c.name AS course_name,
      c.access_type AS course_access_type,
      c.buy_type AS course_buy_type,
      c.price_base AS course_price_base,
      c.price_discount AS course_price_discount,
      c.discount_end AS course_discount_end
    FROM lectures l
    INNER JOIN courses c ON c.id = l.course_id
    WHERE l.id=?
    LIMIT 1
  ");
  $stmt->execute([$lectureId]);
  $lecture = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $lecture = null;
}

if (!$lecture) {
  header('Location: account.php?page=platform_courses');
  exit;
}

$courseId = (int)($lecture['course_id'] ?? 0);

// ✅ Access checks
$isCourseEnrolled = student_has_course_access($pdo, $studentId, $courseId);
$isLectureOpen = student_has_lecture_access($pdo, $studentId, $lectureId);

/* Redirect online student away from attendance-only course lectures */
$studentStatus = (string)($student['status'] ?? 'اونلاين');
$isOnline = ($studentStatus === 'اونلاين');
if ($isOnline && (string)($lecture['course_access_type'] ?? '') === 'attendance') {
  header('Location: account.php?page=platform_courses');
  exit;
}

/* ✅ Last update inside this lecture */
$lastLectureContentAt = '';
try {
  $stmt = $pdo->prepare("
    SELECT MAX(dt) AS last_dt
    FROM (
      SELECT v.created_at AS dt FROM videos v WHERE v.lecture_id = ?
      UNION ALL
      SELECT p.created_at AS dt FROM pdfs  p WHERE p.lecture_id = ?
    ) x
  ");
  $stmt->execute([$lectureId, $lectureId]);
  $lastLectureContentAt = (string)($stmt->fetchColumn() ?: '');
} catch (Throwable $e) {
  $lastLectureContentAt = '';
}

/* lists: videos + pdfs inside lecture */
$videos = [];
$pdfs = [];

try {
  $stmt = $pdo->prepare("
    SELECT
      id,
      title,
      duration_minutes,
      allowed_views_per_student,
      video_type,
      exam_id,
      assignment_id,
      embed_iframe,
      embed_iframe_enc,
      embed_iframe_iv
    FROM videos
    WHERE lecture_id=?
    ORDER BY id DESC
  ");
  $stmt->execute([$lectureId]);
  $videos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $videos = []; }

try {
  $stmt = $pdo->prepare("
    SELECT id, title, file_path
    FROM pdfs
    WHERE lecture_id=?
    ORDER BY id DESC
  ");
  $stmt->execute([$lectureId]);
  $pdfs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $pdfs = []; }

$videosCount = count($videos);
$pdfsCount = count($pdfs);

if ($isLectureOpen && !empty($videos)) {
  student_video_views_ensure_table($pdo);

  foreach ($videos as &$videoRow) {
    $videoId = (int)($videoRow['id'] ?? 0);
    $stats = student_get_video_watch_stats($pdo, $studentId, $videoId, $videoRow);
    $videoRequirement = student_get_video_requirement_status($pdo, $studentId, $videoRow);
    $videoRow['views_allowed'] = (int)$stats['allowed'];
    $videoRow['views_used'] = (int)$stats['used'];
    $videoRow['views_remaining'] = (int)$stats['remaining'];
    $videoRow['is_blocked'] = (bool)$stats['blocked'];
    $videoRow['video_requirement'] = $videoRequirement;
    $videoRow['is_requirement_locked'] = !empty($videoRequirement['required']) && empty($videoRequirement['satisfied']);
    $videoRow['half_watch_seconds'] = student_video_half_watch_seconds((int)($videoRow['duration_minutes'] ?? 0));
  }
  unset($videoRow);
}

/* lecture price show */
$courseAccessType = (string)($lecture['course_access_type'] ?? 'attendance');
$lecturePriceText = ($courseAccessType === 'buy')
  ? (to_money($lecture['price'] ?? null) . ' جنيه')
  : 'غير مطلوب';

/* page assets */
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
  <link rel="stylesheet" href="assets/css/footer.css">
  <link rel="stylesheet" href="assets/css/account.css?v=<?php echo h($cssVer); ?>">
  <link rel="stylesheet" href="assets/css/account-lecture.css?v=<?php echo h($lecCssVer); ?>">

  <style>
    .buy-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    .buy-row form{display:inline}
    .pill{padding:10px 12px;border:1px solid var(--border);border-radius:14px;font-weight:900}
    .acc-modal-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border:2px solid transparent;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:1em}
    .acc-modal-btn--primary{background:var(--btn-solid-bg);color:var(--btn-solid-text)}
    .acc-modal-btn--ghost{background:var(--page-bg);border-color:var(--border);color:var(--text)}
  </style>

  <title>تفاصيل المحاضرة - <?php echo h((string)$lecture['name']); ?></title>
</head>
<body>

<header class="acc-topbar" role="banner">
  <div class="container">
    <div class="acc-topbar__bar">
      <div class="acc-topbar__right">
        <a class="acc-brand" href="account_course.php?course_id=<?php echo (int)$courseId; ?>" aria-label="<?php echo h($platformName); ?>">
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
        <a class="acc-btn acc-btn--ghost" href="account_course.php?course_id=<?php echo (int)$courseId; ?>">⬅️ رجوع</a>

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

<main class="acc-lecturePage">
  <div class="container">

    <section class="acc-card" aria-label="تفاصيل المحاضرة">
      <div class="acc-card__head">
        <h2>📑 <?php echo h((string)$lecture['name']); ?></h2>
        <p>📚 الكورس: <b><?php echo h((string)$lecture['course_name']); ?></b></p>
      </div>

      <div class="acc-lectureInfo">
        <div class="acc-lectureInfo__row">
          الحالة:
          <?php if ($isLectureOpen): ?>
            <b class="ui-status--success">✅ مفتوح</b>
          <?php else: ?>
            <b class="ui-status--danger">🔒 مقفول</b>
          <?php endif; ?>
        </div>

        <div class="acc-lectureInfo__row">💰 السعر: <b><?php echo h($lecturePriceText); ?></b></div>
        <div class="acc-lectureInfo__row">🎥 عدد الفيديوهات: <b><?php echo (int)$videosCount; ?></b></div>
        <div class="acc-lectureInfo__row">📑 عدد ملفات PDF: <b><?php echo (int)$pdfsCount; ?></b></div>

        <?php if (trim($lastLectureContentAt) !== ''): ?>
          <div class="acc-lectureInfo__row">
            🔁 آخر تحديث داخل المحاضرة:
            <b><?php echo h($lastLectureContentAt); ?></b>
          </div>
        <?php endif; ?>
      </div>

      <?php $details = trim((string)($lecture['details'] ?? '')); ?>
      <?php if ($details !== ''): ?>
        <div class="acc-lectureDetails"><?php echo nl2br(h($details)); ?></div>
      <?php endif; ?>

      <div class="buy-row">
        <?php if ($isLectureOpen): ?>
          <span class="pill">✅ لديك صلاحية مشاهدة المحاضرة</span>
        <?php elseif ($courseAccessType === 'attendance'): ?>
          <span class="pill">ℹ️ هذه المحاضرة تفتح بالحضور فقط.</span>
        <?php else: ?>
          <button class="acc-modal-btn acc-modal-btn--ghost" type="button" onclick="openRedeemModal('lecture', <?php echo (int)$lectureId; ?>)">🎫 تفعيل كود</button>
          <?php if (!$isCourseEnrolled && $courseAccessType === 'buy'): ?>
            <button class="acc-modal-btn acc-modal-btn--primary" type="button"
              onclick="openBuyLectureModal(<?php echo (int)$lectureId; ?>, '<?php echo h($lecturePriceText); ?>')">🛒 شراء المحاضرة بالمحفظة</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="acc-card" aria-label="فيديوهات المحاضرة">
      <div class="acc-card__head">
        <h2>🎥 فيديوهات المحاضرة</h2>
      </div>

      <?php if (empty($videos)): ?>
        <div style="font-weight:900;color:var(--muted);">لا توجد فيديوهات داخل هذه المحاضرة.</div>
      <?php else: ?>
        <?php if ($isLectureOpen): ?>
          <div class="acc-playerNotice">
          </div>
        <?php endif; ?>

        <div class="acc-itemsList acc-itemsList--media">
          <?php foreach ($videos as $v): ?>
            <?php
              $videoRemaining = (int)($v['views_remaining'] ?? 0);
              $videoAllowed = (int)($v['views_allowed'] ?? (int)($v['allowed_views_per_student'] ?? 1));
              $isBlockedVideo = (bool)($v['is_blocked'] ?? false);
              $videoRequirement = (array)($v['video_requirement'] ?? []);
              $isRequirementLocked = (bool)($v['is_requirement_locked'] ?? false);
              $videoId = (int)($v['id'] ?? 0);
            ?>
            <div
              class="acc-item acc-item--media<?php echo ($isBlockedVideo || $isRequirementLocked ? ' is-blocked' : ''); ?>"
            >
              <div class="acc-item__body">
                <div class="acc-item__title">🎥 <?php echo h((string)$v['title']); ?></div>
                <div class="acc-item__meta">⏱️ <?php echo (int)($v['duration_minutes'] ?? 0); ?> دقيقة</div>
                <?php if ($isLectureOpen): ?>
                  <div class="acc-item__desc">
                    👁️ المشاهدات المستخدمة: <b><?php echo (int)($v['views_used'] ?? 0); ?></b> / <?php echo $videoAllowed; ?>
                    • المتبقي: <b><?php echo $videoRemaining; ?></b>
                  </div>
                  <?php if ($isRequirementLocked): ?>
                    <div class="acc-item__badge acc-item__badge--danger">
                      🔒 يجب حل وتسليم <?php echo h((string)($videoRequirement['assessment_name'] ?? $videoRequirement['assessment_label'] ?? 'المحتوى المرتبط')); ?> أولًا
                    </div>
                  <?php elseif ($isBlockedVideo): ?>
                    <div class="acc-item__badge acc-item__badge--danger">انتهت عدد المشاهدات</div>
                  <?php else: ?>
                    <div class="acc-item__badge">تشغيل داخل المنصة</div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>

              <div class="acc-item__side">
                <?php if ($isLectureOpen && $isRequirementLocked): ?>
                  <a
                    class="acc-modal-btn acc-modal-btn--ghost"
                    href="<?php echo h((string)($videoRequirement['assessment_href'] ?? '#')); ?>"
                  >حل <?php echo h((string)($videoRequirement['assessment_label'] ?? 'المحتوى')); ?></a>
                <?php elseif ($isLectureOpen && !$isBlockedVideo): ?>
                  <a
                    class="acc-modal-btn acc-modal-btn--primary"
                    href="lecture_video_player.php?video_id=<?php echo $videoId; ?>"
                  >فتح المشغل</a>
                <?php elseif ($isLectureOpen): ?>
                  <button class="acc-modal-btn acc-modal-btn--ghost" type="button" disabled>انتهت المشاهدات</button>
                <?php endif; ?>

                <?php if ($isLectureOpen): ?>
                  <div class="acc-item__lock"><?php echo $isRequirementLocked ? '🔒' : ($isBlockedVideo ? '⛔' : '✅'); ?></div>
                <?php else: ?>
                  <div class="acc-item__lock">🔒</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="acc-card" aria-label="ملفات PDF للمحاضرة">
      <div class="acc-card__head">
        <h2>📑 ملفات PDF</h2>
      </div>

      <?php if (empty($pdfs)): ?>
        <div style="font-weight:900;color:var(--muted);">لا توجد ملفات PDF داخل هذه المحاضرة.</div>
      <?php else: ?>
        <?php if ($isLectureOpen): ?>
        <?php endif; ?>

        <div class="acc-itemsList acc-itemsList--media">
          <?php foreach ($pdfs as $p): ?>
            <?php $pdfId = (int)($p['id'] ?? 0); ?>
            <?php
              $pdfViewerHref = 'lecture_pdf_viewer.php?pdf_id=' . $pdfId;
              $pdfDirectHref = 'lecture_pdf.php?pdf_id=' . $pdfId;
              $pdfAccessToken = student_create_pdf_access_token($studentId, $pdfId);
              if ($pdfAccessToken !== '') {
                $pdfViewerHref .= '&access_token=' . rawurlencode($pdfAccessToken);
                $pdfDirectHref .= '&access_token=' . rawurlencode($pdfAccessToken);
              }
            ?>
            <div
              class="acc-item acc-item--media"
            >
              <div class="acc-item__body">
                <div class="acc-item__title">📑 <?php echo h((string)$p['title']); ?></div>
                <?php if ($isLectureOpen): ?>
                  <div class="acc-item__meta">✅ متاح داخل المحاضرة</div>
                  <div class="acc-item__badge">لن يتم فتح الملف إلا بعد الضغط على عرض</div>
                <?php else: ?>
                  <div class="acc-item__meta">🔒 مقفول</div>
                <?php endif; ?>
              </div>

              <div class="acc-item__side">
                <?php if ($isLectureOpen): ?>
                  <a
                    class="acc-modal-btn acc-modal-btn--ghost"
                    data-app-pdf-link="1"
                    data-app-pdf-url="<?php echo h($pdfDirectHref); ?>"
                    href="<?php echo h($pdfViewerHref); ?>"
                  >عرض</a>
                  <div class="acc-item__lock">✅</div>
                <?php else: ?>
                  <div class="acc-item__lock">🔒</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </div>
</main>

<?php if ($hasFooter): ?>
  <footer class="site-footer" aria-label="Footer">
    <div class="container">
      <div class="footer__grid">
        <div class="footer__col footer__col--left">
          <?php if ($footerLogoUrl): ?>
            <img class="footer__logo" src="<?php echo h($footerLogoUrl); ?>" alt="Logo">
          <?php else: ?>
            <div class="footer__logoFallback" aria-hidden="true"></div>
          <?php endif; ?>
        </div>

        <div class="footer__col footer__col--mid">
          <?php if ($footerSocialTitle !== ''): ?>
            <div class="footer__title"><?php echo h($footerSocialTitle); ?></div>
          <?php endif; ?>

          <?php if (!empty($footerSocials)): ?>
            <ul class="footer__list">
              <?php foreach ($footerSocials as $s): ?>
                <?php
                  $socIconDb = trim((string)($s['icon_path'] ?? ''));
                  $socIconUrl = null;
                  if ($socIconDb !== '') $socIconUrl = student_public_asset_url($socIconDb);
                ?>
                <li class="footer__item">
                  <a class="footer__link" href="<?php echo h((string)$s['url']); ?>" target="_blank" rel="noopener">
                    <span class="footer__ico" aria-hidden="true">
                      <?php if ($socIconUrl): ?>
                        <img class="footer__icoImg" src="<?php echo h($socIconUrl); ?>" alt="">
                      <?php else: ?>
                        <?php echo footer_icon_svg('website'); ?>
                      <?php endif; ?>
                    </span>
                    <span class="footer__lbl"><?php echo h((string)$s['label']); ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="footer__col footer__col--mid2">
          <?php if ($footerContactTitle !== ''): ?>
            <div class="footer__title"><?php echo h($footerContactTitle); ?></div>
          <?php endif; ?>

          <div class="footer__phones">
            <?php if ($footerPhone1 !== ''): ?><div class="footer__phone"><?php echo h($footerPhone1); ?></div><?php endif; ?>
            <?php if ($footerPhone2 !== ''): ?><div class="footer__phone"><?php echo h($footerPhone2); ?></div><?php endif; ?>
          </div>
        </div>

        <div class="footer__col footer__col--right">
          <?php if ($footerRights !== ''): ?>
            <div class="footer-copy footer-copy--rights"><?php echo h($footerRights); ?></div>
          <?php endif; ?>
          <?php if ($footerDev !== ''): ?>
            <div class="footer-copy footer-copy--dev"><?php echo h($footerDev); ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </footer>
<?php endif; ?>

<script src="assets/js/theme.js"></script>

<!-- ✅ Purchase / Code Modals (same as account_course.php) -->
<div id="accModalBackdrop" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;" role="dialog" aria-modal="true">
  <div id="accModalBox" style="background:var(--card-bg,#fff);color:var(--text,#111);border-radius:18px;padding:28px 24px;max-width:420px;width:calc(100% - 32px);box-shadow:0 8px 40px rgba(0,0,0,.25);position:relative;font-family:inherit;">
    <button id="accModalClose" style="position:absolute;top:12px;left:12px;background:none;border:none;font-size:1.4em;cursor:pointer;color:var(--muted,#888);" aria-label="إغلاق">✖</button>
    <h3 id="accModalTitle" style="margin:0 0 14px;font-size:1.2em;"></h3>
    <div id="accModalMsg" style="display:none;padding:10px 14px;border-radius:10px;margin-bottom:12px;font-weight:700;"></div>
    <div id="accModalBody"></div>
  </div>
</div>

<script>
(function(){
  var backdrop = document.getElementById('accModalBackdrop');
  var titleEl  = document.getElementById('accModalTitle');
  var msgEl    = document.getElementById('accModalMsg');
  var bodyEl   = document.getElementById('accModalBody');
  var closeBtn = document.getElementById('accModalClose');

  function openModal(title, bodyHtml) {
    titleEl.textContent = title;
    bodyEl.innerHTML = bodyHtml;
    msgEl.style.display = 'none';
    msgEl.className = '';
    backdrop.style.display = 'flex';
  }
  function closeModal() { backdrop.style.display = 'none'; }
  function showMsg(text, ok) {
    msgEl.textContent = text;
    msgEl.style.display = 'block';
    msgEl.className = ok ? 'ui-msg--success' : 'ui-msg--error';
  }
  function setLoading(btn, loading) {
    btn.disabled = loading;
    if (loading) { btn._orig = btn.textContent; btn.textContent = '⏳ جاري التنفيذ...'; }
    else { btn.textContent = btn._orig || btn.textContent; }
  }

  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', function(e){ if(e.target===backdrop) closeModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && backdrop.style.display!=='none') closeModal(); });

  function updateWalletPill(newBalance) {
    var pill = document.querySelector('.acc-pill span:last-child');
    if (pill) pill.textContent = parseFloat(newBalance).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' جنيه';
  }

  var _redeemType = null, _redeemContextId = 0, _redeemLastCode = '';

  window.openRedeemModal = function(type, contextId) {
    _redeemType = type || null;
    _redeemContextId = parseInt(contextId) || 0;
    _redeemLastCode = '';
    openModal('🎫 تفعيل كود اشتراك',
      '<input id="rCodeIn" type="text" placeholder="XXXX-XXXX-XXXX" dir="ltr" class="ui-input" style="margin-bottom:10px;">' +
      '<button id="rCodeBtn" onclick="doRedeemCode()" class="ui-btn ui-btn--solid">✅ تفعيل</button>'
    );
    setTimeout(function(){ var i=document.getElementById('rCodeIn'); if(i) i.focus(); }, 80);
  };

  window.doRedeemCode = async function() {
    var codeIn = document.getElementById('rCodeIn');
    var btn    = document.getElementById('rCodeBtn');
    var code   = (codeIn ? codeIn.value.trim() : '');
    if (!code) { showMsg('من فضلك أدخل الكود.', false); return; }
    _redeemLastCode = code;
    setLoading(btn, true);
    try {
      var fd = new FormData();
      fd.append('code', code);
      if (_redeemType === 'course' && _redeemContextId > 0) fd.append('target_course_id', _redeemContextId);
      if (_redeemType === 'lecture' && _redeemContextId > 0) fd.append('target_lecture_id', _redeemContextId);
      var res  = await fetch('api/redeem_code_api.php', {method:'POST', body:fd});
      var data = await res.json();
      setLoading(btn, false);
      if (data.needs_target && data.target_type === 'course') {
        var opts = '<option value="">-- اختر الكورس --</option>';
        (data.courses || []).forEach(function(c){ opts += '<option value="' + c.id + '">' + c.name + '</option>'; });
        bodyEl.innerHTML =
          '<p class="ui-note--warning" style="margin:0 0 8px;">🎓 هذا الكود عام — اختر الكورس:</p>' +
          '<select id="rCourseIn" class="ui-select" style="margin-bottom:10px;">' + opts + '</select>' +
          '<button id="rCourseBtn" onclick="doRedeemWithCourse()" class="ui-btn ui-btn--success">✅ تفعيل</button>';
        showMsg(data.message || 'اختر الكورس.', false);
      } else if (data.ok) {
        showMsg('✅ ' + (data.message||'تم التفعيل بنجاح.'), true);
        setTimeout(function(){ closeModal(); location.reload(); }, 1800);
      } else {
        showMsg('❌ ' + (data.message||'حدث خطأ.'), false);
      }
    } catch(e) { setLoading(btn, false); showMsg('❌ خطأ في الاتصال.', false); }
  };

  window.doRedeemWithCourse = async function() {
    var sel = document.getElementById('rCourseIn');
    var btn = document.getElementById('rCourseBtn');
    if (!sel || !sel.value) { showMsg('من فضلك اختر كورساً.', false); return; }
    setLoading(btn, true);
    try {
      var fd = new FormData();
      fd.append('code', _redeemLastCode);
      fd.append('target_course_id', sel.value);
      var res  = await fetch('api/redeem_code_api.php', {method:'POST', body:fd});
      var data = await res.json();
      setLoading(btn, false);
      if (data.ok) { showMsg('✅ ' + (data.message||'تم.'), true); setTimeout(function(){ closeModal(); location.reload(); }, 1800); }
      else showMsg('❌ ' + (data.message||'حدث خطأ.'), false);
    } catch(e) { setLoading(btn, false); showMsg('❌ خطأ في الاتصال.', false); }
  };

  window.openBuyLectureModal = function(lectureId, priceText) {
    openModal('🛒 شراء المحاضرة بالمحفظة',
      '<p style="margin:0 0 10px;font-weight:700;">💰 السعر: <b>' + priceText + '</b></p>' +
      '<p style="margin:0 0 14px;color:var(--muted,#666);font-size:.95em;">سيتم خصم المبلغ من رصيد محفظتك.</p>' +
      '<button id="buyLectureBtn" onclick="doBuyLecture(' + parseInt(lectureId) + ')" class="ui-btn ui-btn--solid">✅ تأكيد الشراء</button>'
    );
  };

  window.doBuyLecture = async function(lectureId) {
    var btn = document.getElementById('buyLectureBtn');
    setLoading(btn, true);
    try {
      var fd = new FormData(); fd.append('lecture_id', lectureId);
      var res  = await fetch('api/buy_lecture_wallet_api.php', {method:'POST', body:fd});
      var data = await res.json();
      setLoading(btn, false);
      if (data.ok) {
        if (data.new_balance !== undefined) updateWalletPill(data.new_balance);
        showMsg('✅ ' + (data.message||'تم الشراء بنجاح.'), true);
        setTimeout(function(){ closeModal(); location.reload(); }, 1800);
      } else {
        showMsg('❌ ' + (data.message||'حدث خطأ.'), false);
      }
    } catch(e) { setLoading(btn, false); showMsg('❌ خطأ في الاتصال.', false); }
  };

  var hasNativePdfBridge = !!(window.StudentAppBridge && typeof window.StudentAppBridge.openProtectedPdf === 'function');

  function resolveToAbsoluteUrl(url) {
    try {
      return new URL(String(url || ''), window.location.href).toString();
    } catch(e) {
      if (window.console && typeof window.console.warn === 'function') {
        window.console.warn('تعذر تحويل رابط PDF إلى رابط مطلق صالح.', e);
      }
      return '';
    }
  }

  var pdfLinksContainer = document.querySelector('.acc-itemsList--media');
  if (pdfLinksContainer) pdfLinksContainer.querySelectorAll('[data-app-pdf-link="1"]').forEach(function(link){
    link.addEventListener('click', function(e){
      if (!hasNativePdfBridge) return;
      var directPdfUrl = link.getAttribute('data-app-pdf-url') || '';
      if (!directPdfUrl) return;
      var resolvedPdfUrl = resolveToAbsoluteUrl(directPdfUrl);
      if (!resolvedPdfUrl) return;
      e.preventDefault();
      try {
        window.StudentAppBridge.openProtectedPdf(resolvedPdfUrl);
      } catch(err) {
        if (window.console && typeof window.console.warn === 'function') {
          window.console.warn('تعذر فتح ملف PDF المحمي عبر StudentAppBridge.', err);
        }
      }
    });
  });

})();
</script>

</body>
</html>
