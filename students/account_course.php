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

function to_int($v): int { return (int)$v; }
function to_money($v): string {
  if ($v === null || $v === '') return '0';
  return number_format((float)$v, 2);
}

/* platform settings (for header/footer) */
$row = get_platform_settings_row($pdo);
$platformName = trim((string)($row['platform_name'] ?? 'منصتي التعليمية'));
if ($platformName === '') $platformName = 'منصتي التعليمية';

$logoDb = trim((string)($row['platform_logo'] ?? ''));
$logoUrl = null;
if ($logoDb !== '') $logoUrl = student_public_asset_url($logoDb);

/* footer (same pattern as account.php) */
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

/* current student */
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
$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId <= 0) {
  header('Location: account.php?page=platform_courses');
  exit;
}

/* course row */
$course = null;
try {
  $stmt = $pdo->prepare("
    SELECT c.*, gr.name AS grade_name
    FROM courses c
    INNER JOIN grades gr ON gr.id = c.grade_id
    WHERE c.id=?
    LIMIT 1
  ");
  $stmt->execute([$courseId]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $course = null;
}

if (!$course) {
  header('Location: account.php?page=platform_courses');
  exit;
}

/* ✅ NEW: is enrolled in course? => opens all lectures automatically */
$isEnrolledInCourse = student_has_course_access($pdo, $studentId, $courseId);

/* Redirect online student away from attendance-only courses */
$studentStatus = (string)($student['status'] ?? 'اونلاين');
$isOnline = ($studentStatus === 'اونلاين');
if ($isOnline && (string)($course['access_type'] ?? '') === 'attendance') {
  header('Location: account.php?page=platform_courses');
  exit;
}

/* totals for course */
$courseLecturesCount = 0;
$courseVideosCount = 0;
$coursePdfsCount = 0;

try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM lectures WHERE course_id=?");
  $stmt->execute([$courseId]);
  $courseLecturesCount = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM videos WHERE course_id=?");
  $stmt->execute([$courseId]);
  $courseVideosCount = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM pdfs WHERE course_id=?");
  $stmt->execute([$courseId]);
  $coursePdfsCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

/* ✅ NEW: Last update inside this course (last add lecture/video/pdf) */
$lastCourseContentAt = '';
try {
  $stmt = $pdo->prepare("
    SELECT MAX(dt) AS last_dt
    FROM (
      SELECT l.created_at AS dt FROM lectures l WHERE l.course_id = ?
      UNION ALL
      SELECT v.created_at AS dt FROM videos  v WHERE v.course_id = ?
      UNION ALL
      SELECT p.created_at AS dt FROM pdfs   p WHERE p.course_id = ?
    ) x
  ");
  $stmt->execute([$courseId, $courseId, $courseId]);
  $lastCourseContentAt = (string)($stmt->fetchColumn() ?: '');
} catch (Throwable $e) {
  $lastCourseContentAt = '';
}

/* lectures list with counts */
$lectures = [];
try {
  $stmt = $pdo->prepare("
    SELECT
      l.*,
      (SELECT COUNT(*) FROM videos v WHERE v.lecture_id = l.id) AS videos_count,
      (SELECT COUNT(*) FROM pdfs  p WHERE p.lecture_id = l.id) AS pdfs_count
    FROM lectures l
    WHERE l.course_id=?
    ORDER BY l.id DESC
  ");
  $stmt->execute([$courseId]);
  $lectures = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $lectures = [];
}

/* page assets */
$cssVer = (string)@filemtime(__DIR__ . '/assets/css/account.css');
if ($cssVer === '' || $cssVer === '0') $cssVer = (string)time();
$courseCssVer = (string)@filemtime(__DIR__ . '/assets/css/account-course.css');
if ($courseCssVer === '' || $courseCssVer === '0') $courseCssVer = (string)time();

/* course cover url */
$imgDb = trim((string)($course['image_path'] ?? ''));
$imgUrl = null;
if ($imgDb !== '') $imgUrl = student_public_asset_url($imgDb);

/* pricing (course) */
$accessType = (string)($course['access_type'] ?? 'attendance'); // attendance | buy | free
$buyType = (string)($course['buy_type'] ?? 'none');             // none | discount
$isFree = ($accessType === 'free');
$isBuy = ($accessType === 'buy');
$isDiscount = ($isBuy && $buyType === 'discount');

$priceBase = $course['price_base'];
$priceDiscount = $course['price_discount'];
$discountEnd = (string)($course['discount_end'] ?? '');

// Effective price for purchase (considering active discount)
$effectiveCoursePrice = (float)($priceBase ?? 0);
if ($isDiscount && !empty($priceDiscount)) {
  $discountEndTs = !empty($discountEnd) ? strtotime($discountEnd . ' 23:59:59') : null;
  if ($discountEndTs === null || $discountEndTs >= time()) {
    $effectiveCoursePrice = (float)$priceDiscount;
  }
}
$effectiveCoursePriceStr = number_format($effectiveCoursePrice, 2);
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
  <link rel="stylesheet" href="assets/css/account-course.css?v=<?php echo h($courseCssVer); ?>">

  <style>
    .buy-box{margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .buy-box form{display:inline}
    .buy-pill{padding:10px 12px;border:1px solid var(--border);border-radius:14px;font-weight:900}
    .acc-modal-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border:2px solid transparent;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:1em}
    .acc-modal-btn--primary{background:var(--btn-solid-bg);color:var(--btn-solid-text)}
    .acc-modal-btn--ghost{background:var(--page-bg);border-color:var(--border);color:var(--text)}
    .acc-modal-btn--success{background:var(--btn-success-bg);color:var(--btn-success-text)}
  </style>

  <title>تفاصيل الكورس - <?php echo h((string)$course['name']); ?></title>
</head>
<body>

<header class="acc-topbar" role="banner">
  <div class="container">
    <div class="acc-topbar__bar">
      <div class="acc-topbar__right">
        <a class="acc-brand" href="account.php?page=platform_courses" aria-label="<?php echo h($platformName); ?>">
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
        <a class="acc-btn acc-btn--ghost" href="account.php?page=platform_courses">⬅️ رجوع</a>

        <div class="acc-student" title="<?php echo h($studentName); ?>">
          <span aria-hidden="true">👤</span>
          <span class="acc-student__name"><?php echo h($studentName); ?></span>
        </div>

        <div class="acc-pill" title="رصيد المحفظ��">
          <span aria-hidden="true">💳</span>
          <span><?php echo number_format($wallet, 2); ?> جنيه</span>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="acc-coursePage">
  <div class="container">

    <section class="acc-courseHero">
      <div class="acc-courseHero__grid">

        <div class="acc-courseHero__media">
          <?php if ($imgUrl): ?>
            <img class="acc-courseHero__img" src="<?php echo h($imgUrl); ?>" alt="<?php echo h((string)$course['name']); ?>">
          <?php else: ?>
            <div class="acc-courseHero__imgFallback">📚</div>
          <?php endif; ?>
        </div>

        <div class="acc-courseHero__body">
          <div class="acc-courseHero__title"><?php echo h((string)$course['name']); ?></div>
          <div class="acc-courseHero__sub">🏫 <?php echo h((string)$course['grade_name']); ?></div>

          <div class="acc-courseHero__pricing">
            <?php if ($isFree): ?>
              <span class="acc-badge acc-badge--free">🆓 مجاني</span>
            <?php elseif ($isBuy): ?>
              <span class="acc-badge acc-badge--buy">🛒 شراء</span>
              <?php if ($isDiscount): ?>
                <div class="acc-price">
                  <span class="acc-price__label">قبل الخصم:</span>
                  <span class="acc-price__val acc-price__val--before"><?php echo h((string)$priceBase); ?> جنيه</span>
                </div>
                <div class="acc-price">
                  <span class="acc-price__label">بعد الخصم:</span>
                  <span class="acc-price__val acc-price__val--after"><?php echo h((string)$priceDiscount); ?> جنيه</span>
                </div>
                <?php if ($discountEnd !== ''): ?>
                  <div class="acc-price acc-price--muted">⏳ حتى <?php echo h($discountEnd); ?></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="acc-price">
                  <span class="acc-price__label">السعر:</span>
                  <span class="acc-price__val acc-price__val--after"><?php echo h((string)$priceBase); ?> جنيه</span>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <span class="acc-badge acc-badge--att">✅ بالحضور</span>
            <?php endif; ?>
          </div>

          <div class="acc-courseHero__counts">
            <div class="acc-count">🧑‍🏫 المحاضرات: <b><?php echo (int)$courseLecturesCount; ?></b></div>
            <div class="acc-count">🎥 الفيديوهات: <b><?php echo (int)$courseVideosCount; ?></b></div>
            <div class="acc-count">📑 ملفات PDF: <b><?php echo (int)$coursePdfsCount; ?></b></div>
          </div>

          <?php if (trim($lastCourseContentAt) !== ''): ?>
            <div class="acc-courseHero__details" style="margin-top:10px;">
              🔁 آخر تحديث داخل الكورس: <b><?php echo h($lastCourseContentAt); ?></b>
            </div>
          <?php endif; ?>

          <?php $details = trim((string)($course['details'] ?? '')); ?>
          <?php if ($details !== ''): ?>
            <div class="acc-courseHero__details"><?php echo nl2br(h($details)); ?></div>
          <?php endif; ?>

          <div class="buy-box">
            <?php if ($isEnrolledInCourse): ?>
              <span class="buy-pill">✅ أنت مشترك في هذا الكورس — كل المحاضرات مفتوحة</span>
            <?php elseif ($accessType === 'attendance'): ?>
              <span class="buy-pill">ℹ️ هذا الكورس يفتح بالحضور فقط.</span>
            <?php else: ?>
              <button class="acc-modal-btn acc-modal-btn--ghost" type="button" onclick="openRedeemModal('course', <?php echo (int)$courseId; ?>)">🎫 تفعيل كود</button>
              <?php if ($accessType === 'buy'): ?>
                <button class="acc-modal-btn acc-modal-btn--primary" type="button" onclick="openBuyCourseModal(<?php echo (int)$courseId; ?>, '<?php echo h($effectiveCoursePriceStr); ?>')">🛒 شراء الكورس بالمحفظة</button>
              <?php endif; ?>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </section>

    <section class="acc-card" aria-label="قائمة المحاضرات">
      <div class="acc-card__head">
        <h2>🧑‍🏫 محاضرات الكورس</h2>
        <p>
          <?php if ($isEnrolledInCourse): ?>
            ✅ أنت مشترك في الكورس — كل المحاضرات مفتوحة.
          <?php else: ?>
            🔒 أنت غير مشترك في الكورس — يمكنك شراء محاضرة واحدة بالكود أو بالمحفظة.
          <?php endif; ?>
        </p>
      </div>

      <?php if (empty($lectures)): ?>
        <div style="font-weight:900;color:var(--muted);">لا توجد محاضرات داخل هذا الكورس حتى الآن.</div>
      <?php else: ?>
        <div class="acc-lecturesGrid">
          <?php foreach ($lectures as $l): ?>
            <?php
              $lectureId = (int)$l['id'];
              $videosCount = (int)($l['videos_count'] ?? 0);
              $pdfsCount = (int)($l['pdfs_count'] ?? 0);

              $lectureDetails = trim((string)($l['details'] ?? ''));

              // lecture price
              $lecturePrice = ($l['price'] ?? null);
              $priceText = ($accessType === 'buy') ? (to_money($lecturePrice) . ' جنيه') : 'غير مطلوب';

              // ✅ access
              $lectureOpen = $isEnrolledInCourse ? true : student_has_lecture_access($pdo, $studentId, $lectureId);
            ?>
            <article class="acc-lecture">
              <div class="acc-lecture__head">
                <div class="acc-lecture__title"><?php echo h((string)$l['name']); ?></div>
                <?php if ($lectureOpen): ?>
                  <div class="acc-lecture__lock" title="مفتوح">✅</div>
                <?php else: ?>
                  <div class="acc-lecture__lock" title="مقفول">🔒</div>
                <?php endif; ?>
              </div>

              <div class="acc-lecture__meta">
                <div class="acc-lecture__row">💰 السعر: <b><?php echo h($priceText); ?></b></div>
                <div class="acc-lecture__row">🎥 فيديوهات: <b><?php echo $videosCount; ?></b></div>
                <div class="acc-lecture__row">📑 PDFs: <b><?php echo $pdfsCount; ?></b></div>
              </div>

              <?php if ($lectureDetails !== ''): ?>
                <div class="acc-lecture__details"><?php echo nl2br(h($lectureDetails)); ?></div>
              <?php endif; ?>

              <div class="acc-lecture__actions">
                <a class="acc-btn acc-btn--ghost" href="account_lecture.php?lecture_id=<?php echo $lectureId; ?>">📑 تفاصيل المحاضرة</a>

                <?php if (!$lectureOpen && !$isEnrolledInCourse && $accessType !== 'attendance'): ?>
                  <button class="acc-modal-btn acc-modal-btn--primary" type="button"
                    onclick="openBuyLectureModal(<?php echo (int)$lectureId; ?>, '<?php echo h(to_money($lecturePrice)); ?>')">🛒 شراء المحاضرة بالمحفظة</button>
                  <button class="acc-modal-btn acc-modal-btn--ghost" type="button"
                    onclick="openRedeemModal('lecture', <?php echo (int)$lectureId; ?>)">🎫 تفعيل كود</button>
                <?php endif; ?>
              </div>
            </article>
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

<!-- ✅ Purchase / Code Modals -->
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
  function closeModal() {
    backdrop.style.display = 'none';
  }
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

  // Wallet pill updater
  function updateWalletPill(newBalance) {
    var pill = document.querySelector('.acc-pill span:last-child');
    if (pill) pill.textContent = parseFloat(newBalance).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' جنيه';
  }

  /* Redeem Code Modal */
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
        showMsg(data.message || 'اختر الكورس المراد فتحه.', false);
      } else if (data.ok) {
        showMsg('✅ ' + (data.message || 'تم التفعيل بنجاح.'), true);
        setTimeout(function(){ closeModal(); location.reload(); }, 1800);
      } else {
        showMsg('❌ ' + (data.message || 'حدث خطأ.'), false);
      }
    } catch(e) { setLoading(btn, false); showMsg('❌ خطأ في الاتصال، حاول مرة أخرى.', false); }
  };

  window.doRedeemWithCourse = async function() {
    var sel = document.getElementById('rCourseIn');
    var btn = document.getElementById('rCourseBtn');
    var courseId = sel ? sel.value : '';
    if (!courseId) { showMsg('من فضلك اختر كورساً.', false); return; }
    setLoading(btn, true);
    try {
      var fd = new FormData();
      fd.append('code', _redeemLastCode);
      fd.append('target_course_id', courseId);
      var res  = await fetch('api/redeem_code_api.php', {method:'POST', body:fd});
      var data = await res.json();
      setLoading(btn, false);
      if (data.ok) { showMsg('✅ ' + (data.message||'تم.'), true); setTimeout(function(){ closeModal(); location.reload(); }, 1800); }
      else showMsg('❌ ' + (data.message||'حدث خطأ.'), false);
    } catch(e) { setLoading(btn, false); showMsg('❌ خطأ في الاتصال.', false); }
  };

  /* Buy Course Modal */
  window.openBuyCourseModal = function(courseId, price) {
    openModal('🛒 شراء الكورس بالمحفظة',
      '<p style="margin:0 0 10px;font-weight:700;">💰 السعر: <b>' + price + ' جنيه</b></p>' +
      '<p style="margin:0 0 14px;color:var(--muted,#666);font-size:.95em;">سيتم خصم المبلغ من رصيد محفظتك.</p>' +
      '<button id="buyCourseBtn" onclick="doBuyCourse(' + parseInt(courseId) + ')" class="ui-btn ui-btn--solid">✅ تأكيد الشراء</button>'
    );
  };

  window.doBuyCourse = async function(courseId) {
    var btn = document.getElementById('buyCourseBtn');
    setLoading(btn, true);
    try {
      var fd = new FormData(); fd.append('course_id', courseId);
      var res  = await fetch('api/buy_course_wallet_api.php', {method:'POST', body:fd});
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

  /* Buy Lecture Modal */
  window.openBuyLectureModal = function(lectureId, price) {
    openModal('🛒 شراء المحاضرة بالمحفظة',
      '<p style="margin:0 0 10px;font-weight:700;">💰 السعر: <b>' + price + ' جنيه</b></p>' +
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
})();
</script>

</body>
</html>
