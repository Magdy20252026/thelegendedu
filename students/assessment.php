<?php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require __DIR__ . '/inc/student_auth.php';
require_once __DIR__ . '/inc/assessments.php';

no_cache_headers();
student_require_login();

if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function fmt_dt(?string $dt): string {
  $dt = trim((string)$dt);
  return $dt !== '' ? $dt : '—';
}

function fmt_timer(int $seconds): string {
  $seconds = max(0, $seconds);
  $minutes = (int)floor($seconds / 60);
  $secs = $seconds % 60;
  return sprintf('%02d:%02d', $minutes, $secs);
}

$settingsRow = get_platform_settings_row($pdo);
$platformName = trim((string)($settingsRow['platform_name'] ?? 'منصتي التعليمية'));
if ($platformName === '') $platformName = 'منصتي التعليمية';
$logoDb = trim((string)($settingsRow['platform_logo'] ?? ''));
$logoUrl = $logoDb !== '' ? student_public_asset_url($logoDb) : null;

$studentId = (int)($_SESSION['student_id'] ?? 0);
$stmt = $pdo->prepare("
  SELECT s.*, g.name AS grade_name
  FROM students s
  INNER JOIN grades g ON g.id = s.grade_id
  WHERE s.id = ?
  LIMIT 1
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
  header('Location: logout.php');
  exit;
}

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$assessmentId = (int)($_GET['id'] ?? 0);
$config = student_assessment_type_config($type);

$error = null;
$success = null;
$payload = null;
$attempt = null;
$assessment = null;

if (!$config || $assessmentId <= 0) {
  $error = 'رابط ' . ($config['label'] ?? 'المحتوى') . ' غير صحيح.';
} else {
  try {
    student_assessment_ensure_attempt_tables($pdo);
    student_assessment_expire_stale_attempts($pdo, $type, $studentId);
  } catch (Throwable $e) {
    $error = 'تعذر تجهيز صفحة ' . $config['label'] . '.';
  }
}

if (!$error && $config) {
  $assessment = student_assessment_fetch_item($pdo, (int)$student['grade_id'], $type, $assessmentId);
  if (!$assessment) {
    $error = 'هذا ' . $config['label'] . ' غير متاح للصف الدراسي الخاص بك.';
  }
}

if (!isset($_SESSION['assessment_csrf'])) {
  $_SESSION['assessment_csrf'] = bin2hex(random_bytes(24));
}
$csrfToken = (string)$_SESSION['assessment_csrf'];

if (!$error && $config && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'submit_attempt') {
  $postedToken = (string)($_POST['csrf_token'] ?? '');
  $attemptId = (int)($_POST['attempt_id'] ?? 0);
  if (!hash_equals($csrfToken, $postedToken)) {
    $error = 'انتهت صلاحية الجلسة. من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
  } else {
    $submit = student_assessment_submit_attempt($pdo, $type, $attemptId, $studentId, (array)($_POST['answers'] ?? []));
    if (!$submit['ok']) {
      $error = (string)($submit['error'] ?? 'تعذر تسليم المحاولة الحالية.');
    } else {
      $qs = http_build_query([
        'type' => $type,
        'id' => $assessmentId,
        'submitted' => 1,
      ]);
      header('Location: assessment.php?' . $qs);
      exit;
    }
  }
}

if (!$error && $config) {
  $attempt = student_assessment_fetch_latest_attempt($pdo, $type, $assessmentId, $studentId);
  if (!$attempt) {
    $created = student_assessment_create_attempt($pdo, $studentId, (int)$student['grade_id'], $type, $assessmentId);
    if (!$created['ok']) {
      $error = (string)($created['error'] ?? 'تعذر بدء المحاولة الحالية.');
    } else {
      $attempt = student_assessment_fetch_latest_attempt($pdo, $type, $assessmentId, $studentId);
    }
  }
}

if (!$error && $config && $attempt) {
  $payload = student_assessment_fetch_attempt_payload($pdo, $type, (int)$attempt['id'], $studentId);
  if (!$payload) {
    $error = 'تعذر تحميل بيانات ' . $config['label'] . '.';
  }
}

if (!$error && isset($_GET['submitted']) && $payload) {
  $success = 'تم تسليم ' . $config['label'] . ' وإظهار النتيجة والتصحيح لكل سؤال.';
}

