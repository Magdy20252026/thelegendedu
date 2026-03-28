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
function upload_chat_image(string $field): ?string {
  if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
  $tmp = (string)$_FILES[$field]['tmp_name'];
  $size = (int)($_FILES[$field]['size'] ?? 0);
  if ($size <= 0 || $size > 5 * 1024 * 1024) throw new RuntimeException('صورة الحساب يجب ألا تزيد عن 5 ميجا.');
  $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
  $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
  if ($finfo) finfo_close($finfo);
  $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  if (!isset($extMap[$mime])) throw new RuntimeException('صيغة صورة الحساب غير مدعومة.');
  $dirFs = __DIR__ . '/uploads/chat_profiles';
  if (!is_dir($dirFs) && !@mkdir($dirFs, 0775, true) && !is_dir($dirFs)) throw new RuntimeException('تعذر تجهيز مجلد الصور.');
  $filename = 'chat_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
  $targetFs = $dirFs . '/' . $filename;
  if (!move_uploaded_file($tmp, $targetFs)) throw new RuntimeException('تعذر حفظ الصورة.');
  return 'uploads/chat_profiles/' . $filename;
}
$reactionTypes = [
  'like'  => '👍 إعجاب',
  'love'  => '❤️ حب',
  'care'  => '🤗 دعم',
  'wow'   => '😮 واو',
  'haha'  => '😂 هاها',
  'sad'   => '😢 حزين',
  'angry' => '😡 غاضب',
];

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'مشرف');
$adminUsername = (string)($_SESSION['admin_username'] ?? 'الإدارة');
$allowedMenuKeys = get_allowed_menu_keys($pdo, $adminId, $adminRole);
$success = null;
$error = null;

$pdo->prepare('INSERT INTO admin_chat_profiles (admin_id, display_name, is_online) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE display_name = COALESCE(display_name, VALUES(display_name))')
  ->execute([$adminId, $adminUsername]);

if (($_POST['action'] ?? '') === 'save_profile') {
  $displayName = trim((string)($_POST['display_name'] ?? ''));
  $isOnline = isset($_POST['is_online']) ? 1 : 0;
  if ($displayName === '') $displayName = $adminUsername;
  try {
    $imagePath = upload_chat_image('profile_image');
    if ($imagePath) {
      $stmt = $pdo->prepare('UPDATE admin_chat_profiles SET display_name=?, image_path=?, is_online=? WHERE admin_id=?');
      $stmt->execute([$displayName, $imagePath, $isOnline, $adminId]);
    } else {
      $stmt = $pdo->prepare('UPDATE admin_chat_profiles SET display_name=?, is_online=? WHERE admin_id=?');
      $stmt->execute([$displayName, $isOnline, $adminId]);
    }
    header('Location: student-conversations.php?profile_saved=1');
    exit;
  } catch (Throwable $e) {
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'تعذر حفظ بيانات الشات.';
  }
}

