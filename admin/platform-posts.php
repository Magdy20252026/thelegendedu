<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/../inc/platform_features.php';

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

platform_features_ensure_tables($pdo);
$settings = get_platform_settings($pdo);
$platformName = (string)($rowSettings['platform_name'] ?? ($settings['platform_name'] ?? 'منصتي التعليمية'));
$logo = (string)($rowSettings['platform_logo'] ?? ($settings['platform_logo'] ?? ''));
if ($logo === '') $logo = null;

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function menu_visible(array $allowedKeys, string $key, string $role): bool {
  if ($role === 'مدير') return true;
  if ($key === 'logout') return true;
  return menu_allowed($allowedKeys, $key);
}
function format_post_dt(?string $dt): string {
  $dt = trim((string)$dt);
  return $dt === '' ? '—' : $dt;
}
function upload_post_image(string $field): ?string {
  if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
  $tmp = (string)$_FILES[$field]['tmp_name'];
  $size = (int)($_FILES[$field]['size'] ?? 0);
  if ($size <= 0 || $size > 5 * 1024 * 1024) throw new RuntimeException('حجم الصورة يجب ألا يزيد عن 5 ميجا.');

  $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
  $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
  if ($finfo) finfo_close($finfo);

  $extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
  ];
  if (!isset($extMap[$mime])) throw new RuntimeException('صيغة الصورة غير مدعومة.');

  $dirFs = __DIR__ . '/uploads/platform_posts';
  if (!is_dir($dirFs) && !@mkdir($dirFs, 0775, true) && !is_dir($dirFs)) {
    throw new RuntimeException('تعذر تجهيز مجلد رفع الصور.');
  }

  $filename = 'post_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
  $targetFs = $dirFs . '/' . $filename;
  if (!move_uploaded_file($tmp, $targetFs)) throw new RuntimeException('تعذر رفع الصورة.');

  return 'uploads/platform_posts/' . $filename;
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'مشرف');
$allowedMenuKeys = get_allowed_menu_keys($pdo, $adminId, $adminRole);
$success = null;
$error = null;

if (($_POST['action'] ?? '') === 'create_post') {
  $body = trim((string)($_POST['body'] ?? ''));
  try {
    $imagePath = upload_post_image('image');
    if ($body === '' && !$imagePath) {
      $error = 'أضف نصًا أو صورة على الأقل قبل نشر المنشور.';
    } else {
      $stmt = $pdo->prepare('INSERT INTO platform_posts (admin_id, body, image_path) VALUES (?, ?, ?)');
      $stmt->execute([$adminId, ($body !== '' ? $body : null), $imagePath]);
      header('Location: platform-posts.php?posted=1');
      exit;
    }
  } catch (Throwable $e) {
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'تعذر نشر المنشور الآن.';
  }
}

