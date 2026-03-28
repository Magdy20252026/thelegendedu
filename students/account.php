<?php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require __DIR__ . '/inc/student_auth.php';
require_once __DIR__ . '/inc/wallet_transactions.php';
require_once __DIR__ . '/inc/assessments.php';
require __DIR__ . '/inc/platform_features.php';

no_cache_headers();
student_require_login();
platform_features_ensure_tables($pdo);

if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function normalize_phone(string $p): string {
  $p = trim((string)$p);
  return (string)preg_replace('/[^\d\+]/', '', $p);
}

function is_arabic_name_3plus(string $name): bool {
  $name = trim((string)preg_replace('/\s+/u', ' ', $name));
  if ($name === '') return false;
  if (!preg_match('/^[\p{Arabic}\s]+$/u', $name)) return false;
  $parts = array_values(array_filter(explode(' ', $name), fn($p) => trim($p) !== ''));
  return count($parts) >= 3;
}

function fmt_dt(?string $dt): string {
  $dt = trim((string)$dt);
  if ($dt === '') return '';
  return $dt;
}

/* محافظات مصر */
$governorates = [
  'القاهرة','الجيزة','الإسكندرية','الدقهلية','البحر الأحمر','البحيرة','الفيوم','الغربية',
  'الإسماعيلية','المنوفية','المنيا','القليوبية','الوادي الجديد','السويس','اسوان','اسيوط',
  'بني سويف','بورسعيد','دمياط','الشرقية','جنوب سيناء','كفر الشيخ','مطروح','الأقصر',
  'قنا','شمال سيناء','سوهاج'
];

/* platform settings */
$row = get_platform_settings_row($pdo);
$platformName = trim((string)($row['platform_name'] ?? 'منصتي التعليمية'));
if ($platformName === '') $platformName = 'منصتي التعليمية';

$logoDb = trim((string)($row['platform_logo'] ?? ''));
$logoUrl = null;
if ($logoDb !== '') $logoUrl = student_public_asset_url($logoDb);

/* footer data */
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
$studentStatus = (string)($student['status'] ?? 'اونلاين');
$studentGradeId = (int)($student['grade_id'] ?? 0);
$isOnline = ($studentStatus === 'اونلاين');