$isFinished = (bool)($payload['is_finished'] ?? false);
$questions = (array)($payload['questions'] ?? []);
$attemptRow = (array)($payload['attempt'] ?? []);
$remainingSeconds = (int)($payload['remaining_seconds'] ?? 0);
$finishedTimerLabel = '✅ تم التسليم';
if ((string)($attemptRow['status'] ?? '') === 'expired') {
  $finishedTimerLabel = '⏱️ انتهى الوقت';
} elseif ((string)($attemptRow['status'] ?? '') === 'in_progress') {
  $finishedTimerLabel = '⏱️ المتبقي: ' . fmt_timer($remainingSeconds);
}
$backHref = 'account.php?page=' . rawurlencode((string)($config['page'] ?? 'home'));
$pageTitle = $config ? ($config['label'] . ' - ' . $platformName) : ('المحتوى - ' . $platformName);
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
  <link rel="stylesheet" href="assets/css/account.css?v=<?php echo h((string)@filemtime(__DIR__ . '/assets/css/account.css')); ?>">
  <link rel="stylesheet" href="assets/css/exams.css?v=<?php echo h((string)@filemtime(__DIR__ . '/assets/css/exams.css')); ?>">
  <style>
    body{padding:18px 0}
    .ass-wrap{max-width:1120px;margin:0 auto;padding:0 12px}
    .ass-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
    .ass-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:1000}
    .ass-brand img{width:42px;height:42px;border-radius:14px;object-fit:contain;background:#fff}
    .ass-hero{background:var(--card-bg);border:1px solid var(--border);border-radius:20px;padding:18px;box-shadow:0 12px 24px rgba(0,0,0,.06);margin-bottom:16px}
    .ass-hero__head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
    .ass-hero__title h1{margin:0;font-size:1.6rem}
    .ass-hero__meta{margin-top:12px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .ass-meta{padding:12px 14px;border:1px solid var(--border);border-radius:16px;background:rgba(255,255,255,.02);font-weight:900}
    .ass-meta b{display:block;color:var(--muted);margin-bottom:5px}
    .ass-summary{display:flex;gap:10px;flex-wrap:wrap}
    .ass-summary .acc-badge{font-size:13px}
    .ass-alert{margin-bottom:14px;padding:14px 16px;border-radius:16px;font-weight:1000;border:1px solid var(--border)}
    .ass-alert.ok{background:rgba(0,200,83,.10);border-color:rgba(0,200,83,.22)}
    .ass-alert.err{background:rgba(244,67,54,.12);border-color:rgba(244,67,54,.22)}
    .ass-result-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0}
    .ass-result-card{background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:0 10px 22px rgba(0,0,0,.05)}
    .ass-result-card b{display:block;color:var(--muted);margin-bottom:8px}
    .ass-result-card strong{font-size:1.5rem}
    .ass-foot-note{font-weight:900;color:var(--muted);line-height:1.8}
    .as-modal__card{width:100%;margin:0}
    .as-modal__body{max-height:none}
    .as-choice{transition:.18s ease}
    .as-choice.is-disabled{cursor:default}
    .as-choice__hint{margin-top:8px;color:var(--muted);font-size:12px;font-weight:1000}
    .as-q__foot strong{display:block;margin-bottom:4px}
    @media (max-width: 900px){
      .ass-hero__meta,.ass-result-summary{grid-template-columns:1fr}
      .ass-top{align-items:flex-start}
    }
  </style>
  <title><?php echo h($pageTitle); ?></title>
</head>
<body>
  <div class="ass-wrap">
    <div class="ass-top">
      <a class="ass-brand" href="account.php?page=home" aria-label="<?php echo h($platformName); ?>">
        <?php if ($logoUrl): ?>
          <img src="<?php echo h($logoUrl); ?>" alt="Logo">
        <?php else: ?>
          <span class="acc-brand__logoFallback" aria-hidden="true"></span>
        <?php endif; ?>
        <span><?php echo h($platformName); ?></span>
      </a>
      <div class="acc-actions">
        <a class="acc-btn acc-btn--ghost" href="<?php echo h($backHref); ?>">⬅ الرجوع إلى <?php echo h((string)($config['plural_label'] ?? 'الحساب')); ?></a>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="ass-alert err" role="alert"><?php echo h($error); ?></div>
    <?php else: ?>
      <section class="ass-hero">
        <div class="ass-hero__head">
          <div class="ass-hero__title">
            <h1><?php echo h((string)($config['icon'] ?? '📌')); ?> <?php echo h((string)($assessment['name'] ?? '')); ?></h1>
            <p class="ass-foot-note">
              <?php echo h((string)($config['label'] ?? 'المحتوى')); ?> مخصص للصف:
              <strong><?php echo h((string)($student['grade_name'] ?? '')); ?></strong>
            </p>
          </div>
          <div class="ass-summary">
            <span class="acc-badge acc-badge--att"><?php echo h((string)($config['icon'] ?? '📌')); ?> <?php echo h((string)($config['label'] ?? '')); ?></span>
            <span class="acc-badge acc-badge--buy">
              <?php echo ((string)($attemptRow['status'] ?? '') === 'submitted') ? '✅' : (((string)($attemptRow['status'] ?? '') === 'expired') ? '⏱️' : '🟢'); ?>
              <?php
                $statusLabel = 'متاح الآن';
                if ((string)($attemptRow['status'] ?? '') === 'submitted') $statusLabel = 'تم الحل';
                elseif ((string)($attemptRow['status'] ?? '') === 'expired') $statusLabel = 'انتهى الوقت';
                elseif ((string)($attemptRow['status'] ?? '') === 'in_progress') $statusLabel = 'جاري الحل';
                echo h($statusLabel);
              ?>
            </span>
            <span class="timer-pill" id="assessmentTimer" aria-live="polite" dir="ltr">
              <?php if ($isFinished): ?>
                <?php echo h($finishedTimerLabel); ?>
              <?php else: ?>
                <?php echo '⏱️ المتبقي: ' . h(fmt_timer($remainingSeconds)); ?>
              <?php endif; ?>
            </span>
          </div>
        </div>

        <div class="ass-hero__meta">
          <div class="ass-meta"><b>🏫 الصف الدراسي</b><?php echo h((string)($assessment['grade_name'] ?? '')); ?></div>
          <div class="ass-meta"><b>🧠 بنك الأسئلة</b><?php echo h((string)($assessment['bank_name'] ?? '')); ?></div>
          <div class="ass-meta"><b>❓ عدد الأسئلة للطالب</b><?php echo (int)count($questions); ?> سؤال</div>
          <div class="ass-meta"><b>⏰ الوقت المحدد</b><?php echo (int)($assessment['duration_minutes'] ?? 0); ?> دقيقة</div>
        </div>
      </section>

      <?php if ($success): ?>
        <div class="ass-alert ok" role="alert"><?php echo h($success); ?></div>
      <?php endif; ?>

      <?php if ($isFinished): ?>
        <section class="ass-result-summary">
          <div class="ass-result-card">
            <b>النتيجة النهائية</b>
            <strong><?php echo h(student_assessment_format_score_pair((float)($attemptRow['score'] ?? 0), (float)($attemptRow['max_score'] ?? 0))); ?></strong>
          </div>
          <div class="ass-result-card">
            <b>حالة التسليم</b>
            <strong><?php echo h(((string)($attemptRow['status'] ?? '') === 'expired') ? 'انتهى الوقت' : 'تم التسليم'); ?></strong>
          </div>
          <div class="ass-result-card">
            <b>تاريخ التسليم</b>
            <strong style="font-size:1rem;"><?php echo h(fmt_dt((string)($attemptRow['submitted_at'] ?? ''))); ?></strong>
          </div>
        </section>
      <?php endif; ?>

      <section class="as-modal__card">
        <div class="as-modal__body">
          <div class="as-student">
            <div class="as-student__head">
              <div class="as-student__title"><?php echo $isFinished ? '📋 مراجعة الإجابات' : '📌 أجب عن الأسئلة'; ?></div>
              <div class="as-student__sub">
                <?php if ($isFinished): ?>
                  يمكنك الرجوع إلى هذه الصفحة في أي وقت لمراجعة النتيجة والتصحيح الكامل.
                <?php else: ?>
                  الأسئلة مرقمة وبإمكانك التنقل بينها حتى تسليم <?php echo h((string)($config['label'] ?? 'المحتوى')); ?>.
                <?php endif; ?>
              </div>
            </div>

            <div class="as-nav" id="questionNav" style="<?php echo empty($questions) ? 'display:none;' : ''; ?>">
              <div class="as-nav__nums" id="questionNums"></div>
              <div class="as-nav__actions">
                <button class="acc-btn acc-btn--ghost" type="button" id="questionPrev">⬅ السابق</button>
                <button class="acc-btn acc-btn--ghost" type="button" id="questionNext">التالي ➡</button>
              </div>
            </div>

            <?php if ($isFinished): ?>
              <div class="as-result ok" style="display:block;">
                النتيجة الحالية: <?php echo h(student_assessment_format_score_pair((float)($attemptRow['score'] ?? 0), (float)($attemptRow['max_score'] ?? 0))); ?>
              </div>
            <?php endif; ?>

            <form method="post" id="assessmentForm">
              <input type="hidden" name="action" value="submit_attempt">
              <input type="hidden" name="attempt_id" value="<?php echo (int)($attemptRow['id'] ?? 0); ?>">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

              <div class="as-questions" id="questionList">
                <?php foreach ($questions as $idx => $item): ?>
                  <?php
                    $question = (array)($item['q'] ?? []);
                    $questionId = (int)($question['id'] ?? 0);
                    $questionKind = (string)($question['question_kind'] ?? 'text');
                    $choicesKind = (string)($question['choices_kind'] ?? 'text');
                    $correctionType = (string)($question['correction_type'] ?? 'single');
                    $inputType = ($correctionType === 'double') ? 'checkbox' : 'radio';
                    $questionImg = student_assessment_media_url((string)($question['question_image_path'] ?? ''));
                    $selectedIds = array_map('intval', (array)($item['selected_choice_ids'] ?? []));
                    $correctIds = array_map('intval', (array)($item['correct_choice_ids'] ?? []));
                    $selectedText = empty($item['selected_choice_indices']) ? 'لم يتم اختيار إجابة.' : ('إجابتك: ' . implode(' ، ', array_map('intval', (array)$item['selected_choice_indices'])));
                    $correctText = empty($item['correct_choice_indices']) ? 'لا توجد إجابة صحيحة محددة.' : ('الإجابات الصحيحة: ' . implode(' ، ', array_map('intval', (array)$item['correct_choice_indices'])));
                  ?>
                  <article class="as-q<?php echo ($idx > 0) ? ' is-hidden' : ''; ?>" data-question-card data-index="<?php echo (int)$idx; ?>">
                    <div class="as-q__head">
                      <div class="as-q__no">❓ سؤال <?php echo (int)($idx + 1); ?> / <?php echo count($questions); ?></div>
                      <div class="as-q__meta">
                        <span class="pill green">🎯 <?php echo h(student_assessment_format_number((float)($question['degree'] ?? 0))); ?> درجة</span>
                        <span class="pill purple"><?php echo ($correctionType === 'double') ? '☑️ متعدد' : '🔘 اختيار واحد'; ?></span>
                      </div>
                    </div>
                    <div class="as-q__body">
                      <?php if (in_array($questionKind, ['text', 'text_image'], true)): ?>
                        <div class="as-q__text"><?php echo nl2br(h((string)($question['question_text'] ?? ''))); ?></div>
                      <?php endif; ?>
                      <?php if ($questionImg && in_array($questionKind, ['image', 'text_image'], true)): ?>
                        <div class="as-q__img"><img src="<?php echo h($questionImg); ?>" alt="question image"></div>
                      <?php endif; ?>

                      <div class="as-choices">
                        <?php foreach ((array)($item['choices'] ?? []) as $choice): ?>
                          <?php
                            $choiceId = (int)($choice['id'] ?? 0);
                            $choiceIndex = (int)($choice['choice_index'] ?? 0);
                            $choiceImg = student_assessment_media_url((string)($choice['choice_image_path'] ?? ''));
                            $isSelected = in_array($choiceId, $selectedIds, true);
                            $isCorrectChoice = in_array($choiceId, $correctIds, true);
                            $choiceClass = 'as-choice' . ($isFinished ? ' is-disabled' : '');
                            if ($isFinished && $isSelected && $isCorrectChoice) $choiceClass .= ' is-correct';
                            if ($isFinished && $isSelected && !$isCorrectChoice) $choiceClass .= ' is-wrong';
                            if ($isFinished && !$isSelected && $isCorrectChoice) $choiceClass .= ' is-reveal-correct';
                          ?>
                          <label class="<?php echo h($choiceClass); ?>">
                            <input
                              type="<?php echo h($inputType); ?>"
                              name="answers[<?php echo (int)$questionId; ?>][]"
                              value="<?php echo (int)$choiceId; ?>"
                              <?php echo $isSelected ? 'checked' : ''; ?>
                              <?php echo $isFinished ? 'disabled' : ''; ?>
                            >
                            <div class="as-choice__body">
                              <div class="as-choice__idx">#<?php echo (int)$choiceIndex; ?></div>
                              <div style="flex:1;min-width:0;">
                                <?php if (in_array($choicesKind, ['text', 'text_image'], true)): ?>
                                  <div class="as-choice__text"><?php echo nl2br(h((string)($choice['choice_text'] ?? ''))); ?></div>
                                <?php endif; ?>
                                <?php if ($choiceImg && in_array($choicesKind, ['image', 'text_image'], true)): ?>
                                  <div class="as-choice__img" style="margin-top:10px;"><img src="<?php echo h($choiceImg); ?>" alt="choice image"></div>
                                <?php endif; ?>
                                <?php if ($isFinished): ?>
                                  <div class="as-choice__hint">
                                    <?php
                                      if ($isSelected && $isCorrectChoice) echo '✅ إجابة الطالب الصحيحة';
                                      elseif ($isSelected) echo '❌ إجابة الطالب الخاطئة';
                                      elseif ($isCorrectChoice) echo '🟢 هذه من الإجابات الصحيحة';
                                    ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <?php if ($isFinished): ?>
                      <div class="as-q__foot">
                        <strong><?php echo !empty($item['is_correct']) ? '✅ إجابة صحيحة' : '❌ إجابة خاطئة'; ?></strong>
                        <div><?php echo h($selectedText); ?></div>
                        <div><?php echo h($correctText); ?></div>
                        <div>الدرجة المحصلة في هذا السؤال: <?php echo h(student_assessment_format_number((float)($item['question_score'] ?? 0))); ?> / <?php echo h(student_assessment_format_number((float)($question['degree'] ?? 0))); ?></div>
                      </div>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>

              <?php if (!$isFinished): ?>
                <div class="as-actions" style="margin-top:14px;">
                  <a class="acc-btn acc-btn--ghost" href="<?php echo h($backHref); ?>">رجوع بدون تسليم</a>
                  <button class="acc-btn" type="submit" id="submitAttemptBtn">✅ إنهاء وتسليم</button>
                </div>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <script src="assets/js/theme.js"></script>
  <script>
  (function(){
    const cards = Array.from(document.querySelectorAll('[data-question-card]'));
    const navNums = document.getElementById('questionNums');
    const prevBtn = document.getElementById('questionPrev');
    const nextBtn = document.getElementById('questionNext');
    const timerEl = document.getElementById('assessmentTimer');
    const form = document.getElementById('assessmentForm');
    const submitBtn = document.getElementById('submitAttemptBtn');
    const finished = <?php echo $isFinished ? 'true' : 'false'; ?>;
    let current = 0;

    function showQuestion(index){
      if (!cards.length) return;
      current = Math.max(0, Math.min(index, cards.length - 1));
      cards.forEach((card, idx) => {
        card.classList.toggle('is-hidden', idx !== current);
      });
      Array.from(document.querySelectorAll('.qnum')).forEach((btn, idx) => {
        btn.classList.toggle('active', idx === current);
      });
      if (prevBtn) prevBtn.disabled = current <= 0;
      if (nextBtn) nextBtn.disabled = current >= cards.length - 1;
    }

    if (navNums && cards.length) {
      navNums.innerHTML = cards.map((_, idx) => `<button class="qnum${idx === 0 ? ' active' : ''}" type="button">${idx + 1}</button>`).join('');
      Array.from(navNums.querySelectorAll('.qnum')).forEach((btn, idx) => {
        btn.addEventListener('click', () => showQuestion(idx));
      });
      prevBtn && prevBtn.addEventListener('click', () => showQuestion(current - 1));
      nextBtn && nextBtn.addEventListener('click', () => showQuestion(current + 1));
      showQuestion(0);
    }

    if (finished || !timerEl) return;

    const initialRemainingSeconds = Math.max(0, <?php echo (int)$remainingSeconds; ?>);
    const deadlineAt = Date.now() + (initialRemainingSeconds * 1000);
    let timeoutTriggered = false;

    function formatTime(totalSeconds) {
      const safeSeconds = Math.max(0, totalSeconds);
      const minutes = String(Math.floor(safeSeconds / 60)).padStart(2, '0');
      const seconds = String(safeSeconds % 60).padStart(2, '0');
      return minutes + ':' + seconds;
    }

    function getRemainingSeconds() {
      return Math.max(0, Math.ceil((deadlineAt - Date.now()) / 1000));
    }

    function renderTimer(left) {
      timerEl.textContent = '⏱️ المتبقي: ' + formatTime(left);
    }

    function tick() {
      const left = getRemainingSeconds();
      renderTimer(left);

      if (left <= 0) {
        clearInterval(timer);
        if (!timeoutTriggered && form && submitBtn) {
          timeoutTriggered = true;
          submitBtn.click();
        }
      }
    }

    renderTimer(initialRemainingSeconds);
    const timer = setInterval(tick, 1000);
  })();
  </script>
</body>
</html>
