<?php
// students/inc/access_control.php
// ✅ Rules:
// 1) If student enrolled in course => all lectures open automatically.
// 2) If not enrolled in course => lecture open only if enrolled in that lecture.
// 3) If course.access_type = free => open.

require __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/assessments.php';

function student_has_course_access(PDO $pdo, int $studentId, int $courseId): bool {
  if ($studentId <= 0 || $courseId <= 0) return false;

  // free course?
  $stmt = $pdo->prepare("SELECT access_type FROM courses WHERE id=? LIMIT 1");
  $stmt->execute([$courseId]);
  $accessType = (string)($stmt->fetchColumn() ?: '');
  if ($accessType === 'free') return true;

  // enrolled in course?
  $stmt = $pdo->prepare("SELECT 1 FROM student_course_enrollments WHERE student_id=? AND course_id=? LIMIT 1");
  $stmt->execute([$studentId, $courseId]);
  return (bool)$stmt->fetchColumn();
}

function lecture_get_course_id(PDO $pdo, int $lectureId): int {
  if ($lectureId <= 0) return 0;
  $stmt = $pdo->prepare("SELECT course_id FROM lectures WHERE id=? LIMIT 1");
  $stmt->execute([$lectureId]);
  return (int)($stmt->fetchColumn() ?: 0);
}

function student_has_lecture_access(PDO $pdo, int $studentId, int $lectureId): bool {
  if ($studentId <= 0 || $lectureId <= 0) return false;

  $courseId = lecture_get_course_id($pdo, $lectureId);
  if ($courseId <= 0) return false;

  // ✅ IMPORTANT: course access opens all lectures
  if (student_has_course_access($pdo, $studentId, $courseId)) return true;

  // lecture enrollment opens single lecture
  $stmt = $pdo->prepare("SELECT 1 FROM student_lecture_enrollments WHERE student_id=? AND lecture_id=? LIMIT 1");
  $stmt->execute([$studentId, $lectureId]);
  return (bool)$stmt->fetchColumn();
}