/* ✅ Auto-enroll free courses so they appear in "كورساتك" */
try {
  $pdo->prepare("
    INSERT IGNORE INTO student_course_enrollments (student_id, course_id, access_type)
    SELECT ?, c.id, 'free'
    FROM courses c
    WHERE c.access_type = 'free'
      AND c.grade_id = ?
  ")->execute([$studentId, $studentGradeId]);
} catch (Throwable $e) { /* non-fatal */ }

/* navigation */
$page = (string)($_GET['page'] ?? 'home');
$allowedPages = ['home','settings','platform_courses','my_courses','wallet','notifications','assignments','exams','facebook','chat'];
if (!in_array($page, $allowedPages, true)) $page = 'home';

/* sidebar items */
$sidebar = [
  ['key'=>'home', 'label'=>'الصفحه الرئيسية', 'icon'=>'🏠', 'href'=>'account.php?page=home'],

  ['key'=>'platform_courses', 'label'=>'كورسات المنصة', 'icon'=>'📚', 'href'=>'account.php?page=platform_courses'],
  ['key'=>'my_courses', 'label'=>'كورساتك', 'icon'=>'🎓', 'href'=>'account.php?page=my_courses'],

  ['key'=>'assignments', 'label'=>'الواجبات', 'icon'=>'📝', 'href'=>'account.php?page=assignments'],
  ['key'=>'exams', 'label'=>'الامتحانات', 'icon'=>'🧠', 'href'=>'account.php?page=exams'],
  ['key'=>'notifications', 'label'=>'اشعارات الطلاب', 'icon'=>'🔔', 'href'=>'account.php?page=notifications'],
  ['key'=>'facebook', 'label'=>'فيسبوك المنصة', 'icon'=>'📘', 'href'=>'account.php?page=facebook'],
  ['key'=>'chat', 'label'=>'شات', 'icon'=>'💬', 'href'=>'account.php?page=chat'],
  ['key'=>'wallet', 'label'=>'المحفظة', 'icon'=>'💳', 'href'=>'account.php?page=wallet'],

  ['key'=>'settings', 'label'=>'إعدادات الحساب', 'icon'=>'⚙️', 'href'=>'account.php?page=settings'],

  ['key'=>'logout', 'label'=>'تسجيل الخروج', 'icon'=>'🚪', 'href'=>'logout.php', 'danger'=>true],
];

/* grades list for settings */
$gradesList = [];
try {
  $gradesList = $pdo->query("SELECT id, name FROM grades WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $gradesList = []; }

/* handle settings update */
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_profile') {
  $fullName = trim((string)($_POST['full_name'] ?? ''));
  $governorate = trim((string)($_POST['governorate'] ?? ''));
  $studentPhone = normalize_phone((string)($_POST['student_phone'] ?? ''));
  $parentPhone = normalize_phone((string)($_POST['parent_phone'] ?? ''));
  $gradeId = (int)($_POST['grade_id'] ?? 0);

  $newPass = (string)($_POST['new_password'] ?? '');
  $newPass2 = (string)($_POST['new_password2'] ?? '');

  if (!is_arabic_name_3plus($fullName)) {
    $error = 'اسم الطالب يجب أن يكون ثلاثي (3 كلمات أو أكثر) وباللغة العربية.';
  } elseif ($studentPhone === '') {
    $error = 'رقم الهاتف مطلوب.';
  } elseif ($governorate === '' || !in_array($governorate, $governorates, true)) {
    $error = 'من فضلك اختر المحافظة.';
  } elseif ($gradeId <= 0) {
    $error = 'من فضلك اختر الصف الدراسي.';
  } elseif ($newPass !== '' && $newPass !== $newPass2) {
    $error = 'كلمة السر الجديدة وتأكيدها غير متطابقين.';
  } else {
    try {
      $chk = $pdo->prepare("SELECT id FROM grades WHERE id=? AND is_active=1 LIMIT 1");
      $chk->execute([$gradeId]);
      if (!$chk->fetch()) $error = 'الصف الدراسي غير موجود.';
    } catch (Throwable $e) {
      $error = 'حدث خطأ أثناء التحقق من الصف الدراسي.';
    }
  }

  if (!$error) {
    try {
      $stmt = $pdo->prepare("SELECT id FROM students WHERE student_phone=? AND id<>? LIMIT 1");
      $stmt->execute([$studentPhone, $studentId]);
      if ($stmt->fetch()) $error = 'رقم الهاتف مسجل لطالب آخر.';
    } catch (Throwable $e) {
      $error = 'تعذر التحقق من رقم الهاتف.';
    }
  }

  if (!$error) {
    try {
      if ($newPass !== '') {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $up = $pdo->prepare("
          UPDATE students
          SET full_name=?,
              governorate=?,
              student_phone=?,
              parent_phone=?,
              grade_id=?,
              password_hash=?,
              password_plain=?
          WHERE id=?
        ");
        $up->execute([
          $fullName,
          $governorate,
          $studentPhone,
          ($parentPhone !== '' ? $parentPhone : null),
          $gradeId,
          $hash,
          $newPass,
          $studentId
        ]);
      } else {
        $up = $pdo->prepare("
          UPDATE students
          SET full_name=?,
              governorate=?,
              student_phone=?,
              parent_phone=?,
              grade_id=?
          WHERE id=?
        ");
        $up->execute([
          $fullName,
          $governorate,
          $studentPhone,
          ($parentPhone !== '' ? $parentPhone : null),
          $gradeId,
          $studentId
        ]);
      }

      $_SESSION['student_name'] = $fullName;

      header('Location: account.php?page=settings&saved=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حفظ بيانات الحساب.';
    }
  }
}

if (isset($_GET['saved'])) $success = 'تم حفظ بيانات الحساب بنجاح.';

$reactionTypes = [
  'like'  => '👍 إعجاب',
  'love'  => '❤️ حب',
  'care'  => '🤗 دعم',
  'wow'   => '😮 واو',
  'haha'  => '😂 هاها',
  'sad'   => '😢 حزين',
  'angry' => '😡 غاضب',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'react_post') {
    $postId = (int)($_POST['post_id'] ?? 0);
    $reactionType = (string)($_POST['reaction_type'] ?? 'like');
    if ($postId <= 0 || !isset($reactionTypes[$reactionType])) {
      $error = 'التفاعل المطلوب غير صالح.';
    } else {
      try {
        $stmt = $pdo->prepare("INSERT INTO platform_post_reactions (post_id, student_id, reaction_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reaction_type=VALUES(reaction_type), updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$postId, $studentId, $reactionType]);
        header('Location: account.php?page=facebook#post-' . $postId);
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر تسجيل التفاعل الآن.';
      }
    }
  } elseif ($action === 'clear_post_reaction') {
    $postId = (int)($_POST['post_id'] ?? 0);
    if ($postId > 0) {
      try {
        $stmt = $pdo->prepare("DELETE FROM platform_post_reactions WHERE post_id=? AND student_id=?");
        $stmt->execute([$postId, $studentId]);
        header('Location: account.php?page=facebook#post-' . $postId);
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر حذف التفاعل الآن.';
      }
    }
  } elseif ($action === 'comment_post') {
    $postId = (int)($_POST['post_id'] ?? 0);
    $commentText = trim((string)($_POST['comment_text'] ?? ''));
    if ($postId <= 0 || $commentText === '') {
      $error = 'اكتب التعليق أولاً.';
    } else {
      try {
        $stmt = $pdo->prepare("INSERT INTO platform_post_comments (post_id, student_id, comment_text) VALUES (?, ?, ?)");
        $stmt->execute([$postId, $studentId, $commentText]);
        header('Location: account.php?page=facebook#post-' . $postId);
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر إضافة التعليق حالياً.';
      }
    }
  } elseif ($action === 'start_chat') {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    $messageText = trim((string)($_POST['message_text'] ?? ''));
    if ($adminId <= 0 || $messageText === '') {
      $error = 'اختر شخصًا من الإدارة واكتب الرسالة الأولى.';
    } else {
      try {
        $stmt = $pdo->prepare("
          SELECT a.id
          FROM admins a
          INNER JOIN admin_chat_profiles p ON p.admin_id = a.id
          WHERE a.id = ? AND a.is_active = 1 AND p.is_online = 1
          LIMIT 1
        ");
        $stmt->execute([$adminId]);
        $onlineAdmin = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$onlineAdmin) {
          $error = 'لا يمكن بدء المحادثة إلا مع شخص من الإدارة في وضع أونلاين.';
        } else {
          $stmt = $pdo->prepare("
            INSERT INTO student_chat_conversations (student_id, admin_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP, id = LAST_INSERT_ID(id)
          ");
          $stmt->execute([$studentId, $adminId]);
          $conversationId = (int)$pdo->lastInsertId();
          $stmt = $pdo->prepare("INSERT INTO student_chat_messages (conversation_id, sender_type, sender_id, message_text, is_read) VALUES (?, 'student', ?, ?, 0)");
          $stmt->execute([$conversationId, $studentId, $messageText]);
          $pdo->prepare("UPDATE student_chat_conversations SET updated_at = NOW() WHERE id=?")->execute([$conversationId]);
          header('Location: account.php?page=chat&chat_id=' . $conversationId);
          exit;
        }
      } catch (Throwable $e) {
        $error = 'تعذر بدء المحادثة الآن.';
      }
    }
  } elseif ($action === 'reply_chat') {
    $conversationId = (int)($_POST['conversation_id'] ?? 0);
    $messageText = trim((string)($_POST['message_text'] ?? ''));
    if ($conversationId <= 0 || $messageText === '') {
      $error = 'اكتب الرسالة أولاً.';
    } else {
      try {
        $stmt = $pdo->prepare("SELECT id FROM student_chat_conversations WHERE id=? AND student_id=? LIMIT 1");
        $stmt->execute([$conversationId, $studentId]);
        if (!$stmt->fetch()) {
          $error = 'المحادثة غير متاحة لك.';
        } else {
          $stmt = $pdo->prepare("INSERT INTO student_chat_messages (conversation_id, sender_type, sender_id, message_text, is_read) VALUES (?, 'student', ?, ?, 0)");
          $stmt->execute([$conversationId, $studentId, $messageText]);
          $pdo->prepare("UPDATE student_chat_conversations SET updated_at = NOW() WHERE id=?")->execute([$conversationId]);
          header('Location: account.php?page=chat&chat_id=' . $conversationId);
          exit;
        }
      } catch (Throwable $e) {
        $error = 'تعذر إرسال الرسالة حالياً.';
      }
    }
  } elseif ($action === 'react_chat_message' || $action === 'clear_chat_message_reaction') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    $reactionType = (string)($_POST['reaction_type'] ?? 'like');
    if ($messageId <= 0) {
      $error = 'رسالة الإدارة غير متاحة للتفاعل.';
    } elseif ($action === 'react_chat_message' && !isset($reactionTypes[$reactionType])) {
      $error = 'التفاعل المطلوب غير صالح.';
    } else {
      try {
        $stmt = $pdo->prepare("
          SELECT m.id, c.id AS conversation_id
          FROM student_chat_messages m
          INNER JOIN student_chat_conversations c ON c.id = m.conversation_id
          WHERE m.id = ? AND c.student_id = ? AND m.sender_type = 'admin'
          LIMIT 1
        ");
        $stmt->execute([$messageId, $studentId]);
        $chatMessageRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$chatMessageRow) {
          $error = 'يمكنك التفاعل فقط مع ردود الإدارة داخل محادثاتك.';
        } else {
          $conversationId = (int)$chatMessageRow['conversation_id'];
          if ($action === 'react_chat_message') {
            $stmt = $pdo->prepare("
              INSERT INTO student_chat_message_reactions (message_id, student_id, reaction_type)
              VALUES (?, ?, ?) AS new_reaction
              ON DUPLICATE KEY UPDATE reaction_type = new_reaction.reaction_type, updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$messageId, $studentId, $reactionType]);
          } else {
            $stmt = $pdo->prepare("DELETE FROM student_chat_message_reactions WHERE message_id = ? AND student_id = ?");
            $stmt->execute([$messageId, $studentId]);
          }
          header('Location: account.php?page=chat&chat_id=' . $conversationId . '#chat-message-' . $messageId);
          exit;
        }
      } catch (Throwable $e) {
        $error = 'تعذر حفظ التفاعل على رد الإدارة حالياً.';
      }
    }
  }
}

/* =========================
   Platform courses (NOT enrolled)
   ========================= */
$platformCourses = [];
try {
  if ($isOnline) {
    $stmt = $pdo->prepare("
      SELECT
        c.*,
        gr.name AS grade_name
      FROM courses c
      INNER JOIN grades gr ON gr.id = c.grade_id
      LEFT JOIN student_course_enrollments e
        ON e.course_id = c.id AND e.student_id = ?
      WHERE e.id IS NULL
        AND c.grade_id = ?
        AND c.access_type != 'attendance'
      ORDER BY c.id DESC
    ");
  } else {
    $stmt = $pdo->prepare("
      SELECT
        c.*,
        gr.name AS grade_name
      FROM courses c
      INNER JOIN grades gr ON gr.id = c.grade_id
      LEFT JOIN student_course_enrollments e
        ON e.course_id = c.id AND e.student_id = ?
      WHERE e.id IS NULL
        AND c.grade_id = ?
      ORDER BY c.id DESC
    ");
  }
  $stmt->execute([$studentId, $studentGradeId]);
  $platformCourses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $platformCourses = [];
}

/* =========================
   ✅ My courses (enrolled)
   ========================= */
$myCourses = [];
try {
  $stmt = $pdo->prepare("
    SELECT
      c.*,
      gr.name AS grade_name,
      e.access_type AS enroll_access_type,
      e.created_at AS enrolled_at
    FROM student_course_enrollments e
    INNER JOIN courses c ON c.id = e.course_id
    INNER JOIN grades gr ON gr.id = c.grade_id
    WHERE e.student_id = ?
      AND c.grade_id = ?
    ORDER BY e.id DESC
  ");
  $stmt->execute([$studentId, $studentGradeId]);
  $myCourses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $myCourses = [];
}

/* Wallet history */
$walletHistory = [];
$walletSummary = [
  'credits'   => 0.0,
  'purchases' => 0.0,
];
try {
  wallet_transactions_ensure_table($pdo);

  /* Mirrors the wallet purchase pricing rules to estimate old course purchases that predate transaction logging. */
  $stmt = $pdo->prepare("
    SELECT *
    FROM (
      SELECT
        wt.id AS source_id,
        0 AS source_rank,
        wt.created_at,
        wt.transaction_type,
        wt.amount,
        wt.description,
        c.name AS course_name,
        l.name AS lecture_name
      FROM wallet_transactions wt
      LEFT JOIN courses c ON c.id = wt.related_course_id
      LEFT JOIN lectures l ON l.id = wt.related_lecture_id
      WHERE wt.student_id = ?

      UNION ALL

      SELECT
        sle.id AS source_id,
        1 AS source_rank,
        sle.created_at,
        'legacy_lecture_purchase' AS transaction_type,
        COALESCE(sle.paid_amount, l.price, 0) AS amount,
        NULL AS description,
        c.name AS course_name,
        l.name AS lecture_name
      FROM student_lecture_enrollments sle
      LEFT JOIN courses c ON c.id = sle.course_id
      LEFT JOIN lectures l ON l.id = sle.lecture_id
      WHERE sle.student_id = ?
        AND sle.access_type = 'wallet'
        AND NOT EXISTS (
          SELECT 1
          FROM wallet_transactions wt2
          WHERE wt2.student_id = sle.student_id
            AND wt2.reference_type = 'lecture_enrollment'
            AND wt2.reference_id = sle.id
        )

      UNION ALL

      SELECT
        sce.id AS source_id,
        2 AS source_rank,
        sce.created_at,
        'legacy_course_purchase' AS transaction_type,
        CASE
          WHEN c.buy_type = 'discount'
           AND c.price_discount IS NOT NULL
           AND c.price_discount > 0
           AND (c.discount_end IS NULL OR DATE_ADD(c.discount_end, INTERVAL 1 DAY) > sce.created_at)
            THEN c.price_discount
          ELSE COALESCE(c.price, c.price_base, 0)
        END AS amount,
        NULL AS description,
        c.name AS course_name,
        NULL AS lecture_name
      FROM student_course_enrollments sce
      LEFT JOIN courses c ON c.id = sce.course_id
      WHERE sce.student_id = ?
        AND sce.access_type = 'buy'
        AND NOT EXISTS (
          SELECT 1
          FROM wallet_transactions wt3
          WHERE wt3.student_id = sce.student_id
            AND wt3.reference_type = 'course_enrollment'
            AND wt3.reference_id = sce.id
        )
    ) wallet_rows
    ORDER BY created_at DESC, source_rank ASC, source_id DESC
  ");
  $stmt->execute([$studentId, $studentId, $studentId]);
  $walletRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($walletRows as $row) {
    $type = (string)($row['transaction_type'] ?? '');
    $amount = (float)($row['amount'] ?? 0);
    $courseName = trim((string)($row['course_name'] ?? ''));
    $lectureName = trim((string)($row['lecture_name'] ?? ''));

    $label = 'عملية على المحفظة';
    $details = trim((string)($row['description'] ?? ''));
    $amountPrefix = '-';
    $amountClass = 'is-negative';

    if ($type === 'credit') {
      $label = 'إضافة رصيد';
      $details = ($details !== '' ? $details : 'تمت إضافة رصيد إلى محفظتك.');
      $amountPrefix = '+';
      $amountClass = 'is-positive';
      $walletSummary['credits'] += $amount;
    } elseif ($type === 'debit') {
      $label = 'خصم رصيد';
      $details = ($details !== '' ? $details : 'تم خصم مبلغ من محفظتك.');
    } elseif (in_array($type, ['lecture_purchase', 'legacy_lecture_purchase'], true)) {
      $label = 'شراء محاضرة';
      $details = $lectureName !== '' ? $lectureName : 'محاضرة بالمحفظة';
      if ($courseName !== '') $details .= ' — ' . $courseName;
      $walletSummary['purchases'] += $amount;
    } elseif (in_array($type, ['course_purchase', 'legacy_course_purchase'], true)) {
      $label = 'شراء كورس';
      $details = $courseName !== '' ? $courseName : 'كورس بالمحفظة';
      $walletSummary['purchases'] += $amount;
    }

    $walletHistory[] = [
      'label'        => $label,
      'details'      => $details,
      'created_at'   => fmt_dt((string)($row['created_at'] ?? '')),
      'amount_text'  => $amountPrefix . number_format($amount, 2) . ' جنيه',
      'amount_class' => $amountClass,
    ];
  }
} catch (Throwable $e) {
  $walletHistory = [];
}

$studentNotifications = [];
$studentUnreadNotifications = 0;
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS student_notifications (
      id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      grade_id INT(10) UNSIGNED NOT NULL,
      title VARCHAR(190) NOT NULL,
      body LONGTEXT NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_by_admin_id INT(10) UNSIGNED DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_grade (grade_id),
      KEY idx_active (is_active),
      CONSTRAINT fk_student_notifications_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS student_notification_reads (
      id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      student_id INT(10) UNSIGNED NOT NULL,
      notification_id INT(10) UNSIGNED NOT NULL,
      read_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_student_notification (student_id, notification_id),
      KEY idx_student_id (student_id),
      KEY idx_notification_id (notification_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  $stmt = $pdo->prepare("
    SELECT
      n.id,
      n.title,
      n.body,
      n.created_at,
      CASE WHEN r.id IS NULL THEN 0 ELSE 1 END AS is_read
    FROM student_notifications n
    LEFT JOIN student_notification_reads r
      ON r.notification_id = n.id AND r.student_id = ?
    WHERE n.grade_id = ? AND n.is_active = 1
    ORDER BY n.id DESC
    LIMIT 50
  ");
  $stmt->execute([$studentId, (int)$student['grade_id']]);
  $studentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($studentNotifications as $rowNotif) {
    if (empty($rowNotif['is_read'])) $studentUnreadNotifications++;
  }
} catch (Throwable $e) {
  $studentNotifications = [];
  $studentUnreadNotifications = 0;
}

$platformPosts = [];
$platformPostReactionSummary = [];
$platformCommentsByPost = [];
$platformRepliesByParent = [];
$studentPostReactions = [];
try {
  $platformPosts = $pdo->query("
    SELECT p.*, COALESCE(a.username, 'الإدارة') AS admin_name,
           (SELECT COUNT(*) FROM platform_post_reactions r WHERE r.post_id = p.id) AS reactions_total,
           (SELECT COUNT(*) FROM platform_post_comments c WHERE c.post_id = p.id AND c.parent_comment_id IS NULL) AS comments_total
    FROM platform_posts p
    LEFT JOIN admins a ON a.id = p.admin_id
    WHERE p.is_active = 1
    ORDER BY p.id DESC
    LIMIT 30
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $postIds = array_values(array_map(fn($post) => (int)$post['id'], $platformPosts));
  if ($postIds) {
    $in = implode(',', array_fill(0, count($postIds), '?'));

    $stmt = $pdo->prepare("
      SELECT post_id, reaction_type, COUNT(*) AS c
      FROM platform_post_reactions
      WHERE post_id IN ($in)
      GROUP BY post_id, reaction_type
    ");
    $stmt->execute($postIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $reactionRow) {
      $pid = (int)$reactionRow['post_id'];
      if (!isset($platformPostReactionSummary[$pid])) $platformPostReactionSummary[$pid] = [];
      $platformPostReactionSummary[$pid][(string)$reactionRow['reaction_type']] = (int)$reactionRow['c'];
    }

    $stmt = $pdo->prepare("
      SELECT post_id, reaction_type
      FROM platform_post_reactions
      WHERE post_id IN ($in) AND student_id = ?
    ");
    $params = $postIds;
    $params[] = $studentId;
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $studentReactionRow) {
      $studentPostReactions[(int)$studentReactionRow['post_id']] = (string)$studentReactionRow['reaction_type'];
    }

    $stmt = $pdo->prepare("
      SELECT c.*, s.full_name AS student_name, COALESCE(p.display_name, a.username, 'الإدارة') AS admin_name
      FROM platform_post_comments c
      LEFT JOIN students s ON s.id = c.student_id
      LEFT JOIN admins a ON a.id = c.admin_id
      LEFT JOIN admin_chat_profiles p ON p.admin_id = c.admin_id
      WHERE c.post_id IN ($in)
      ORDER BY c.id ASC
    ");
    $stmt->execute($postIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $commentRow) {
      $parentId = (int)($commentRow['parent_comment_id'] ?? 0);
      $postId = (int)$commentRow['post_id'];
      if ($parentId > 0) {
        if (!isset($platformRepliesByParent[$parentId])) $platformRepliesByParent[$parentId] = [];
        $platformRepliesByParent[$parentId][] = $commentRow;
      } else {
        if (!isset($platformCommentsByPost[$postId])) $platformCommentsByPost[$postId] = [];
        $platformCommentsByPost[$postId][] = $commentRow;
      }
    }
  }
} catch (Throwable $e) {
  $platformPosts = [];
  $platformPostReactionSummary = [];
  $platformCommentsByPost = [];
  $platformRepliesByParent = [];
  $studentPostReactions = [];
}

$onlineAdmins = [];
$studentConversations = [];
$selectedConversationId = (int)($_GET['chat_id'] ?? 0);
$selectedConversation = null;
$conversationMessages = [];
$chatMessageReactions = [];
try {
  $onlineAdmins = $pdo->query("
    SELECT a.id, COALESCE(p.display_name, a.username) AS display_name, p.image_path, p.updated_at
    FROM admins a
    INNER JOIN admin_chat_profiles p ON p.admin_id = a.id
    WHERE a.is_active = 1 AND p.is_online = 1
    ORDER BY p.updated_at DESC, a.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $stmt = $pdo->prepare("
    SELECT c.id, c.updated_at, c.admin_id,
           COALESCE(p.display_name, a.username) AS display_name,
           p.image_path,
           (SELECT message_text FROM student_chat_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
           (SELECT COUNT(*) FROM student_chat_messages m2 WHERE m2.conversation_id = c.id AND m2.sender_type = 'admin' AND m2.is_read = 0) AS unread_count
    FROM student_chat_conversations c
    INNER JOIN admins a ON a.id = c.admin_id
    LEFT JOIN admin_chat_profiles p ON p.admin_id = a.id
    WHERE c.student_id = ?
    ORDER BY c.updated_at DESC, c.id DESC
  ");
  $stmt->execute([$studentId]);
  $studentConversations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if ($selectedConversationId <= 0 && !empty($studentConversations)) {
    $selectedConversationId = (int)$studentConversations[0]['id'];
  }

  if ($selectedConversationId > 0) {
    $stmt = $pdo->prepare("
      SELECT c.*, COALESCE(p.display_name, a.username) AS display_name, p.image_path, p.is_online
      FROM student_chat_conversations c
      INNER JOIN admins a ON a.id = c.admin_id
      LEFT JOIN admin_chat_profiles p ON p.admin_id = a.id
      WHERE c.id = ? AND c.student_id = ?
      LIMIT 1
    ");
    $stmt->execute([$selectedConversationId, $studentId]);
    $selectedConversation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedConversation) {
      $pdo->prepare("UPDATE student_chat_messages SET is_read = 1 WHERE conversation_id=? AND sender_type='admin'")->execute([$selectedConversationId]);
      $stmt = $pdo->prepare("SELECT * FROM student_chat_messages WHERE conversation_id=? ORDER BY id ASC");
      $stmt->execute([$selectedConversationId]);
      $conversationMessages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $adminMessageIds = array_values(array_map(fn($row) => (int)$row['id'], array_filter($conversationMessages, fn($row) => (string)($row['sender_type'] ?? '') === 'admin')));
      if ($adminMessageIds) {
        $in = implode(',', array_fill(0, count($adminMessageIds), '?'));
        $stmt = $pdo->prepare("SELECT message_id, reaction_type FROM student_chat_message_reactions WHERE student_id = ? AND message_id IN ($in)");
        $stmt->execute(array_merge([$studentId], $adminMessageIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $reactionRow) {
          $chatMessageReactions[(int)$reactionRow['message_id']] = (string)$reactionRow['reaction_type'];
        }
      }
    }
  }
} catch (Throwable $e) {
  $onlineAdmins = [];
  $studentConversations = [];
  $selectedConversation = null;
  $conversationMessages = [];
  $chatMessageReactions = [];
}

$assignmentCards = [];
$examCards = [];
try {
  $assignmentCards = student_assessment_fetch_cards($pdo, $studentId, (int)$student['grade_id'], 'assignment');
} catch (Throwable $e) {
  $assignmentCards = [];
}

try {
  $examCards = student_assessment_fetch_cards($pdo, $studentId, (int)$student['grade_id'], 'exam');
} catch (Throwable $e) {
  $examCards = [];
}

$assignmentSummary = student_assessment_cards_summary($assignmentCards);
$examSummary = student_assessment_cards_summary($examCards);

/* ✅ NEW: attach last content update per course (lecture/video/pdf created_at) */
$courseLastUpdateMap = [];
$allCoursesForMap = array_merge($platformCourses, $myCourses);

if (!empty($allCoursesForMap)) {
  $courseIds = array_values(array_unique(array_map(fn($c) => (int)($c['id'] ?? 0), $allCoursesForMap)));
  $courseIds = array_values(array_filter($courseIds, fn($id) => $id > 0));

  if (!empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    try {
      $stmt = $pdo->prepare("
        SELECT course_id, MAX(dt) AS last_dt
        FROM (
          SELECT course_id, created_at AS dt FROM lectures WHERE course_id IN ($placeholders)
          UNION ALL
          SELECT course_id, created_at AS dt FROM videos  WHERE course_id IN ($placeholders)
          UNION ALL
          SELECT course_id, created_at AS dt FROM pdfs   WHERE course_id IN ($placeholders)
        ) x
        GROUP BY course_id
      ");
      $stmt->execute(array_merge($courseIds, $courseIds, $courseIds));
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $cid = (int)($r['course_id'] ?? 0);
        if ($cid > 0) $courseLastUpdateMap[$cid] = (string)($r['last_dt'] ?? '');
      }
    } catch (Throwable $e) {
      $courseLastUpdateMap = [];
    }
  }
}

/* ✅ Compute real stats now that courses lists are loaded */
$stats = [
  ['label' => 'كورسات المنصة', 'value' => count($platformCourses), 'icon' => '📚'],
  ['label' => 'كورساتك',       'value' => count($myCourses),        'icon' => '🎓'],
  ['label' => 'رصيد المحفظة',  'value' => number_format($wallet, 2), 'icon' => '💳'],
];

// count total lectures available in enrolled courses
$totalLectures = 0;
$totalVideos   = 0;
$totalPdfs     = 0;
if (!empty($myCourses)) {
  $enrolledCourseIds = array_values(array_filter(
    array_map(fn($c) => (int)($c['id'] ?? 0), $myCourses),
    fn($id) => $id > 0
  ));
  if (!empty($enrolledCourseIds)) {
    $ph = implode(',', array_fill(0, count($enrolledCourseIds), '?'));
    try {
      $s = $pdo->prepare("SELECT COUNT(*) FROM lectures WHERE course_id IN ($ph)");
      $s->execute($enrolledCourseIds);
      $totalLectures = (int)$s->fetchColumn();

      $s = $pdo->prepare("SELECT COUNT(*) FROM videos WHERE course_id IN ($ph)");
      $s->execute($enrolledCourseIds);
      $totalVideos = (int)$s->fetchColumn();

      $s = $pdo->prepare("SELECT COUNT(*) FROM pdfs WHERE course_id IN ($ph)");
      $s->execute($enrolledCourseIds);
      $totalPdfs = (int)$s->fetchColumn();
    } catch (Throwable $e) {}
  }
}
$stats[] = ['label' => 'المحاضرات المتاحة لك', 'value' => $totalLectures, 'icon' => '🧑‍🏫'];
$stats[] = ['label' => 'الفيديوهات المتاحة لك', 'value' => $totalVideos,   'icon' => '🎥'];
$stats[] = ['label' => 'ملفات PDF المتاحة لك',  'value' => $totalPdfs,     'icon' => '📑'];
$stats[] = ['label' => 'الامتحانات المتاحة', 'value' => $examSummary['available_count'], 'icon' => '🧠'];
$stats[] = ['label' => 'الواجبات المتاحة', 'value' => $assignmentSummary['available_count'], 'icon' => '📝'];
$stats[] = ['label' => 'إجمالي درجات الامتحانات', 'value' => $examSummary['score_text'], 'icon' => '🏆'];
$stats[] = ['label' => 'إجمالي درجات الواجبات', 'value' => $assignmentSummary['score_text'], 'icon' => '📊'];

/* ✅ cache-bust for account.css */
$cssVer = (string)@filemtime(__DIR__ . '/assets/css/account.css');
if ($cssVer === '' || $cssVer === '0') $cssVer = (string)time();
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

  <style>
    .acc-topbar{ position: static !important; top: auto !important; }
    @media (max-width: 980px){
      :root{ --acc-topbar-h: 118px !important; }
      .acc-topbar__bar{ flex-wrap: wrap !important; gap:10px !important; align-items: stretch !important; }
      .acc-topbar__right{ width:100% !important; justify-content: space-between !important; order:1 !important; }
      .acc-topbar__left{ width:100% !important; justify-content: space-between !important; order:2 !important; gap:8px !important; }
      .acc-layout{ grid-template-columns: 1fr !important; }
      .acc-sidebar{
        position: fixed !important;
        top: calc(var(--acc-topbar-h) + 10px) !important;
        right: 16px !important;
        left: 16px !important;
        height: auto !important;
        max-height: 72vh !important;
        overflow:auto !important;
        opacity: 0 !important;
        pointer-events:none !important;
        transform: translateY(-10px) !important;
        transition: .18s ease !important;
        z-index: 9997 !important;
      }
      .acc-sidebar.is-open{ opacity:1 !important; pointer-events:auto !important; transform: translateY(0) !important; }
      .acc-stats__grid{ grid-template-columns: repeat(2, minmax(0,1fr)) !important; }
      .acc-grid{ grid-template-columns: 1fr !important; }
    }
    @media (max-width: 560px){
      .acc-stats__grid{ grid-template-columns: 1fr !important; }
      .acc-brand__name{ display:none !important; }
    }
    .acc-actionsRow{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
    .acc-btnx{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;font-weight:900;text-decoration:none;cursor:pointer;border:none;font-family:inherit;font-size:1em}
    .acc-btnx--solid{background:var(--btn-solid-bg);color:var(--btn-solid-text)}
    .acc-btnx--ghost{background:transparent;border:2px solid var(--text);color:var(--text)}
    /* Stats grid */
    .acc-stats{margin:20px 0}
    .acc-stats__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .acc-stat{background:var(--card-bg,#fff);border:1px solid var(--border,#e2e8f0);border-radius:16px;padding:18px 14px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.05)}
    .acc-stat__ico{font-size:2em;margin-bottom:6px}
    .acc-stat__val{font-size:1.7em;font-weight:900;color:var(--accent,#0b63ce)}
    .acc-stat__lbl{font-size:.85em;color:var(--muted,#666);margin-top:4px;font-weight:700}
    .acc-pill--link{text-decoration:none;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease}
    .acc-pill--link:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,0,0,.08)}
    .wallet-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}
    .wallet-summary__card{background:var(--card-bg,#fff);border:1px solid var(--border,#e2e8f0);border-radius:16px;padding:16px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
    .wallet-summary__label{color:var(--muted,#666);font-size:.9em;font-weight:800}
    .wallet-summary__value{margin-top:10px;font-size:1.45em;font-weight:1000;color:var(--accent,#0b63ce)}
    .wallet-history{display:grid;gap:12px}
    .wallet-history__item{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px;border:1px solid var(--border,#e2e8f0);border-radius:16px;background:var(--card-bg,#fff);box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .wallet-history__title{font-size:1.05em;font-weight:1000}
    .wallet-history__meta{margin-top:6px;color:var(--muted,#666);font-size:.92em;font-weight:700;line-height:1.8}
    .wallet-history__amount{white-space:nowrap;font-weight:1000;font-size:1.05em}
    .wallet-history__amount.is-positive{color:#157347}
    .wallet-history__amount.is-negative{color:#b42318}
    @media (max-width: 980px){ .wallet-summary{grid-template-columns:repeat(2,minmax(0,1fr));} }
    @media (max-width: 640px){
      .wallet-summary{grid-template-columns:1fr;}
      .wallet-history__item{flex-direction:column;align-items:flex-start}
      .wallet-history__amount{white-space:normal}
    }
    .acc-assess-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .acc-assess-card{background:var(--card-bg);border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:0 12px 24px rgba(0,0,0,.06)}
    .acc-assess-card__head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .acc-assess-card__title{font-size:1.05rem;font-weight:1000}
    .acc-assess-card__meta{margin-top:12px;display:grid;gap:8px;color:var(--muted);font-weight:900;line-height:1.8}
    .acc-assess-card__meta span{color:var(--text)}
    .acc-assess-card__actions{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
    .acc-assess-empty{padding:18px;border:1px dashed var(--border);border-radius:16px;color:var(--muted);font-weight:900;line-height:1.9;background:rgba(0,0,0,.02)}
    .acc-assess-summary{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
    .acc-feed{display:grid;gap:16px}
    .acc-feedCard,.acc-chatCard{background:var(--card-bg);border:1px solid var(--border);border-radius:18px;padding:18px;box-shadow:0 12px 24px rgba(0,0,0,.06)}
    .acc-feedHead{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .acc-feedBody{margin-top:14px;line-height:2;white-space:pre-wrap;font-weight:800}
    .acc-feedImage{margin-top:14px;border-radius:16px;overflow:hidden;border:1px solid var(--border)}
    .acc-feedImage img{display:block;width:100%;max-height:420px;object-fit:cover}
    .acc-feedStats{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
    .acc-reactions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
    .acc-reactionBtn{border:none;border-radius:999px;padding:10px 14px;font:inherit;font-weight:1000;cursor:pointer;background:rgba(59,130,246,.1);color:var(--text)}
    .acc-reactionBtn.is-active{background:rgba(34,197,94,.15);color:#166534}
    .acc-commentForm textarea,.acc-chatSend textarea{width:100%;min-height:100px;border:1px solid var(--border);border-radius:16px;padding:14px;background:var(--card-bg);color:var(--text);font:inherit}
    .acc-comments{display:grid;gap:12px;margin-top:14px}
    .acc-comment{padding:14px;border-radius:16px;background:rgba(15,23,42,.04);border:1px solid var(--border)}
    .acc-commentReply{margin-top:10px;margin-right:18px;padding:12px;border-radius:14px;background:rgba(34,197,94,.08);border:1px dashed rgba(34,197,94,.35)}
    .acc-chatLayout{display:grid;grid-template-columns:320px 1fr;gap:16px}
    .acc-chatSidebar{display:grid;gap:16px}
    .acc-chatList{display:grid;gap:12px}
    .acc-chatItem{display:block;text-decoration:none;color:inherit;padding:14px;border-radius:16px;border:1px solid var(--border);background:rgba(15,23,42,.04)}
    .acc-chatItem.is-active{border-color:var(--accent);background:rgba(59,130,246,.08)}
    .acc-chatMsgs{display:grid;gap:12px;max-height:520px;overflow:auto}
    .acc-chatMsg{max-width:82%;padding:14px 16px;border-radius:18px;border:1px solid var(--border);font-weight:800;line-height:1.9}
    .acc-chatMsg--student{background:rgba(59,130,246,.08);margin-right:auto}
    .acc-chatMsg--admin{background:rgba(34,197,94,.08);margin-left:auto}
    .acc-chatMeta{color:var(--muted);font-weight:900;font-size:.92rem}
    .acc-unread{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;border-radius:999px;background:#ef4444;color:#fff;padding:0 10px;font-size:.85rem;font-weight:1000}
    .acc-emptyBox{padding:18px;border:1px dashed var(--border);border-radius:16px;color:var(--muted);font-weight:900;line-height:1.9;background:rgba(0,0,0,.02)}
    .acc-onlinePill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:8px 12px;background:rgba(34,197,94,.12);font-weight:1000;color:#166534}
    .acc-avatar{width:46px;height:46px;border-radius:50%;object-fit:cover;border:1px solid var(--border)}
    .acc-chatUser{display:flex;gap:10px;align-items:center}
    .acc-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:rgba(59,130,246,.08);font-weight:1000}
    .acc-commentForm{margin-top:12px;display:grid;gap:10px}
    .acc-chatSend{margin-top:14px;display:grid;gap:10px}
    .acc-chatReactions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .acc-chatReactionForm{display:inline-flex}
    @media (max-width: 980px){ .acc-assess-grid{grid-template-columns:1fr;} .acc-chatLayout{grid-template-columns:1fr;} }
  </style>

  <title>حساب الطالب - <?php echo h($platformName); ?></title>
</head>
<body>

<header class="acc-topbar" role="banner">
  <div class="container">
    <div class="acc-topbar__bar">

      <div class="acc-topbar__right">
        <a class="acc-brand" href="account.php?page=home" aria-label="<?php echo h($platformName); ?>">
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
        <button class="acc-burger" id="accBurger" type="button" aria-label="فتح القائمة">☰</button>

        <div class="acc-student" title="<?php echo h($studentName); ?>">
          <span aria-hidden="true">👤</span>
          <span class="acc-student__name"><?php echo h($studentName); ?></span>
        </div>

        <a class="acc-pill acc-pill--link" href="account.php?page=wallet" title="فتح سجل المحفظة">
          <span aria-hidden="true">💳</span>
          <span><?php echo number_format($wallet, 2); ?> جنيه</span>
        </a>

        <button class="acc-bell" type="button" id="btnBell" aria-label="الإشعارات" title="الإشعارات">
          🔔
          <span class="acc-bell__badge" id="bellBadge" style="display:none;">0</span>
        </button>
      </div>

    </div>
  </div>
</header>

<div class="acc-backdrop" id="accBackdrop" aria-hidden="true"></div>

<div class="acc-notifs" id="notifsBox" aria-hidden="true">
  <div class="acc-notifs__head">
    <div class="acc-notifs__title">🔔 إشعارات الصف</div>
    <button class="acc-notifs__close" type="button" id="closeNotifs">✖</button>
  </div>
  <div class="acc-notifs__body" id="notifsBody">
    <div class="acc-notifs__loading">جارٍ التحميل...</div>
  </div>
</div>

<div class="acc-layout">
  <aside class="acc-sidebar" id="accSidebar" aria-label="القائمة الجانبية">
    <nav class="acc-nav">
      <?php foreach ($sidebar as $it): ?>
        <?php
          $isActive = false;
          if (($it['key'] ?? '') === 'home' && $page === 'home') $isActive = true;
          if (($it['key'] ?? '') === 'settings' && $page === 'settings') $isActive = true;
          if (($it['key'] ?? '') === 'platform_courses' && $page === 'platform_courses') $isActive = true;
          if (($it['key'] ?? '') === 'my_courses' && $page === 'my_courses') $isActive = true;
          if (($it['key'] ?? '') === 'assignments' && $page === 'assignments') $isActive = true;
          if (($it['key'] ?? '') === 'exams' && $page === 'exams') $isActive = true;
          if (($it['key'] ?? '') === 'notifications' && $page === 'notifications') $isActive = true;
          if (($it['key'] ?? '') === 'facebook' && $page === 'facebook') $isActive = true;
          if (($it['key'] ?? '') === 'chat' && $page === 'chat') $isActive = true;
          if (($it['key'] ?? '') === 'wallet' && $page === 'wallet') $isActive = true;

          $cls = 'acc-nav__item';
          if ($isActive) $cls .= ' is-active';
          if (!empty($it['danger'])) $cls .= ' is-danger';
          if (!empty($it['disabled'])) $cls .= ' is-disabled';
        ?>

        <?php if (!empty($it['disabled'])): ?>
          <span class="<?php echo $cls; ?>" title="يبرمج لاحقًا">
            <span aria-hidden="true"><?php echo h((string)$it['icon']); ?></span>
            <span class="acc-nav__lbl"><?php echo h((string)$it['label']); ?></span>
            <span class="acc-nav__soon">قريبًا</span>
          </span>
        <?php else: ?>
          <a class="<?php echo $cls; ?>" href="<?php echo h((string)$it['href']); ?>">
            <span aria-hidden="true"><?php echo h((string)$it['icon']); ?></span>
            <span class="acc-nav__lbl"><?php echo h((string)$it['label']); ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </aside>

  <main class="acc-main">
    <div class="container">

      <?php if ($success): ?>
        <div class="acc-alert acc-alert--success" role="alert"><?php echo h($success); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="acc-alert acc-alert--error" role="alert"><?php echo h($error); ?></div>
      <?php endif; ?>

      <?php if ($page === 'home'): ?>
        <section class="acc-hero">
          <h1>👋 أهلاً <?php echo h($studentName); ?></h1>
          <p style="margin-top:6px;color:var(--muted);font-weight:700;">مرحباً بك في حسابك على المنصة.</p>
        </section>

        <section class="acc-stats" aria-label="إحصائيات">
          <div class="acc-stats__grid">
            <?php foreach ($stats as $st): ?>
              <div class="acc-stat">
                <div class="acc-stat__ico" aria-hidden="true"><?php echo h((string)$st['icon']); ?></div>
                <div class="acc-stat__val"><?php echo is_numeric($st['value']) ? (int)$st['value'] : h((string)$st['value']); ?></div>
                <div class="acc-stat__lbl"><?php echo h((string)$st['label']); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

      <?php elseif ($page === 'platform_courses'): ?>
        <section class="acc-card" aria-label="كورسات المنصة">
          <div class="acc-card__head">
            <h2>📚 كورسات المنصة</h2>
          </div>

          <?php if (empty($platformCourses)): ?>
            <div style="font-weight:900;color:var(--muted);line-height:1.9;">
              لا توجد كورسات متاحة حالياً (أو أنت مشترك في كل الكورسات).
            </div>
          <?php else: ?>
            <div class="acc-courses-grid">
              <?php foreach ($platformCourses as $c): ?>
                <?php
                  $accessType = (string)($c['access_type'] ?? 'attendance');
                  $buyType = (string)($c['buy_type'] ?? 'none');

                  $isFree = ($accessType === 'free');
                  $isBuy = ($accessType === 'buy');
                  $isDiscount = ($isBuy && $buyType === 'discount');

                  $priceBase = $c['price_base'];
                  $priceDiscount = $c['price_discount'];
                  $discountEnd = (string)($c['discount_end'] ?? '');

                  $imgDb = trim((string)($c['image_path'] ?? ''));
                  $imgUrl = null;
                  if ($imgDb !== '') $imgUrl = student_public_asset_url($imgDb);

                  $details = trim((string)($c['details'] ?? ''));
                  $courseLast = (string)($courseLastUpdateMap[(int)$c['id']] ?? '');
                ?>

                <article class="acc-course">
                  <div class="acc-course__cover">
                    <?php if ($imgUrl): ?>
                      <img class="acc-course__img" src="<?php echo h($imgUrl); ?>" alt="<?php echo h((string)$c['name']); ?>">
                    <?php else: ?>
                      <div class="acc-course__imgFallback">📚</div>
                    <?php endif; ?>
                  </div>

                  <div class="acc-course__body">
                    <div class="acc-course__head">
                      <div class="acc-course__title"><?php echo h((string)$c['name']); ?></div>
                      <div class="acc-course__grade">🏫 <?php echo h((string)$c['grade_name']); ?></div>
                    </div>

                    <div class="acc-course__details">
                      <?php if ($details !== ''): ?>
                        <?php echo nl2br(h($details)); ?>
                      <?php else: ?>
                        <span style="color:var(--muted);font-weight:900;">بدون تفاصيل.</span>
                      <?php endif; ?>
                    </div>

                    <div class="acc-course__meta">
                      <div class="acc-metaRow">
                        🧩 آخر تحديث داخل الكورس:
                        <span><?php echo h($courseLast !== '' ? $courseLast : 'لا يوجد محتوى بعد'); ?></span>
                      </div>
                    </div>

                    <div class="acc-course__pricing">
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

                    <div class="acc-course__actions">
                      <a class="acc-btn acc-btn--ghost" href="account_course.php?course_id=<?php echo (int)$c['id']; ?>">📑 تفاصيل الكورس</a>
                      <a class="acc-btn" href="account_course.php?course_id=<?php echo (int)$c['id']; ?>">🛒 شراء / تفعيل</a>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($page === 'my_courses'): ?>
        <section class="acc-card" aria-label="كورساتك">
          <div class="acc-card__head">
            <h2>🎓 كورساتك</h2>
          </div>

          <?php if (empty($myCourses)): ?>
            <div style="font-weight:900;color:var(--muted);line-height:1.9;">
              أنت غير مشترك في أي كورس حتى الآن.
            </div>
          <?php else: ?>
            <div class="acc-courses-grid">
              <?php foreach ($myCourses as $c): ?>
                <?php
                  $imgDb = trim((string)($c['image_path'] ?? ''));
                  $imgUrl = null;
                  if ($imgDb !== '') $imgUrl = student_public_asset_url($imgDb);

                  $details = trim((string)($c['details'] ?? ''));
                  $courseLast = (string)($courseLastUpdateMap[(int)$c['id']] ?? '');
                  $enrollType = (string)($c['enroll_access_type'] ?? '');
                  $enrolledAt = (string)($c['enrolled_at'] ?? '');
                ?>

                <article class="acc-course">
                  <div class="acc-course__cover">
                    <?php if ($imgUrl): ?>
                      <img class="acc-course__img" src="<?php echo h($imgUrl); ?>" alt="<?php echo h((string)$c['name']); ?>">
                    <?php else: ?>
                      <div class="acc-course__imgFallback">🎓</div>
                    <?php endif; ?>
                  </div>

                  <div class="acc-course__body">
                    <div class="acc-course__head">
                      <div class="acc-course__title"><?php echo h((string)$c['name']); ?></div>
                      <div class="acc-course__grade">🏫 <?php echo h((string)$c['grade_name']); ?></div>
                    </div>

                    <div class="acc-course__meta">
                      <div class="acc-metaRow">✅ نوع الاشتراك: <b><?php echo h($enrollType); ?></b></div>
                      <?php if ($enrolledAt !== ''): ?>
                        <div class="acc-metaRow">🗓️ تاريخ الاشتراك: <span><?php echo h($enrolledAt); ?></span></div>
                      <?php endif; ?>
                      <div class="acc-metaRow">🧩 آخر تحديث داخل الكورس: <span><?php echo h($courseLast !== '' ? $courseLast : '—'); ?></span></div>
                    </div>

                    <div class="acc-course__details">
                      <?php if ($details !== ''): ?>
                        <?php echo nl2br(h($details)); ?>
                      <?php else: ?>
                        <span style="color:var(--muted);font-weight:900;">بدون تفاصيل.</span>
                      <?php endif; ?>
                    </div>

                    <div class="acc-course__actions">
                      <a class="acc-btn" href="account_course.php?course_id=<?php echo (int)$c['id']; ?>">▶️ دخول الكورس</a>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($page === 'assignments'): ?>
        <section class="acc-card" aria-label="الواجبات">
          <div class="acc-card__head">
            <h2>📝 الواجبات</h2>
            <p>هنا تظهر الواجبات المضافة للصف الدراسي الخاص بك مع إمكانية البدء أو استكمال الحل أو مراجعة النتيجة لاحقًا.</p>
          </div>

          <div class="acc-assess-summary">
            <span class="acc-badge acc-badge--att">🟢 المتاح الآن: <?php echo (int)$assignmentSummary['available_count']; ?></span>
            <span class="acc-badge acc-badge--buy">📊 إجمالي درجات الواجبات: <?php echo h((string)$assignmentSummary['score_text']); ?></span>
          </div>

          <?php if (empty($assignmentCards)): ?>
            <div class="acc-assess-empty">
              لا توجد واجبات مضافة للصف الدراسي الخاص بك حالياً.
            </div>
          <?php else: ?>
            <div class="acc-assess-grid">
              <?php foreach ($assignmentCards as $card): ?>
                <article class="acc-assess-card">
                  <div class="acc-assess-card__head">
                    <div>
                      <div class="acc-assess-card__title">📝 <?php echo h((string)$card['name']); ?></div>
                      <div class="acc-course__grade">🏫 <?php echo h((string)$card['grade_name']); ?></div>
                    </div>
                    <span class="acc-badge <?php echo in_array((string)$card['status_key'], ['submitted','expired'], true) ? 'acc-badge--buy' : 'acc-badge--att'; ?>">
                      <?php echo h((string)$card['status_icon']); ?> <?php echo h((string)$card['status_label']); ?>
                    </span>
                  </div>

                  <div class="acc-assess-card__meta">
                    <div>🧠 بنك الأسئلة: <span><?php echo h((string)$card['bank_name']); ?></span></div>
                    <div>⏰ الوقت: <span><?php echo (int)$card['duration_minutes']; ?> دقيقة</span></div>
                    <div>❓ الأسئلة للطالب: <span><?php echo (int)$card['questions_per_student']; ?> من <?php echo (int)$card['questions_total']; ?></span></div>
                    <div>🗓️ أضيف بتاريخ: <span><?php echo h((string)$card['created_at']); ?></span></div>
                    <?php if (!empty($card['attempt_id'])): ?>
                      <div>🏆 الدرجة: <span><?php echo h((string)$card['score_text']); ?></span></div>
                    <?php endif; ?>
                  </div>

                  <div class="acc-assess-card__actions">
                    <a class="acc-btn" href="<?php echo h((string)$card['href']); ?>"><?php echo h((string)$card['action_label']); ?></a>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($page === 'exams'): ?>
        <section class="acc-card" aria-label="الامتحانات">
          <div class="acc-card__head">
            <h2>🧠 الامتحانات</h2>
            <p>هنا تظهر الامتحانات المضافة للصف الدراسي الخاص بك مع عرض الامتحانات غير المحلولة وإمكانية مراجعة النتيجة في أي وقت.</p>
          </div>

          <div class="acc-assess-summary">
            <span class="acc-badge acc-badge--att">🟢 المتاح الآن: <?php echo (int)$examSummary['available_count']; ?></span>
            <span class="acc-badge acc-badge--buy">🏆 إجمالي درجات الامتحانات: <?php echo h((string)$examSummary['score_text']); ?></span>
          </div>

          <?php if (empty($examCards)): ?>
            <div class="acc-assess-empty">
              لا توجد امتحانات مضافة للصف الدراسي الخاص بك حالياً.
            </div>
          <?php else: ?>
            <div class="acc-assess-grid">
              <?php foreach ($examCards as $card): ?>
                <article class="acc-assess-card">
                  <div class="acc-assess-card__head">
                    <div>
                      <div class="acc-assess-card__title">🧠 <?php echo h((string)$card['name']); ?></div>
                      <div class="acc-course__grade">🏫 <?php echo h((string)$card['grade_name']); ?></div>
                    </div>
                    <span class="acc-badge <?php echo in_array((string)$card['status_key'], ['submitted','expired'], true) ? 'acc-badge--buy' : 'acc-badge--att'; ?>">
                      <?php echo h((string)$card['status_icon']); ?> <?php echo h((string)$card['status_label']); ?>
                    </span>
                  </div>

                  <div class="acc-assess-card__meta">
                    <div>🧠 بنك الأسئلة: <span><?php echo h((string)$card['bank_name']); ?></span></div>
                    <div>⏰ الوقت: <span><?php echo (int)$card['duration_minutes']; ?> دقيقة</span></div>
                    <div>❓ الأسئلة للطالب: <span><?php echo (int)$card['questions_per_student']; ?> من <?php echo (int)$card['questions_total']; ?></span></div>
                    <div>🗓️ أضيف بتاريخ: <span><?php echo h((string)$card['created_at']); ?></span></div>
                    <?php if (!empty($card['attempt_id'])): ?>
                      <div>🏆 الدرجة: <span><?php echo h((string)$card['score_text']); ?></span></div>
                    <?php endif; ?>
                  </div>

                  <div class="acc-assess-card__actions">
                    <a class="acc-btn" href="<?php echo h((string)$card['href']); ?>"><?php echo h((string)$card['action_label']); ?></a>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($page === 'facebook'): ?>
        <section class="acc-card" aria-label="فيسبوك المنصة">
          <div class="acc-card__head">
            <h2>📘 فيسبوك المنصة</h2>
            <p>تابع منشورات الإدارة، أضف رياكت مثل الفيسبوك، واكتب تعليقك لتتلقى رد الإدارة عليه.</p>
          </div>

          <?php if (empty($platformPosts)): ?>
            <div class="acc-emptyBox">لا توجد منشورات حالياً.</div>
          <?php else: ?>
            <div class="acc-feed">
              <?php foreach ($platformPosts as $post): ?>
                <?php
                  $postId = (int)$post['id'];
                  $postImage = trim((string)($post['image_path'] ?? ''));
                  $currentReaction = (string)($studentPostReactions[$postId] ?? '');
                ?>
                <article class="acc-feedCard" id="post-<?php echo $postId; ?>">
                  <div class="acc-feedHead">
                    <div>
                      <div style="font-weight:1000;font-size:1.06rem;">👨‍🏫 <?php echo h((string)$post['admin_name']); ?></div>
                      <div class="acc-chatMeta">🗓️ <?php echo h((string)($post['created_at'] ?? '')); ?></div>
                    </div>
                    <?php if ($currentReaction !== ''): ?>
                      <span class="acc-onlinePill">تفاعلك الحالي: <?php echo h((string)$reactionTypes[$currentReaction]); ?></span>
                    <?php endif; ?>
                  </div>

                  <?php if (trim((string)($post['body'] ?? '')) !== ''): ?><div class="acc-feedBody"><?php echo nl2br(h((string)$post['body'])); ?></div><?php endif; ?>
                  <?php if ($postImage !== ''): ?><div class="acc-feedImage"><img src="<?php echo h(student_public_asset_url($postImage)); ?>" alt="صورة المنشور"></div><?php endif; ?>

                  <div class="acc-feedStats">
                    <span class="acc-chip">❤️ إجمالي التفاعلات: <?php echo (int)($post['reactions_total'] ?? 0); ?></span>
                    <span class="acc-chip">💬 التعليقات: <?php echo (int)($post['comments_total'] ?? 0); ?></span>
                    <?php foreach (($platformPostReactionSummary[$postId] ?? []) as $reactionKey => $reactionCount): ?>
                      <span class="acc-chip"><?php echo h((string)$reactionTypes[$reactionKey]); ?>: <?php echo (int)$reactionCount; ?></span>
                    <?php endforeach; ?>
                  </div>

                  <div class="acc-reactions">
                    <?php foreach ($reactionTypes as $reactionKey => $reactionLabel): ?>
                      <form method="post">
                        <input type="hidden" name="action" value="react_post">
                        <input type="hidden" name="post_id" value="<?php echo $postId; ?>">
                        <input type="hidden" name="reaction_type" value="<?php echo h($reactionKey); ?>">
                        <button class="acc-reactionBtn <?php echo $currentReaction === $reactionKey ? 'is-active' : ''; ?>" type="submit"><?php echo h($reactionLabel); ?></button>
                      </form>
                    <?php endforeach; ?>
                    <?php if ($currentReaction !== ''): ?>
                      <form method="post">
                        <input type="hidden" name="action" value="clear_post_reaction">
                        <input type="hidden" name="post_id" value="<?php echo $postId; ?>">
                        <button class="acc-reactionBtn" type="submit">➖ إزالة التفاعل</button>
                      </form>
                    <?php endif; ?>
                  </div>

                  <form method="post" class="acc-commentForm">
                    <input type="hidden" name="action" value="comment_post">
                    <input type="hidden" name="post_id" value="<?php echo $postId; ?>">
                    <textarea name="comment_text" placeholder="اكتب تعليقك هنا..."></textarea>
                    <button class="acc-btn" type="submit">💬 إضافة تعليق</button>
                  </form>

                  <div class="acc-comments">
                    <?php foreach (($platformCommentsByPost[$postId] ?? []) as $comment): ?>
                      <?php $commentId = (int)$comment['id']; ?>
                      <div class="acc-comment">
                        <div class="acc-chatMeta">🧑‍🎓 <?php echo h((string)($comment['student_name'] ?? 'طالب')); ?> — <?php echo h((string)($comment['created_at'] ?? '')); ?></div>
                        <div style="margin-top:8px;"><?php echo nl2br(h((string)$comment['comment_text'])); ?></div>
                        <?php foreach (($platformRepliesByParent[$commentId] ?? []) as $reply): ?>
                          <div class="acc-commentReply">
                            <div class="acc-chatMeta">👨‍💼 <?php echo h((string)($reply['admin_name'] ?? 'الإدارة')); ?> — <?php echo h((string)($reply['created_at'] ?? '')); ?></div>
                            <div style="margin-top:8px;"><?php echo nl2br(h((string)$reply['comment_text'])); ?></div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($page === 'chat'): ?>
        <section class="acc-card" aria-label="شات الطلاب">
          <div class="acc-card__head">
            <h2>💬 شات الطلاب</h2>
            <p>يمكنك بدء محادثة نصية فقط عندما يكون هناك شخص من الإدارة في وضع أونلاين، ثم تواصل معه من نفس الصفحة.</p>
          </div>

          <div class="acc-chatLayout">
            <div class="acc-chatSidebar">
              <div class="acc-chatCard">
                <h3 style="margin-top:0;">🟢 الإدارة المتصلة الآن</h3>
                <?php if (empty($onlineAdmins)): ?>
                  <div class="acc-emptyBox">لا يوجد أي شخص من الإدارة متصل الآن. جرّب لاحقًا لبدء محادثة جديدة.</div>
                <?php else: ?>
                  <div class="acc-chatList">
                    <?php foreach ($onlineAdmins as $adminChat): ?>
                      <?php $imgUrl = trim((string)($adminChat['image_path'] ?? '')); ?>
                      <div class="acc-chatItem">
                        <div class="acc-chatUser">
                          <?php if ($imgUrl !== ''): ?>
                            <img class="acc-avatar" src="<?php echo h(student_public_asset_url($imgUrl)); ?>" alt="<?php echo h((string)$adminChat['display_name']); ?>">
                          <?php else: ?>
                            <div class="acc-avatar" style="display:grid;place-items:center;background:rgba(59,130,246,.12);">👨‍🏫</div>
                          <?php endif; ?>
                          <div>
                            <div style="font-weight:1000;"><?php echo h((string)$adminChat['display_name']); ?></div>
                            <div class="acc-chatMeta">آخر نشاط: <?php echo h((string)($adminChat['updated_at'] ?? '')); ?></div>
                          </div>
                        </div>
                        <form method="post" class="acc-chatSend" style="margin-top:12px;">
                          <input type="hidden" name="action" value="start_chat">
                          <input type="hidden" name="admin_id" value="<?php echo (int)$adminChat['id']; ?>">
                          <textarea name="message_text" placeholder="اكتب أول رسالة لبدء المحادثة..."></textarea>
                          <button class="acc-btn" type="submit">🚀 بدء المحادثة</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="acc-chatCard">
                <h3 style="margin-top:0;">🗂️ محادثاتك</h3>
                <?php if (empty($studentConversations)): ?>
                  <div class="acc-emptyBox">لا توجد محادثات محفوظة بعد.</div>
                <?php else: ?>
                  <div class="acc-chatList">
                    <?php foreach ($studentConversations as $conversation): ?>
                      <?php $convActive = ((int)$conversation['id'] === $selectedConversationId); $imgUrl = trim((string)($conversation['image_path'] ?? '')); ?>
                      <a class="acc-chatItem <?php echo $convActive ? 'is-active' : ''; ?>" href="account.php?page=chat&chat_id=<?php echo (int)$conversation['id']; ?>">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                          <div class="acc-chatUser">
                            <?php if ($imgUrl !== ''): ?>
                              <img class="acc-avatar" src="<?php echo h(student_public_asset_url($imgUrl)); ?>" alt="<?php echo h((string)$conversation['display_name']); ?>">
                            <?php else: ?>
                              <div class="acc-avatar" style="display:grid;place-items:center;background:rgba(59,130,246,.12);">👨‍🏫</div>
                            <?php endif; ?>
                            <div>
                              <div style="font-weight:1000;"><?php echo h((string)$conversation['display_name']); ?></div>
                              <div class="acc-chatMeta"><?php echo h((string)($conversation['last_message'] ?: 'لا توجد رسائل بعد')); ?></div>
                            </div>
                          </div>
                          <?php if (!empty($conversation['unread_count'])): ?><span class="acc-unread"><?php echo (int)$conversation['unread_count']; ?></span><?php endif; ?>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="acc-chatCard">
              <?php if (!$selectedConversation): ?>
                <div class="acc-emptyBox">اختر محادثة من القائمة أو ابدأ محادثة جديدة مع الإدارة المتصلة.</div>
              <?php else: ?>
                <?php $convImg = trim((string)($selectedConversation['image_path'] ?? '')); ?>
                <div class="acc-feedHead" style="margin-bottom:14px;">
                  <div class="acc-chatUser">
                    <?php if ($convImg !== ''): ?>
                      <img class="acc-avatar" src="<?php echo h(student_public_asset_url($convImg)); ?>" alt="<?php echo h((string)$selectedConversation['display_name']); ?>">
                    <?php else: ?>
                      <div class="acc-avatar" style="display:grid;place-items:center;background:rgba(59,130,246,.12);">👨‍🏫</div>
                    <?php endif; ?>
                    <div>
                      <div style="font-weight:1000;font-size:1.05rem;"><?php echo h((string)$selectedConversation['display_name']); ?></div>
                      <div class="acc-chatMeta"><?php echo !empty($selectedConversation['is_online']) ? '🟢 متصل الآن' : '⚪ غير متصل الآن'; ?></div>
                    </div>
                  </div>
                  <div class="acc-chatMeta">آخر تحديث: <?php echo h((string)($selectedConversation['updated_at'] ?? '')); ?></div>
                </div>

                <div class="acc-chatMsgs">
                  <?php foreach ($conversationMessages as $message): ?>
                    <?php $senderType = (string)($message['sender_type'] ?? 'student'); $messageId = (int)($message['id'] ?? 0); $currentChatReaction = $chatMessageReactions[$messageId] ?? null; ?>
                    <div class="acc-chatMsg <?php echo $senderType === 'student' ? 'acc-chatMsg--student' : 'acc-chatMsg--admin'; ?>" id="chat-message-<?php echo $messageId; ?>">
                      <div><?php echo nl2br(h((string)$message['message_text'])); ?></div>
                      <div class="acc-chatMeta" style="margin-top:6px;"><?php echo $senderType === 'student' ? 'أنت' : h((string)$selectedConversation['display_name']); ?> — <?php echo h((string)($message['created_at'] ?? '')); ?></div>
                      <?php if ($senderType === 'admin'): ?>
                        <?php if ($currentChatReaction && isset($reactionTypes[$currentChatReaction])): ?>
                          <div class="acc-chatMeta" style="margin-top:10px;">تفاعلك الحالي: <?php echo h((string)$reactionTypes[$currentChatReaction]); ?></div>
                        <?php endif; ?>
                        <div class="acc-chatReactions">
                          <?php foreach ($reactionTypes as $reactionKey => $reactionLabel): ?>
                            <form method="post" class="acc-chatReactionForm">
                              <input type="hidden" name="action" value="react_chat_message">
                              <input type="hidden" name="message_id" value="<?php echo $messageId; ?>">
                              <input type="hidden" name="reaction_type" value="<?php echo h($reactionKey); ?>">
                              <button class="acc-reactionBtn <?php echo $currentChatReaction === $reactionKey ? 'is-active' : ''; ?>" type="submit"><?php echo h($reactionLabel); ?></button>
                            </form>
                          <?php endforeach; ?>
                          <?php if ($currentChatReaction): ?>
                            <form method="post" class="acc-chatReactionForm">
                              <input type="hidden" name="action" value="clear_chat_message_reaction">
                              <input type="hidden" name="message_id" value="<?php echo $messageId; ?>">
                              <button class="acc-reactionBtn" type="submit">➖ إزالة التفاعل</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>

                <form method="post" class="acc-chatSend">
                  <input type="hidden" name="action" value="reply_chat">
                  <input type="hidden" name="conversation_id" value="<?php echo (int)$selectedConversation['id']; ?>">
                  <textarea name="message_text" placeholder="اكتب رسالتك..."></textarea>
                  <button class="acc-btn" type="submit">📤 إرسال الرسالة</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </section>

      <?php elseif ($page === 'wallet'): ?>
        <section class="acc-card" aria-label="سجل المحفظة">
          <div class="acc-card__head">
            <h2>💳 سجل المحفظة</h2>
            <p>هنا تظهر عمليات إضافة الرصيد للمحفظة ومشترياتك بالكورسات والمحاضرات.</p>
          </div>

          <div class="wallet-summary">
            <div class="wallet-summary__card">
              <div class="wallet-summary__label">الرصيد الحالي</div>
              <div class="wallet-summary__value"><?php echo number_format($wallet, 2); ?> جنيه</div>
            </div>
            <div class="wallet-summary__card">
              <div class="wallet-summary__label">إجمالي الإضافات</div>
              <div class="wallet-summary__value"><?php echo number_format((float)$walletSummary['credits'], 2); ?> جنيه</div>
            </div>
            <div class="wallet-summary__card">
              <div class="wallet-summary__label">إجمالي المشتريات</div>
              <div class="wallet-summary__value"><?php echo number_format((float)$walletSummary['purchases'], 2); ?> جنيه</div>
            </div>
            <div class="wallet-summary__card">
              <div class="wallet-summary__label">عدد العمليات</div>
              <div class="wallet-summary__value"><?php echo count($walletHistory); ?></div>
            </div>
          </div>

          <?php if (empty($walletHistory)): ?>
            <div style="font-weight:900;color:var(--muted);line-height:1.9;">
              لا توجد عمليات مسجلة في المحفظة حتى الآن.
            </div>
          <?php else: ?>
            <div class="wallet-history">
              <?php foreach ($walletHistory as $item): ?>
                <article class="wallet-history__item">
                  <div>
                    <div class="wallet-history__title"><?php echo h((string)$item['label']); ?></div>
                    <div class="wallet-history__meta">
                      <div><?php echo h((string)$item['details']); ?></div>
                      <div>🗓️ <?php echo h((string)$item['created_at']); ?></div>
                    </div>
                  </div>
                  <div class="wallet-history__amount <?php echo h((string)$item['amount_class']); ?>">
                    <?php echo h((string)$item['amount_text']); ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($page === 'notifications'): ?>
        <section class="acc-card" aria-label="إشعارات الطلاب">
          <div class="acc-card__head">
            <h2>🔔 إشعارات الطلاب</h2>
            <p>
              هنا تظهر الإشعارات التي تضيفها الإدارة للصف الدراسي الخاص بك:
              <strong><?php echo h((string)$student['grade_name']); ?></strong>.
            </p>
          </div>

          <?php if (empty($studentNotifications)): ?>
            <div class="acc-notifications-empty">
              لا توجد إشعارات مضافة لهذا الصف الدراسي حالياً.
            </div>
          <?php else: ?>
            <div class="acc-notifications-summary">
              إجمالي الإشعارات: <strong><?php echo count($studentNotifications); ?></strong>
              <?php if ($studentUnreadNotifications > 0): ?>
                <span class="acc-notifications-summary__badge">
                  <?php echo (int)$studentUnreadNotifications; ?> جديد
                </span>
              <?php endif; ?>
            </div>

            <div class="acc-notifications-list">
              <?php foreach ($studentNotifications as $notif): ?>
                <?php $isUnread = empty($notif['is_read']); ?>
                <article class="acc-notification<?php echo $isUnread ? ' acc-notification--unread' : ''; ?>">
                  <div class="acc-notification__head">
                    <h3 class="acc-notification__title"><?php echo h((string)$notif['title']); ?></h3>
                    <?php if ($isUnread): ?>
                      <span class="acc-notification__badge">جديد</span>
                    <?php endif; ?>
                  </div>
                  <div class="acc-notification__body"><?php echo nl2br(h((string)$notif['body'])); ?></div>
                  <div class="acc-notification__time">🗓️ <?php echo h((string)$notif['created_at']); ?></div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

      <?php elseif ($page === 'settings'): ?>
        <!-- settings unchanged -->
        <section class="acc-card" aria-label="إعدادات الحساب">
          <div class="acc-card__head">
            <h2>⚙️ إعدادات الحساب</h2>
            <p>يمكنك تعديل بياناتك هنا.</p>
          </div>

          <form method="post" class="acc-form" autocomplete="off">
            <input type="hidden" name="action" value="update_profile">

            <div class="acc-grid">
              <label class="acc-field">
                <span class="acc-label">اسم الطالب</span>
                <input class="acc-input" name="full_name" required value="<?php echo h((string)$student['full_name']); ?>" placeholder="مثال: محمد أحمد علي">
              </label>

              <label class="acc-field">
                <span class="acc-label">المحافظة</span>
                <select class="acc-input" name="governorate" required>
                  <option value="">— اختر المحافظة —</option>
                  <?php foreach ($governorates as $gov): ?>
                    <option value="<?php echo h($gov); ?>" <?php echo ((string)$student['governorate'] === $gov) ? 'selected' : ''; ?>>
                      <?php echo h($gov); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="acc-field">
                <span class="acc-label">رقم هاتف الطالب</span>
                <input class="acc-input" name="student_phone" required inputmode="numeric" pattern="[0-9]*"
                       value="<?php echo h((string)$student['student_phone']); ?>" placeholder="010xxxxxxxx">
              </label>

              <label class="acc-field">
                <span class="acc-label">رقم هاتف ولي الأمر</span>
                <input class="acc-input" name="parent_phone" inputmode="numeric" pattern="[0-9]*"
                       value="<?php echo h((string)($student['parent_phone'] ?? '')); ?>" placeholder="010xxxxxxxx">
              </label>

              <label class="acc-field">
                <span class="acc-label">الصف الدراسي</span>
                <select class="acc-input" name="grade_id" required>
                  <option value="0">— اختر الصف —</option>
                  <?php foreach ($gradesList as $g): ?>
                    <option value="<?php echo (int)$g['id']; ?>" <?php echo ((int)$student['grade_id'] === (int)$g['id']) ? 'selected' : ''; ?>>
                      <?php echo h((string)$g['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="acc-field">
                <span class="acc-label">كلمة سر جديدة</span>
                <input class="acc-input" type="password" name="new_password" placeholder="••••••••">
              </label>

              <label class="acc-field">
                <span class="acc-label">تأكيد كلمة السر الجديدة</span>
                <input class="acc-input" type="password" name="new_password2" placeholder="••••••••">
              </label>
            </div>

            <div class="acc-actions">
              <button class="acc-btn" type="submit">💾 حفظ التغييرات</button>
              <a class="acc-btn acc-btn--ghost" href="account.php?page=settings">إلغاء</a>
            </div>
          </form>
        </section>
      <?php endif; ?>

    </div>
  </main>
</div>

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
<script>
(function(){
  const burger = document.getElementById('accBurger');
  const sidebar = document.getElementById('accSidebar');
  const backdrop = document.getElementById('accBackdrop');

  function isMobile(){ return window.matchMedia && window.matchMedia('(max-width: 980px)').matches; }

  function openSidebar(){
    if (!isMobile()) return;
    sidebar.classList.add('is-open');
    backdrop.classList.add('is-open');
    backdrop.setAttribute('aria-hidden','false');
  }
  function closeSidebar(){
    sidebar.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    backdrop.setAttribute('aria-hidden','true');
  }

  burger && burger.addEventListener('click', (e) => {
    e.preventDefault();
    if (sidebar.classList.contains('is-open')) closeSidebar();
    else openSidebar();
  });

  backdrop && backdrop.addEventListener('click', (e) => {
    e.preventDefault();
    closeSidebar();
  });

  window.addEventListener('resize', () => {
    if (!isMobile()) closeSidebar();
  });

  // Notifications
  const btnBell = document.getElementById('btnBell');
  const notifsBox = document.getElementById('notifsBox');
  const closeBtn = document.getElementById('closeNotifs');
  const body = document.getElementById('notifsBody');
  const badge = document.getElementById('bellBadge');

  let opened = false;
  let lastUnreadCount = 0;

  function renderBadge(){
    if (!badge) return;
    if (opened) { badge.style.display = 'none'; return; }

    if (lastUnreadCount > 0) {
      badge.style.display = '';
      badge.textContent = String(lastUnreadCount);
    } else {
      badge.style.display = 'none';
      badge.textContent = '0';
    }
  }

  function openNotifs(){
    opened = true;
    notifsBox.classList.add('is-open');
    notifsBox.setAttribute('aria-hidden','false');
    renderBadge();
  }
  function closeNotifs(){
    opened = false;
    notifsBox.classList.remove('is-open');
    notifsBox.setAttribute('aria-hidden','true');
    renderBadge();
  }

  function escapeHtml(s){
    return (s||'').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  async function loadNotifs(markRead){
    try{
      body.innerHTML = '<div class="acc-notifs__loading">جارٍ التحميل...</div>';

      const url = markRead ? 'account_notifications_api.php?mark_read=1' : 'account_notifications_api.php';
      const res = await fetch(url, { credentials:'same-origin' });
      const data = await res.json();
      if (!data || !data.ok) throw new Error('api_error');

      lastUnreadCount = Math.max(0, parseInt(data.unread_count || 0, 10) || 0);
      renderBadge();

      const items = Array.isArray(data.items) ? data.items : [];
      if (!items.length) {
        body.innerHTML = '<div class="acc-notifs__empty">لا توجد إشعارات حالياً.</div>';
        return;
      }

      body.innerHTML = items.map(it => {
        const title = (it.title || '').toString();
        const text = (it.body || '').toString();
        const dt = (it.created_at || '').toString();
        return `
          <div class="acc-notif">
            <div class="acc-notif__title">${escapeHtml(title)}</div>
            <div class="acc-notif__body">${escapeHtml(text)}</div>
            <div class="acc-notif__time">${escapeHtml(dt)}</div>
          </div>
        `;
      }).join('');
    }catch(e){
      body.innerHTML = '<div class="acc-notifs__err">تعذر تحميل الإشعارات.</div>';
      lastUnreadCount = 0;
      renderBadge();
    }
  }

  btnBell && btnBell.addEventListener('click', async () => {
    if (!opened) {
      openNotifs();
      await loadNotifs(true);
    } else {
      closeNotifs();
      await loadNotifs(false);
    }
  });

  closeBtn && closeBtn.addEventListener('click', async () => {
    closeNotifs();
    await loadNotifs(false);
  });

  document.addEventListener('click', (e) => {
    const t = e.target;
    if (!t) return;
    if (opened) {
      const inside = notifsBox.contains(t) || (btnBell && btnBell.contains(t));
      if (!inside) closeNotifs();
    }
  });

  loadNotifs(false);

  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeSidebar();
      closeNotifs();
    }
  });
})();
</script>

<!-- ✅ Redeem Code Modal -->
<div id="redeemModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-label="تفعيل كود">
  <div style="background:var(--card-bg,#fff);color:var(--text,#111);border-radius:18px;padding:28px 24px;max-width:420px;width:calc(100% - 32px);box-shadow:0 8px 40px rgba(0,0,0,.25);position:relative;font-family:inherit;">
    <button onclick="closeRedeemModal()" style="position:absolute;top:12px;left:12px;background:none;border:none;font-size:1.4em;cursor:pointer;color:var(--muted,#888);" aria-label="إغلاق">✖</button>
    <h3 style="margin:0 0 14px;font-size:1.2em;">🎫 تفعيل كود اشتراك</h3>

    <div id="redeemMsg" style="display:none;padding:10px 14px;border-radius:10px;margin-bottom:12px;font-weight:700;"></div>

    <div id="redeemCodeStep">
      <input id="redeemCodeInput" type="text" placeholder="XXXX-XXXX-XXXX" class="ui-input" style="margin-bottom:10px;" dir="ltr">
      <button onclick="submitRedeemCode()" class="ui-btn ui-btn--solid">✅ تفعيل</button>
    </div>

    <div id="redeemCourseStep" style="display:none;">
      <p class="ui-note--warning" style="margin:0 0 8px;">🎓 هذا الكود عام — اختر الكورس الذي تريد فتحه:</p>
      <select id="redeemCourseSelect" class="ui-select" style="margin-bottom:10px;">
        <option value="">-- اختر الكورس --</option>
      </select>
      <button onclick="submitRedeemWithCourse()" class="ui-btn ui-btn--success">✅ تفعيل الكورس</button>
    </div>
  </div>
</div>

<script>
(function(){
  var modal = document.getElementById('redeemModal');
  var codeInput = document.getElementById('redeemCodeInput');
  var msgBox = document.getElementById('redeemMsg');
  var codeStep = document.getElementById('redeemCodeStep');
  var courseStep = document.getElementById('redeemCourseStep');
  var courseSelect = document.getElementById('redeemCourseSelect');
  var lastCode = '';

  window.openRedeemModal = function() {
    modal.style.display = 'flex';
    codeInput.value = '';
    lastCode = '';
    hideMsg();
    showStep('code');
    setTimeout(function(){ codeInput.focus(); }, 80);
  };
  window.closeRedeemModal = function() {
    modal.style.display = 'none';
  };

  modal.addEventListener('click', function(e){ if (e.target === modal) closeRedeemModal(); });

  function showMsg(text, ok) {
    msgBox.textContent = text;
    msgBox.style.display = 'block';
    msgBox.className = ok ? 'ui-msg--success' : 'ui-msg--error';
  }
  function hideMsg() {
    msgBox.style.display = 'none';
    msgBox.textContent = '';
    msgBox.className = '';
  }
  function showStep(step) {
    codeStep.style.display = step === 'code' ? 'block' : 'none';
    courseStep.style.display = step === 'course' ? 'block' : 'none';
  }

  window.submitRedeemCode = async function() {
    var code = codeInput.value.trim();
    if (!code) { showMsg('من فضلك أدخل الكود.', false); return; }
    lastCode = code;
    hideMsg();
    codeStep.querySelector('button').disabled = true;
    codeStep.querySelector('button').textContent = '⏳ جاري التفعيل...';

    try {
      var fd = new FormData();
      fd.append('code', code);
      var res = await fetch('api/redeem_code_api.php', {method:'POST', body:fd});
      var data = await res.json();

      codeStep.querySelector('button').disabled = false;
      codeStep.querySelector('button').textContent = '✅ تفعيل';

      if (data.needs_target && data.target_type === 'course') {
        // Show course picker
        courseSelect.innerHTML = '<option value="">-- اختر الكورس --</option>';
        (data.courses || []).forEach(function(c){
          var o = document.createElement('option');
          o.value = c.id;
          o.textContent = c.name;
          courseSelect.appendChild(o);
        });
        showStep('course');
        showMsg(data.message || 'اختر الكورس المراد فتحه.', false);
      } else if (data.ok) {
        showMsg('✅ ' + (data.message || 'تم التفعيل بنجاح.'), true);
        showStep('code');
        setTimeout(function(){ closeRedeemModal(); location.reload(); }, 1800);
      } else {
        showMsg('❌ ' + (data.message || 'حدث خطأ.'), false);
      }
    } catch(e) {
      codeStep.querySelector('button').disabled = false;
      codeStep.querySelector('button').textContent = '✅ تفعيل';
      showMsg('❌ حدث خطأ في الاتصال، حاول مرة أخرى.', false);
    }
  };

  window.submitRedeemWithCourse = async function() {
    var courseId = courseSelect.value;
    if (!courseId) { showMsg('من فضلك اختر كورساً.', false); return; }
    hideMsg();
    courseStep.querySelector('button').disabled = true;
    courseStep.querySelector('button').textContent = '⏳ جاري التفعيل...';

    try {
      var fd = new FormData();
      fd.append('code', lastCode);
      fd.append('target_course_id', courseId);
      var res = await fetch('api/redeem_code_api.php', {method:'POST', body:fd});
      var data = await res.json();

      courseStep.querySelector('button').disabled = false;
      courseStep.querySelector('button').textContent = '✅ تفعيل الكورس';

      if (data.ok) {
        showMsg('✅ ' + (data.message || 'تم التفعيل بنجاح.'), true);
        setTimeout(function(){ closeRedeemModal(); location.reload(); }, 1800);
      } else {
        showMsg('❌ ' + (data.message || 'حدث خطأ.'), false);
      }
    } catch(e) {
      courseStep.querySelector('button').disabled = false;
      courseStep.querySelector('button').textContent = '✅ تفعيل الكورس';
      showMsg('❌ حدث خطأ في الاتصال.', false);
    }
  };

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && modal.style.display !== 'none') closeRedeemModal();
  });
})();
</script>

</body>
</html>