if (($_POST['action'] ?? '') === 'send_message') {
  $conversationId = (int)($_POST['conversation_id'] ?? 0);
  $message = trim((string)($_POST['message_text'] ?? ''));
  if ($conversationId <= 0 || $message === '') {
    $error = 'اكتب الرسالة أولاً.';
  } else {
    try {
      $stmt = $pdo->prepare('SELECT id FROM student_chat_conversations WHERE id=? AND admin_id=? LIMIT 1');
      $stmt->execute([$conversationId, $adminId]);
      if (!$stmt->fetch()) {
        $error = 'المحادثة غير متاحة لك.';
      } else {
        $stmt = $pdo->prepare('INSERT INTO student_chat_messages (conversation_id, sender_type, sender_id, message_text, is_read) VALUES (?, ?, ?, ?, 0)');
        $stmt->execute([$conversationId, 'admin', $adminId, $message]);
        $pdo->prepare('UPDATE student_chat_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);
        $pdo->prepare("UPDATE student_chat_messages SET is_read=1 WHERE conversation_id=? AND sender_type='student'")->execute([$conversationId]);
        header('Location: student-conversations.php?chat_id=' . $conversationId . '&sent=1');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'تعذر إرسال الرسالة.';
    }
  }
}

if (isset($_GET['profile_saved'])) $success = 'تم تحديث ملف الشات الخاص بك.';
if (isset($_GET['sent'])) $success = 'تم إرسال الرسالة.';

$profile = ['display_name' => $adminUsername, 'image_path' => null, 'is_online' => 0];
$conversations = [];
$chatMessageReactions = [];
try {
  $profileStmt = $pdo->prepare('SELECT * FROM admin_chat_profiles WHERE admin_id=? LIMIT 1');
  $profileStmt->execute([$adminId]);
  $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: $profile;

  $conversationsStmt = $pdo->prepare(" 
    SELECT c.id, c.student_id, c.updated_at, s.full_name, s.student_phone, s.grade_id, COALESCE(g.name, 'بدون صف') AS grade_name,
           (SELECT message_text FROM student_chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
           (SELECT COUNT(*) FROM student_chat_messages m2 WHERE m2.conversation_id=c.id AND m2.sender_type='student' AND m2.is_read=0) AS unread_count
    FROM student_chat_conversations c
    INNER JOIN students s ON s.id = c.student_id
    LEFT JOIN grades g ON g.id = s.grade_id
    WHERE c.admin_id = ?
    ORDER BY c.updated_at DESC, c.id DESC
  ");
  $conversationsStmt->execute([$adminId]);
  $conversations = $conversationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $error = $error ?: 'تعذر تحميل محادثات الطلاب حالياً.';
}

$selectedChatId = (int)($_GET['chat_id'] ?? 0);
if ($selectedChatId <= 0 && !empty($conversations)) $selectedChatId = (int)$conversations[0]['id'];

$chatMeta = null;
$messages = [];
if ($selectedChatId > 0) {
  try {
    $stmt = $pdo->prepare(" 
      SELECT c.*, s.full_name, s.student_phone, COALESCE(g.name, 'بدون صف') AS grade_name
      FROM student_chat_conversations c
      INNER JOIN students s ON s.id = c.student_id
      LEFT JOIN grades g ON g.id = s.grade_id
      WHERE c.id=? AND c.admin_id=?
      LIMIT 1
    ");
    $stmt->execute([$selectedChatId, $adminId]);
    $chatMeta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($chatMeta) {
      $pdo->prepare("UPDATE student_chat_messages SET is_read=1 WHERE conversation_id=? AND sender_type='student'")->execute([$selectedChatId]);
      $stmt = $pdo->prepare('SELECT * FROM student_chat_messages WHERE conversation_id=? ORDER BY id ASC');
      $stmt->execute([$selectedChatId]);
      $messages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $adminMessageIds = array_values(array_map(fn($row) => (int)$row['id'], array_filter($messages, fn($row) => (string)($row['sender_type'] ?? '') === 'admin')));
      if ($adminMessageIds) {
        $in = implode(',', array_fill(0, count($adminMessageIds), '?'));
        $params = array_merge([(int)$chatMeta['student_id']], $adminMessageIds);
        $stmt = $pdo->prepare("SELECT message_id, reaction_type FROM student_chat_message_reactions WHERE student_id=? AND message_id IN ($in)");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $reactionRow) {
          $chatMessageReactions[(int)$reactionRow['message_id']] = (string)$reactionRow['reaction_type'];
        }
      }
    }
  } catch (Throwable $e) {
    $error = $error ?: 'تعذر تحميل تفاصيل المحادثة الآن.';
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
  ['key' => 'facebook', 'label' => 'فيس بوك المنصة', 'icon' => '📘', 'href' => 'platform-posts.php'],
  ['key' => 'chat', 'label' => 'شات الطلاب', 'icon' => '💬', 'href' => 'student-conversations.php', 'active' => true],
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
  <title>شات الطلاب - <?php echo h($platformName); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/centers.css">
  <style>
    .chat-layout{display:grid;grid-template-columns:330px 1fr;gap:16px}
    .card-lite{background:var(--panel);border:1px solid var(--line);border-radius:22px;padding:18px;box-shadow:0 10px 24px rgba(0,0,0,.08)}
    .profile-grid{display:grid;gap:12px}
    .profile-grid input,.profile-grid textarea{width:100%;border:1px solid var(--line);border-radius:14px;padding:12px;background:var(--panel);color:var(--text);font:inherit}
    .conv-list{display:grid;gap:12px;margin-top:12px}
    .conv-item{display:block;text-decoration:none;color:inherit;padding:14px;border-radius:16px;border:1px solid var(--line);background:rgba(15,23,42,.04)}
    .conv-item.is-active{border-color:var(--brand);background:rgba(59,130,246,.08)}
    .conv-meta{color:var(--muted);font-weight:900;font-size:.92rem}
    .unread-pill{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;border-radius:999px;background:#ef4444;color:#fff;font-size:.85rem;font-weight:1000;padding:0 10px}
    .messages{display:grid;gap:12px;max-height:560px;overflow:auto;padding-left:4px}
    .msg{max-width:82%;padding:14px 16px;border-radius:18px;line-height:1.9;font-weight:800;border:1px solid var(--line)}
    .msg.student{background:rgba(59,130,246,.08);margin-right:auto}
    .msg.admin{background:rgba(34,197,94,.08);margin-left:auto}
    .msg small{display:block;margin-top:6px;color:var(--muted)}
    .msg-reaction{display:inline-flex;align-items:center;gap:6px;margin-top:10px;padding:8px 12px;border-radius:999px;background:rgba(59,130,246,.08);font-weight:900;color:var(--text)}
    .send-box{margin-top:14px;display:grid;gap:10px}
    .send-box textarea{min-height:110px;width:100%;border:1px solid var(--line);border-radius:16px;padding:14px;background:var(--panel);color:var(--text);font:inherit}
    .chat-empty{padding:22px;border-radius:18px;border:1px dashed var(--line);color:var(--muted);font-weight:900;text-align:center}
    @media (max-width:980px){.chat-layout{grid-template-columns:1fr;}}
  </style>
</head>
<body class="app" data-theme="auto">
<div class="bg" aria-hidden="true"><div class="bg-grad"></div><div class="bg-noise"></div></div>
<header class="topbar">
  <button class="burger" id="burger" type="button" aria-label="فتح القائمة">☰</button>
  <div class="brand"><?php if (!empty($logo)) : ?><img class="brand-logo" src="<?php echo h($logo); ?>" alt="Logo"><?php else: ?><div class="brand-fallback" aria-hidden="true"></div><?php endif; ?><div class="brand-text"><div class="brand-name"><?php echo h($platformName); ?></div><div class="brand-sub">لوحة التحكم</div></div></div>
  <div class="top-actions"><a class="back-btn" href="dashboard.php">🏠 الرجوع للوحة التحكم</a><div class="theme-emoji" title="تبديل الوضع"><span class="emoji" aria-hidden="true">🌞</span><label class="emoji-switch"><input id="themeSwitch" type="checkbox" /><span class="emoji-slider" aria-hidden="true"></span></label><span class="emoji" aria-hidden="true">🌚</span></div></div>
</header>
<div class="layout">
  <aside class="sidebar" id="sidebar" aria-label="القائمة الجانبية"><div class="sidebar-head"><div class="sidebar-title">🧭 التنقل</div></div><nav class="nav"><?php foreach ($menu as $item): ?><?php $cls = 'nav-item'; if (!empty($item['active'])) $cls .= ' active'; if (!empty($item['danger'])) $cls .= ' danger'; ?><a class="<?php echo $cls; ?>" href="<?php echo h((string)$item['href']); ?>"><span class="nav-icon"><?php echo h((string)$item['icon']); ?></span><span class="nav-label"><?php echo h((string)$item['label']); ?></span></a><?php endforeach; ?></nav></aside>
  <main class="main">
    <div class="page-head"><h1>💬 شات الطلاب</h1><p class="muted">فعّل وضع الأونلاين، حدّث اسمك وصورتك، ثم رد على رسائل الطلاب النصية.</p></div>
    <?php if ($success): ?><div class="alert success"><?php echo h($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?php echo h($error); ?></div><?php endif; ?>

    <div class="chat-layout">
      <div class="card-lite">
        <h3 style="margin-top:0;">⚙️ إعدادات الشات</h3>
        <form method="post" enctype="multipart/form-data" class="profile-grid">
          <input type="hidden" name="action" value="save_profile">
          <div><label>اسم الظهور</label><input type="text" name="display_name" value="<?php echo h((string)($profile['display_name'] ?? $adminUsername)); ?>" placeholder="مثال: مستر محمد"></div>
          <div><label>الصورة</label><input type="file" name="profile_image" accept="image/png,image/jpeg,image/webp,image/gif"></div>
          <?php if (!empty($profile['image_path'])): ?><div><img src="<?php echo h((string)$profile['image_path']); ?>" alt="صورة الحساب" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:1px solid var(--line);"></div><?php endif; ?>
          <label style="display:flex;align-items:center;gap:10px;font-weight:900;"><input type="checkbox" name="is_online" value="1" <?php echo !empty($profile['is_online']) ? 'checked' : ''; ?>> تفعيل وضع أونلاين للطلاب</label>
          <button class="btn" type="submit">💾 حفظ إعدادات الشات</button>
        </form>

        <h3 style="margin:20px 0 8px;">📨 محادثاتك</h3>
        <div class="conv-list">
          <?php if (!$conversations): ?><div class="chat-empty">لا توجد محادثات حتى الآن.</div><?php endif; ?>
          <?php foreach ($conversations as $conv): ?>
            <?php $isActive = ((int)$conv['id'] === $selectedChatId); ?>
            <a class="conv-item <?php echo $isActive ? 'is-active' : ''; ?>" href="student-conversations.php?chat_id=<?php echo (int)$conv['id']; ?>">
              <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                <div>
                  <div style="font-weight:1000;">🧑‍🎓 <?php echo h((string)$conv['full_name']); ?></div>
                  <div class="conv-meta">🏫 <?php echo h((string)$conv['grade_name']); ?> • 📱 <?php echo h((string)$conv['student_phone']); ?></div>
                </div>
                <?php if (!empty($conv['unread_count'])): ?><span class="unread-pill"><?php echo (int)$conv['unread_count']; ?></span><?php endif; ?>
              </div>
              <div class="conv-meta" style="margin-top:8px;"><?php echo h((string)($conv['last_message'] ?: 'ابدأ الرد الآن')); ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card-lite">
        <?php if (!$chatMeta): ?>
          <div class="chat-empty">اختر محادثة من القائمة لعرض الرسائل والرد على الطالب.</div>
        <?php else: ?>
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start; margin-bottom:14px;">
            <div>
              <h3 style="margin:0;">🧑‍🎓 <?php echo h((string)$chatMeta['full_name']); ?></h3>
              <div class="conv-meta">🏫 <?php echo h((string)$chatMeta['grade_name']); ?> • 📱 <?php echo h((string)$chatMeta['student_phone']); ?></div>
            </div>
            <div class="conv-meta">آخر تحديث: <?php echo h((string)$chatMeta['updated_at']); ?></div>
          </div>
          <div class="messages">
            <?php foreach ($messages as $message): ?>
              <?php $senderType = (string)($message['sender_type'] ?? 'student'); $messageReaction = $chatMessageReactions[(int)($message['id'] ?? 0)] ?? null; ?>
              <div class="msg <?php echo $senderType === 'admin' ? 'admin' : 'student'; ?>">
                <div><?php echo nl2br(h((string)$message['message_text'])); ?></div>
                <small><?php echo $senderType === 'admin' ? '👨‍🏫 أنت' : '🧑‍🎓 الطالب'; ?> — <?php echo h((string)$message['created_at']); ?></small>
                <?php if ($senderType === 'admin' && $messageReaction && isset($reactionTypes[$messageReaction])): ?>
                  <div class="msg-reaction">تفاعل الطالب: <?php echo h((string)$reactionTypes[$messageReaction]); ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="send-box">
            <input type="hidden" name="action" value="send_message">
            <input type="hidden" name="conversation_id" value="<?php echo (int)$chatMeta['id']; ?>">
            <textarea name="message_text" placeholder="اكتب رد الإدارة هنا..."></textarea>
            <button class="btn" type="submit">📤 إرسال الرد</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<div class="backdrop" id="backdrop" aria-hidden="true"></div>
<script>
(function(){const root=document.body,themeSwitch=document.getElementById('themeSwitch'),stored=localStorage.getItem('admin_theme')||'auto';function osPrefersDark(){return window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches}function applyTheme(mode){root.setAttribute('data-theme',mode);localStorage.setItem('admin_theme',mode);if(themeSwitch)themeSwitch.checked=(mode==='dark')||(mode==='auto'&&osPrefersDark())}applyTheme(stored);themeSwitch&&themeSwitch.addEventListener('change',()=>applyTheme(themeSwitch.checked?'dark':'light'));if(stored==='auto'&&window.matchMedia)window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',()=>applyTheme('auto'));const burger=document.getElementById('burger'),sidebar=document.getElementById('sidebar'),backdrop=document.getElementById('backdrop');function isMobile(){return window.matchMedia&&window.matchMedia('(max-width: 980px)').matches}function openSidebar(){if(!isMobile())return;sidebar.classList.add('open');backdrop.classList.add('show');document.body.style.overflow='hidden'}function closeSidebar(){if(!isMobile())return;sidebar.classList.remove('open');backdrop.classList.remove('show');document.body.style.overflow=''}function syncInitial(){if(isMobile())closeSidebar();else{sidebar.classList.remove('open');backdrop.classList.remove('show');document.body.style.overflow=''}}syncInitial();burger&&burger.addEventListener('click',(e)=>{e.preventDefault();if(sidebar.classList.contains('open'))closeSidebar();else openSidebar();});backdrop&&backdrop.addEventListener('click',closeSidebar);window.addEventListener('keydown',(e)=>{if(e.key==='Escape')closeSidebar();});window.addEventListener('resize',syncInitial);})();
</script>
</body>
</html>