if (($_POST['action'] ?? '') === 'delete_post') {
  $postId = (int)($_POST['post_id'] ?? 0);
  if ($postId > 0) {
    try {
      $stmt = $pdo->prepare('DELETE FROM platform_posts WHERE id=? AND admin_id=?');
      $stmt->execute([$postId, $adminId]);
      header('Location: platform-posts.php?deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف المنشور.';
    }
  }
}

if (($_POST['action'] ?? '') === 'reply_comment') {
  $postId = (int)($_POST['post_id'] ?? 0);
  $commentId = (int)($_POST['comment_id'] ?? 0);
  $replyText = trim((string)($_POST['reply_text'] ?? ''));
  if ($postId <= 0 || $commentId <= 0 || $replyText === '') {
    $error = 'من فضلك اكتب رد الإدارة أولاً.';
  } else {
    try {
      $stmt = $pdo->prepare('SELECT id FROM platform_post_comments WHERE id=? AND post_id=? AND parent_comment_id IS NULL LIMIT 1');
      $stmt->execute([$commentId, $postId]);
      if (!$stmt->fetch()) {
        $error = 'التعليق المطلوب غير موجود.';
      } else {
        $stmt = $pdo->prepare('INSERT INTO platform_post_comments (post_id, admin_id, parent_comment_id, comment_text) VALUES (?, ?, ?, ?)');
        $stmt->execute([$postId, $adminId, $commentId, $replyText]);
        header('Location: platform-posts.php?replied=1#post-' . $postId);
        exit;
      }
    } catch (Throwable $e) {
      $error = 'تعذر حفظ الرد حالياً.';
    }
  }
}

if (($_POST['action'] ?? '') === 'delete_comment') {
  $commentId = (int)($_POST['comment_id'] ?? 0);
  if ($commentId > 0) {
    try {
      $stmt = $pdo->prepare('DELETE FROM platform_post_comments WHERE id=? LIMIT 1');
      $stmt->execute([$commentId]);
      header('Location: platform-posts.php?comment_deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف التعليق.';
    }
  }
}

if (isset($_GET['posted'])) $success = 'تم نشر المنشور بنجاح.';
if (isset($_GET['deleted'])) $success = 'تم حذف المنشور.';
if (isset($_GET['replied'])) $success = 'تم إرسال رد الإدارة.';
if (isset($_GET['comment_deleted'])) $success = 'تم حذف التعليق.';

$posts = $pdo->query(" 
  SELECT p.*, COALESCE(a.username, 'الإدارة') AS admin_name,
         (SELECT COUNT(*) FROM platform_post_reactions r WHERE r.post_id=p.id) AS reactions_total,
         (SELECT COUNT(*) FROM platform_post_comments c WHERE c.post_id=p.id AND c.parent_comment_id IS NULL) AS comments_total
  FROM platform_posts p
  LEFT JOIN admins a ON a.id = p.admin_id
  WHERE p.is_active = 1
  ORDER BY p.id DESC
  LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$postIds = array_values(array_map(fn($p) => (int)$p['id'], $posts));
$reactionSummary = [];
$commentsByPost = [];
$repliesByParent = [];
if ($postIds) {
  $in = implode(',', array_fill(0, count($postIds), '?'));

  $stmt = $pdo->prepare("SELECT post_id, reaction_type, COUNT(*) AS c FROM platform_post_reactions WHERE post_id IN ($in) GROUP BY post_id, reaction_type");
  $stmt->execute($postIds);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rowRx) {
    $pid = (int)$rowRx['post_id'];
    if (!isset($reactionSummary[$pid])) $reactionSummary[$pid] = [];
    $reactionSummary[$pid][(string)$rowRx['reaction_type']] = (int)$rowRx['c'];
  }

  $stmt = $pdo->prepare(" 
    SELECT c.*, s.full_name AS student_name, a.username AS admin_name
    FROM platform_post_comments c
    LEFT JOIN students s ON s.id = c.student_id
    LEFT JOIN admins a ON a.id = c.admin_id
    WHERE c.post_id IN ($in)
    ORDER BY c.id ASC
  ");
  $stmt->execute($postIds);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $commentRow) {
    $parentId = (int)($commentRow['parent_comment_id'] ?? 0);
    $postId = (int)$commentRow['post_id'];
    if ($parentId > 0) {
      if (!isset($repliesByParent[$parentId])) $repliesByParent[$parentId] = [];
      $repliesByParent[$parentId][] = $commentRow;
    } else {
      if (!isset($commentsByPost[$postId])) $commentsByPost[$postId] = [];
      $commentsByPost[$postId][] = $commentRow;
    }
  }
}

$menu = [
  ['key' => 'dashboard', 'label' => 'لوحة التحكم', 'icon' => '📊', 'href' => 'dashboard.php'],
  ['key' => 'users', 'label' => 'المستخدمين', 'icon' => '👤', 'href' => 'users.php'],
  ['key' => 'user_permissions', 'label' => 'صلاحيات المستخدمين', 'icon' => '🔐', 'href' => 'user-permissions.php'],
  ['key' => 'grades', 'label' => 'الصفوف الدراسية', 'icon' => '🏫', 'href' => 'grades.php'],
  ['key' => 'centers', 'label' => 'السناتر', 'icon' => '🏢', 'href' => 'centers.php'],
  ['key' => 'groups', 'label' => 'المجموعات', 'icon' => '👥', 'href' => 'groups.php'],
  ['key' => 'students', 'label' => 'الطلاب', 'icon' => '🧑‍🎓', 'href' => 'students.php'],
  ['key' => 'courses', 'label' => 'الكورسات', 'icon' => '📚', 'href' => 'courses.php'],
  ['key' => 'lectures', 'label' => 'المحاضرات', 'icon' => '🧑‍🏫', 'href' => 'lectures.php'],
  ['key' => 'videos', 'label' => 'الفيديوهات', 'icon' => '🎥', 'href' => 'videos.php'],
  ['key' => 'pdfs', 'label' => 'ملفات PDF', 'icon' => '📑', 'href' => 'pdfs.php'],
  ['key' => 'course_codes', 'label' => 'اكواد الكورسات', 'icon' => '🎟️', 'href' => 'course-codes.php'],
  ['key' => 'lecture_codes', 'label' => 'اكواد المحاضرات', 'icon' => '🧾', 'href' => 'lecture-codes.php'],
  ['key' => 'assignment_questions', 'label' => 'أسئلة الواجبات', 'icon' => '🗂️', 'href' => 'assignment-question-banks.php'],
  ['key' => 'assignments', 'label' => 'الواجبات', 'icon' => '📌', 'href' => 'assignments.php'],
  ['key' => 'exams', 'label' => 'الامتحانات', 'icon' => '🧠', 'href' => 'exams.php'],
  ['key' => 'exam_questions', 'label' => 'أسئلة الامتحانات', 'icon' => '❔', 'href' => 'exam-question-banks.php'],
  ['key' => 'student_notifications', 'label' => 'اشعارات الطلاب', 'icon' => '🔔', 'href' => 'student-notifications.php'],
  ['key' => 'attendance', 'label' => 'حضور الطلاب', 'icon' => '🧾', 'href' => 'attendance.php'],
  ['key' => 'facebook', 'label' => 'فيس بوك المنصة', 'icon' => '📘', 'href' => 'platform-posts.php', 'active' => true],
  ['key' => 'chat', 'label' => 'شات الطلاب', 'icon' => '💬', 'href' => 'student-conversations.php'],
  ['key' => 'settings', 'label' => 'الإعدادات', 'icon' => '⚙️', 'href' => 'settings.php'],
  ['key' => 'logout', 'label' => 'تسجيل الخروج', 'icon' => '🚪', 'href' => 'logout.php', 'danger' => true],
];
if ($adminRole !== 'مدير') {
  $menu = array_values(array_filter($menu, fn($item) => menu_visible($allowedMenuKeys, (string)($item['key'] ?? ''), $adminRole)));
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>فيس بوك المنصة - <?php echo h($platformName); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/centers.css">
  <style>
    .feed-grid{display:grid;gap:16px}
    .composer textarea,.reply-form textarea{min-height:120px;width:100%;border:1px solid var(--line);border-radius:16px;padding:14px;background:var(--panel);color:var(--text);font:inherit}
    .composer .form-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}
    .composer .form-row .fileWrap{display:grid;gap:8px}
    .feed-card{background:var(--panel);border:1px solid var(--line);border-radius:22px;padding:18px;box-shadow:0 12px 30px rgba(0,0,0,.08)}
    .feed-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .feed-meta{color:var(--muted);font-weight:900}
    .feed-body{margin-top:14px;line-height:2;font-weight:800;white-space:pre-wrap}
    .feed-image{margin-top:14px;border-radius:18px;overflow:hidden;border:1px solid var(--line)}
    .feed-image img{display:block;width:100%;max-height:420px;object-fit:cover}
    .feed-stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;color:var(--muted);font-weight:900}
    .rx-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(59,130,246,.08);border-radius:999px;padding:8px 12px}
    .comments{margin-top:18px;display:grid;gap:12px}
    .comment{padding:14px;border-radius:16px;background:rgba(15,23,42,.04);border:1px solid var(--line)}
    .comment__meta{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;color:var(--muted);font-weight:900;margin-bottom:8px}
    .reply{margin-top:10px;margin-right:18px;padding:12px;border-radius:14px;background:rgba(34,197,94,.08);border:1px dashed rgba(34,197,94,.35)}
    .reply-form{margin-top:12px;display:grid;gap:10px}
    .reply-actions,.post-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
    .feed-empty{padding:24px;border-radius:18px;border:1px dashed var(--line);color:var(--muted);font-weight:900;text-align:center}
  </style>