function student_video_views_ensure_table(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;
  $ready = true;

  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_student_views (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        video_id INT UNSIGNED NOT NULL,
        student_id INT UNSIGNED NOT NULL,
        views_used INT UNSIGNED NOT NULL DEFAULT 0,
        last_view_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_video_student (video_id, student_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
  } catch (Throwable $e) {
    // ignore: live schema may already exist or current DB user may not have DDL privileges
  }
}

function student_video_bonus_views_ensure_table(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;
  $ready = true;

  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS video_student_bonus_views (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        video_id INT UNSIGNED NOT NULL,
        student_id INT UNSIGNED NOT NULL,
        bonus_views INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_video_student_bonus (video_id, student_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
  } catch (Throwable $e) {
    // ignore
  }
}

function student_get_video_bonus_views(PDO $pdo, int $studentId, int $videoId): int {
  if ($studentId <= 0 || $videoId <= 0) return 0;

  student_video_bonus_views_ensure_table($pdo);

  try {
    $stmt = $pdo->prepare("
      SELECT bonus_views
      FROM video_student_bonus_views
      WHERE video_id=? AND student_id=?
      LIMIT 1
    ");
    $stmt->execute([$videoId, $studentId]);
    return max(0, (int)($stmt->fetchColumn() ?: 0));
  } catch (Throwable $e) {
    return 0;
  }
}

function student_grant_video_bonus_view(PDO $pdo, int $studentId, int $videoId, int $increment = 1): bool {
  $increment = max(1, $increment);
  if ($studentId <= 0 || $videoId <= 0) return false;

  student_video_bonus_views_ensure_table($pdo);

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      SELECT id
      FROM video_student_bonus_views
      WHERE video_id=? AND student_id=?
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$videoId, $studentId]);
    $rowId = (int)($stmt->fetchColumn() ?: 0);

    if ($rowId > 0) {
      $stmt = $pdo->prepare("
        UPDATE video_student_bonus_views
        SET bonus_views = bonus_views + ?
        WHERE id=?
      ");
      $stmt->execute([$increment, $rowId]);
    } else {
      $stmt = $pdo->prepare("
        INSERT INTO video_student_bonus_views (video_id, student_id, bonus_views)
        VALUES (?, ?, ?)
      ");
      $stmt->execute([$videoId, $studentId, $increment]);
    }

    $pdo->commit();
    return true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return false;
  }
}

function student_get_video_row(PDO $pdo, int $videoId): ?array {
  if ($videoId <= 0) return null;

  $stmt = $pdo->prepare("
    SELECT
      id,
      lecture_id,
      title,
      duration_minutes,
      allowed_views_per_student,
      video_type,
      embed_iframe,
      embed_iframe_enc,
      embed_iframe_iv,
      exam_id,
      assignment_id
    FROM videos
    WHERE id=?
    LIMIT 1
  ");
  $stmt->execute([$videoId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function student_get_video_watch_stats(PDO $pdo, int $studentId, int $videoId, ?array $videoRow = null): array {
  $video = $videoRow ?: student_get_video_row($pdo, $videoId);
  $baseAllowed = max(1, (int)($video['allowed_views_per_student'] ?? 1));
  $bonusAllowed = 0;
  $used = 0;

  if ($studentId > 0 && $videoId > 0) {
    student_video_views_ensure_table($pdo);
    $bonusAllowed = student_get_video_bonus_views($pdo, $studentId, $videoId);
    try {
      $stmt = $pdo->prepare("SELECT views_used FROM video_student_views WHERE video_id=? AND student_id=? LIMIT 1");
      $stmt->execute([$videoId, $studentId]);
      $used = max(0, (int)($stmt->fetchColumn() ?: 0));
    } catch (Throwable $e) {
      $used = 0;
    }
  }

  $allowed = max(1, $baseAllowed + $bonusAllowed);
  $remaining = max(0, $allowed - $used);
  return [
    'base_allowed' => $baseAllowed,
    'bonus_allowed' => $bonusAllowed,
    'allowed' => $allowed,
    'used' => $used,
    'remaining' => $remaining,
    'blocked' => ($remaining <= 0),
  ];
}

function student_linked_assessment_name(PDO $pdo, string $type, int $assessmentId): string {
  $assessmentId = (int)$assessmentId;
  if ($assessmentId <= 0) return '';

  try {
    if ($type === 'exam') {
      $stmt = $pdo->prepare("SELECT name FROM exams WHERE id=? LIMIT 1");
    } elseif ($type === 'assignment') {
      $stmt = $pdo->prepare("SELECT name FROM assignments WHERE id=? LIMIT 1");
    } else {
      return '';
    }
    $stmt->execute([$assessmentId]);
    return trim((string)($stmt->fetchColumn() ?: ''));
  } catch (Throwable $e) {
    return '';
  }
}

function student_get_video_requirement_status(PDO $pdo, int $studentId, ?array $videoRow = null, int $videoId = 0): array {
  $video = $videoRow ?: student_get_video_row($pdo, $videoId);
  if (!$video) {
    return [
      'required' => false,
      'satisfied' => true,
    ];
  }

  $assessmentType = '';
  $assessmentId = 0;
  if ((int)($video['assignment_id'] ?? 0) > 0) {
    $assessmentType = 'assignment';
    $assessmentId = (int)$video['assignment_id'];
  } elseif ((int)($video['exam_id'] ?? 0) > 0) {
    $assessmentType = 'exam';
    $assessmentId = (int)$video['exam_id'];
  }

  if ($assessmentType === '' || $assessmentId <= 0) {
    return [
      'required' => false,
      'satisfied' => true,
    ];
  }

  $cfg = student_assessment_type_config($assessmentType);
  $attempt = student_assessment_fetch_latest_attempt($pdo, $assessmentType, $assessmentId, $studentId);
  $attemptStatus = (string)($attempt['status'] ?? '');
  $completion = student_assessment_attempt_answer_summary($pdo, $assessmentType, (int)($attempt['id'] ?? 0));
  $assessmentName = student_linked_assessment_name($pdo, $assessmentType, $assessmentId);
  if ($assessmentName === '') $assessmentName = (string)($cfg['label'] ?? 'المحتوى');

  return [
    'required' => true,
    'satisfied' => student_assessment_attempt_is_completed($pdo, $assessmentType, $attempt),
    'assessment_type' => $assessmentType,
    'assessment_id' => $assessmentId,
    'assessment_label' => (string)($cfg['label'] ?? 'المحتوى'),
    'assessment_name' => $assessmentName,
    'assessment_href' => 'assessment.php?type=' . rawurlencode($assessmentType) . '&id=' . $assessmentId,
    'attempt_status' => $attemptStatus,
    'answered_questions' => (int)($completion['answered_count'] ?? 0),
    'total_questions' => (int)($completion['question_count'] ?? 0),
  ];
}

function student_increment_video_watch(PDO $pdo, int $studentId, int $videoId, int $allowedViews): array {
  $allowedViews = max(1, $allowedViews);
  student_video_views_ensure_table($pdo);

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      SELECT id, views_used
      FROM video_student_views
      WHERE video_id=? AND student_id=?
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$videoId, $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $used = max(0, (int)($row['views_used'] ?? 0));
    if ($used >= $allowedViews) {
      $pdo->commit();
      return [
        'ok' => false,
        'allowed' => $allowedViews,
        'used' => $used,
        'remaining' => 0,
      ];
    }

    if ($row) {
      $stmt = $pdo->prepare("
        UPDATE video_student_views
        SET views_used = views_used + 1,
            last_view_at = CURRENT_TIMESTAMP
        WHERE id=?
      ");
      $stmt->execute([(int)$row['id']]);
      $used++;
    } else {
      $stmt = $pdo->prepare("
        INSERT INTO video_student_views (video_id, student_id, views_used, last_view_at)
        VALUES (?, ?, 1, CURRENT_TIMESTAMP)
      ");
      $stmt->execute([$videoId, $studentId]);
      $used = 1;
    }

    $pdo->commit();

    return [
      'ok' => true,
      'allowed' => $allowedViews,
      'used' => $used,
      'remaining' => max(0, $allowedViews - $used),
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return [
      'ok' => false,
      'allowed' => $allowedViews,
      'used' => 0,
      'remaining' => max(0, $allowedViews),
    ];
  }
}

function student_video_half_watch_seconds(int $durationMinutes): int {
  $durationSeconds = max(60, $durationMinutes * 60);
  return max(30, (int)ceil($durationSeconds / 2));
}

function student_extract_iframe_src(string $iframeHtml): string {
  $iframeHtml = trim($iframeHtml);
  if ($iframeHtml === '') return '';

  if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*([\"\'])(.*?)\1/i', $iframeHtml, $m)) {
    return html_entity_decode((string)$m[2], ENT_QUOTES, 'UTF-8');
  }

  if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*([^\s>]+)/i', $iframeHtml, $m)) {
    return html_entity_decode(trim((string)$m[1], "\"'"), ENT_QUOTES, 'UTF-8');
  }

  if (preg_match('~^(https?:)?//~i', $iframeHtml)) {
    return $iframeHtml;
  }

  return '';
}

function student_append_url_params(string $url, array $params): string {
  if ($url === '') return '';
  $sep = (strpos($url, '?') === false) ? '?' : '&';
  return $url . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function student_sanitize_video_id(string $value): string {
  return preg_replace('~[^A-Za-z0-9_-]~', '', $value);
}

function student_extract_youtube_video_id(string $url): string {
  $parts = @parse_url($url);
  if (!is_array($parts)) return '';

  $host = strtolower((string)($parts['host'] ?? ''));
  $path = trim((string)($parts['path'] ?? ''), '/');

  if ($host === 'youtu.be') {
    $segments = $path === '' ? [] : explode('/', $path);
    return student_sanitize_video_id((string)($segments[0] ?? ''));
  }

  if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtube-nocookie.com') !== false) {
    $segments = $path === '' ? [] : explode('/', $path);
    if (!empty($segments[0]) && in_array($segments[0], ['embed', 'shorts', 'live'], true) && !empty($segments[1])) {
      return student_sanitize_video_id((string)$segments[1]);
    }

    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    if (!empty($query['v'])) {
      return student_sanitize_video_id((string)$query['v']);
    }
  }

  return '';
}

function student_extract_vimeo_video_id(string $url): string {
  $parts = parse_url($url);
  if (!is_array($parts)) return '';

  $host = strtolower((string)($parts['host'] ?? ''));
  $path = trim((string)($parts['path'] ?? ''), '/');
  if ($host === '' || $path === '') return '';
  if (strpos($host, 'vimeo.com') === false) return '';

  $segments = array_values(array_filter(explode('/', $path), static function ($segment): bool {
    return $segment !== '';
  }));
  if (empty($segments)) return '';

  $candidate = (string)$segments[count($segments) - 1];
  if (!preg_match('~^\d+$~', $candidate)) return '';
  return $candidate;
}

function student_normalize_video_src(string $src, string $videoType, string $origin = ''): string {
  $src = trim(html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
  if ($src === '') return '';
  if (strpos($src, '//') === 0) $src = 'https:' . $src;

  $parts = @parse_url($src);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  if (!in_array($scheme, ['http', 'https'], true)) return '';

  if ($videoType === 'youtube') {
    $videoId = student_extract_youtube_video_id($src);
    if ($videoId === '') return '';

    $embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId);
    $params = [
      'controls' => 0,
      'disablekb' => 1,
      'rel' => 0,
      'modestbranding' => 1,
      'playsinline' => 1,
      'iv_load_policy' => 3,
      'fs' => 0,
      'enablejsapi' => 1,
    ];
    if ($origin !== '') $params['origin'] = $origin;
    return student_append_url_params($embed, $params);
  }

  if ($videoType === 'vimeo') {
    $videoId = student_extract_vimeo_video_id($src);
    if ($videoId !== '') {
      $src = 'https://player.vimeo.com/video/' . rawurlencode($videoId);
    }

    return student_append_url_params($src, [
      'title' => 0,
      'byline' => 0,
      'portrait' => 0,
    ]);
  }

  return $src;
}

function student_decrypt_video_iframe(?string $cipherBase64, ?string $ivHex): string {
  $cipherBase64 = (string)$cipherBase64;
  $ivHex = (string)$ivHex;
  if ($cipherBase64 === '' || $ivHex === '') return '';
  if (!defined('APP_EMBED_SECRET_KEY')) return '';
  if (!function_exists('openssl_decrypt')) return '';

  $secret = (string)APP_EMBED_SECRET_KEY;
  if (strlen($secret) !== 32) return '';

  $cipherRaw = base64_decode($cipherBase64, true);
  $iv = hex2bin($ivHex);
  if ($cipherRaw === false || $iv === false || strlen($iv) !== 16) return '';

  $keys = [hash('sha256', $secret, true)];
  if (strlen($secret) === 32) $keys[] = $secret;

  foreach ($keys as $key) {
    $plain = openssl_decrypt($cipherRaw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain !== false) return (string)$plain;
  }

  return '';
}

function student_is_allowed_video_embed_url(string $url, string $videoType): bool {
  $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
  $videoType = strtolower(trim($videoType));
  if ($url === '' || $videoType === '') return false;
  if (strpos($url, '//') === 0) $url = 'https:' . $url;

  $parts = @parse_url($url);
  if (!is_array($parts)) return false;

  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  $host = strtolower((string)($parts['host'] ?? ''));
  if (!in_array($scheme, ['http', 'https'], true) || $host === '') return false;

  $allowedHosts = [
    'youtube' => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
    'bunny' => ['mediadelivery.net', 'bunnycdn.com', 'bunny.net'],
    'inkrypt' => ['inkryptvideos.com'],
    'vimeo' => ['vimeo.com'],
    'vdocipher' => ['vdocipher.com', 'vdo.ai'],
  ];

  if (empty($allowedHosts[$videoType])) return false;

  foreach ($allowedHosts[$videoType] as $allowedHost) {
    if (
      $host === $allowedHost ||
      substr($host, -strlen('.' . $allowedHost)) === '.' . $allowedHost
    ) {
      return true;
    }
  }

  return false;
}

function student_is_supported_video_embed_html(string $embedHtml, string $videoType): bool {
  $embedHtml = trim($embedHtml);
  $videoType = strtolower(trim($videoType));
  if ($embedHtml === '' || $videoType === '') return false;
  if (!class_exists('DOMDocument')) {
    return student_is_supported_video_embed_html_fallback($embedHtml, $videoType);
  }

  $internalErrors = libxml_use_internal_errors(true);
  $dom = new DOMDocument('1.0', 'UTF-8');
  $loaded = $dom->loadHTML(
    '<?xml encoding="utf-8" ?><div id="__student_embed_root__">' . $embedHtml . '</div>',
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
  );
  libxml_clear_errors();
  libxml_use_internal_errors($internalErrors);
  if (!$loaded) return student_is_supported_video_embed_html_fallback($embedHtml, $videoType);

  $root = $dom->getElementsByTagName('div')->item(0);
  if (!$root) return false;

  $allowedTags = ['div', 'iframe', 'script'];
  $allowedAttrs = ['id', 'class', 'src', 'type', 'async', 'defer', 'title', 'loading', 'referrerpolicy', 'allow', 'allowfullscreen', 'webkitallowfullscreen', 'mozallowfullscreen', 'allowpaymentrequest', 'playsinline', 'width', 'height', 'frameborder', 'scrolling', 'name', 'sandbox', 'style', 'tabindex'];
  $hasTrustedSource = false;

  foreach ($root->getElementsByTagName('*') as $node) {
    $tag = strtolower((string)$node->nodeName);
    if (!in_array($tag, $allowedTags, true)) return false;

    if ($node->attributes) {
      foreach ($node->attributes as $attr) {
        $name = strtolower((string)$attr->nodeName);
        $value = trim((string)$attr->nodeValue);

        if (strpos($name, 'on') === 0) return false;
        if (preg_match('~(?:javascript|vbscript|data)\s*:|%(?:0*[46]a|0*64|0*61|0*76|0*73)~i', $value)) return false;

        $isAllowedName =
          in_array($name, $allowedAttrs, true) ||
          strpos($name, 'data-') === 0 ||
          strpos($name, 'aria-') === 0;
        if (!$isAllowedName) return false;

        if ($name === 'src') {
          if (!student_is_allowed_video_embed_url($value, $videoType)) return false;
          $hasTrustedSource = true;
        }
      }
    }

    if ($tag === 'iframe' && !$node->hasAttribute('src')) return false;

    if ($tag === 'script' && !$node->hasAttribute('src')) {
      $scriptText = trim((string)$node->textContent);
      if ($scriptText === '') continue;
      if (preg_match('~(?:javascript|vbscript|data)\s*:~i', $scriptText)) return false;
      if (preg_match('~(?:eval\s*\(|new\s+Function\s*\(|setTimeout\s*\(|setInterval\s*\()~i', $scriptText)) return false;

      preg_match_all('~(?:(?:https?:)?//)[a-z0-9.-]+(?::\d+)?(?:/[^\s\'"]*)?~i', $scriptText, $matches);
      $urls = $matches[0] ?? [];
      if (empty($urls) && !$hasTrustedSource) return false;

      foreach ($urls as $url) {
        if (!student_is_allowed_video_embed_url($url, $videoType)) return false;
      }

      if (!empty($urls)) $hasTrustedSource = true;
    }
  }

  return $hasTrustedSource;
}

function student_is_supported_video_embed_html_fallback(string $embedHtml, string $videoType): bool {
  $embedHtml = trim($embedHtml);
  $videoType = strtolower(trim($videoType));
  if ($embedHtml === '' || $videoType === '') return false;
  if (preg_match('~<\s*(?!/?(?:div|iframe|script)\b)[a-z0-9:_-]+~i', $embedHtml)) return false;
  if (preg_match('~\bon[a-z]+\s*=~i', $embedHtml)) return false;
  if (preg_match('~(?:javascript|vbscript|data)\s*:|%(?:0*[46]a|0*64|0*61|0*76|0*73)~i', $embedHtml)) return false;

  $hasTrustedSource = false;

  preg_match_all('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $embedHtml, $quotedSources);
  foreach (($quotedSources[2] ?? []) as $url) {
    if (!student_is_allowed_video_embed_url((string)$url, $videoType)) return false;
    $hasTrustedSource = true;
  }

  preg_match_all('~\bsrc\s*=\s*(https?://[^\s"\'<>]+)~i', $embedHtml, $bareSources);
  foreach (($bareSources[1] ?? []) as $url) {
    $cleanUrl = trim((string)$url, "\"'");
    if ($cleanUrl === '') continue;
    if (!student_is_allowed_video_embed_url($cleanUrl, $videoType)) return false;
    $hasTrustedSource = true;
  }

  if (preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $embedHtml, $inlineScripts)) {
    foreach (($inlineScripts[1] ?? []) as $scriptText) {
      $scriptText = trim((string)$scriptText);
      if ($scriptText === '') continue;
      if (preg_match('~(?:eval\s*\(|new\s+Function\s*\(|setTimeout\s*\(|setInterval\s*\()~i', $scriptText)) return false;

      preg_match_all('~https?://[a-z0-9.-]+(?::\d+)?(?:/[^\s\'"]*)?~i', $scriptText, $scriptUrls);
      $urls = $scriptUrls[0] ?? [];
      if (empty($urls) && !$hasTrustedSource) return false;

      foreach ($urls as $url) {
        if (!student_is_allowed_video_embed_url((string)$url, $videoType)) return false;
        $hasTrustedSource = true;
      }
    }
  }

  return $hasTrustedSource;
}

function student_build_video_player_html(array $videoRow, string $origin = ''): string {
  $iframeHtml = student_decrypt_video_iframe($videoRow['embed_iframe_enc'] ?? null, $videoRow['embed_iframe_iv'] ?? null);
  if ($iframeHtml === '') $iframeHtml = (string)($videoRow['embed_iframe'] ?? '');
  $iframeHtml = trim($iframeHtml);

  $src = student_extract_iframe_src($iframeHtml);
  $src = student_normalize_video_src($src, (string)($videoRow['video_type'] ?? ''), $origin);
  if ($src === '') {
    if ($iframeHtml === '') return '';
    if (!student_is_supported_video_embed_html($iframeHtml, (string)($videoRow['video_type'] ?? ''))) return '';
    return '<div class="acc-embeddedHtml" id="lectureVideoEmbed">' . $iframeHtml . '</div>';
  }

  $title = htmlspecialchars((string)($videoRow['title'] ?? 'مشغل الفيديو'), ENT_QUOTES, 'UTF-8');
  $srcAttr = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

  return '<iframe class="acc-embeddedFrame" id="lectureVideoFrame" src="' . $srcAttr . '" title="' . $title . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen" allowfullscreen></iframe>';
}

function student_resolve_pdf_absolute_path(string $filePath): string {
  $filePath = trim(str_replace('\\', '/', $filePath));
  if ($filePath === '') return '';

  $adminBase = realpath(__DIR__ . '/../../admin');
  if ($adminBase === false) return '';

  $absolute = realpath($adminBase . '/' . ltrim($filePath, '/'));
  if ($absolute === false || !is_file($absolute)) return '';
  if (strpos($absolute, $adminBase) !== 0) return '';
  if (strtolower((string)pathinfo($absolute, PATHINFO_EXTENSION)) !== 'pdf') return '';

  return $absolute;
}

function student_base64url_decode(string $value): string {
  $value = trim($value);
  if ($value === '') return '';

  $decoded = base64_decode(
    strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4),
    true
  );

  return is_string($decoded) ? $decoded : '';
}

/**
 * ينشئ توكن وصول قصير العمر خاص بملف PDF لطالب معيّن.
 *
 * صيغة التوكن: base64url("studentId|pdfId|expiresAt") . "." . base64url(hmacSha256(payload)).
 * قيمة $ttl تمثّل مدة صلاحية التوكن بالثواني وتُطبق بحد أدنى 30 ثانية.
 * يعيد سلسلة فارغة إذا تعذر إنشاء التوكن.
 */
function student_create_pdf_access_token(int $studentId, int $pdfId, int $ttl = 300): string {
  if ($studentId <= 0 || $pdfId <= 0 || !defined('APP_EMBED_SECRET_KEY')) return '';

  $secret = (string)APP_EMBED_SECRET_KEY;
  if ($secret === '') return '';

  $expiresAt = time() + max(30, $ttl);
  $payload = $studentId . '|' . $pdfId . '|' . $expiresAt;
  $signature = hash_hmac('sha256', $payload, $secret, true);

  return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' .
    rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

/**
 * يتحقق من توكن الوصول الخاص بملف PDF ويرجع رقم الطالب عند النجاح.
 *
 * يعيد 0 إذا كان التوكن غير صالح أو منتهي الصلاحية أو لا يخص ملف الـ PDF المطلوب.
 */
function student_verify_pdf_access_token(string $token, int $pdfId): int {
  $token = trim($token);
  if ($token === '' || $pdfId <= 0 || !defined('APP_EMBED_SECRET_KEY')) return 0;

  $secret = (string)APP_EMBED_SECRET_KEY;
  if ($secret === '') return 0;

  $parts = explode('.', $token, 2);
  if (count($parts) !== 2) return 0;

  $payload = student_base64url_decode($parts[0]);
  $signature = student_base64url_decode($parts[1]);
  if ($payload === '' || $signature === '') return 0;

  $expectedSignature = hash_hmac('sha256', $payload, $secret, true);
  if (!hash_equals($expectedSignature, $signature)) return 0;

  $payloadParts = explode('|', $payload, 3);
  if (count($payloadParts) !== 3) return 0;

  $studentId = (int)($payloadParts[0] ?? 0);
  $tokenPdfId = (int)($payloadParts[1] ?? 0);
  $expiresAt = (int)($payloadParts[2] ?? 0);

  if ($studentId <= 0 || $tokenPdfId !== $pdfId || $expiresAt < time()) return 0;

  return $studentId;
}
