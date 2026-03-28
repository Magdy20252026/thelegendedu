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

$studentId = (int)($_SESSION['student_id'] ?? 0);
$videoId = (int)($_GET['video_id'] ?? 0);
if ($studentId <= 0 || $videoId <= 0) {
  header('Location: account.php?page=platform_courses');
  exit;
}

$video = student_get_video_row($pdo, $videoId); // defined in students/inc/access_control.php
if (!$video) {
  header('Location: account.php?page=platform_courses');
  exit;
}

$lectureId = (int)($video['lecture_id'] ?? 0);
if (!student_has_lecture_access($pdo, $studentId, $lectureId)) {
  header('Location: account.php?page=platform_courses');
  exit;
}

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

$stmt = $pdo->prepare("
  SELECT l.id, l.name, c.id AS course_id, c.name AS course_name
  FROM lectures l
  INNER JOIN courses c ON c.id = l.course_id
  WHERE l.id=?
  LIMIT 1
");
$stmt->execute([$lectureId]);
$lecture = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$lecture) {
  header('Location: account.php?page=platform_courses');
  exit;
}

student_video_views_ensure_table($pdo);
$stats = student_get_video_watch_stats($pdo, $studentId, $videoId, $video);
$videoRequirement = student_get_video_requirement_status($pdo, $studentId, $video);
$videoLockedByRequirement = !empty($videoRequirement['required']) && empty($videoRequirement['satisfied']);
$halfSeconds = student_video_half_watch_seconds((int)($video['duration_minutes'] ?? 0));

$row = get_platform_settings_row($pdo);
$platformName = trim((string)($row['platform_name'] ?? 'منصتي التعليمية'));
if ($platformName === '') $platformName = 'منصتي التعليمية';
$logoDb = trim((string)($row['platform_logo'] ?? ''));
$logoUrl = $logoDb !== '' ? student_public_asset_url($logoDb) : null;

$studentName = (string)($student['full_name'] ?? ($_SESSION['student_name'] ?? ''));
$wallet = (float)($student['wallet_balance'] ?? 0);
$studentCode = trim((string)($student['barcode'] ?? ''));
if ($studentCode === '') $studentCode = 'STD-' . $studentId;
$studentWatermark = $studentCode . ' • ' . $studentName;

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
    .acc-modal-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border:2px solid transparent;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:1em;text-decoration:none}
    .acc-modal-btn--primary{background:var(--btn-solid-bg);color:var(--btn-solid-text)}
    .acc-modal-btn--ghost{background:var(--page-bg);border-color:var(--border);color:var(--text)}
  </style>

  <title>مشغل المحاضرة - <?php echo h((string)($video['title'] ?? 'فيديو المحاضرة')); ?></title>
</head>
<body>

<header class="acc-topbar" role="banner">
  <div class="container">
    <div class="acc-topbar__bar">
      <div class="acc-topbar__right">
        <a class="acc-brand" href="account_lecture.php?lecture_id=<?php echo (int)$lectureId; ?>" aria-label="العودة إلى صفحة المحاضرة">
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
        <a class="acc-btn acc-btn--ghost" href="account_lecture.php?lecture_id=<?php echo (int)$lectureId; ?>">⬅️ رجوع</a>

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

<div class="acc-screenWatermark" aria-hidden="true">
  <span class="acc-screenWatermark__chip acc-screenWatermark__chip--one"><?php echo h($studentWatermark); ?></span>
  <span class="acc-screenWatermark__chip acc-screenWatermark__chip--two"><?php echo h($studentWatermark); ?></span>
</div>

<main class="acc-viewerPage">
  <div class="container">
    <section class="acc-card acc-viewerHero" aria-label="بيانات الفيديو">
      <div class="acc-viewerHero__top">
        <div class="acc-viewerHero__meta">
          <h1 class="acc-viewerHero__title">🎥 <?php echo h((string)($video['title'] ?? 'فيديو المحاضرة')); ?></h1>
          <div class="acc-viewerHero__sub">
            المحاضرة: <b><?php echo h((string)($lecture['name'] ?? '')); ?></b>
            <br>
            الكورس: <b><?php echo h((string)($lecture['course_name'] ?? '')); ?></b>
          </div>
        </div>

      </div>

      <div class="acc-viewerStats">
        <span class="pill">⏱️ المدة: <b><?php echo (int)($video['duration_minutes'] ?? 0); ?> دقيقة</b></span>
        <span class="pill">👁️ المستخدم: <b id="videoViewsUsed"><?php echo (int)($stats['used'] ?? 0); ?></b> / <b id="videoViewsAllowed"><?php echo (int)($stats['allowed'] ?? 1); ?></b></span>
        <span class="pill">🟢 المتبقي: <b id="videoViewsRemaining"><?php echo (int)($stats['remaining'] ?? 0); ?></b></span>
      </div>
    </section>

    <section class="acc-card acc-viewerFrameShell" aria-label="مشغل فيديو المحاضرة">
      <div class="acc-playerStage" id="lecturePlayerStage">
        <div class="acc-playerSurface" id="lecturePlayerSurface">
          <div class="acc-playerPlaceholder" id="lecturePlayerPlaceholder">
            <?php if ($videoLockedByRequirement): ?>
              🔒 هذا الفيديو مرتبط بـ <?php echo h((string)($videoRequirement['assessment_label'] ?? 'المحتوى')); ?>، ولن يعمل إلا بعد حله وتسليمه.
            <?php elseif (!empty($stats['blocked'])): ?>
              ⛔ انتهت عدد المشاهدات المسموحة لهذا الفيديو، ولن يتم تشغيله مرة أخرى.
            <?php else: ?>
              يتم حجب الفيديو افتراضيًا للحماية، ولن يظهر إلا بعد فتح المشغل المحمي من طبقة الأمان داخل الصفحة. على الموبايل داخل التطبيق يمكن التشغيل مباشرة، بينما يدعم المتصفح وضع العرض الآمن بملء الشاشة عند الحاجة.
            <?php endif; ?>
          </div>
        </div>
        <div class="acc-playerOverlay">
          <span class="acc-playerOverlay__chip"><?php echo h($studentWatermark); ?></span>
        </div>
        <div class="acc-playerInteractionShield" id="lecturePlayerInteractionShield" aria-hidden="true" hidden></div>
        <div class="acc-playerProtectionMask" aria-hidden="true"></div>
        <div class="acc-platformControls" id="lecturePlayerControls" hidden>
          <div class="acc-platformControls__group acc-platformControls__group--actions">
            <button class="acc-modal-btn acc-modal-btn--primary acc-platformControls__iconBtn" type="button" id="lecturePlayerCtrlPlayPause" aria-label="تشغيل أو إيقاف الفيديو" disabled>▶️ تشغيل</button>
            <button class="acc-modal-btn acc-modal-btn--ghost acc-platformControls__iconBtn" type="button" id="lecturePlayerCtrlSeekBack" aria-label="إرجاع عشر ثواني" disabled>⏪ 10 ث</button>
            <button class="acc-modal-btn acc-modal-btn--ghost acc-platformControls__iconBtn" type="button" id="lecturePlayerCtrlSeekForward" aria-label="تقديم عشر ثواني" disabled>⏩ 10 ث</button>
            <button class="acc-modal-btn acc-modal-btn--ghost acc-platformControls__iconBtn" type="button" id="lecturePlayerCtrlFullscreen" aria-label="تكبير المشغل" disabled>⛶ ملء</button>
          </div>
          <div class="acc-platformControls__group acc-platformControls__group--timeline">
            <span class="acc-platformControls__label">⏱️ الوقت</span>
            <input class="acc-platformControls__timeline" type="range" id="lecturePlayerCtrlTimeline" min="0" max="0" step="1" value="0" aria-label="التحكم في وقت الفيديو" disabled>
            <span class="acc-platformControls__time" id="lecturePlayerCtrlTime">00:00 / 00:00</span>
          </div>
          <div class="acc-platformControls__group acc-platformControls__group--audio">
            <span class="acc-platformControls__label">🔊 الصوت</span>
            <input class="acc-platformControls__range" type="range" id="lecturePlayerCtrlVolume" min="0" max="100" step="1" value="100" aria-label="مستوى الصوت" disabled>
          </div>
          <div class="acc-platformControls__group">
            <label class="acc-platformControls__label" for="lecturePlayerCtrlQuality">🎚️ الجودة</label>
            <select class="acc-platformControls__select" id="lecturePlayerCtrlQuality" aria-label="جودة الفيديو" disabled>
              <option value="auto">تلقائي</option>
            </select>
          </div>
          <div class="acc-platformControls__group">
            <label class="acc-platformControls__label" for="lecturePlayerCtrlSpeed">⚡ السرعة</label>
            <select class="acc-platformControls__select" id="lecturePlayerCtrlSpeed" aria-label="سرعة التشغيل" disabled>
              <option value="1">1x</option>
            </select>
          </div>
        </div>
        <div class="acc-captureShield" id="lectureCaptureShield" role="status" aria-live="polite">
          <div class="acc-captureShield__content">
            <div class="acc-captureShield__text" id="lectureCaptureShieldText">⚫️ تم تعتيم المشغل لحماية المحتوى أثناء محاولة تصوير الشاشة.</div>
            <button class="acc-modal-btn acc-modal-btn--primary acc-captureShield__action" type="button" id="lectureCaptureShieldAction">
              🔓 فتح المشغل المحمي
            </button>
          </div>
        </div>
      </div>

      <div class="acc-playerNotice" id="lecturePlayerNotice">
        <?php if ($videoLockedByRequirement): ?>
          🔒 يجب حل وتسليم <?php echo h((string)($videoRequirement['assessment_name'] ?? 'المحتوى المرتبط')); ?> أولًا قبل تشغيل الفيديو. <a href="<?php echo h((string)($videoRequirement['assessment_href'] ?? '#')); ?>">فتح <?php echo h((string)($videoRequirement['assessment_label'] ?? 'المحتوى')); ?></a>
        <?php elseif (!empty($stats['blocked'])): ?>
          ⛔ لا يمكن تشغيل هذا الفيديو لأن عدد المشاهدات المسموحة انتهى.
        <?php else: ?>
          🔒 هذه الصفحة محمية: الفيديو يفتح من طبقة الحماية فقط. داخل تطبيق الطالب يمكن التشغيل مباشرة مع تفعيل ملء الشاشة اختياريًا، بينما يدعم المتصفح وضع العرض الآمن بملء الشاشة، وتبقى حماية فقدان التركيز الصارمة على الكمبيوتر.
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>

