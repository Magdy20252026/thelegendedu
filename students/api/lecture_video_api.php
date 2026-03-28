<?php
require __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/platform_settings.php';
require __DIR__ . '/../inc/student_auth.php';
require __DIR__ . '/../inc/access_control.php';

no_cache_headers();
student_require_login();

header('Content-Type: application/json; charset=utf-8');

$studentId = (int)($_SESSION['student_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$videoId = (int)($_POST['video_id'] ?? 0);

function lecture_video_api_response(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

const LECTURE_VIDEO_HEARTBEAT_INTERVAL_SECONDS = 10;
// Allows one heartbeat window plus a 10-second grace period
// to prevent large idle gaps from inflating watch time.
const LECTURE_VIDEO_MAX_WATCH_TIME_INCREMENT_SECONDS = LECTURE_VIDEO_HEARTBEAT_INTERVAL_SECONDS + 10;

if ($studentId <= 0 || $videoId <= 0 || !in_array($action, ['start', 'heartbeat', 'complete'], true)) {
  lecture_video_api_response(['ok' => false, 'message' => 'طلب غير صالح.']);
}

$video = student_get_video_row($pdo, $videoId);
if (!$video) {
  lecture_video_api_response(['ok' => false, 'message' => 'الفيديو غير موجود.']);
}

$lectureId = (int)($video['lecture_id'] ?? 0);
if (!student_has_lecture_access($pdo, $studentId, $lectureId)) {
  lecture_video_api_response(['ok' => false, 'message' => 'لا تملك صلاحية مشاهدة هذا الفيديو.']);
}

$stats = student_get_video_watch_stats($pdo, $studentId, $videoId, $video);
$videoRequirement = student_get_video_requirement_status($pdo, $studentId, $video);
$halfSeconds = student_video_half_watch_seconds((int)($video['duration_minutes'] ?? 0));

if (!isset($_SESSION['lecture_video_watch'])) {
  $_SESSION['lecture_video_watch'] = [];
}

if ($action === 'start') {
  if (!empty($videoRequirement['required']) && empty($videoRequirement['satisfied'])) {
    lecture_video_api_response([
      'ok' => false,
      'message' => 'يجب حل وتسليم ' . (string)($videoRequirement['assessment_name'] ?? 'المحتوى المرتبط') . ' أولًا قبل تشغيل الفيديو.',
      'stats' => $stats,
      'requirement' => $videoRequirement,
    ]);
  }

  if ($stats['blocked']) {
    lecture_video_api_response([
      'ok' => false,
      'message' => 'انتهت عدد المشاهدات المسموحة لهذا الفيديو.',
      'stats' => $stats,
    ]);
  }

  foreach ($_SESSION['lecture_video_watch'] as $token => $watch) {
    $startedAt = (int)($watch['started_at'] ?? 0);
    if ($startedAt > 0 && ($startedAt + 43200) < time()) {
      unset($_SESSION['lecture_video_watch'][$token]);
    }
  }

  $existingToken = '';
  $existingWatch = [];
  foreach ($_SESSION['lecture_video_watch'] as $token => $watch) {
    if (
      (int)($watch['student_id'] ?? 0) === $studentId &&
      (int)($watch['video_id'] ?? 0) === $videoId &&
      empty($watch['counted'])
    ) {
      $existingToken = (string)$token;
      $existingWatch = is_array($watch) ? $watch : [];
      break;
    }
  }

  // Restore progress from an existing uncounted session when the same student opens
  // the same video again, preventing restart from zero during the active session.
  $restoredWatchedSeconds = max(0, (int)($existingWatch['watched_seconds'] ?? 0));
  $startedAt = (int)($existingWatch['started_at'] ?? 0);
  if ($startedAt <= 0) $startedAt = time();
  $token = $existingToken !== '' ? $existingToken : bin2hex(random_bytes(18));
  $_SESSION['lecture_video_watch'][$token] = [
    'student_id' => $studentId,
    'video_id' => $videoId,
    'lecture_id' => $lectureId,
    'started_at' => $startedAt,
    'last_ping_at' => time(),
    'watched_seconds' => $restoredWatchedSeconds,
    'half_seconds' => $halfSeconds,
    'counted' => false,
  ];

  $origin = '';
  if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin = $scheme . '://' . $_SERVER['HTTP_HOST'];
  }

  $playerHtml = student_build_video_player_html($video, $origin);
  if ($playerHtml === '') {
    lecture_video_api_response([
      'ok' => false,
      'message' => 'تعذر تجهيز مشغل الفيديو داخل المنصة.',
      'stats' => $stats,
    ]);
  }

  lecture_video_api_response([
    'ok' => true,
    'message' => 'تم تجهيز الفيديو داخل المنصة.',
    'watch_token' => $token,
    'half_seconds' => $halfSeconds,
    'watched_seconds' => $restoredWatchedSeconds,
    'player_html' => $playerHtml,
    'stats' => $stats,
    'video' => [
      'id' => (int)$video['id'],
      'title' => (string)($video['title'] ?? ''),
      'duration_minutes' => (int)($video['duration_minutes'] ?? 0),
      'video_type' => (string)($video['video_type'] ?? ''),
    ],
  ]);
}

$token = trim((string)($_POST['watch_token'] ?? ''));
if ($token === '' || empty($_SESSION['lecture_video_watch'][$token])) {
  lecture_video_api_response([
    'ok' => false,
    'message' => 'جلسة المشاهدة غير صالحة.',
    'stats' => $stats,
  ]);
}

$watch = $_SESSION['lecture_video_watch'][$token];
if (
  (int)($watch['student_id'] ?? 0) !== $studentId ||
  (int)($watch['video_id'] ?? 0) !== $videoId
) {
  lecture_video_api_response([
    'ok' => false,
    'message' => 'جلسة المشاهدة لا تخص هذا الفيديو.',
    'stats' => $stats,
  ]);
}

if (!empty($watch['counted'])) {
  lecture_video_api_response([
    'ok' => true,
    'message' => 'تم احتساب هذه المشاهدة بالفعل.',
    'counted' => true,
    'watched_seconds' => max(0, (int)($watch['watched_seconds'] ?? $halfSeconds)),
    'half_seconds' => max(5, (int)($watch['half_seconds'] ?? $halfSeconds)),
    'stats' => $stats,
  ]);
}

$now = time();
$startedAt = (int)($watch['started_at'] ?? $now);
$lastPingAt = (int)($watch['last_ping_at'] ?? $startedAt);
// Session recreation after a refresh or timeout recovery can leave
// a stale ping timestamp; normalize it so watch time never starts
// before the actual session start.
if ($lastPingAt < $startedAt) $lastPingAt = $startedAt;

$watchedSeconds = max(0, (int)($watch['watched_seconds'] ?? 0));
$delta = max(0, $now - $lastPingAt);
if ($delta > LECTURE_VIDEO_MAX_WATCH_TIME_INCREMENT_SECONDS) $delta = LECTURE_VIDEO_MAX_WATCH_TIME_INCREMENT_SECONDS;
if ($delta > 0) {
  $watchedSeconds += $delta;
}

$requiredSeconds = max(5, (int)($watch['half_seconds'] ?? $halfSeconds));
$_SESSION['lecture_video_watch'][$token]['last_ping_at'] = $now;
$_SESSION['lecture_video_watch'][$token]['watched_seconds'] = $watchedSeconds;

if ($watchedSeconds < $requiredSeconds) {
  $payload = [
    'ok' => ($action === 'heartbeat'),
    'message' => 'لم يصل زمن المشاهدة إلى الحد المطلوب بعد.',
    'counted' => false,
    'watched_seconds' => $watchedSeconds,
    'half_seconds' => $requiredSeconds,
    'stats' => $stats,
  ];
  lecture_video_api_response($payload);
}

$result = student_increment_video_watch($pdo, $studentId, $videoId, (int)$stats['allowed']);
if (!$result['ok']) {
  lecture_video_api_response([
    'ok' => false,
    'message' => 'انتهت عدد المشاهدات المسموحة لهذا الفيديو.',
    'stats' => $result,
  ]);
}

$_SESSION['lecture_video_watch'][$token]['counted'] = true;
$_SESSION['lecture_video_watch'][$token]['watched_seconds'] = max($watchedSeconds, $requiredSeconds);
$_SESSION['lecture_video_watch'][$token]['last_ping_at'] = $now;

lecture_video_api_response([
  'ok' => true,
  'message' => 'تم احتساب مشاهدة الفيديو بنجاح.',
  'counted' => true,
  'watched_seconds' => max($watchedSeconds, $requiredSeconds),
  'half_seconds' => $requiredSeconds,
  'stats' => $result,
]);