</head>
<body class="app" data-theme="auto">
  <div class="bg" aria-hidden="true"><div class="bg-grad"></div><div class="bg-noise"></div></div>
  <header class="topbar">
    <button class="burger" id="burger" type="button" aria-label="فتح القائمة">☰</button>
    <div class="brand">
      <?php if (!empty($logo)) : ?><img class="brand-logo" src="<?php echo h($logo); ?>" alt="Logo"><?php else: ?><div class="brand-fallback" aria-hidden="true"></div><?php endif; ?>
      <div class="brand-text"><div class="brand-name"><?php echo h($platformName); ?></div><div class="brand-sub">لوحة التحكم</div></div>
    </div>
    <div class="top-actions">
      <a class="back-btn" href="dashboard.php">🏠 الرجوع للوحة التحكم</a>
      <div class="theme-emoji" title="تبديل الوضع"><span class="emoji" aria-hidden="true">🌞</span><label class="emoji-switch"><input id="themeSwitch" type="checkbox" /><span class="emoji-slider" aria-hidden="true"></span></label><span class="emoji" aria-hidden="true">🌚</span></div>
    </div>
  </header>

  <div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="القائمة الجانبية">
      <div class="sidebar-head"><div class="sidebar-title">🧭 التنقل</div></div>
      <nav class="nav">
        <?php foreach ($menu as $item): ?>
          <?php $cls = 'nav-item'; if (!empty($item['active'])) $cls .= ' active'; if (!empty($item['danger'])) $cls .= ' danger'; ?>
          <a class="<?php echo $cls; ?>" href="<?php echo h((string)$item['href']); ?>"><span class="nav-icon" aria-hidden="true"><?php echo h((string)$item['icon']); ?></span><span class="nav-label"><?php echo h((string)$item['label']); ?></span></a>
        <?php endforeach; ?>
      </nav>
    </aside>

    <main class="main">
      <div class="page-head"><h1>📘 فيس بوك المنصة</h1><p class="muted">انشر كتابة فقط أو صورة فقط أو صورة مع كتابة، وراجع تفاعل الطلاب وتعليقاتهم والردود الرسمية.</p></div>
      <?php if ($success): ?><div class="alert success"><?php echo h($success); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert error"><?php echo h($error); ?></div><?php endif; ?>

      <section class="cardx composer">
        <div class="cardx-head"><div class="cardx-title"><span class="cardx-badge">➕</span><h3>منشور جديد</h3></div></div>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="create_post">
          <textarea name="body" placeholder="اكتب المنشور هنا... يمكنك ترك النص فارغًا إذا أردت نشر صورة فقط."></textarea>
          <div class="form-row">
            <div class="fileWrap">
              <label class="muted">صورة اختيارية</label>
              <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
            </div>
            <button class="btn" type="submit">🚀 نشر المنشور</button>
          </div>
        </form>
      </section>

      <section class="feed-grid" style="margin-top:16px;">
        <?php if (!$posts): ?>
          <div class="feed-empty">لا توجد منشورات بعد. ابدأ أول منشور الآن ليظهر للطلاب مباشرة.</div>
        <?php endif; ?>
        <?php foreach ($posts as $post): ?>
          <?php $pid = (int)$post['id']; $postImage = trim((string)($post['image_path'] ?? '')); ?>
          <article class="feed-card" id="post-<?php echo $pid; ?>">
            <div class="feed-head">
              <div>
                <div style="font-weight:1000;font-size:1.1rem;">👨‍🏫 <?php echo h((string)$post['admin_name']); ?></div>
                <div class="feed-meta">تم النشر: <?php echo h(format_post_dt((string)($post['created_at'] ?? ''))); ?></div>
              </div>
              <?php if ((int)($post['admin_id'] ?? 0) === $adminId): ?>
                <form method="post" onsubmit="return confirm('حذف هذا المنشور؟');">
                  <input type="hidden" name="action" value="delete_post">
                  <input type="hidden" name="post_id" value="<?php echo $pid; ?>">
                  <button class="btn danger" type="submit">🗑️ حذف المنشور</button>
                </form>
              <?php endif; ?>
            </div>

            <?php if (trim((string)($post['body'] ?? '')) !== ''): ?><div class="feed-body"><?php echo nl2br(h((string)$post['body'])); ?></div><?php endif; ?>
            <?php if ($postImage !== ''): ?><div class="feed-image"><img src="<?php echo h($postImage); ?>" alt="صورة المنشور"></div><?php endif; ?>

            <div class="feed-stats">
              <span class="rx-pill">❤️ إجمالي التفاعلات: <?php echo (int)($post['reactions_total'] ?? 0); ?></span>
              <span class="rx-pill">💬 التعليقات: <?php echo (int)($post['comments_total'] ?? 0); ?></span>
              <?php foreach (($reactionSummary[$pid] ?? []) as $reactionKey => $reactionCount): ?>
                <span class="rx-pill"><?php echo h((string)$reactionKey); ?>: <?php echo (int)$reactionCount; ?></span>
              <?php endforeach; ?>
            </div>

            <div class="comments">
              <?php foreach (($commentsByPost[$pid] ?? []) as $comment): ?>
                <?php $cid = (int)$comment['id']; $commentAuthor = trim((string)($comment['student_name'] ?? '')); ?>
                <div class="comment">
                  <div class="comment__meta">
                    <span>🧑‍🎓 <?php echo h($commentAuthor !== '' ? $commentAuthor : 'طالب'); ?></span>
                    <span><?php echo h(format_post_dt((string)($comment['created_at'] ?? ''))); ?></span>
                  </div>
                  <div><?php echo nl2br(h((string)$comment['comment_text'])); ?></div>
                  <div class="post-actions">
                    <form method="post" onsubmit="return confirm('حذف هذا التعليق؟');">
                      <input type="hidden" name="action" value="delete_comment">
                      <input type="hidden" name="comment_id" value="<?php echo $cid; ?>">
                      <button class="btn ghost" type="submit">🗑️ حذف تعليق الطالب</button>
                    </form>
                  </div>
                  <?php foreach (($repliesByParent[$cid] ?? []) as $reply): ?>
                    <div class="reply">
                      <div class="comment__meta"><span>👨‍💼 الإدارة: <?php echo h((string)($reply['admin_name'] ?? 'الإدارة')); ?></span><span><?php echo h(format_post_dt((string)($reply['created_at'] ?? ''))); ?></span></div>
                      <div><?php echo nl2br(h((string)$reply['comment_text'])); ?></div>
                    </div>
                  <?php endforeach; ?>
                  <form method="post" class="reply-form">
                    <input type="hidden" name="action" value="reply_comment">
                    <input type="hidden" name="post_id" value="<?php echo $pid; ?>">
                    <input type="hidden" name="comment_id" value="<?php echo $cid; ?>">
                    <textarea name="reply_text" placeholder="اكتب رد الإدارة على تعليق الطالب..."></textarea>
                    <div class="reply-actions"><button class="btn" type="submit">↩️ إرسال الرد</button></div>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    </main>
  </div>

  <div class="backdrop" id="backdrop" aria-hidden="true"></div>
  <script>
    (function () {
      const root = document.body;
      const themeSwitch = document.getElementById('themeSwitch');
      const stored = localStorage.getItem('admin_theme') || 'auto';
      function osPrefersDark() { return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches; }
      function applyTheme(mode) { root.setAttribute('data-theme', mode); localStorage.setItem('admin_theme', mode); if (themeSwitch) themeSwitch.checked = (mode === 'dark') || (mode === 'auto' && osPrefersDark()); }
      applyTheme(stored);
      themeSwitch && themeSwitch.addEventListener('change', () => applyTheme(themeSwitch.checked ? 'dark' : 'light'));
      if (stored === 'auto' && window.matchMedia) window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme('auto'));
      const burger = document.getElementById('burger');
      const sidebar = document.getElementById('sidebar');
      const backdrop = document.getElementById('backdrop');
      function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 980px)').matches; }
      function openSidebar() { if (!isMobile()) return; sidebar.classList.add('open'); backdrop.classList.add('show'); document.body.style.overflow = 'hidden'; }
      function closeSidebar() { if (!isMobile()) return; sidebar.classList.remove('open'); backdrop.classList.remove('show'); document.body.style.overflow = ''; }
      function syncInitial() { if (isMobile()) closeSidebar(); else { sidebar.classList.remove('open'); backdrop.classList.remove('show'); document.body.style.overflow = ''; } }
      syncInitial();
      burger && burger.addEventListener('click', (e) => { e.preventDefault(); if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar(); });
      backdrop && backdrop.addEventListener('click', closeSidebar);
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);
    })();
  </script>
</body>
</html>