<script src="assets/js/theme.js"></script>
<script>
(function(){
  var videoId = <?php echo (int)$videoId; ?>;
  var lectureId = <?php echo (int)$lectureId; ?>;
  var videoState = {
    id: videoId,
    title: <?php echo json_encode((string)($video['title'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    durationMinutes: <?php echo (int)($video['duration_minutes'] ?? 0); ?>,
    viewsAllowed: <?php echo (int)($stats['allowed'] ?? 1); ?>,
    viewsUsed: <?php echo (int)($stats['used'] ?? 0); ?>,
    viewsRemaining: <?php echo (int)($stats['remaining'] ?? 0); ?>,
    videoType: <?php echo json_encode((string)($video['video_type'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    isAssessmentLocked: <?php echo $videoLockedByRequirement ? 'true' : 'false'; ?>,
    assessmentLockMessage: <?php echo json_encode(
      $videoLockedByRequirement
        ? ('يجب حل وتسليم ' . (string)($videoRequirement['assessment_name'] ?? 'المحتوى المرتبط') . ' أولًا قبل تشغيل الفيديو.')
        : '',
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ); ?>,
    isBlocked: <?php echo (!empty($stats['blocked']) || $videoLockedByRequirement) ? 'true' : 'false'; ?>,
    halfSeconds: <?php echo (int)$halfSeconds; ?>
  };

  var surface = document.getElementById('lecturePlayerSurface');
  var noticeEl = document.getElementById('lecturePlayerNotice');
  var startBtn = null;
  var fullscreenBtn = document.getElementById('lecturePlayerCtrlFullscreen');
  var playerStage = document.getElementById('lecturePlayerStage');
  var viewsAllowedEl = document.getElementById('videoViewsAllowed');
  var viewsUsedEl = document.getElementById('videoViewsUsed');
  var viewsRemainingEl = document.getElementById('videoViewsRemaining');
  var halfSecondsEl = null;
  var platformControls = document.getElementById('lecturePlayerControls');
  var ctrlPlayPauseBtn = document.getElementById('lecturePlayerCtrlPlayPause');
  var ctrlSeekBackBtn = document.getElementById('lecturePlayerCtrlSeekBack');
  var ctrlSeekForwardBtn = document.getElementById('lecturePlayerCtrlSeekForward');
  var ctrlTimelineInput = document.getElementById('lecturePlayerCtrlTimeline');
  var ctrlTimeLabel = document.getElementById('lecturePlayerCtrlTime');
  var ctrlVolumeInput = document.getElementById('lecturePlayerCtrlVolume');
  var ctrlQualitySelect = document.getElementById('lecturePlayerCtrlQuality');
  var ctrlSpeedSelect = document.getElementById('lecturePlayerCtrlSpeed');
  var captureShield = document.getElementById('lectureCaptureShield');
  var captureShieldText = document.getElementById('lectureCaptureShieldText');
  var captureShieldActionBtn = document.getElementById('lectureCaptureShieldAction');
  var playerInteractionShield = document.getElementById('lecturePlayerInteractionShield');

  var activeWatchToken = '';
  var countedToken = '';
  var heartbeatHandle = 0;
  var progressHandle = 0;
  var progressBaseSeconds = 0;
  var progressBaseStartedAt = 0;
  var requestInFlight = false;
  var protectedPageClosed = false;
  var devtoolsDetectionStrikes = 0;
  var controlsHideHandle = 0;
  var lastImmersiveWakeAt = 0;
  var youtubePlayer = null;
  var youtubeApiReadyPromise = null;
  var youtubeTimeHandle = 0;
  var timelineDragging = false;
  var maxReachedSeconds = 0;
  var playbackBootstrapped = false;
  var startRequestInFlight = false;
  var html5Player = null;
  // tuned for typical browser UI gaps so docked DevTools detection triggers before playback continues
  const devtoolsWidthGapThreshold = 160;
  const devtoolsHeightGapThreshold = 140;
  const devtoolsStrikeThreshold = 2;
  const devtoolsCheckIntervalMs = 400;
  const fallbackHalfSeconds = 30;
  const seekDeltaSeconds = 10;
  const html5SupportedPlaybackRates = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
  const immersiveControlsAutoHideDelayMs = 1800;
  const immersiveControlsWakeThrottleMs = 180;
  const youtubeStatePlaying = 1;
  const captureShieldDurationMs = 5200;
  const captureShieldMinHoldMs = 3200;
  const captureShieldDebounceMs = 350;
  const blurCheckDelayMs = 120;
  const fullscreenActivationDelayMs = 220;
  const mobileSecureStateResizeDebounceMs = 180;
  const mobileLandscapeLockRetryMs = 220;
  const mobileLandscapeLockRetryCount = 4;
  const mobileViewportOverlayThresholdPx = 20;
  const mobileViewportOffsetThresholdPx = 2;
  // Keep this above tiny fullscreen jitter, but low enough to catch devices where the notification shade only shrinks the secure viewport slightly.
  const mobileViewportBaselineShrinkThresholdPx = 12;
  const mobileSecureStatePollIntervalMs = 320;
  // A tiny delay gives the native WebView bridge and initial layout a moment to settle
  // before we auto-open playback inside the student app.
  const nativeStudentAppAutoUnlockDelayMs = 120;
  var captureShieldHandle = 0;
  var captureShieldVisibleUntil = 0;
  var lastCaptureShieldTriggerAt = 0;
  var captureShieldLocked = false;
  var captureShieldHeldIndefinitely = false;
  var fullscreenUnlockHandle = 0;
  var mobileSecureStateResizeHandle = 0;
  var mobileLandscapeLockHandle = 0;
  var mobileSecureStatePollHandle = 0;
  var mobileSecureViewportSnapshot = null;
  var mobileSecureStateWasSecure = null;
  var nativeStudentAppLandscapeModeActive = false;
  var initialShieldMessage = '🔒 الفيديو محجوب افتراضيًا للحماية. اضغط على زر فتح المشغل المحمي لعرض الفيديو داخل الصفحة الآمنة.';
  var hiddenShieldMessage = '⚫️ تم تعتيم المشغل تلقائيًا لحماية المحتوى عند محاولة تصوير الشاشة أو مغادرة الصفحة. افتح المشغل المحمي يدويًا للمتابعة.';
  var blurShieldMessage = '⚫️ تم تعتيم المشغل تلقائيًا لحماية المحتوى عند محاولة تصوير الشاشة أو سحب التركيز من نافذة المشغل. افتح المشغل المحمي يدويًا للمتابعة.';
  var recordShieldMessage = '⚫️ تم تعتيم المشغل تلقائيًا لحماية المحتوى عند محاولة تصوير أو تسجيل الشاشة. افتح المشغل المحمي يدويًا للمتابعة.';
  var mobileExitShieldMessage = '🔒 تم إعادة حجب الفيديو بعد الخروج من العرض الآمن على هذا الجهاز. اضغط على فتح المشغل المحمي للمتابعة.';
  var mobileSecureStateShieldMessage = '🔒 على الموبايل يجب إبقاء الفيديو داخل وضع عرض آمن وبدون شريط إشعارات أو أي طبقة نظام فوقه. أعد المشغل إلى الوضع الآمن ثم افتح المشغل المحمي.';
  var mobileFullscreenRejectionShieldMessage = '🔒 لم يتم تفعيل ملء الشاشة الآمن على هذا الجهاز، لذلك بقي الفيديو محجوبًا. اسمح بملء الشاشة أو استخدم متصفحًا يدعمها.';
  var html5DefaultQualityLabel = 'افتراضي';

  function ensureValidHalfSeconds(nextValue) {
    return Math.max(5, parseInt(nextValue || videoState.halfSeconds || fallbackHalfSeconds, 10));
  }

  function updateNotice(text, isError) {
    if (!noticeEl) return;
    noticeEl.textContent = text;
    noticeEl.style.borderColor = isError ? 'rgba(207,42,55,.35)' : 'rgba(44,123,229,.35)';
    noticeEl.style.background = isError ? 'rgba(207,42,55,.08)' : 'rgba(44,123,229,.08)';
  }

  function renderPlaceholder(message) {
    if (!surface) return;
    surface.innerHTML = '<div class="acc-playerPlaceholder">' + message + '</div>';
    if (playerStage && playerStage.classList) playerStage.classList.remove('acc-playerStage--platformControls');
    if (platformControls) platformControls.hidden = true;
    setYoutubeInteractionShieldEnabled(false);
  }

  function mountPlayerHtml(html) {
    if (!surface) return Promise.resolve();

    surface.innerHTML = '';
    if (!html) return Promise.resolve();

    var host = document.createElement('div');
    host.className = 'acc-playerEmbedHost';
    host.innerHTML = html;
    surface.appendChild(host);

    var scripts = Array.prototype.slice.call(host.querySelectorAll('script'));
    return scripts.reduce(function(chain, oldScript){
      return chain.then(function(){
        return new Promise(function(resolve){
          if (!oldScript.parentNode) {
            resolve();
            return;
          }

          var newScript = document.createElement('script');
          Array.prototype.slice.call(oldScript.attributes).forEach(function(attr){
            var attrName = String(attr.name || '').toLowerCase();
            if (
              attrName === 'src' ||
              attrName === 'type' ||
              attrName === 'async' ||
              attrName === 'defer' ||
              attrName === 'id' ||
              attrName.indexOf('data-') === 0
            ) {
              newScript.setAttribute(attr.name, attr.value);
            }
          });

          if (newScript.src) {
            newScript.async = false;
            newScript.onload = resolve;
            newScript.onerror = resolve;
          } else {
            newScript.text = oldScript.text || oldScript.textContent || '';
            resolve();
          }

          oldScript.parentNode.replaceChild(newScript, oldScript);
        });
      });
    }, Promise.resolve());
  }

  function setPlatformControlsEnabled(enabled) {
    [ctrlPlayPauseBtn, ctrlSeekBackBtn, ctrlSeekForwardBtn, fullscreenBtn, ctrlTimelineInput, ctrlVolumeInput, ctrlQualitySelect, ctrlSpeedSelect].forEach(function(el){
      if (el) el.disabled = !enabled;
    });
  }

  function setYoutubeInteractionShieldEnabled(enabled) {
    if (!playerInteractionShield || !playerStage || !playerStage.classList) return;
    playerInteractionShield.hidden = !enabled;
    playerStage.classList.toggle('acc-playerStage--youtubeInteractionBlocked', !!enabled);
  }

  function setPlayPauseLabel(isPlaying) {
    if (!ctrlPlayPauseBtn) return;
    ctrlPlayPauseBtn.textContent = isPlaying ? '⏸️ إيقاف' : '▶️ تشغيل';
  }

  function hideCaptureShield(force) {
    if (!captureShield) return;
    if (force && fullscreenUnlockHandle) {
      window.clearTimeout(fullscreenUnlockHandle);
      fullscreenUnlockHandle = 0;
    }
    if (captureShieldLocked && !force) return;
    if (captureShieldHeldIndefinitely && !force) return;
    if (!force && Date.now() < captureShieldVisibleUntil) return;
    if (force) {
      captureShieldLocked = false;
      captureShieldHeldIndefinitely = false;
      captureShieldVisibleUntil = 0;
    }
    captureShield.classList.remove('acc-captureShield--active', 'acc-captureShield--locked');
    if (playerStage && playerStage.classList) playerStage.classList.remove('acc-playerStage--captureBlocked');
  }

  function setCaptureShieldMessage(reason) {
    if (captureShieldText) {
      captureShieldText.textContent = reason;
      return;
    }
    if (captureShield) captureShield.textContent = reason;
  }

  function setCaptureShieldLocked(reason, options) {
    if (!captureShield || !playerStage) return;
    options = options || {};
    if (captureShieldHandle) {
      window.clearTimeout(captureShieldHandle);
      captureShieldHandle = 0;
    }
    captureShieldLocked = true;
    captureShieldHeldIndefinitely = true;
    setCaptureShieldMessage(reason || initialShieldMessage);
    captureShieldVisibleUntil = 0;
    captureShield.classList.add('acc-captureShield--active', 'acc-captureShield--locked');
    playerStage.classList.add('acc-playerStage--captureBlocked');
    if (captureShieldActionBtn) {
      captureShieldActionBtn.disabled = !!options.disableAction;
    }
    if (youtubePlayer) {
      try { youtubePlayer.pauseVideo(); } catch(e) {}
    }
  }

  function isLikelyMobilePlayback() {
    if (!window.matchMedia) return false;
    return window.matchMedia('(pointer: coarse)').matches || window.matchMedia('(max-width: 980px)').matches;
  }

  function requestSecureFullscreen(target) {
    if (!target) return null;
    return target.requestFullscreen ||
      target.webkitRequestFullscreen ||
      target.mozRequestFullScreen ||
      target.msRequestFullscreen ||
      null;
  }

  function getFullscreenElement() {
    return document.fullscreenElement ||
      document.webkitFullscreenElement ||
      document.mozFullScreenElement ||
      document.msFullscreenElement ||
      null;
  }

  function isStageFullscreenActive() {
    var fullscreenElement = getFullscreenElement();
    return !!(playerStage && fullscreenElement === playerStage);
  }

  function isFullscreenPresentationActive() {
    return !!getFullscreenElement() || (nativeStudentAppLandscapeModeActive && isLikelyMobilePlayback());
  }

  function syncFullscreenToggleLabels() {
    if (!fullscreenBtn) return;
    var fullscreenActive = isFullscreenPresentationActive();
    fullscreenBtn.textContent = fullscreenActive ? '🡼 تصغير' : '⛶ ملء';
    fullscreenBtn.setAttribute('aria-label', fullscreenActive ? 'إنهاء تكبير المشغل' : 'تكبير المشغل');
  }

  function exitSecureFullscreen() {
    var exitFullscreen = document.exitFullscreen ||
      document.webkitExitFullscreen ||
      document.mozCancelFullScreen ||
      document.msExitFullscreen ||
      null;
    if (typeof exitFullscreen !== 'function') return Promise.resolve();
    return exitFullscreen.call(document);
  }

  function lockMobileLandscapeOrientation() {
    if (!isLikelyMobilePlayback() || !isStageFullscreenActive()) return;
    notifyNativeStudentAppLandscapeMode(true);
    if (!window.screen || !window.screen.orientation || typeof window.screen.orientation.lock !== 'function') return;
    try {
      var lockPromise = window.screen.orientation.lock('landscape');
      if (lockPromise && typeof lockPromise.catch === 'function') {
        lockPromise.catch(function(){});
      }
    } catch(e) {}
  }

  function unlockMobileLandscapeOrientation() {
    notifyNativeStudentAppLandscapeMode(false);
    if (!window.screen || !window.screen.orientation || typeof window.screen.orientation.unlock !== 'function') return;
    try {
      window.screen.orientation.unlock();
    } catch(e) {}
  }

  function notifyNativeStudentAppLandscapeMode(enabled) {
    if (!enabled) {
      nativeStudentAppLandscapeModeActive = false;
      syncMobileLandscapePresentation();
      if (!window.StudentAppBridge) return;
    } else if (!window.StudentAppBridge) {
      return;
    }

    try {
      if (enabled && typeof window.StudentAppBridge.enterLandscapeVideoMode === 'function') {
        window.StudentAppBridge.enterLandscapeVideoMode();
        nativeStudentAppLandscapeModeActive = true;
      } else if (!enabled && typeof window.StudentAppBridge.exitLandscapeVideoMode === 'function') {
        window.StudentAppBridge.exitLandscapeVideoMode();
        nativeStudentAppLandscapeModeActive = false;
      }
    } catch(e) {}
    syncMobileLandscapePresentation();
  }

  function activateNativeStudentAppLandscapeMode() {
    if (!isNativeStudentAppPlayback() || nativeStudentAppLandscapeModeActive) return;
    notifyNativeStudentAppLandscapeMode(true);
    mobileSecureStateWasSecure = true;
    syncMobileSecureViewportSnapshot();
  }

  function isNativeStudentAppPlayback() {
    return !!(
      isLikelyMobilePlayback() &&
      window.StudentAppBridge &&
      typeof window.StudentAppBridge.enterLandscapeVideoMode === 'function' &&
      typeof window.StudentAppBridge.exitLandscapeVideoMode === 'function'
    );
  }

  function hasDocumentFocus() {
    if (typeof document.hasFocus === 'function') return document.hasFocus();
    if (typeof window.hasFocus === 'function') return window.hasFocus();
    return false;
  }

  function getMobileViewportMetrics() {
    var viewport = window.visualViewport || null;
    var viewportWidth = parseFloat((viewport && viewport.width) || window.innerWidth || 0);
    var viewportHeight = parseFloat((viewport && viewport.height) || window.innerHeight || 0);
    return {
      layoutWidth: parseFloat(window.innerWidth || viewportWidth || 0),
      layoutHeight: parseFloat(window.innerHeight || viewportHeight || 0),
      viewportWidth: viewportWidth,
      viewportHeight: viewportHeight,
      offsetTop: Math.max(0, parseFloat((viewport && viewport.offsetTop) || 0)),
      offsetLeft: Math.max(0, parseFloat((viewport && viewport.offsetLeft) || 0))
    };
  }

  function syncMobileSecureViewportSnapshot() {
    if (!isLikelyMobilePlayback() || !isFullscreenPresentationActive()) {
      mobileSecureViewportSnapshot = null;
      return;
    }
    mobileSecureViewportSnapshot = getMobileViewportMetrics();
  }

  function getViewportMajorMinorDimensions(metrics) {
    return {
      layoutMajor: Math.max(metrics.layoutWidth, metrics.layoutHeight),
      layoutMinor: Math.min(metrics.layoutWidth, metrics.layoutHeight),
      viewportMajor: Math.max(metrics.viewportWidth, metrics.viewportHeight),
      viewportMinor: Math.min(metrics.viewportWidth, metrics.viewportHeight)
    };
  }

  function hasMobileViewportOverlay() {
    if (!isLikelyMobilePlayback()) return false;
    var metrics = getMobileViewportMetrics();
    var widthGap = metrics.layoutWidth > 0 && metrics.viewportWidth > 0
      ? Math.abs(metrics.layoutWidth - metrics.viewportWidth)
      : 0;
    var heightGap = metrics.layoutHeight > 0 && metrics.viewportHeight > 0
      ? Math.abs(metrics.layoutHeight - metrics.viewportHeight)
      : 0;
    if (
      widthGap > mobileViewportOverlayThresholdPx ||
      heightGap > mobileViewportOverlayThresholdPx ||
      metrics.offsetTop > mobileViewportOffsetThresholdPx ||
      metrics.offsetLeft > mobileViewportOffsetThresholdPx
    ) {
      return true;
    }
    if (!mobileSecureViewportSnapshot) return false;
    var baselineDimensions = getViewportMajorMinorDimensions(mobileSecureViewportSnapshot);
    var currentDimensions = getViewportMajorMinorDimensions(metrics);

    return (
      baselineDimensions.layoutMajor - currentDimensions.layoutMajor > mobileViewportBaselineShrinkThresholdPx ||
      baselineDimensions.layoutMinor - currentDimensions.layoutMinor > mobileViewportBaselineShrinkThresholdPx ||
      baselineDimensions.viewportMajor - currentDimensions.viewportMajor > mobileViewportBaselineShrinkThresholdPx ||
      baselineDimensions.viewportMinor - currentDimensions.viewportMinor > mobileViewportBaselineShrinkThresholdPx
    );
  }

  function hasMobileSecurePlaybackState() {
    if (isNativeStudentAppPlayback()) {
      if (document.visibilityState === 'hidden') return false;
      if (hasMobileViewportOverlay()) return false;
      return true;
    }
    if (!isStageFullscreenActive()) return false;
    if (document.visibilityState === 'hidden') return false;
    if (hasMobileViewportOverlay()) return false;
    return true;
  }

  function needsMobileLandscapeViewportFallback() {
    if (!isLikelyMobilePlayback() || !isFullscreenPresentationActive()) return false;
    var viewportWidth = window.innerWidth || (window.visualViewport && window.visualViewport.width) || 0;
    var viewportHeight = window.innerHeight || (window.visualViewport && window.visualViewport.height) || 0;
    return viewportHeight > viewportWidth;
  }

  function syncMobileLandscapePresentation() {
    if (!playerStage || !playerStage.classList) return;
    playerStage.classList.toggle('acc-playerStage--mobileLandscapeFallback', needsMobileLandscapeViewportFallback());
    playerStage.classList.toggle('acc-playerStage--nativeAppFullscreen', nativeStudentAppLandscapeModeActive && isLikelyMobilePlayback());
    if (document.body && document.body.classList) {
      document.body.classList.toggle('acc-body--nativeVideoFullscreen', nativeStudentAppLandscapeModeActive && isLikelyMobilePlayback());
    }
    showImmersiveControls();
    syncFullscreenToggleLabels();
  }

  function scheduleMobileLandscapeLock(attemptsLeft) {
    if (mobileLandscapeLockHandle) {
      window.clearTimeout(mobileLandscapeLockHandle);
      mobileLandscapeLockHandle = 0;
    }
    if (!isLikelyMobilePlayback() || !isStageFullscreenActive()) {
      syncMobileLandscapePresentation();
      return;
    }
    lockMobileLandscapeOrientation();
    syncMobileLandscapePresentation();
    if ((attemptsLeft || 0) <= 0) return;
    mobileLandscapeLockHandle = window.setTimeout(function(){
      mobileLandscapeLockHandle = 0;
      scheduleMobileLandscapeLock((attemptsLeft || 0) - 1);
    }, mobileLandscapeLockRetryMs);
  }

  function hasSecurePlaybackFocus() {
    // On mobile the player must remain in protected fullscreen; any system overlay will force the shield back on.
    if (isLikelyMobilePlayback()) return hasMobileSecurePlaybackState();
    if (document.visibilityState === 'hidden') return false;
    if (!hasDocumentFocus()) return false;
    return true;
  }

  function securePlaybackLockReason() {
    if (isLikelyMobilePlayback()) {
      return hasMobileSecurePlaybackState() ? '' : mobileSecureStateShieldMessage;
    }
    if (document.visibilityState === 'hidden') return hiddenShieldMessage;
    if (!hasDocumentFocus()) return blurShieldMessage;
    return '';
  }

  function enforceSecurePlaybackState(reason, noticeText) {
    var lockReason = reason || securePlaybackLockReason();
    if (!lockReason) return true;
    setCaptureShieldLocked(lockReason);
    if (noticeText) updateNotice(noticeText, true);
    sendProgress('heartbeat');
    return false;
  }

  function evaluateMobileSecurePlaybackState() {
    if (protectedPageClosed || videoState.isBlocked || !playbackBootstrapped || !isLikelyMobilePlayback()) {
      mobileSecureStateWasSecure = null;
      return true;
    }

    var securePlaybackState = hasMobileSecurePlaybackState();
    if (securePlaybackState) {
      mobileSecureStateWasSecure = true;
      syncMobileSecureViewportSnapshot();
      return true;
    }

    if (mobileSecureStateWasSecure === false && captureShieldLocked) return false;
    mobileSecureStateWasSecure = false;
    var mobileViewportOverlayDetected = hasMobileViewportOverlay();
    var mobileViewportNotice = mobileViewportOverlayDetected
      ? '🔒 تم اكتشاف شريط إشعارات أو طبقة نظام فوق الفيديو، لذلك تمت إعادة حجب الفيديو حتى يعود العرض الآمن الكامل.'
      : '🔒 تغيّرت حالة العرض على الموبايل، لذلك تمت إعادة حجب الفيديو حتى يعود الوضع الآمن المناسب.';
    return enforceSecurePlaybackState(mobileViewportOverlayDetected ? mobileSecureStateShieldMessage : mobileExitShieldMessage, mobileViewportNotice);
  }

  function ensureMobileSecureStatePolling() {
    if (mobileSecureStatePollHandle) return;
    mobileSecureStatePollHandle = window.setInterval(function(){
      if (!isStageFullscreenActive()) {
        if (!isLikelyMobilePlayback() || protectedPageClosed || videoState.isBlocked || !playbackBootstrapped) {
          mobileSecureStateWasSecure = null;
        }
        return;
      }
      evaluateMobileSecurePlaybackState();
    }, mobileSecureStatePollIntervalMs);
  }

  function unlockProtectedPlayback() {
    if (videoState.isBlocked) return;
    if (!hasSecurePlaybackFocus()) {
      var secureReason = securePlaybackLockReason() || '🔒 أعد الصفحة إلى الواجهة أولًا ثم افتح المشغل المحمي.';
      setCaptureShieldLocked(secureReason);
      updateNotice(isLikelyMobilePlayback()
        ? '🔒 على الموبايل يجب أن يبقى الفيديو داخل وضع آمن قبل فتح المشغل.'
        : '🔒 يجب أن تبقى الصفحة في الواجهة قبل فتح المشغل المحمي.', true);
      return;
    }

    hideCaptureShield(true);
    updateNotice('⏳ جاري تجهيز المشغل المحمي داخل الصفحة الآمنة...', false);
    if (!playbackBootstrapped) {
      startPlayback();
      return;
    }

    if (youtubePlayer) {
      try { youtubePlayer.pauseVideo(); } catch(e) {}
    }
    updateNotice('✅ تم فتح المشغل المحمي. شغّل الفيديو من زر التشغيل داخل المشغل.', false);
  }

  function triggerCaptureShield(reason) {
    if (!captureShield || !playerStage) return;
    if (captureShieldHandle) {
      window.clearTimeout(captureShieldHandle);
      captureShieldHandle = 0;
    }
    captureShieldLocked = false;
    captureShieldHeldIndefinitely = false;
    if (captureShieldActionBtn) captureShieldActionBtn.disabled = false;
    captureShield.classList.remove('acc-captureShield--locked');
    if (reason) setCaptureShieldMessage(reason);
    captureShieldVisibleUntil = Date.now() + captureShieldMinHoldMs;
    captureShield.classList.add('acc-captureShield--active');
    playerStage.classList.add('acc-playerStage--captureBlocked');
    if (youtubePlayer) {
      try { youtubePlayer.pauseVideo(); } catch(e) {}
    }
    captureShieldHandle = window.setTimeout(function(){
      hideCaptureShield();
      if (playerStage && playerStage.classList) playerStage.classList.remove('acc-playerStage--captureBlocked');
    }, captureShieldDurationMs);
  }

  function isCaptureShortcutEvent(e) {
    var key = String((e && e.key) || '').toLowerCase();
    if (!key) return false;
    if (key === 'printscreen' || key === 'snapshot') return true;
    if (e && e.metaKey && e.shiftKey && (key === '3' || key === '4' || key === '5' || key === 's')) return true;
    if (e && e.ctrlKey && e.shiftKey && (key === 's' || key === 'printscreen')) return true;
    if (e && e.altKey && key === 'printscreen') return true;
    return false;
  }

  function triggerCaptureShieldAttempt(reason) {
    var now = Date.now();
    if (now - lastCaptureShieldTriggerAt < captureShieldDebounceMs) return;
    lastCaptureShieldTriggerAt = now;
    triggerCaptureShield(reason);
    sendProgress('heartbeat');
  }

  function setPlatformControlsVisible(visible) {
    if (platformControls) platformControls.hidden = !visible;
    if (playerStage && playerStage.classList) {
      if (visible) playerStage.classList.add('acc-playerStage--platformControls');
      else playerStage.classList.remove('acc-playerStage--platformControls');
      if (!visible) {
        playerStage.classList.remove('acc-playerStage--controlsVisible');
      }
    }
    if (visible) showImmersiveControls();
    else clearControlsHideTimer();
  }

  function clearControlsHideTimer() {
    if (controlsHideHandle) {
      window.clearTimeout(controlsHideHandle);
      controlsHideHandle = 0;
    }
  }

  function hasPlayerStageClassList() {
    return !!(playerStage && playerStage.classList);
  }

  function shouldKeepControlsVisibleInAppLandscape() {
    return nativeStudentAppLandscapeModeActive && isLikelyMobilePlayback();
  }

  function pinPlatformControls() {
    clearControlsHideTimer();
    if (!hasPlayerStageClassList()) return;
    playerStage.classList.remove('acc-playerStage--immersive');
    playerStage.classList.add('acc-playerStage--controlsVisible');
  }

  function pinControlsInAppLandscapeIfNeeded() {
    if (!shouldKeepControlsVisibleInAppLandscape()) return false;
    pinPlatformControls();
    return true;
  }

  function hideImmersiveControls() {
    if (!hasPlayerStageClassList() || !platformControls || platformControls.hidden) return;
    if (!isFullscreenPresentationActive()) return;
    if (pinControlsInAppLandscapeIfNeeded()) return;
    playerStage.classList.remove('acc-playerStage--controlsVisible');
  }

  function scheduleImmersiveControlsHide() {
    if (!hasPlayerStageClassList() || !platformControls || platformControls.hidden) return;
    if (pinControlsInAppLandscapeIfNeeded()) return;
    if (!isFullscreenPresentationActive()) {
      clearControlsHideTimer();
      return;
    }
    clearControlsHideTimer();
    controlsHideHandle = window.setTimeout(function(){
      controlsHideHandle = 0;
      hideImmersiveControls();
    }, immersiveControlsAutoHideDelayMs);
  }

  function showImmersiveControls() {
    if (!playerStage || !platformControls || platformControls.hidden) return;
    var isFullscreenPresentation = isFullscreenPresentationActive();
    if (!isFullscreenPresentation) {
      clearControlsHideTimer();
      playerStage.classList.remove('acc-playerStage--immersive');
      playerStage.classList.add('acc-playerStage--controlsVisible');
      return;
    }
    if (pinControlsInAppLandscapeIfNeeded()) return;

    playerStage.classList.add('acc-playerStage--immersive');
    playerStage.classList.add('acc-playerStage--controlsVisible');
    scheduleImmersiveControlsHide();
  }

  function toggleImmersiveControlsVisibility() {
    if (!playerStage || !playerStage.classList || !platformControls || platformControls.hidden) return;
    if (!isFullscreenPresentationActive()) {
      showImmersiveControls();
      return;
    }
    if (playerStage.classList.contains('acc-playerStage--controlsVisible')) {
      hideImmersiveControls();
      clearControlsHideTimer();
      return;
    }
    showImmersiveControls();
  }

  function formatClock(seconds) {
    var totalSeconds = Math.max(0, Math.floor(parseFloat(seconds) || 0));
    var hours = Math.floor(totalSeconds / 3600);
    var minutes = Math.floor((totalSeconds % 3600) / 60);
    var secs = totalSeconds % 60;
    var zeroPad = function(n){ return n < 10 ? '0' + n : String(n); };
    if (hours > 0) return hours + ':' + zeroPad(minutes) + ':' + zeroPad(secs);
    return zeroPad(minutes) + ':' + zeroPad(secs);
  }

  function stopYoutubeTimeTicker() {
    if (youtubeTimeHandle) {
      window.clearInterval(youtubeTimeHandle);
      youtubeTimeHandle = 0;
    }
  }

  function refreshYoutubeTimeControl(force) {
    var current = 0;
    var duration = 0;
    if (youtubePlayer) {
      try { current = youtubePlayer.getCurrentTime() || 0; } catch(e) {}
      try { duration = youtubePlayer.getDuration() || 0; } catch(e) {}
    }

    if (!isFinite(current)) current = 0;
    if (!isFinite(duration) || duration < 0) duration = 0;
    current = Math.max(0, current);
    if (duration > 0) current = Math.min(current, duration);
    maxReachedSeconds = Math.max(maxReachedSeconds, current);

    if (!ctrlTimelineInput || !ctrlTimeLabel) return;

    ctrlTimelineInput.max = String(Math.max(0, Math.floor(duration)));
    if (!timelineDragging || force) {
      ctrlTimelineInput.value = String(Math.floor(current));
    }

    var displayedCurrent = force ? Math.floor(ctrlTimelineInput.value || 0) : Math.floor(current);
    if (!isFinite(displayedCurrent)) displayedCurrent = 0;
    if (duration > 0) displayedCurrent = Math.min(displayedCurrent, Math.floor(duration));
    ctrlTimeLabel.textContent = formatClock(displayedCurrent) + ' / ' + formatClock(duration);
    ctrlTimelineInput.setAttribute('aria-valuetext', ctrlTimeLabel.textContent);
  }

  function loadYoutubeApi() {
    if (window.YT && window.YT.Player) return Promise.resolve();
    if (youtubeApiReadyPromise) return youtubeApiReadyPromise;

    youtubeApiReadyPromise = new Promise(function(resolve){
      var previous = window.onYouTubeIframeAPIReady;
      window.onYouTubeIframeAPIReady = function(){
        if (typeof previous === 'function') previous();
        resolve();
      };

      var exists = document.querySelector('script[src*="youtube.com/iframe_api"]');
      if (exists) return;
      var script = document.createElement('script');
      script.src = 'https://www.youtube.com/iframe_api';
      script.async = true;
      document.head.appendChild(script);
    });

    return youtubeApiReadyPromise;
  }

  function refreshYoutubeQualityOptions() {
    if (!youtubePlayer || !ctrlQualitySelect) return;
    var levels = [];
    try { levels = youtubePlayer.getAvailableQualityLevels() || []; } catch(e) {}

    ctrlQualitySelect.innerHTML = '<option value="auto">تلقائي</option>';
    levels.forEach(function(level){
      var opt = document.createElement('option');
      opt.value = level;
      opt.textContent = level;
      ctrlQualitySelect.appendChild(opt);
    });

    var current = '';
    try { current = youtubePlayer.getPlaybackQuality() || 'auto'; } catch(e) {}
    if (current !== '' && ctrlQualitySelect.querySelector('option[value="' + current + '"]')) {
      ctrlQualitySelect.value = current;
    } else {
      ctrlQualitySelect.value = 'auto';
    }
  }

  function refreshYoutubeSpeedOptions() {
    if (!youtubePlayer || !ctrlSpeedSelect) return;
    var rates = [];
    try { rates = youtubePlayer.getAvailablePlaybackRates() || [1]; } catch(e) { rates = [1]; }

    ctrlSpeedSelect.innerHTML = '';
    rates.forEach(function(rate){
      var val = String(rate);
      var opt = document.createElement('option');
      opt.value = val;
      opt.textContent = val + 'x';
      ctrlSpeedSelect.appendChild(opt);
    });

    var currentRate = 1;
    try { currentRate = youtubePlayer.getPlaybackRate() || 1; } catch(e) {}
    ctrlSpeedSelect.value = String(currentRate);
  }

  function refreshYoutubeVolumeControl() {
    if (!youtubePlayer || !ctrlVolumeInput) return;
    var currentVolume = 100;
    var isMuted = false;
    try { currentVolume = youtubePlayer.getVolume(); } catch(e) {}
    try { isMuted = !!youtubePlayer.isMuted(); } catch(e) {}
    if (!isFinite(currentVolume)) currentVolume = 100;
    var normalizedVolume = isMuted ? 0 : Math.max(0, Math.min(100, Math.round(currentVolume)));
    ctrlVolumeInput.value = String(normalizedVolume);
    ctrlVolumeInput.setAttribute('aria-valuetext', normalizedVolume + '%');
  }

  function refreshHtml5TimeControl(force) {
    if (!html5Player || !ctrlTimelineInput || !ctrlTimeLabel) return;
    var current = parseFloat(html5Player.currentTime || 0);
    var duration = parseFloat(html5Player.duration || 0);
    if (!isFinite(current)) current = 0;
    if (!isFinite(duration) || duration < 0) duration = 0;
    current = Math.max(0, current);
    if (duration > 0) current = Math.min(current, duration);
    maxReachedSeconds = Math.max(maxReachedSeconds, current);

    ctrlTimelineInput.max = String(Math.max(0, Math.floor(duration)));
    if (!timelineDragging || force) {
      ctrlTimelineInput.value = String(Math.floor(current));
    }

    var displayedCurrent = force ? Math.floor(ctrlTimelineInput.value || 0) : Math.floor(current);
    if (!isFinite(displayedCurrent)) displayedCurrent = 0;
    if (duration > 0) displayedCurrent = Math.min(displayedCurrent, Math.floor(duration));
    ctrlTimeLabel.textContent = formatClock(displayedCurrent) + ' / ' + formatClock(duration);
    ctrlTimelineInput.setAttribute('aria-valuetext', ctrlTimeLabel.textContent);
  }

  function refreshHtml5SpeedOptions() {
    if (!html5Player || !ctrlSpeedSelect) return;
    ctrlSpeedSelect.innerHTML = '';
    html5SupportedPlaybackRates.forEach(function(rate){
      var opt = document.createElement('option');
      opt.value = String(rate);
      opt.textContent = rate + 'x';
      ctrlSpeedSelect.appendChild(opt);
    });
    var currentRate = parseFloat(html5Player.playbackRate || 1);
    if (!isFinite(currentRate) || currentRate <= 0) currentRate = 1;
    ctrlSpeedSelect.value = String(currentRate);
    if (ctrlSpeedSelect.value === '' && ctrlSpeedSelect.querySelector('option[value="1"]')) {
      ctrlSpeedSelect.value = '1';
    }
  }

  function refreshHtml5VolumeControl() {
    if (!html5Player || !ctrlVolumeInput) return;
    var normalizedVolume = html5Player.muted ? 0 : Math.round(Math.max(0, Math.min(1, parseFloat(html5Player.volume || 0))) * 100);
    ctrlVolumeInput.value = String(normalizedVolume);
    ctrlVolumeInput.setAttribute('aria-valuetext', normalizedVolume + '%');
  }

  function detachHtml5PlatformControls() {
    if (!html5Player || !html5Player.__accPlatformHandlers) return;
    var handlers = html5Player.__accPlatformHandlers;
    Object.keys(handlers).forEach(function(evt){
      html5Player.removeEventListener(evt, handlers[evt]);
    });
    delete html5Player.__accPlatformHandlers;
  }

  function initHtml5PlatformControls(videoEl) {
    detachHtml5PlatformControls();
    if (!videoEl) {
      html5Player = null;
      return;
    }

    html5Player = videoEl;
    html5Player.controls = false;
    setPlatformControlsVisible(true);
    setPlatformControlsEnabled(true);
    setPlayPauseLabel(!html5Player.paused && !html5Player.ended);
    refreshHtml5TimeControl(false);
    refreshHtml5VolumeControl();
    refreshHtml5SpeedOptions();
    if (ctrlQualitySelect) {
      ctrlQualitySelect.innerHTML = '<option value="default">' + html5DefaultQualityLabel + '</option>';
      ctrlQualitySelect.value = 'default';
      ctrlQualitySelect.disabled = true;
    }

    var html5Handlers = {
      loadedmetadata: function(){
        refreshHtml5TimeControl(false);
      },
      durationchange: function(){
        refreshHtml5TimeControl(false);
      },
      timeupdate: function(){
        refreshHtml5TimeControl(false);
      },
      seeking: function(){
        refreshHtml5TimeControl(false);
      },
      seeked: function(){
        refreshHtml5TimeControl(false);
      },
      play: function(){
        setPlayPauseLabel(!html5Player.paused && !html5Player.ended);
        scheduleImmersiveControlsHide();
      },
      playing: function(){
        setPlayPauseLabel(!html5Player.paused && !html5Player.ended);
        scheduleImmersiveControlsHide();
      },
      pause: function(){
        setPlayPauseLabel(!html5Player.paused && !html5Player.ended);
        showImmersiveControls();
      },
      ended: function(){
        setPlayPauseLabel(!html5Player.paused && !html5Player.ended);
        showImmersiveControls();
      },
      volumechange: function(){
        refreshHtml5VolumeControl();
      },
      ratechange: function(){
        refreshHtml5SpeedOptions();
      }
    };
    html5Player.__accPlatformHandlers = html5Handlers;
    Object.keys(html5Handlers).forEach(function(evt){
      html5Player.addEventListener(evt, html5Handlers[evt]);
    });
  }

  function hasPlatformPlaybackController() {
    return !!youtubePlayer || !!html5Player;
  }

  function togglePlatformPlayback() {
    if (youtubePlayer) {
      var state = -1;
      try { state = youtubePlayer.getPlayerState(); } catch(e) {}
      if (state === youtubeStatePlaying) {
        youtubePlayer.pauseVideo();
      } else {
        youtubePlayer.playVideo();
      }
      return;
    }
    if (!html5Player) return;
    if (html5Player.paused || html5Player.ended) {
      var playPromise = html5Player.play();
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(function(error){
          if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('تعذر بدء تشغيل فيديو HTML5 أو تم منعه من المتصفح.', error);
          }
        });
      }
    } else {
      html5Player.pause();
    }
  }

  function seekPlatformPlayerBy(deltaSeconds) {
    if (youtubePlayer) {
      var duration = 0;
      var current = 0;
      try { duration = youtubePlayer.getDuration() || 0; } catch(e) {}
      try { current = youtubePlayer.getCurrentTime() || 0; } catch(e) {}
      if (!isFinite(duration) || duration < 0) duration = 0;
      if (!isFinite(current) || current < 0) current = 0;
      var nextYoutubeTime = current + (parseFloat(deltaSeconds) || 0);
      if (duration > 0) nextYoutubeTime = Math.min(nextYoutubeTime, duration);
      nextYoutubeTime = Math.max(0, nextYoutubeTime);
      youtubePlayer.seekTo(nextYoutubeTime, true);
      refreshYoutubeTimeControl(true);
      return;
    }
    if (!html5Player) return;
    var html5Duration = parseFloat(html5Player.duration || 0);
    var html5Current = parseFloat(html5Player.currentTime || 0);
    if (!isFinite(html5Duration) || html5Duration < 0) html5Duration = 0;
    if (!isFinite(html5Current) || html5Current < 0) html5Current = 0;
    var nextHtml5Time = html5Current + (parseFloat(deltaSeconds) || 0);
    if (html5Duration > 0) nextHtml5Time = Math.min(nextHtml5Time, html5Duration);
    nextHtml5Time = Math.max(0, nextHtml5Time);
    html5Player.currentTime = nextHtml5Time;
    refreshHtml5TimeControl(true);
  }

  function initYoutubePlatformControls(frame) {
    if (!frame) {
      setPlatformControlsVisible(false);
      setPlatformControlsEnabled(false);
      return;
    }

    frame.id = frame.id || 'lectureVideoFrame';
    loadYoutubeApi().then(function(){
      if (!window.YT || !window.YT.Player) return;
      youtubePlayer = new window.YT.Player(frame.id, {
        events: {
          onReady: function(){
            setPlatformControlsVisible(true);
            setPlatformControlsEnabled(true);
            setPlayPauseLabel(false);
            refreshYoutubeQualityOptions();
            refreshYoutubeSpeedOptions();
            refreshYoutubeVolumeControl();
            refreshYoutubeTimeControl(false);
            stopYoutubeTimeTicker();
            youtubeTimeHandle = window.setInterval(function(){
              refreshYoutubeTimeControl(false);
            }, 500);
          },
          onStateChange: function(event){
            var isPlaying = !!(event && event.data === youtubeStatePlaying);
            setPlayPauseLabel(isPlaying);
            refreshYoutubeTimeControl(false);
            if (isPlaying) scheduleImmersiveControlsHide();
            else showImmersiveControls();
          }
        }
      });
    }).catch(function(){
      setPlatformControlsVisible(false);
      setPlatformControlsEnabled(false);
      stopYoutubeTimeTicker();
    });
  }

  function syncStats(stats) {
    if (!stats) return;
    videoState.viewsAllowed = parseInt(stats.allowed || videoState.viewsAllowed || 1, 10);
    videoState.viewsUsed = parseInt(stats.used || 0, 10);
    videoState.viewsRemaining = parseInt(stats.remaining || 0, 10);
    videoState.isBlocked = !!videoState.isAssessmentLocked || videoState.viewsRemaining <= 0;

    if (viewsAllowedEl) viewsAllowedEl.textContent = videoState.viewsAllowed;
    if (viewsUsedEl) viewsUsedEl.textContent = videoState.viewsUsed;
    if (viewsRemainingEl) viewsRemainingEl.textContent = videoState.viewsRemaining;

    if (videoState.isBlocked && fullscreenBtn && !getFullscreenElement()) fullscreenBtn.disabled = true;
  }

  function stopProgressTimers() {
    if (heartbeatHandle) {
      window.clearInterval(heartbeatHandle);
      heartbeatHandle = 0;
    }
    if (progressHandle) {
      window.clearInterval(progressHandle);
      progressHandle = 0;
    }
    requestInFlight = false;
  }

  function currentWatchedSeconds() {
    if (!progressBaseStartedAt) return progressBaseSeconds;
    return progressBaseSeconds + Math.max(0, Math.floor((Date.now() - progressBaseStartedAt) / 1000));
  }

  function resetProgress(seconds) {
    progressBaseSeconds = Math.max(0, parseInt(seconds || 0, 10));
    progressBaseStartedAt = Date.now();
  }

  function startNoticeTicker() {
    if (progressHandle) window.clearInterval(progressHandle);
    progressHandle = window.setInterval(function(){
      if (!activeWatchToken || countedToken === activeWatchToken) return;
      var remaining = Math.max(0, videoState.halfSeconds - currentWatchedSeconds());
      if (remaining <= 0) {
        window.clearInterval(progressHandle);
        progressHandle = 0;
      }
    }, 1000);
  }

  function sendProgress(action) {
    if (!activeWatchToken || requestInFlight || protectedPageClosed) return;

    requestInFlight = true;
    var body = new URLSearchParams();
    body.set('action', action || 'heartbeat');
    body.set('video_id', videoId);
    body.set('watch_token', activeWatchToken);

    fetch('api/lecture_video_api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function(res){
      return res.json();
    }).then(function(data){
      requestInFlight = false;
      if (!data) return;

      if (data.stats) syncStats(data.stats);
      if (typeof data.half_seconds !== 'undefined') {
        videoState.halfSeconds = ensureValidHalfSeconds(data.half_seconds);
        if (halfSecondsEl) halfSecondsEl.textContent = videoState.halfSeconds;
      }
      if (typeof data.watched_seconds !== 'undefined') {
        resetProgress(parseInt(data.watched_seconds || 0, 10));
      }

      if (data.counted) {
        countedToken = activeWatchToken;
        stopProgressTimers();
        updateNotice('✅ ' + (data.message || 'تم احتساب مشاهدة الفيديو بنجاح.'), false);
        return;
      }

      if (data.ok === false && action !== 'heartbeat') {
        updateNotice('⛔ ' + (data.message || 'لم يكتمل زمن المشاهدة المطلوب بعد.'), true);
        return;
      }
    }).catch(function(){
      requestInFlight = false;
    });
  }

  function startBackgroundTracking(initialWatchedSeconds) {
    stopProgressTimers();
    resetProgress(initialWatchedSeconds);
    startNoticeTicker();
    heartbeatHandle = window.setInterval(function(){
      sendProgress('heartbeat');
    }, 10000);
  }

  function startPlayback() {
    if (videoState.isBlocked) {
      updateNotice(videoState.isAssessmentLocked ? ('🔒 ' + (videoState.assessmentLockMessage || 'الفيديو مرتبط بمحتوى يجب حله وتسليمه أولًا.')) : '⛔ انتهت عدد المشاهدات المسموحة لهذا الفيديو.', true);
      return;
    }
    if (startRequestInFlight || playbackBootstrapped) {
      return;
    }
    startRequestInFlight = true;

    renderPlaceholder('⏳ جاري تجهيز الفيديو داخل مشغل المنصة...');

    var body = new URLSearchParams();
    body.set('action', 'start');
    body.set('video_id', videoId);

    fetch('api/lecture_video_api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function(res){
      return res.json();
    }).then(function(data){
      startRequestInFlight = false;

      if (!data || !data.ok) {
        renderPlaceholder('❌ تعذر تشغيل الفيديو داخل بلاير المنصة.');
        if (data && data.stats) syncStats(data.stats);
        updateNotice('⛔ ' + ((data && data.message) || 'تعذر تشغيل الفيديو داخل بلاير المنصة.'), true);
        return;
      }

      activeWatchToken = data.watch_token || '';
      countedToken = '';
      maxReachedSeconds = Math.max(0, parseInt(data.watched_seconds || 0, 10));
      playbackBootstrapped = true;
      if (typeof data.half_seconds !== 'undefined') {
        videoState.halfSeconds = ensureValidHalfSeconds(data.half_seconds);
        if (halfSecondsEl) halfSecondsEl.textContent = videoState.halfSeconds;
      }
      if (data.video && typeof data.video.video_type !== 'undefined') {
        videoState.videoType = String(data.video.video_type || '');
      }

      mountPlayerHtml(data.player_html || '').then(function(){
        if (data.stats) syncStats(data.stats);
        detachHtml5PlatformControls();
        var mountedFrame = surface ? surface.querySelector('iframe') : null;
        var mountedVideo = surface ? surface.querySelector('video') : null;
        var isYoutube = mountedFrame && /youtube(?:-nocookie)?\.com/i.test(String(mountedFrame.src || ''));
        if (isYoutube || String(videoState.videoType || '').toLowerCase() === 'youtube') {
          html5Player = null;
          setYoutubeInteractionShieldEnabled(true);
          initYoutubePlatformControls(mountedFrame);
        } else if (mountedVideo) {
          youtubePlayer = null;
          stopYoutubeTimeTicker();
          setYoutubeInteractionShieldEnabled(false);
          initHtml5PlatformControls(mountedVideo);
        } else {
          youtubePlayer = null;
          html5Player = null;
          stopYoutubeTimeTicker();
          setYoutubeInteractionShieldEnabled(false);
          setPlatformControlsVisible(false);
          setPlatformControlsEnabled(false);
        }
        startBackgroundTracking(parseInt(data.watched_seconds || 0, 10));
        updateNotice('✅ تم تجهيز الفيديو. شغّل الفيديو من زر التشغيل داخل المشغل.', false);
      });
    }).catch(function(){
      startRequestInFlight = false;
      renderPlaceholder('❌ حدث خطأ أثناء الاتصال بالسيرفر.');
      updateNotice('❌ حدث خطأ أثناء تجهيز المشغل.', true);
    });
  }

  function closeProtectedPage(reason) {
    if (protectedPageClosed) return;
    protectedPageClosed = true;
    stopProgressTimers();
    unlockMobileLandscapeOrientation();
    nativeStudentAppLandscapeModeActive = false;
    syncMobileLandscapePresentation();
    if (mobileSecureStatePollHandle) {
      window.clearInterval(mobileSecureStatePollHandle);
      mobileSecureStatePollHandle = 0;
    }
    if (fullscreenUnlockHandle) {
      window.clearTimeout(fullscreenUnlockHandle);
      fullscreenUnlockHandle = 0;
    }
    setCaptureShieldLocked(reason, {disableAction:true});
    updateNotice(reason, true);
    renderPlaceholder(reason);
    if (fullscreenBtn) fullscreenBtn.disabled = true;

    var exitFullscreenPromise = exitSecureFullscreen();
    if (exitFullscreenPromise && typeof exitFullscreenPromise.catch === 'function') {
      exitFullscreenPromise.catch(function(){});
    }

    window.location.replace('account_lecture.php?lecture_id=' + lectureId);
  }

  if (fullscreenBtn && playerStage) {
    var toggleFullscreen = function(){
      if (isLikelyMobilePlayback() && isNativeStudentAppPlayback() && nativeStudentAppLandscapeModeActive && !getFullscreenElement()) {
        if (mobileLandscapeLockHandle) {
          window.clearTimeout(mobileLandscapeLockHandle);
          mobileLandscapeLockHandle = 0;
        }
        mobileSecureStateWasSecure = null;
        syncMobileSecureViewportSnapshot();
        unlockMobileLandscapeOrientation();
        syncMobileLandscapePresentation();
        syncFullscreenToggleLabels();
        return;
      }
      if (getFullscreenElement()) {
        exitSecureFullscreen();
        return;
      }
      if (isLikelyMobilePlayback() && isNativeStudentAppPlayback()) {
        notifyNativeStudentAppLandscapeMode(true);
        if (fullscreenUnlockHandle) window.clearTimeout(fullscreenUnlockHandle);
        fullscreenUnlockHandle = window.setTimeout(function(){
          fullscreenUnlockHandle = 0;
          mobileSecureStateWasSecure = true;
          syncMobileSecureViewportSnapshot();
          syncMobileLandscapePresentation();
          syncFullscreenToggleLabels();
        }, fullscreenActivationDelayMs);
        return;
      }
      var requestFullscreenFn = requestSecureFullscreen(playerStage);
      if (typeof requestFullscreenFn === 'function') requestFullscreenFn.call(playerStage);
    };

    fullscreenBtn.addEventListener('click', toggleFullscreen);

    var handleFullscreenChange = function(){
      var fullscreenElement = getFullscreenElement();
      syncFullscreenToggleLabels();
      if (!fullscreenElement && isLikelyMobilePlayback() && playbackBootstrapped && !protectedPageClosed) {
        if (mobileLandscapeLockHandle) {
          window.clearTimeout(mobileLandscapeLockHandle);
          mobileLandscapeLockHandle = 0;
        }
        mobileSecureStateWasSecure = false;
        syncMobileSecureViewportSnapshot();
        syncMobileLandscapePresentation();
        unlockMobileLandscapeOrientation();
        setCaptureShieldLocked(mobileExitShieldMessage);
        updateNotice('🔒 تمت إعادة حماية الفيديو بعد الخروج من العرض الآمن على الموبايل. افتح المشغل المحمي للمتابعة.', true);
      } else if (fullscreenElement && isLikelyMobilePlayback() && !protectedPageClosed && !videoState.isBlocked) {
        mobileSecureStateWasSecure = true;
        syncMobileSecureViewportSnapshot();
        scheduleMobileLandscapeLock(mobileLandscapeLockRetryCount);
        updateNotice('✅ تم تفعيل العرض الآمن بملء الشاشة على هذا الجهاز. سيعمل الفيديو تلقائيًا بأفضل وضع أفقي متاح أثناء التشغيل، وإن تعذر ذلك فسيستمر العرض الآمن بالشكل المناسب للجهاز.', false);
      } else {
        syncMobileLandscapePresentation();
      }
      showImmersiveControls();
    };

    ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(function(evt){
      document.addEventListener(evt, handleFullscreenChange);
    });
  }

  if (playerStage) {
    ['mousemove', 'touchstart', 'touchmove', 'pointerdown'].forEach(function(evt){
      playerStage.addEventListener(evt, function(){
        if (!isFullscreenPresentationActive()) return;
        var now = Date.now();
        if (now - lastImmersiveWakeAt < immersiveControlsWakeThrottleMs) return;
        lastImmersiveWakeAt = now;
        showImmersiveControls();
      }, {passive:true});
    });
  }

  if (ctrlPlayPauseBtn) {
    ctrlPlayPauseBtn.addEventListener('click', function(){
      togglePlatformPlayback();
    });
  }

  if (ctrlSeekBackBtn) {
    ctrlSeekBackBtn.addEventListener('click', function(){
      seekPlatformPlayerBy(-seekDeltaSeconds);
    });
  }

  if (ctrlSeekForwardBtn) {
    ctrlSeekForwardBtn.addEventListener('click', function(){
      seekPlatformPlayerBy(seekDeltaSeconds);
    });
  }

  if (ctrlQualitySelect) {
    ctrlQualitySelect.addEventListener('change', function(){
      if (!youtubePlayer) return;
      var nextQuality = String(ctrlQualitySelect.value || 'auto');
      if (nextQuality === 'auto') {
        return;
      }
      youtubePlayer.setPlaybackQuality(nextQuality);
    });
  }

  if (ctrlSpeedSelect) {
    ctrlSpeedSelect.addEventListener('change', function(){
      var nextRate = parseFloat(ctrlSpeedSelect.value || '1');
      if (!isFinite(nextRate) || nextRate <= 0) nextRate = 1;
      if (youtubePlayer) {
        youtubePlayer.setPlaybackRate(nextRate);
        return;
      }
      if (html5Player) html5Player.playbackRate = nextRate;
    });
  }

  if (ctrlVolumeInput) {
    ctrlVolumeInput.addEventListener('input', function(){
      var nextVolume = parseInt(ctrlVolumeInput.value || '100', 10);
      if (!isFinite(nextVolume)) nextVolume = 100;
      nextVolume = Math.max(0, Math.min(100, nextVolume));
      ctrlVolumeInput.setAttribute('aria-valuetext', nextVolume + '%');
      if (youtubePlayer) {
        if (nextVolume === 0) {
          youtubePlayer.mute();
        } else {
          youtubePlayer.unMute();
          youtubePlayer.setVolume(nextVolume);
        }
        return;
      }
      if (!html5Player) return;
      html5Player.muted = nextVolume === 0;
      html5Player.volume = nextVolume / 100;
    });
  }

  if (ctrlTimelineInput) {
    ctrlTimelineInput.addEventListener('input', function(){
      if (!hasPlatformPlaybackController()) return;
      timelineDragging = true;
      var nextTime = parseInt(ctrlTimelineInput.value || '0', 10);
      if (!isFinite(nextTime)) nextTime = 0;
      var duration = 0;
      if (youtubePlayer) {
        try { duration = youtubePlayer.getDuration() || 0; } catch(e) {}
      } else if (html5Player) {
        duration = parseFloat(html5Player.duration || 0);
      }
      if (isFinite(duration) && duration > 0) nextTime = Math.min(nextTime, Math.floor(duration));
      nextTime = Math.max(0, nextTime);
      if (youtubePlayer) {
        youtubePlayer.seekTo(nextTime, true);
        refreshYoutubeTimeControl(true);
      } else if (html5Player) {
        html5Player.currentTime = nextTime;
        refreshHtml5TimeControl(true);
      }
    });

    var endTimelineInteraction = function(){
      timelineDragging = false;
      if (youtubePlayer) refreshYoutubeTimeControl(false);
      else if (html5Player) refreshHtml5TimeControl(false);
    };
    ctrlTimelineInput.addEventListener('change', endTimelineInteraction);
    ctrlTimelineInput.addEventListener('mouseup', endTimelineInteraction);
    ctrlTimelineInput.addEventListener('touchend', endTimelineInteraction);
    ctrlTimelineInput.addEventListener('keyup', function(e){
      var key = String(e.key || '').toLowerCase();
      if (key === 'arrowleft' || key === 'arrowright' || key === 'home' || key === 'end') {
        endTimelineInteraction();
      }
    });
  }

  document.addEventListener('keydown', function(e){
    if (!hasPlatformPlaybackController() || !platformControls || platformControls.hidden) return;
    var target = e.target;
    if (target && target.tagName) {
      var tagName = String(target.tagName).toUpperCase();
      if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT') return;
    }

    var key = String(e.key || '').toLowerCase();
    if (key === ' ' || key === 'k') {
      e.preventDefault();
      togglePlatformPlayback();
      return;
    }
    if (key === 'f') {
      e.preventDefault();
      if (fullscreenBtn) fullscreenBtn.click();
      return;
    }
    if (key === 'arrowleft' || key === 'j') {
      e.preventDefault();
      seekPlatformPlayerBy(-seekDeltaSeconds);
      return;
    }
    if (key === 'arrowright' || key === 'l') {
      e.preventDefault();
      seekPlatformPlayerBy(seekDeltaSeconds);
    }
  });

  if (captureShieldActionBtn) {
    captureShieldActionBtn.addEventListener('click', function(e){
      e.preventDefault();
      if (isLikelyMobilePlayback() && playerStage && !isStageFullscreenActive()) {
        if (isNativeStudentAppPlayback()) {
          if (fullscreenUnlockHandle) window.clearTimeout(fullscreenUnlockHandle);
          fullscreenUnlockHandle = window.setTimeout(function(){
            fullscreenUnlockHandle = 0;
            mobileSecureStateWasSecure = true;
            syncMobileSecureViewportSnapshot();
            syncMobileLandscapePresentation();
            unlockProtectedPlayback();
          }, fullscreenActivationDelayMs);
          return;
        }
        var requestFullscreen = requestSecureFullscreen(playerStage);
        if (typeof requestFullscreen !== 'function') {
          setCaptureShieldLocked(mobileFullscreenRejectionShieldMessage);
          updateNotice('🔒 هذا المتصفح لا يفعّل ملء الشاشة الآمن للموبايل، لذلك سيبقى الفيديو محجوبًا للحماية.', true);
          return;
        }
        try {
          var fullscreenResult = requestFullscreen.call(playerStage);
          var unlockWhenFullscreenReady = function(){
            if (fullscreenUnlockHandle) window.clearTimeout(fullscreenUnlockHandle);
            fullscreenUnlockHandle = window.setTimeout(function(){
              fullscreenUnlockHandle = 0;
              if (!isStageFullscreenActive()) {
                setCaptureShieldLocked(mobileSecureStateShieldMessage);
                updateNotice('🔒 لم يدخل المشغل وضع ملء الشاشة الآمن، لذلك بقي الفيديو محجوبًا.', true);
                return;
              }
              scheduleMobileLandscapeLock(mobileLandscapeLockRetryCount);
              unlockProtectedPlayback();
            }, fullscreenActivationDelayMs);
          };
          if (fullscreenResult && typeof fullscreenResult.then === 'function') {
            fullscreenResult.then(unlockWhenFullscreenReady).catch(function(){
              setCaptureShieldLocked(mobileFullscreenRejectionShieldMessage);
              updateNotice('🔒 تم رفض أو إلغاء ملء الشاشة، لذلك بقي الفيديو محجوبًا للحماية.', true);
            });
            return;
          }
          unlockWhenFullscreenReady();
          return;
        } catch(e) {
          setCaptureShieldLocked(mobileFullscreenRejectionShieldMessage);
          updateNotice('🔒 تعذر تشغيل ملء الشاشة الآمن على هذا الجهاز، لذلك بقي الفيديو محجوبًا.', true);
          return;
        }
      }
      unlockProtectedPlayback();
    });
  }

  if (!videoState.isBlocked) {
    if (isNativeStudentAppPlayback()) {
      hideCaptureShield(true);
      updateNotice('⏳ جاري تجهيز الفيديو تلقائيًا داخل تطبيق الطالب...', false);
      window.setTimeout(function(){
        if (isNativeStudentAppPlayback() && !protectedPageClosed && !playbackBootstrapped && !videoState.isBlocked) {
          unlockProtectedPlayback();
        }
      }, nativeStudentAppAutoUnlockDelayMs);
    } else {
      setCaptureShieldLocked(initialShieldMessage);
      updateNotice('🔒 الفيديو محجوب افتراضيًا للحماية. اضغط على "فتح المشغل المحمي" من داخل طبقة الحماية لبدء التجهيز.', false);
    }
  } else if (videoState.isAssessmentLocked) {
    setCaptureShieldLocked('🔒 الفيديو مقفل حتى يتم تسليم المحتوى المرتبط به.');
  }
  syncFullscreenToggleLabels();

  document.addEventListener('contextmenu', function(e){ e.preventDefault(); });
  document.addEventListener('mousedown', function(e){ if (e.button === 2) e.preventDefault(); }, true);
  document.addEventListener('keydown', function(e){
    var key = String(e.key || '').toLowerCase();
    if (isCaptureShortcutEvent(e)) {
      triggerCaptureShieldAttempt('⚫️ تم تعتيم المشغل لحماية المحتوى أثناء محاولة تصوير الشاشة.');
    }
    var blocked =
      key === 'f12' ||
      (e.ctrlKey && e.shiftKey && (key === 'i' || key === 'j' || key === 'c')) ||
      (e.ctrlKey && key === 'u');
    if (blocked) {
      e.preventDefault();
      closeProtectedPage('⛔ تم الرجوع إلى صفحة تفاصيل المحاضرة لحماية المحتوى عند محاولة فتح أدوات المطور.');
    }
  }, true);
  document.addEventListener('keyup', function(e){
    if (!isCaptureShortcutEvent(e)) return;
    triggerCaptureShieldAttempt('⚫️ تم تعتيم المشغل لحماية المحتوى أثناء محاولة تصوير الشاشة.');
  }, true);

  document.addEventListener('visibilitychange', function(){
    if (document.visibilityState === 'hidden') {
      setCaptureShieldLocked(hiddenShieldMessage);
      sendProgress('heartbeat');
      return;
    }
    if (captureShieldLocked && !protectedPageClosed && !videoState.isBlocked) {
      updateNotice('🔒 عادت الصفحة إلى الواجهة، لكن الفيديو سيبقى محجوبًا حتى تضغط على "فتح المشغل المحمي" مرة أخرى.', false);
    }
  });
  window.addEventListener('blur', function(){
    if (isLikelyMobilePlayback()) return;
    window.setTimeout(function(){
      setCaptureShieldLocked(document.visibilityState === 'hidden' ? recordShieldMessage : blurShieldMessage);
      sendProgress('heartbeat');
    }, blurCheckDelayMs);
  });
  window.addEventListener('pagehide', function(){
    if (protectedPageClosed || videoState.isBlocked) return;
    unlockMobileLandscapeOrientation();
    setCaptureShieldLocked('⚫️ تمت إعادة حجب المشغل مباشرة عند مغادرة الصفحة أو إخفائها لحماية الفيديو.');
    sendProgress('heartbeat');
  });
  window.addEventListener('pageshow', function(){
    if (protectedPageClosed || videoState.isBlocked || !playbackBootstrapped) return;
    if (!hasSecurePlaybackFocus()) {
      enforceSecurePlaybackState('', isLikelyMobilePlayback()
        ? '🔒 عاد المشغل لكنه سيبقى محجوبًا حتى يعود الوضع الآمن المناسب على الموبايل.'
        : '🔒 عاد المشغل لكنه سيبقى محجوبًا حتى تعود الصفحة إلى الواجهة.');
    }
  });
  window.addEventListener('focus', function(){
    if (isLikelyMobilePlayback()) return;
    if (protectedPageClosed || videoState.isBlocked || !playbackBootstrapped) return;
    if (!hasSecurePlaybackFocus()) {
      enforceSecurePlaybackState('', isLikelyMobilePlayback()
        ? '🔒 على الموبايل يجب إبقاء الفيديو داخل وضع آمن قبل متابعة التشغيل.'
        : '🔒 يجب إبقاء الصفحة في الواجهة قبل متابعة الفيديو.');
    }
  });
  function handleMobileSecureStateViewportChange() {
    if (protectedPageClosed || videoState.isBlocked || !playbackBootstrapped || !isLikelyMobilePlayback()) return;
    // Lock immediately on overlay detection so the video never stays visible during the debounce window.
    if (hasMobileViewportOverlay()) setCaptureShieldLocked(mobileSecureStateShieldMessage);
    window.clearTimeout(mobileSecureStateResizeHandle);
    mobileSecureStateResizeHandle = window.setTimeout(function(){
      mobileSecureStateResizeHandle = 0;
      syncMobileLandscapePresentation();
      if (hasMobileSecurePlaybackState()) syncMobileSecureViewportSnapshot();
      if (isStageFullscreenActive()) scheduleMobileLandscapeLock(mobileLandscapeLockRetryCount);
      evaluateMobileSecurePlaybackState();
    }, mobileSecureStateResizeDebounceMs);
  }

  ['resize', 'orientationchange'].forEach(function(evt){
    window.addEventListener(evt, handleMobileSecureStateViewportChange);
  });
  if (window.visualViewport && typeof window.visualViewport.addEventListener === 'function') {
    ['resize', 'scroll'].forEach(function(evt){
      window.visualViewport.addEventListener(evt, handleMobileSecureStateViewportChange);
    });
  }
  ensureMobileSecureStatePolling();

  window.addEventListener('beforeunload', function(){
    unlockMobileLandscapeOrientation();
    if (!activeWatchToken || countedToken === activeWatchToken) return;
    var body = new URLSearchParams();
    body.set('action', 'complete');
    body.set('video_id', videoId);
    body.set('watch_token', activeWatchToken);
    if (navigator.sendBeacon) {
      navigator.sendBeacon('api/lecture_video_api.php', new Blob([body.toString()], {type: 'application/x-www-form-urlencoded; charset=UTF-8'}));
    }
  });

  window.setInterval(function(){
    if (protectedPageClosed) return;
    if (isNativeStudentAppPlayback()) {
      devtoolsDetectionStrikes = 0;
      return;
    }
    if (document.hidden) {
      devtoolsDetectionStrikes = 0;
      return;
    }

    var widthGap = Math.abs(window.outerWidth - window.innerWidth);
    var heightGap = Math.abs(window.outerHeight - window.innerHeight);
    var devtoolsOpen =
      widthGap > devtoolsWidthGapThreshold ||
      heightGap > devtoolsHeightGapThreshold;

    if (devtoolsOpen) {
      devtoolsDetectionStrikes++;
    } else {
      devtoolsDetectionStrikes = 0;
    }

    if (devtoolsDetectionStrikes >= devtoolsStrikeThreshold) {
      closeProtectedPage('⛔ تم اكتشاف فتح أدوات المطور، وتم الرجوع إلى صفحة تفاصيل المحاضرة لحماية الفيديو.');
    }
  }, devtoolsCheckIntervalMs);
})();
</script>
</body>
</html>
