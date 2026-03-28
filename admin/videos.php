<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS platform_settings (
      id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      platform_name VARCHAR(190) NOT NULL DEFAULT 'منصتي التعليمية',
      platform_logo VARCHAR(255) DEFAULT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
  $rowSettings = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
  if (!$rowSettings) {
    $pdo->exec("INSERT INTO platform_settings (id, platform_name, platform_logo) VALUES (1, 'منصتي التعليمية', NULL)");
    $rowSettings = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
  }
} catch (Throwable $e) {
  $rowSettings = null;
}

$settings = get_platform_settings($pdo);

$platformName = (string)($rowSettings['platform_name'] ?? ($settings['platform_name'] ?? 'منصتي التعليمية'));
$logo = (string)($rowSettings['platform_logo'] ?? ($settings['platform_logo'] ?? ''));

if ($logo === '') $logo = null;

function h(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
/* =========================
   صلاحيات السايدبار
   ========================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'مشرف');
$allowedMenuKeys = get_allowed_menu_keys($pdo, $adminId, $adminRole);

function menu_visible(array $allowedKeys, string $key, string $role): bool {
  if ($role === 'مدير') return true;
  if ($key === 'logout') return true;
  return menu_allowed($allowedKeys, $key);
}

/* =========================
   Crypto helpers (AES-256-CBC)
   - IMPORTANT: ضع المفتاح في ملف config غير مرفوع لـ Git
   - هنا fallback بسيط لو غير موجود
   ========================= */
function videos_secret_key(): string {
  // الأفضل: define('APP_SECRET', '...') في ملف خارج الويب
  if (defined('APP_SECRET') && is_string(APP_SECRET) && APP_SECRET !== '') return APP_SECRET;
  if (defined('APP_EMBED_SECRET_KEY') && is_string(APP_EMBED_SECRET_KEY) && APP_EMBED_SECRET_KEY !== '') return APP_EMBED_SECRET_KEY;

  // fallback (غير مثالي): يعتمد على session id
  $sid = session_id();
  return hash('sha256', 'videos_secret|' . $sid, true);
}

function encrypt_iframe(string $plain): array {
  $plain = trim($plain);
  if ($plain === '') return ['enc' => null, 'iv' => null];

  $secret = videos_secret_key();
  $key = hash('sha256', $secret, true);

  $iv = random_bytes(16);
  $encRaw = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
  if ($encRaw === false) throw new RuntimeException('encrypt failed');

  return [
    'enc' => base64_encode($encRaw),
    'iv'  => bin2hex($iv),
  ];
}

function decrypt_iframe(?string $encB64, ?string $ivHex): string {
  if (!$encB64 || !$ivHex) return '';

  $secret = videos_secret_key();

  $iv = hex2bin($ivHex);
  if ($iv === false || strlen($iv) !== 16) return '';

  $encRaw = base64_decode($encB64, true);
  if ($encRaw === false) return '';

  $keys = [hash('sha256', $secret, true)];
  if (is_string($secret) && strlen($secret) === 32) $keys[] = $secret;

  foreach ($keys as $key) {
    $plain = openssl_decrypt($encRaw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if (is_string($plain)) return $plain;
  }

  return '';
}

function admin_extract_iframe_src(string $iframeHtml): string {
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

function admin_append_url_params(string $url, array $params): string {
  if ($url === '') return '';
  $sep = (strpos($url, '?') === false) ? '?' : '&';
  return $url . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function admin_sanitize_video_id(string $value): string {
  return preg_replace('~[^A-Za-z0-9_-]~', '', $value);
}

function admin_extract_youtube_video_id(string $url): string {
  $parts = @parse_url($url);
  if (!is_array($parts)) return '';

  $host = strtolower((string)($parts['host'] ?? ''));
  $path = trim((string)($parts['path'] ?? ''), '/');

  if ($host === 'youtu.be') {
    $segments = $path === '' ? [] : explode('/', $path);
    return admin_sanitize_video_id((string)($segments[0] ?? ''));
  }

  if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtube-nocookie.com') !== false) {
    $segments = $path === '' ? [] : explode('/', $path);
    if (!empty($segments[0]) && in_array($segments[0], ['embed', 'shorts', 'live'], true) && !empty($segments[1])) {
      return admin_sanitize_video_id((string)$segments[1]);
    }

    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    if (!empty($query['v'])) {
      return admin_sanitize_video_id((string)$query['v']);
    }
  }

  return '';
}

function admin_normalize_video_src(string $src, string $videoType): string {
  $src = trim(html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
  if ($src === '') return '';
  if (strpos($src, '//') === 0) $src = 'https:' . $src;

  $parts = @parse_url($src);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  if (!in_array($scheme, ['http', 'https'], true)) return '';

  if ($videoType === 'youtube') {
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    $segments = $path === '' ? [] : explode('/', $path);
    if (
      (strpos($host, 'youtube.com') !== false || strpos($host, 'youtube-nocookie.com') !== false) &&
      !empty($segments[0]) &&
      $segments[0] === 'embed'
    ) {
      return $src;
    }

    $videoId = admin_extract_youtube_video_id($src);
    if ($videoId === '') return '';

    return admin_append_url_params(
      'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId),
      [
        'rel' => 0,
        'modestbranding' => 1,
        'playsinline' => 1,
        'iv_load_policy' => 3,
        'fs' => 0,
      ]
    );
  }

  return $src;
}

function admin_build_video_preview_html(array $videoRow, string $iframeHtml): string {
  $iframeHtml = trim($iframeHtml);
  if ($iframeHtml === '') return '';

  $src = admin_extract_iframe_src($iframeHtml);
  $src = admin_normalize_video_src($src, (string)($videoRow['video_type'] ?? ''));
  if ($src === '') return $iframeHtml;

  $title = htmlspecialchars((string)($videoRow['title'] ?? 'معاينة الفيديو'), ENT_QUOTES, 'UTF-8');
  $srcAttr = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
  return '<iframe class="acc-embeddedFrame" src="' . $srcAttr . '" title="' . $title . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen" allowfullscreen></iframe>';
}

function normalize_int($v, int $min, int $max): int {
  $n = (int)$v;
  if ($n < $min) $n = $min;
  if ($n > $max) $n = $max;
  return $n;
}

function valid_video_type(string $t): bool {
  return in_array($t, ['youtube','bunny','inkrypt','vimeo','vdocipher'], true);
}

function find_student_by_code(PDO $pdo, string $code): ?array {
  $code = trim($code);
  if ($code === '') return null;

  $stmt = $pdo->prepare("
    SELECT id, full_name, barcode, grade_id
    FROM students
    WHERE barcode = ?
       OR CONCAT('STD-', id) = ?
    LIMIT 1
  ");
  $stmt->execute([$code, $code]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function video_bonus_views_ensure_table(PDO $pdo): void {
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

function grant_video_bonus_view(PDO $pdo, int $videoId, int $studentId): bool {
  if ($videoId <= 0 || $studentId <= 0) return false;

  video_bonus_views_ensure_table($pdo);

  try {
    $stmt = $pdo->prepare("
      INSERT INTO video_student_bonus_views (video_id, student_id, bonus_views)
      VALUES (?, ?, 1)
      ON DUPLICATE KEY UPDATE bonus_views = bonus_views + 1
    ");
    $stmt->execute([$videoId, $studentId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/* =========================
   Lists
   ========================= */
$coursesList = $pdo->query("SELECT id, name, grade_id FROM courses ORDER BY id DESC")->fetchAll();
$coursesMap = [];
foreach ($coursesList as $c) {
  $coursesMap[(int)$c['id']] = [
    'name' => (string)$c['name'],
    'grade_id' => (int)($c['grade_id'] ?? 0),
  ];
}

$examsList = $pdo->query("
  SELECT e.id, e.name, e.grade_id, g.name AS grade_name
  FROM exams e
  INNER JOIN grades g ON g.id = e.grade_id
  ORDER BY e.id DESC
")->fetchAll();

$assignmentsList = $pdo->query("
  SELECT a.id, a.name, a.grade_id, g.name AS grade_name
  FROM assignments a
  INNER JOIN grades g ON g.id = a.grade_id
  ORDER BY a.id DESC
")->fetchAll();

/* =========================
   CRUD - Videos
   ========================= */
$success = null;
$error = null;

/* CREATE */
if (($_POST['action'] ?? '') === 'create') {
  $title = trim((string)($_POST['title'] ?? ''));
  $duration = normalize_int($_POST['duration_minutes'] ?? 0, 0, 1000000);
  $allowedViews = normalize_int($_POST['allowed_views_per_student'] ?? 1, 1, 1000000);

  $courseId = (int)($_POST['course_id'] ?? 0);
  $lectureId = (int)($_POST['lecture_id'] ?? 0);

  $videoType = (string)($_POST['video_type'] ?? 'youtube');
  $iframe = trim((string)($_POST['embed_iframe'] ?? ''));
  $examId = (int)($_POST['exam_id'] ?? 0);
  $assignmentId = (int)($_POST['assignment_id'] ?? 0);
  if ($examId <= 0) $examId = null;
  if ($assignmentId <= 0) $assignmentId = null;

  if ($title === '') $error = 'من فضلك اكتب اسم/عنوان الفيديو.';
  elseif ($courseId <= 0 || !isset($coursesMap[$courseId])) $error = 'من فضلك اختر الكورس.';
  elseif ($lectureId <= 0) $error = 'من فضلك اختر المحاضرة.';
  elseif (!valid_video_type($videoType)) $error = 'نوع الفيديو غير صحيح.';
  elseif ($iframe === '') $error = 'من فضلك ضع كود الـ iframe.';
  elseif ($examId !== null && $assignmentId !== null) $error = 'اختر ربطًا واحدًا فقط: امتحان أو واجب.';
  else {
    // تأكد أن المحاضرة تابعة للكورس
    $stmt = $pdo->prepare("SELECT id FROM lectures WHERE id=? AND course_id=? LIMIT 1");
    $stmt->execute([$lectureId, $courseId]);
    if (!$stmt->fetch()) $error = 'المحاضرة المختارة لا تتبع هذا الكورس.';
    elseif ($examId !== null) {
      $stmt = $pdo->prepare("SELECT id FROM exams WHERE id=? AND grade_id=? LIMIT 1");
      $stmt->execute([$examId, (int)$coursesMap[$courseId]['grade_id']]);
      if (!$stmt->fetch()) $error = 'الامتحان المختار لا يتبع نفس الصف الدراسي للكورس.';
    } elseif ($assignmentId !== null) {
      $stmt = $pdo->prepare("SELECT id FROM assignments WHERE id=? AND grade_id=? LIMIT 1");
      $stmt->execute([$assignmentId, (int)$coursesMap[$courseId]['grade_id']]);
      if (!$stmt->fetch()) $error = 'الواجب المختار لا يتبع نفس الصف الدراسي للكورس.';
    }
  }

  if (!$error) {
    try {
      $enc = encrypt_iframe($iframe);

      $stmt = $pdo->prepare("
        INSERT INTO videos
          (course_id, lecture_id, title, duration_minutes, allowed_views_per_student,
           video_type, embed_iframe, embed_iframe_enc, embed_iframe_iv,
           exam_id, assignment_id)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([
        $courseId,
        $lectureId,
        $title,
        $duration,
        $allowedViews,
        $videoType,
        '',                  // ✅ مهم: embed_iframe في الجدول NOT NULL
        $enc['enc'],
        $enc['iv'],
        $examId,
        $assignmentId
      ]);

      header('Location: videos.php?added=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر إضافة الفيديو.';
    }
  }
}

/* UPDATE */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int)($_POST['id'] ?? 0);

  $title = trim((string)($_POST['title'] ?? ''));
  $duration = normalize_int($_POST['duration_minutes'] ?? 0, 0, 1000000);
  $allowedViews = normalize_int($_POST['allowed_views_per_student'] ?? 1, 1, 1000000);

  $courseId = (int)($_POST['course_id'] ?? 0);
  $lectureId = (int)($_POST['lecture_id'] ?? 0);

  $videoType = (string)($_POST['video_type'] ?? 'youtube');
  $iframe = trim((string)($_POST['embed_iframe'] ?? ''));
  $examId = (int)($_POST['exam_id'] ?? 0);
  $assignmentId = (int)($_POST['assignment_id'] ?? 0);
  if ($examId <= 0) $examId = null;
  if ($assignmentId <= 0) $assignmentId = null;

  if ($id <= 0) $error = 'طلب غير صالح.';
  elseif ($title === '') $error = 'اسم الفيديو مطلوب.';
  elseif ($courseId <= 0 || !isset($coursesMap[$courseId])) $error = 'من فضلك اختر الكورس.';
  elseif ($lectureId <= 0) $error = 'من فضلك اختر المحاضرة.';
  elseif (!valid_video_type($videoType)) $error = 'نوع الفيديو غير صحيح.';
  elseif ($iframe === '') $error = 'من فضلك ضع كود الـ iframe.';
  elseif ($examId !== null && $assignmentId !== null) $error = 'اختر ربطًا واحدًا فقط: امتحان أو واجب.';
  else {
    $stmt = $pdo->prepare("SELECT id FROM videos WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) $error = 'الفيديو غير موجود.';
  }

  if (!$error) {
    try {
      // تأكد أن المحاضرة تابعة للكورس
      $stmt = $pdo->prepare("SELECT id FROM lectures WHERE id=? AND course_id=? LIMIT 1");
      $stmt->execute([$lectureId, $courseId]);
      if (!$stmt->fetch()) {
        $error = 'المحاضرة المختارة لا تتبع هذا الكورس.';
      } elseif ($examId !== null) {
        $stmt = $pdo->prepare("SELECT id FROM exams WHERE id=? AND grade_id=? LIMIT 1");
        $stmt->execute([$examId, (int)$coursesMap[$courseId]['grade_id']]);
        if (!$stmt->fetch()) $error = 'الامتحان المختار لا يتبع نفس الصف الدراسي للكورس.';
      } elseif ($assignmentId !== null) {
        $stmt = $pdo->prepare("SELECT id FROM assignments WHERE id=? AND grade_id=? LIMIT 1");
        $stmt->execute([$assignmentId, (int)$coursesMap[$courseId]['grade_id']]);
        if (!$stmt->fetch()) $error = 'الواجب المختار لا يتبع نفس الصف الدراسي للكورس.';
      } else {
        $enc = encrypt_iframe($iframe);

        $stmt = $pdo->prepare("
          UPDATE videos
          SET course_id=?,
              lecture_id=?,
              title=?,
              duration_minutes=?,
              allowed_views_per_student=?,
              video_type=?,
              embed_iframe=?,
              embed_iframe_enc=?,
              embed_iframe_iv=?,
              exam_id=?,
              assignment_id=?
          WHERE id=?
        ");
        $stmt->execute([
          $courseId,
          $lectureId,
          $title,
          $duration,
          $allowedViews,
          $videoType,
          '',                  // ✅ مهم: embed_iframe NOT NULL
          $enc['enc'],
          $enc['iv'],
          $examId,
          $assignmentId,
          $id
        ]);

        header('Location: videos.php?updated=1');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'تعذر تعديل الفيديو.';
    }
  }
}

if (($_POST['action'] ?? '') === 'add_student_view') {
  $videoIdForView = (int)($_POST['video_id'] ?? 0);
  $studentCode = trim((string)($_POST['student_code'] ?? ''));

  if ($videoIdForView <= 0) {
    $error = 'الفيديو المطلوب غير صالح.';
  } elseif ($studentCode === '') {
    $error = 'من فضلك اكتب كود الطالب.';
  } else {
    $stmt = $pdo->prepare("
      SELECT v.id, c.grade_id
      FROM videos v
      INNER JOIN courses c ON c.id = v.course_id
      WHERE v.id=?
      LIMIT 1
    ");
    $stmt->execute([$videoIdForView]);
    $videoForView = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $studentRow = find_student_by_code($pdo, $studentCode);

    if (!$videoForView) {
      $error = 'الفيديو غير موجود.';
    } elseif (!$studentRow) {
      $error = 'لم يتم العثور على طالب بهذا الكود.';
    } elseif ((int)($studentRow['grade_id'] ?? 0) !== (int)($videoForView['grade_id'] ?? 0)) {
      $error = 'هذا الطالب لا يتبع نفس الصف الدراسي الخاص بالفيديو.';
    } elseif (!grant_video_bonus_view($pdo, $videoIdForView, (int)$studentRow['id'])) {
      $error = 'تعذر إضافة مشاهدة إضافية لهذا الطالب.';
    } else {
      header('Location: videos.php?extra_view=1&video_students=' . $videoIdForView);
      exit;
    }
  }
}

/* DELETE */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);

  if ($id <= 0) $error = 'طلب غير صالح.';
  else {
    try {
      $stmt = $pdo->prepare("DELETE FROM videos WHERE id=?");
      $stmt->execute([$id]);

      header('Location: videos.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف الفيديو.';
    }
  }
}

/* Messages */
if (isset($_GET['added'])) $success = 'تمت إضافة الفيديو بنجاح.';
if (isset($_GET['updated'])) $success = 'تم تعديل الفيديو بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف الفيديو بنجاح.';
if (isset($_GET['extra_view'])) $success = 'تمت إضافة مشاهدة إضافية للطالب بنجاح.';

/* Fetch list */
$videos = $pdo->query("
  SELECT
    v.*,
    c.name AS course_name,
    c.grade_id AS course_grade_id,
    l.name AS lecture_name,
    e.name AS exam_name,
    a.name AS assignment_name
  FROM videos v
  INNER JOIN courses c ON c.id = v.course_id
  INNER JOIN lectures l ON l.id = v.lecture_id
  LEFT JOIN exams e ON e.id = v.exam_id
  LEFT JOIN assignments a ON a.id = v.assignment_id
  ORDER BY v.id DESC
")->fetchAll();

$totalVideos = count($videos);

/* Edit mode */
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM videos WHERE id=? LIMIT 1");
  $stmt->execute([$editId]);
  $editRow = $stmt->fetch() ?: null;
}

/* For edit: decrypt iframe for textarea */
$editIframe = '';
if ($editRow) {
  $editIframe = decrypt_iframe($editRow['embed_iframe_enc'] ?? null, $editRow['embed_iframe_iv'] ?? null);
}

$studentsByVideoId = [];
$videoStudentsId = (int)($_GET['video_students'] ?? 0);
$videoStudentsRow = null;
if ($videoStudentsId > 0) {
  video_bonus_views_ensure_table($pdo);
  foreach ($videos as $videoItem) {
    if ((int)$videoItem['id'] === $videoStudentsId) {
      $videoStudentsRow = $videoItem;
      break;
    }
  }

  if ($videoStudentsRow) {
    $stmt = $pdo->prepare("
      SELECT
        base_students.id,
        base_students.full_name,
        base_students.student_phone,
        base_students.barcode,
        COALESCE(vsv.views_used, 0) AS views_used,
        COALESCE(vsb.bonus_views, 0) AS bonus_views
      FROM (
        SELECT s.id, s.full_name, s.student_phone, s.barcode
        FROM student_lecture_enrollments sle
        INNER JOIN students s ON s.id = sle.student_id
        WHERE sle.lecture_id = ?

        UNION

        SELECT s.id, s.full_name, s.student_phone, s.barcode
        FROM student_course_enrollments sce
        INNER JOIN students s ON s.id = sce.student_id
        WHERE sce.course_id = ?
      ) base_students
      LEFT JOIN video_student_views vsv
        ON vsv.video_id = ? AND vsv.student_id = base_students.id
      LEFT JOIN video_student_bonus_views vsb
        ON vsb.video_id = ? AND vsb.student_id = base_students.id
      ORDER BY base_students.full_name ASC, base_students.id ASC
    ");
    $stmt->execute([
      (int)$videoStudentsRow['lecture_id'],
      (int)$videoStudentsRow['course_id'],
      $videoStudentsId,
      $videoStudentsId,
    ]);
    $studentsByVideoId = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

/* Sidebar menu */
$menu = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],

  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php'],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php'],

  ['key' => 'grades', 'label' => 'الصفوف الدراسية', 'icon' => '🏫', 'href' => 'grades.php'],
  ['key' => 'centers', 'label' => 'السناتر', 'icon' => '🏢', 'href' => 'centers.php'],
  ['key' => 'groups', 'label' => 'المجموعات', 'icon' => '👥', 'href' => 'groups.php'],
  ['key' => 'students', 'label' => 'الطلاب', 'icon' => '🧑‍🎓', 'href' => 'students.php'], // ✅✅ (التعديل المطلوب)

  ['key' => 'courses', 'label' => 'الكورسات', 'icon' => '📚', 'href' => 'courses.php'], // ✅✅ (التعديل المطلوب)
  ['key' => 'lectures', 'label' => 'المحاضرات', 'icon' => '🧑‍🏫', 'href' => 'lectures.php'], // ✅✅ (التعديل المطلوب)
  ['key' => 'videos', 'label' => 'الفيديوهات', 'icon' => '🎥', 'href' => 'videos.php', 'active' => true], // ✅✅ (التعديل المطلوب)

  // ✅✅ المطلوب: زر ملفات PDF يفتح صفحة pdfs.php
  ['key' => 'pdfs', 'label' => 'ملفات PDF', 'icon' => '📑', 'href' => 'pdfs.php'],

  // ✅✅ المطلوب: زر اكواد الكورسات يفتح صفحة اكواد الكورسات
  ['key' => 'course_codes', 'label' => 'اكواد الكورسات', 'icon' => '🎟️', 'href' => 'course-codes.php'],

  // ✅✅ المطلوب: زر اكواد المحاضرات يفتح صفحة اكواد المحاضرات
  ['key' => 'lecture_codes', 'label' => 'اكواد المحاضرات', 'icon' => '🧾', 'href' => 'lecture-codes.php'],

  // ✅✅ المطلوب: زر أسئلة الواجبات يفتح صفحة بنوك أسئلة الواجبات
  ['key' => 'assignment_questions', 'label' => 'أسئلة الواجبات', 'icon' => '🗂️', 'href' => 'assignment-question-banks.php'],

  // ✅✅✅ التعديل المطلوب: زر الواجبات يفتح صفحة assignments.php
  ['key' => 'assignments', 'label' => 'الواجبات', 'icon' => '📌', 'href' => 'assignments.php'],

  // ✅✅ التعديل المطلوب: زر الامتحانات يفتح صفحة exams.php
  ['key' => 'exams', 'label' => 'الامتحانات', 'icon' => '🧠', 'href' => 'exams.php'],

  // ✅✅ التعديل المطلوب: زر اسئلة الامتحانات يفتح صفحة بنك اسئلة الامتحانات
  ['key' => 'exam_questions', 'label' => 'أسئلة الامتحانات', 'icon' => '❔', 'href' => 'exam-question-banks.php'],

  // ✅✅✅ التعديل المطلوب هنا: اجعل الرابط يذهب لصفحة student-notifications.php بدل #
  ['key' => 'student_notifications', 'label' => 'اشعارات الطلاب', 'icon' => '🔔', 'href' => 'student-notifications.php'],

  ['key' => 'attendance', 'label' => 'حضور الطلاب', 'icon' => '🧾', 'href' => 'attendance.php'],
  ['key' => 'facebook', 'label' => 'فيس بوك المنصة', 'icon' => '📘', 'href' => 'platform-posts.php'],
  ['key' => 'chat', 'label' => 'شات الطلاب', 'icon' => '💬', 'href' => 'student-conversations.php'],

  // ✅✅✅ التعديل المطلوب: زر الإعدادات يفتح صفحة settings.php بدل #settings
  ['key' => 'settings', 'label' => 'الإعدادات', 'icon' => '⚙️', 'href' => 'settings.php'],

  ['key' => 'logout', 'label' => 'تسجيل الخروج', 'icon' => '🚪', 'href' => 'logout.php', 'danger' => true],
];

if ($adminRole !== 'مدير') {
  $filtered = [];
  foreach ($menu as $it) {
    $key = (string)($it['key'] ?? '');
    if ($key === '') continue;
    if (menu_visible($allowedMenuKeys, $key, $adminRole)) $filtered[] = $it;
  }
  $menu = $filtered;
}

/* Preview modal: if preview=ID, decrypt iframe */
$previewId = (int)($_GET['preview'] ?? 0);
$preview = null;
$previewIframe = '';
$previewHtml = '';
if ($previewId > 0) {
  $stmt = $pdo->prepare("
    SELECT v.*, c.name AS course_name, l.name AS lecture_name
    FROM videos v
    INNER JOIN courses c ON c.id = v.course_id
    INNER JOIN lectures l ON l.id = v.lecture_id
    WHERE v.id=? LIMIT 1
  ");
  $stmt->execute([$previewId]);
  $preview = $stmt->fetch() ?: null;
  if ($preview) {
    $previewIframe = decrypt_iframe($preview['embed_iframe_enc'] ?? null, $preview['embed_iframe_iv'] ?? null);
    if ($previewIframe === '') $previewIframe = (string)($preview['embed_iframe'] ?? '');
    $previewHtml = admin_build_video_preview_html($preview, $previewIframe);
  }
}

$videoTypes = [
  'youtube' => 'يوتيوب',
  'bunny' => 'بايني (Bunny)',
  'inkrypt' => 'انكربت (Inkrypt)',
  'vimeo' => 'فيميو (Vimeo)',
  'vdocipher' => 'فيديو شيبر (VdoCipher)',
];

?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>الفيديوهات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/videos.css">
</head>

<body class="app" data-theme="auto">
  <div class="bg" aria-hidden="true">
    <div class="bg-grad"></div>
    <div class="bg-noise"></div>
  </div>

  <header class="topbar">
    <button class="burger" id="burger" type="button" aria-label="فتح القائمة">☰</button>

    <div class="brand">
      <?php if (!empty($logo)) : ?>
        <img class="brand-logo" src="<?php echo h($logo); ?>" alt="Logo">
      <?php else: ?>
        <div class="brand-fallback" aria-hidden="true"></div>
      <?php endif; ?>
      <div class="brand-text">
        <div class="brand-name"><?php echo h($platformName); ?></div>
        <div class="brand-sub">لوحة التحكم</div>
      </div>
    </div>

    <div class="top-actions">
      <a class="back-btn" href="dashboard.php">🏠 الرجوع للوحة التحكم</a>

      <div class="theme-emoji" title="تبديل الوضع">
        <span class="emoji" aria-hidden="true">🌞</span>
        <label class="emoji-switch">
          <input id="themeSwitch" type="checkbox" />
          <span class="emoji-slider" aria-hidden="true"></span>
        </label>
        <span class="emoji" aria-hidden="true">🌚</span>
      </div>
    </div>
  </header>

  <div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="القائمة الجانبية">
      <div class="sidebar-head">
        <div class="sidebar-title">🧭 التنقل</div>
      </div>

      <nav class="nav">
        <?php foreach ($menu as $item): ?>
          <?php
            $cls = 'nav-item';
            if (!empty($item['active'])) $cls .= ' active';
            if (!empty($item['danger'])) $cls .= ' danger';
          ?>
          <a class="<?php echo $cls; ?>" href="<?php echo h($item['href']); ?>">
            <span class="nav-icon" aria-hidden="true"><?php echo $item['icon']; ?></span>
            <span class="nav-label"><?php echo h($item['label']); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </aside>

    <main class="main">
      <section class="videos-hero">
        <div class="videos-hero-title">
          <h1>🎥 الفيديوهات</h1>
        </div>

        <div class="videos-metrics">
          <div class="metric">
            <div class="metric-ico">🎥</div>
            <div class="metric-meta">
              <div class="metric-label">عدد الفيديوهات</div>
              <div class="metric-val"><?php echo number_format($totalVideos); ?></div>
            </div>
          </div>
        </div>
      </section>

      <?php if ($success): ?>
        <div class="alert success" role="alert"><?php echo h($success); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert" role="alert"><?php echo h($error); ?></div>
      <?php endif; ?>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge"><?php echo $editRow ? '✏️ تعديل' : '➕ إضافة'; ?></span>
            <h2><?php echo $editRow ? 'تعديل فيديو' : 'إضافة فيديو جديد'; ?></h2>
          </div>

          <?php if ($editRow): ?>
            <a class="btn ghost" href="videos.php">إلغاء</a>
          <?php endif; ?>
        </div>

        <form method="post" class="videos-form" autocomplete="off" id="videoForm">
          <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
          <?php if ($editRow): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
          <?php endif; ?>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">اسم الفيديو</span>
            <input class="input2" name="title" required
              value="<?php echo $editRow ? h((string)$editRow['title']) : ''; ?>"
              placeholder="مثال: شرح الدرس الأول" />
          </label>

          <label class="field">
            <span class="label">مدة الفيديو (بالدقائق)</span>
            <input class="input2" type="number" min="0" step="1" name="duration_minutes"
              value="<?php echo $editRow ? (int)$editRow['duration_minutes'] : 0; ?>">
          </label>

          <label class="field">
            <span class="label">المشاهدات المسموح بها لكل طالب</span>
            <input class="input2" type="number" min="1" step="1" name="allowed_views_per_student" required
              value="<?php echo $editRow ? (int)$editRow['allowed_views_per_student'] : 1; ?>">
          </label>

          <label class="field">
            <span class="label">الكورس</span>
            <select class="input2 select-pro" name="course_id" id="courseSelect" required>
              <option value="0">— اختر الكورس —</option>
              <?php foreach ($coursesList as $c): ?>
                <?php $cid = (int)$c['id']; ?>
                <option value="<?php echo $cid; ?>" data-grade-id="<?php echo (int)($c['grade_id'] ?? 0); ?>" <?php echo ($editRow && (int)$editRow['course_id'] === $cid) ? 'selected' : ''; ?>>
                  <?php echo h((string)$c['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$coursesList): ?>
              <div class="videos-hint">لا يوجد كورسات — أضف كورس أولاً من صفحة الكورسات.</div>
            <?php endif; ?>
          </label>

          <label class="field">
            <span class="label">المحاضرة </span>
            <select class="input2 select-pro" name="lecture_id" id="lectureSelect" required>
              <option value="0">— اختر المحاضرة —</option>
            </select>
          </label>

          <label class="field">
            <span class="label">ربط بامتحان</span>
            <select class="input2 select-pro" name="exam_id" id="examSelect">
              <option value="0">— بدون ربط امتحان —</option>
              <?php foreach ($examsList as $exam): ?>
                <?php $examId = (int)$exam['id']; ?>
                <option value="<?php echo $examId; ?>" data-grade-id="<?php echo (int)($exam['grade_id'] ?? 0); ?>" <?php echo ($editRow && (int)($editRow['exam_id'] ?? 0) === $examId) ? 'selected' : ''; ?>>
                  <?php echo h((string)$exam['name'] . ' — ' . (string)$exam['grade_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="videos-hint">يمكنك اختيار امتحان واحد فقط، وعند اختياره لن يعمل الفيديو إلا بعد تسليمه.</div>
          </label>

          <label class="field">
            <span class="label">ربط بواجب</span>
            <select class="input2 select-pro" name="assignment_id" id="assignmentSelect">
              <option value="0">— بدون ربط واجب —</option>
              <?php foreach ($assignmentsList as $assignment): ?>
                <?php $assignmentId = (int)$assignment['id']; ?>
                <option value="<?php echo $assignmentId; ?>" data-grade-id="<?php echo (int)($assignment['grade_id'] ?? 0); ?>" <?php echo ($editRow && (int)($editRow['assignment_id'] ?? 0) === $assignmentId) ? 'selected' : ''; ?>>
                  <?php echo h((string)$assignment['name'] . ' — ' . (string)$assignment['grade_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="videos-hint">يمكنك اختيار واجب واحد فقط بدلًا من الامتحان.</div>
          </label>

          <label class="field">
            <span class="label">نوع الفيديو</span>
            <?php $vt = $editRow ? (string)$editRow['video_type'] : 'youtube'; ?>
            <select class="input2 select-pro" name="video_type" id="videoType" required>
              <?php foreach ($videoTypes as $k => $label): ?>
                <option value="<?php echo h($k); ?>" <?php echo ($vt === $k) ? 'selected' : ''; ?>>
                  <?php echo h($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">iframe</span>
            <textarea class="textarea2" name="embed_iframe" id="iframeInput" required
              placeholder="ضع كود الـ iframe هنا..."><?php echo $editRow ? h($editIframe) : ''; ?></textarea>
          </label>

          <div class="form-actions" style="grid-column:1 / -1;">
            <button class="btn" type="submit" <?php echo (!$coursesList ? 'disabled' : ''); ?>>
              <?php echo $editRow ? '💾 حفظ التعديل' : '➕ إضافة الفيديو'; ?>
            </button>
          </div>
        </form>
      </section>

      <?php if ($videoStudentsRow): ?>
        <section class="cardx" style="margin-top:12px;">
          <div class="cardx-head">
            <div class="cardx-title">
              <span class="cardx-badge">🧑‍🎓</span>
              <h2>طلاب الفيديو: <?php echo h((string)$videoStudentsRow['title']); ?></h2>
            </div>
            <div class="cardx-actions">
              <a class="btn ghost" href="videos.php">إغلاق</a>
            </div>
          </div>

          <div class="video-manage-grid">
            <div class="video-manage-card">
              <div class="video-manage-title">📚 بيانات الفيديو</div>
              <div class="video-manage-list">
                <div><b>الكورس:</b> <?php echo h((string)$videoStudentsRow['course_name']); ?></div>
                <div><b>المحاضرة:</b> <?php echo h((string)$videoStudentsRow['lecture_name']); ?></div>
                <div><b>المشاهدات الأساسية:</b> <?php echo (int)$videoStudentsRow['allowed_views_per_student']; ?></div>
              </div>
            </div>

            <div class="video-manage-card">
              <div class="video-manage-title">➕ إضافة مشاهدة للطالب</div>
              <form method="post" class="video-manage-form">
                <input type="hidden" name="action" value="add_student_view">
                <input type="hidden" name="video_id" value="<?php echo (int)$videoStudentsRow['id']; ?>">
                <label class="field" style="margin:0;">
                  <span class="label">كود الطالب</span>
                  <input class="input2" name="student_code" placeholder="اكتب كود الطالب أو STD-ID" required>
                </label>
                <div class="form-actions">
                  <button class="btn" type="submit">➕ إضافة مشاهدة</button>
                </div>
              </form>
            </div>
          </div>

          <div class="table-wrap" style="padding:0 14px 14px;">
            <table class="table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>اسم الطالب</th>
                  <th>كود الطالب</th>
                  <th>رقم الطالب</th>
                  <th>المشاهدات المستخدمة</th>
                  <th>المشاهدات الإضافية</th>
                  <th>الإجمالي المتاح</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$studentsByVideoId): ?>
                  <tr><td colspan="7" style="text-align:center">لا يوجد طلاب مشتركين في هذه المحاضرة بعد.</td></tr>
                <?php endif; ?>
                <?php foreach ($studentsByVideoId as $idx => $studentRow): ?>
                  <?php
                    $bonusViews = (int)($studentRow['bonus_views'] ?? 0);
                    $baseViews = (int)($videoStudentsRow['allowed_views_per_student'] ?? 1);
                  ?>
                  <tr>
                    <td><?php echo (int)($idx + 1); ?></td>
                    <td><?php echo h((string)$studentRow['full_name']); ?></td>
                    <td><?php echo h((string)($studentRow['barcode'] ?: ('STD-' . (int)$studentRow['id']))); ?></td>
                    <td><?php echo h((string)$studentRow['student_phone']); ?></td>
                    <td><?php echo (int)($studentRow['views_used'] ?? 0); ?></td>
                    <td><?php echo $bonusViews; ?></td>
                    <td><?php echo $baseViews + $bonusViews; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">📋</span>
            <h2>قائمة الفيديوهات</h2>
          </div>
          <div class="cardx-actions">
            <span class="pillx">عدد: <?php echo number_format($totalVideos); ?></span>
          </div>
        </div>

        <div class="video-grid">
          <?php if (!$videos): ?>
            <div class="videos-empty">لا يوجد فيديوهات بعد.</div>
          <?php endif; ?>

          <?php foreach ($videos as $v): ?>
            <article class="video-card">
              <div class="video-head">
                <div class="video-badge">🎬</div>
                <div class="video-title">
                  <div class="video-name"><?php echo h((string)$v['title']); ?></div>
                  <div class="video-sub">
                    📚 <?php echo h((string)$v['course_name']); ?> • 🧑‍🏫 <?php echo h((string)$v['lecture_name']); ?>
                  </div>
                </div>
              </div>

              <div class="video-body">
                <div class="video-meta">
                  <span class="tagx purple">⏱️ <?php echo (int)$v['duration_minutes']; ?> د</span>
                  <span class="tagx green">👀 <?php echo (int)$v['allowed_views_per_student']; ?> مشاهدة/طالب</span>
                  <span class="tagx orange">🔌 <?php echo h($videoTypes[(string)$v['video_type']] ?? (string)$v['video_type']); ?></span>
                  <?php if (!empty($v['exam_name'])): ?>
                    <span class="tagx red">🧠 <?php echo h((string)$v['exam_name']); ?></span>
                  <?php elseif (!empty($v['assignment_name'])): ?>
                    <span class="tagx red">📝 <?php echo h((string)$v['assignment_name']); ?></span>
                  <?php endif; ?>
                </div>

                <div class="video-actions">
                  <a class="link info" href="videos.php?preview=<?php echo (int)$v['id']; ?>">👁️ معاينة</a>
                  <a class="link" href="videos.php?edit=<?php echo (int)$v['id']; ?>">✏️ تعديل</a>

                  <form method="post" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الفيديو؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$v['id']; ?>">
                    <button class="link danger" type="submit">🗑️ حذف</button>
                  </form>

                  <a class="link warn" href="videos.php?video_students=<?php echo (int)$v['id']; ?>">🧑‍🎓 الطلاب</a>
                  <a class="link warn" href="videos.php?video_students=<?php echo (int)$v['id']; ?>">➕ إضافة مشاهدة</a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if ($preview): ?>
        <div class="modal open" id="previewModal" aria-hidden="false">
          <div class="modal-card" role="dialog" aria-modal="true" aria-label="معاينة الفيديو">
            <div class="modal-head">
              <div class="modal-title">
                <div class="badge">👁️</div>
                <div>
                  <h3>معاينة: <?php echo h((string)$preview['title']); ?></h3>
                  <p>📚 <?php echo h((string)$preview['course_name']); ?> • 🧑‍🏫 <?php echo h((string)$preview['lecture_name']); ?></p>
                </div>
              </div>
              <a class="modal-close" href="videos.php" aria-label="إغلاق">✖</a>
            </div>

            <div class="preview-body">
              <div class="player">
                <?php
                  echo $previewHtml;
                ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </main>
  </div>

  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <script>
    (function () {
      const root = document.body;

      // Theme
      const themeSwitch = document.getElementById('themeSwitch');
      const stored = localStorage.getItem('admin_theme') || 'auto';

      function osPrefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      }
      function applyTheme(mode) {
        root.setAttribute('data-theme', mode);
        localStorage.setItem('admin_theme', mode);
        themeSwitch.checked = (mode === 'dark') || (mode === 'auto' && osPrefersDark());
      }
      applyTheme(stored);

      themeSwitch.addEventListener('change', () => applyTheme(themeSwitch.checked ? 'dark' : 'light'));
      if (stored === 'auto' && window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme('auto'));
      }

      // Sidebar overlay (mobile)
      const burger = document.getElementById('burger');
      const sidebar = document.getElementById('sidebar');
      const backdrop = document.getElementById('backdrop');

      function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 980px)').matches;
      }
      function openSidebar() {
        if (!isMobile()) return;
        sidebar.classList.add('open');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
      }
      function closeSidebar() {
        if (!isMobile()) return;
        sidebar.classList.remove('open');
        backdrop.classList.remove('show');
        document.body.style.overflow = '';
      }
      function syncInitial() {
        if (isMobile()) closeSidebar();
        else {
          sidebar.classList.remove('open');
          backdrop.classList.remove('show');
          document.body.style.overflow = '';
        }
      }
      syncInitial();

      burger && burger.addEventListener('click', (e) => {
        e.preventDefault();
        if (!isMobile()) return;
        if (sidebar.classList.contains('open')) closeSidebar();
        else openSidebar();
      });
      backdrop && backdrop.addEventListener('click', (e) => {
        e.preventDefault();
        closeSidebar();
      });
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);

      // ===== Dependent lectures dropdown
      const courseSelect = document.getElementById('courseSelect');
      const lectureSelect = document.getElementById('lectureSelect');
      const examSelect = document.getElementById('examSelect');
      const assignmentSelect = document.getElementById('assignmentSelect');

      const editLectureId = <?php echo $editRow ? (int)$editRow['lecture_id'] : 0; ?>;

      async function loadLectures(courseId) {
        lectureSelect.innerHTML = '<option value="0">— اختر المحاضرة —</option>';
        if (!courseId) return;

        try {
          const url = 'videos_lectures_api.php?course_id=' + encodeURIComponent(courseId);
          const res = await fetch(url, { credentials: 'same-origin' });
          const data = await res.json();
          if (!data || !Array.isArray(data.lectures)) return;

          data.lectures.forEach(l => {
            const opt = document.createElement('option');
            opt.value = String(l.id);
            opt.textContent = l.name;
            lectureSelect.appendChild(opt);
          });

          if (editLectureId > 0) {
            lectureSelect.value = String(editLectureId);
          }
        } catch (e) {
          // ignore
        }
      }

      function syncAssessmentOptions() {
        if (!courseSelect) return;
        const selectedCourse = courseSelect.options[courseSelect.selectedIndex];
        const gradeId = selectedCourse ? String(selectedCourse.getAttribute('data-grade-id') || '0') : '0';

        [examSelect, assignmentSelect].forEach((select) => {
          if (!select) return;
          Array.from(select.options).forEach((option, index) => {
            if (index === 0) {
              option.hidden = false;
              return;
            }
            const optionGrade = String(option.getAttribute('data-grade-id') || '0');
            const hidden = (gradeId !== '0' && optionGrade !== gradeId);
            option.hidden = hidden;
            if (hidden && option.selected) select.value = '0';
          });
        });
      }

      examSelect && examSelect.addEventListener('change', () => {
        if (examSelect.value !== '0' && assignmentSelect) assignmentSelect.value = '0';
      });

      assignmentSelect && assignmentSelect.addEventListener('change', () => {
        if (assignmentSelect.value !== '0' && examSelect) examSelect.value = '0';
      });

      if (courseSelect) {
        courseSelect.addEventListener('change', () => {
          loadLectures(parseInt(courseSelect.value || '0', 10));
          syncAssessmentOptions();
        });

        // initial
        const initialCourse = parseInt(courseSelect.value || '0', 10);
        loadLectures(initialCourse);
        syncAssessmentOptions();
      }

    })();
  </script>
</body>
</html>
