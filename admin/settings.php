<?php
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

require_login();

/* =========================
   Helpers
   ========================= */
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}
function random_filename(string $ext): string {
  return bin2hex(random_bytes(16)) . '.' . $ext;
}
function detect_image_extension(string $tmpPath): ?string {
  $info = @getimagesize($tmpPath);
  if (!$info || empty($info['mime'])) return null;

  $mime = strtolower(trim((string)$info['mime']));
  $map = [
    'image/jpeg' => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/bmp' => 'bmp',
    'image/x-ms-bmp' => 'bmp',
    'image/x-icon' => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
    'image/avif' => 'avif',
  ];
  return $map[$mime] ?? null;
}
function normalize_upload_error(int $code): string {
  $errors = [
    UPLOAD_ERR_INI_SIZE => 'حجم الملف أكبر من الحد المسموح في السيرفر (upload_max_filesize).',
    UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من الحد المسموح.',
    UPLOAD_ERR_PARTIAL => 'تم رفع الملف بشكل غير كامل.',
    UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف.',
    UPLOAD_ERR_NO_TMP_DIR => 'مجلد مؤقت مفقود في السيرفر.',
    UPLOAD_ERR_CANT_WRITE => 'تعذر كتابة الملف على السيرفر.',
    UPLOAD_ERR_EXTENSION => 'تم منع رفع الملف بسبب امتداد غير مسموح على السيرفر.',
  ];
  return $errors[$code] ?? 'خطأ غير معروف أثناء رفع الملف.';
}

/* =========================
   Sidebar permissions
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
   Upload dirs
   ========================= */
$uploadDirAbs = __DIR__ . '/uploads/platform';
$uploadDirRel = 'uploads/platform';
ensure_dir($uploadDirAbs);

$cardsUploadDirAbs = __DIR__ . '/uploads/platform/cards';
$cardsUploadDirRel = 'uploads/platform/cards';
ensure_dir($cardsUploadDirAbs);

$footerUploadDirAbs = __DIR__ . '/uploads/platform/footer';
$footerUploadDirRel = 'uploads/platform/footer';
ensure_dir($footerUploadDirAbs);

/* ✅ Social icons upload dir */
$socialUploadDirAbs = __DIR__ . '/uploads/platform/social';
$socialUploadDirRel = 'uploads/platform/social';
ensure_dir($socialUploadDirAbs);

/* ✅ register page image dir */
$registerUploadDirAbs = __DIR__ . '/uploads/platform/register';
$registerUploadDirRel = 'uploads/platform/register';
ensure_dir($registerUploadDirAbs);

/* ✅ NEW: login page image dir */
$loginUploadDirAbs = __DIR__ . '/uploads/platform/login';
$loginUploadDirRel = 'uploads/platform/login';
ensure_dir($loginUploadDirAbs);

/* =========================
   Ensure DB schema
   ========================= */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS platform_settings (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    platform_name VARCHAR(190) NOT NULL DEFAULT 'منصتي التعليمية',
    platform_logo VARCHAR(255) DEFAULT NULL,

    hero_small_title VARCHAR(190) DEFAULT NULL,
    hero_title VARCHAR(255) DEFAULT NULL,
    hero_description LONGTEXT DEFAULT NULL,
    hero_button_text VARCHAR(80) DEFAULT NULL,
    hero_button_url VARCHAR(255) DEFAULT NULL,
    hero_teacher_image VARCHAR(255) DEFAULT NULL,

    hero_stats_bg_text VARCHAR(60) DEFAULT NULL,
    hero_stat_1_value VARCHAR(40) DEFAULT NULL,
    hero_stat_1_label VARCHAR(190) DEFAULT NULL,
    hero_stat_2_value VARCHAR(40) DEFAULT NULL,
    hero_stat_2_label VARCHAR(190) DEFAULT NULL,
    hero_stat_3_value VARCHAR(40) DEFAULT NULL,
    hero_stat_3_label VARCHAR(190) DEFAULT NULL,

    feature_cards_enabled TINYINT(1) NOT NULL DEFAULT 1,
    feature_cards_title VARCHAR(255) DEFAULT NULL,

    cta_enabled TINYINT(1) NOT NULL DEFAULT 1,
    cta_title VARCHAR(255) DEFAULT NULL,
    cta_subtitle VARCHAR(255) DEFAULT NULL,
    cta_button_text VARCHAR(80) DEFAULT NULL,
    cta_button_url VARCHAR(255) DEFAULT NULL,

    footer_enabled TINYINT(1) NOT NULL DEFAULT 1,
    footer_logo_path VARCHAR(255) DEFAULT NULL,
    footer_social_title VARCHAR(190) DEFAULT NULL,
    footer_contact_title VARCHAR(190) DEFAULT NULL,
    footer_phone_1 VARCHAR(60) DEFAULT NULL,
    footer_phone_2 VARCHAR(60) DEFAULT NULL,
    footer_rights_line VARCHAR(255) DEFAULT NULL,
    footer_developed_by_line VARCHAR(255) DEFAULT NULL,

    register_image_path VARCHAR(255) DEFAULT NULL,
    login_image_path VARCHAR(255) DEFAULT NULL,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS platform_feature_cards (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT(10) NOT NULL DEFAULT 0,
    theme ENUM('light','dark') NOT NULL DEFAULT 'light',
    icon_path VARCHAR(255) DEFAULT NULL,
    title VARCHAR(190) NOT NULL,
    body LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_active_sort (is_active, sort_order, id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS platform_footer_social_links (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT(10) NOT NULL DEFAULT 0,
    label VARCHAR(60) NOT NULL,
    url VARCHAR(255) NOT NULL,
    icon_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_active_sort (is_active, sort_order, id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

/* Ensure one row exists (id=1) */
$row = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
if (!$row) {
  $pdo->exec("INSERT INTO platform_settings (id, platform_name, platform_logo) VALUES (1, 'منصتي التعليمية', NULL)");
  $row = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
}

/* =========================
   Actions
   ========================= */
$success = null;
$error = null;

$action = (string)($_POST['action'] ?? '');

/* Save main settings (extended) */
if ($action === 'save_settings') {
  $newName = trim((string)($_POST['platform_name'] ?? ''));

  $heroSmallTitle = trim((string)($_POST['hero_small_title'] ?? ''));
  $heroTitle = trim((string)($_POST['hero_title'] ?? ''));
  $heroDescription = trim((string)($_POST['hero_description'] ?? ''));
  $heroButtonText = trim((string)($_POST['hero_button_text'] ?? ''));
  $heroButtonUrl = trim((string)($_POST['hero_button_url'] ?? ''));

  $heroStatsBgText = trim((string)($_POST['hero_stats_bg_text'] ?? 'ENGLISH'));

  $heroStat1Value = trim((string)($_POST['hero_stat_1_value'] ?? ''));
  $heroStat1Label = trim((string)($_POST['hero_stat_1_label'] ?? ''));

  $heroStat2Value = trim((string)($_POST['hero_stat_2_value'] ?? ''));
  $heroStat2Label = trim((string)($_POST['hero_stat_2_label'] ?? ''));

  $heroStat3Value = trim((string)($_POST['hero_stat_3_value'] ?? ''));
  $heroStat3Label = trim((string)($_POST['hero_stat_3_label'] ?? ''));

  $featureCardsEnabled = (int)($_POST['feature_cards_enabled'] ?? 0);
  $featureCardsTitle = trim((string)($_POST['feature_cards_title'] ?? ''));

  $ctaEnabled = (int)($_POST['cta_enabled'] ?? 0);
  $ctaTitle = trim((string)($_POST['cta_title'] ?? ''));
  $ctaSubtitle = trim((string)($_POST['cta_subtitle'] ?? ''));
  $ctaButtonText = trim((string)($_POST['cta_button_text'] ?? ''));
  $ctaButtonUrl = trim((string)($_POST['cta_button_url'] ?? ''));

  $footerEnabled = (int)($_POST['footer_enabled'] ?? 0);
  $footerSocialTitle = trim((string)($_POST['footer_social_title'] ?? ''));
  $footerContactTitle = trim((string)($_POST['footer_contact_title'] ?? ''));
  $footerPhone1 = trim((string)($_POST['footer_phone_1'] ?? ''));
  $footerPhone2 = trim((string)($_POST['footer_phone_2'] ?? ''));
  $footerRightsLine = trim((string)($_POST['footer_rights_line'] ?? ''));
  $footerDevLine = trim((string)($_POST['footer_developed_by_line'] ?? ''));

  if ($newName === '') {
    $error = 'من فضلك اكتب اسم المنصة.';
  } else {
    $newLogoPath = (string)($row['platform_logo'] ?? '');
    if ($newLogoPath === '') $newLogoPath = null;

    $newTeacherImagePath = (string)($row['hero_teacher_image'] ?? '');
    if ($newTeacherImagePath === '') $newTeacherImagePath = null;

    $newFooterLogoPath = (string)($row['footer_logo_path'] ?? '');
    if ($newFooterLogoPath === '') $newFooterLogoPath = null;

    $newRegisterImagePath = (string)($row['register_image_path'] ?? '');
    if ($newRegisterImagePath === '') $newRegisterImagePath = null;

    $newLoginImagePath = (string)($row['login_image_path'] ?? '');
    if ($newLoginImagePath === '') $newLoginImagePath = null;

    // Upload new logo (optional)
    $hasNew = !empty($_FILES['platform_logo']['name']);
    if ($hasNew) {
      $errCode = (int)($_FILES['platform_logo']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($errCode !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error($errCode);
      } else {
        $tmp = (string)$_FILES['platform_logo']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'الملف المرفوع ليس صورة صحيحة.';
        } else {
          $newNameFile = random_filename($ext);
          $destAbs = $uploadDirAbs . '/' . $newNameFile;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ اللوجو على السيرفر.';
          } else {
            if (!empty($row['platform_logo'])) {
              $oldAbs = __DIR__ . '/' . $row['platform_logo'];
              if (is_file($oldAbs)) @unlink($oldAbs);
            }
            $newLogoPath = $uploadDirRel . '/' . $newNameFile;
          }
        }
      }
    }

    // Upload teacher image (optional)
    $hasTeacher = !empty($_FILES['hero_teacher_image']['name']);
    if (!$error && $hasTeacher) {
      $errCode = (int)($_FILES['hero_teacher_image']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($errCode !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error($errCode);
      } else {
        $tmp = (string)$_FILES['hero_teacher_image']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'صورة المدرس المرفوعة ليست صورة صحيحة.';
        } else {
          $newNameFile = random_filename($ext);
          $destAbs = $uploadDirAbs . '/' . $newNameFile;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ صورة المدرس على السيرفر.';
          } else {
            if (!empty($row['hero_teacher_image'])) {
              $oldAbs = __DIR__ . '/' . $row['hero_teacher_image'];
              if (is_file($oldAbs)) @unlink($oldAbs);
            }
            $newTeacherImagePath = $uploadDirRel . '/' . $newNameFile;
          }
        }
      }
    }

    // Upload footer logo (optional)
    $hasFooterLogo = !empty($_FILES['footer_logo']['name']);
    if (!$error && $hasFooterLogo) {
      $errCode = (int)($_FILES['footer_logo']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($errCode !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error($errCode);
      } else {
        $tmp = (string)$_FILES['footer_logo']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'اللوجو المرفوع للفوتر ليس صورة صحيحة.';
        } else {
          $newNameFile = random_filename($ext);
          $destAbs = $footerUploadDirAbs . '/' . $newNameFile;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ لوجو الفوتر على السيرفر.';
          } else {
            if (!empty($row['footer_logo_path'])) {
              $oldAbs = __DIR__ . '/' . $row['footer_logo_path'];
              if (is_file($oldAbs)) @unlink($oldAbs);
            }
            $newFooterLogoPath = $footerUploadDirRel . '/' . $newNameFile;
          }
        }
      }
    }

    // Upload register page image (optional)
    $hasRegisterImg = !empty($_FILES['register_image']['name']);
    if (!$error && $hasRegisterImg) {
      $errCode = (int)($_FILES['register_image']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($errCode !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error($errCode);
      } else {
        $tmp = (string)$_FILES['register_image']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'صورة صفحة التسجيل المرفوعة ليست صورة صحيحة.';
        } else {
          $newNameFile = random_filename($ext);
          $destAbs = $registerUploadDirAbs . '/' . $newNameFile;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ صورة صفحة التسجيل على السيرفر.';
          } else {
            if (!empty($row['register_image_path'])) {
              $oldAbs = __DIR__ . '/' . $row['register_image_path'];
              if (is_file($oldAbs)) @unlink($oldAbs);
            }
            $newRegisterImagePath = $registerUploadDirRel . '/' . $newNameFile;
          }
        }
      }
    }

    // ✅ NEW: Upload login page image (optional)
    $hasLoginImg = !empty($_FILES['login_image']['name']);
    if (!$error && $hasLoginImg) {
      $errCode = (int)($_FILES['login_image']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($errCode !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error($errCode);
      } else {
        $tmp = (string)$_FILES['login_image']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'صورة صفحة تسجيل الدخول المرفوعة ليست صورة صحيحة.';
        } else {
          $newNameFile = random_filename($ext);
          $destAbs = $loginUploadDirAbs . '/' . $newNameFile;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ صورة صفحة تسجيل الدخول على السيرفر.';
          } else {
            if (!empty($row['login_image_path'])) {
              $oldAbs = __DIR__ . '/' . $row['login_image_path'];
              if (is_file($oldAbs)) @unlink($oldAbs);
            }
            $newLoginImagePath = $loginUploadDirRel . '/' . $newNameFile;
          }
        }
      }
    }

    if (!$error) {
      try {
        $stmt = $pdo->prepare("
          UPDATE platform_settings
          SET
            platform_name=?,
            platform_logo=?,

            hero_small_title=?,
            hero_title=?,
            hero_description=?,
            hero_button_text=?,
            hero_button_url=?,
            hero_teacher_image=?,

            hero_stats_bg_text=?,
            hero_stat_1_value=?,
            hero_stat_1_label=?,
            hero_stat_2_value=?,
            hero_stat_2_label=?,
            hero_stat_3_value=?,
            hero_stat_3_label=?,

            feature_cards_enabled=?,
            feature_cards_title=?,

            cta_enabled=?,
            cta_title=?,
            cta_subtitle=?,
            cta_button_text=?,
            cta_button_url=?,

            footer_enabled=?,
            footer_logo_path=?,
            footer_social_title=?,
            footer_contact_title=?,
            footer_phone_1=?,
            footer_phone_2=?,
            footer_rights_line=?,
            footer_developed_by_line=?,

            register_image_path=?,
            login_image_path=?

          WHERE id=1
        ");
        $stmt->execute([
          $newName,
          $newLogoPath,

          ($heroSmallTitle === '' ? null : $heroSmallTitle),
          ($heroTitle === '' ? null : $heroTitle),
          ($heroDescription === '' ? null : $heroDescription),
          ($heroButtonText === '' ? null : $heroButtonText),
          ($heroButtonUrl === '' ? null : $heroButtonUrl),
          $newTeacherImagePath,

          ($heroStatsBgText === '' ? 'ENGLISH' : $heroStatsBgText),

          ($heroStat1Value === '' ? null : $heroStat1Value),
          ($heroStat1Label === '' ? null : $heroStat1Label),

          ($heroStat2Value === '' ? null : $heroStat2Value),
          ($heroStat2Label === '' ? null : $heroStat2Label),

          ($heroStat3Value === '' ? null : $heroStat3Value),
          ($heroStat3Label === '' ? null : $heroStat3Label),

          ($featureCardsEnabled === 1 ? 1 : 0),
          ($featureCardsTitle === '' ? null : $featureCardsTitle),

          ($ctaEnabled === 1 ? 1 : 0),
          ($ctaTitle === '' ? null : $ctaTitle),
          ($ctaSubtitle === '' ? null : $ctaSubtitle),
          ($ctaButtonText === '' ? null : $ctaButtonText),
          ($ctaButtonUrl === '' ? null : $ctaButtonUrl),

          ($footerEnabled === 1 ? 1 : 0),
          $newFooterLogoPath,
          ($footerSocialTitle === '' ? null : $footerSocialTitle),
          ($footerContactTitle === '' ? null : $footerContactTitle),
          ($footerPhone1 === '' ? null : $footerPhone1),
          ($footerPhone2 === '' ? null : $footerPhone2),
          ($footerRightsLine === '' ? null : $footerRightsLine),
          ($footerDevLine === '' ? null : $footerDevLine),

          $newRegisterImagePath,
          $newLoginImagePath,
        ]);

        header('Location: settings.php?saved=1');
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر حفظ الإعدادات.';
      }
    }
  }
}

/* =========================
   Social links CRUD (file upload icon)
   ========================= */
if ($action === 'add_social') {
  $label = trim((string)($_POST['soc_label'] ?? ''));
  $url = trim((string)($_POST['soc_url'] ?? ''));
  $sort = (int)($_POST['soc_sort'] ?? 0);
  $active = ((int)($_POST['soc_active'] ?? 1) === 1) ? 1 : 0;

  if ($label === '' || $url === '') {
    $error = 'من فضلك اكتب اسم ورابط السوشيال.';
  } else {
    $iconPath = null;

    $hasIcon = !empty($_FILES['soc_icon_file']['name']);
    if ($hasIcon) {
      $errCode = (int)($_FILES['soc_icon_file']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($errCode !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error($errCode);
      } else {
        $tmp = (string)$_FILES['soc_icon_file']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'أيقونة السوشيال المرفوعة ليست صورة صحيحة.';
        } else {
          $newNameFile = random_filename($ext);
          $destAbs = $socialUploadDirAbs . '/' . $newNameFile;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ أيقونة السوشيال على السيرفر.';
          } else {
            $iconPath = $socialUploadDirRel . '/' . $newNameFile;
          }
        }
      }
    }

    if (!$error) {
      try {
        $stmt = $pdo->prepare("
          INSERT INTO platform_footer_social_links (is_active, sort_order, label, url, icon_path)
          VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$active, $sort, $label, $url, $iconPath]);
        header('Location: settings.php?social_added=1');
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر إضافة السوشيال.';
      }
    }
  }
}

if ($action === 'delete_social') {
  $id = (int)($_POST['soc_id'] ?? 0);
  if ($id > 0) {
    try {
      $cur = $pdo->prepare("SELECT icon_path FROM platform_footer_social_links WHERE id=? LIMIT 1");
      $cur->execute([$id]);
      $r = $cur->fetch();
      $icon = (string)($r['icon_path'] ?? '');

      if ($icon !== '') {
        $abs = __DIR__ . '/' . $icon;
        if (is_file($abs)) @unlink($abs);
      }

      $stmt = $pdo->prepare("DELETE FROM platform_footer_social_links WHERE id=?");
      $stmt->execute([$id]);
      header('Location: settings.php?social_deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف السوشيال.';
    }
  }
}

if ($action === 'update_social') {
  $id = (int)($_POST['soc_id'] ?? 0);
  $label = trim((string)($_POST['soc_label'] ?? ''));
  $url = trim((string)($_POST['soc_url'] ?? ''));
  $sort = (int)($_POST['soc_sort'] ?? 0);
  $active = ((int)($_POST['soc_active'] ?? 1) === 1) ? 1 : 0;

  if ($id <= 0 || $label === '' || $url === '') {
    $error = 'بيانات تعديل السوشيال غير صحيحة.';
  } else {
    try {
      $cur = $pdo->prepare("SELECT icon_path FROM platform_footer_social_links WHERE id=? LIMIT 1");
      $cur->execute([$id]);
      $r = $cur->fetch();
      $iconPath = (string)($r['icon_path'] ?? '');
      if ($iconPath === '') $iconPath = null;

      $hasIcon = !empty($_FILES['soc_icon_file']['name']);
      if ($hasIcon) {
        $errCode = (int)($_FILES['soc_icon_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
          $error = normalize_upload_error($errCode);
        } else {
          $tmp = (string)$_FILES['soc_icon_file']['tmp_name'];
          $ext = detect_image_extension($tmp);
          if ($ext === null) {
            $error = 'أيقونة السوشيال المرفوعة ليست صورة صحيحة.';
          } else {
            $newNameFile = random_filename($ext);
            $destAbs = $socialUploadDirAbs . '/' . $newNameFile;

            if (!move_uploaded_file($tmp, $destAbs)) {
              $error = 'تعذر حفظ أيقونة السوشيال على السيرفر.';
            } else {
              if (!empty($r['icon_path'])) {
                $oldAbs = __DIR__ . '/' . $r['icon_path'];
                if (is_file($oldAbs)) @unlink($oldAbs);
              }
              $iconPath = $socialUploadDirRel . '/' . $newNameFile;
            }
          }
        }
      }

      if (!$error) {
        $stmt = $pdo->prepare("
          UPDATE platform_footer_social_links
          SET is_active=?, sort_order=?, label=?, url=?, icon_path=?
          WHERE id=?
        ");
        $stmt->execute([$active, $sort, $label, $url, $iconPath, $id]);
        header('Location: settings.php?social_updated=1');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'تعذر تعديل السوشيال.';
    }
  }
}

/* =========================
   Feature cards CRUD (existing)
   ========================= */

/* Add new feature card */
if ($action === 'add_feature_card') {
  $title = trim((string)($_POST['fc_title'] ?? ''));
  $body = trim((string)($_POST['fc_body'] ?? ''));
  $theme = ((string)($_POST['fc_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
  $sort = (int)($_POST['fc_sort'] ?? 0);
  $isActive = (int)($_POST['fc_active'] ?? 0) === 1 ? 1 : 0;

  if ($title === '') {
    $error = 'من فضلك اكتب عنوان الكارت.';
  } else {
    $iconPath = null;

    $hasIcon = !empty($_FILES['fc_icon']['name']);
    if ($hasIcon) {
      $errCode = (int)($_FILES['fc_icon']['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($errCode !== UPLOAD_ERR_OK) {
        $error = normalize_upload_error($errCode);
      } else {
        $tmp = (string)$_FILES['fc_icon']['tmp_name'];
        $ext = detect_image_extension($tmp);
        if ($ext === null) {
          $error = 'صورة الكارت المرفوعة ليست صورة صحيحة.';
        } else {
          $newNameFile = random_filename($ext);
          $destAbs = $cardsUploadDirAbs . '/' . $newNameFile;

          if (!move_uploaded_file($tmp, $destAbs)) {
            $error = 'تعذر حفظ صورة الكارت على السيرفر.';
          } else {
            $iconPath = $cardsUploadDirRel . '/' . $newNameFile;
          }
        }
      }
    }

    if (!$error) {
      try {
        $stmt = $pdo->prepare("
          INSERT INTO platform_feature_cards (is_active, sort_order, theme, icon_path, title, body)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$isActive, $sort, $theme, $iconPath, $title, ($body === '' ? null : $body)]);
        header('Location: settings.php?card_added=1');
        exit;
      } catch (Throwable $e) {
        $error = 'تعذر إضافة الكارت.';
      }
    }
  }
}

/* Delete feature card */
if ($action === 'delete_feature_card') {
  $id = (int)($_POST['fc_id'] ?? 0);
  if ($id > 0) {
    try {
      $cur = $pdo->prepare("SELECT icon_path FROM platform_feature_cards WHERE id=? LIMIT 1");
      $cur->execute([$id]);
      $r = $cur->fetch();
      $icon = (string)($r['icon_path'] ?? '');

      if ($icon !== '') {
        $abs = __DIR__ . '/' . $icon;
        if (is_file($abs)) @unlink($abs);
      }

      $stmt = $pdo->prepare("DELETE FROM platform_feature_cards WHERE id=?");
      $stmt->execute([$id]);

      header('Location: settings.php?card_deleted=1');
      exit;
    } catch (Throwable $e) {
      $error = 'تعذر حذف الكارت.';
    }
  }
}

/* Update feature card */
if ($action === 'update_feature_card') {
  $id = (int)($_POST['fc_id'] ?? 0);
  $title = trim((string)($_POST['fc_title'] ?? ''));
  $body = trim((string)($_POST['fc_body'] ?? ''));
  $theme = ((string)($_POST['fc_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
  $sort = (int)($_POST['fc_sort'] ?? 0);
  $isActive = (int)($_POST['fc_active'] ?? 0) === 1 ? 1 : 0;

  if ($id <= 0 || $title === '') {
    $error = 'بيانات تعديل الكارت غير صحيحة.';
  } else {
    try {
      $cur = $pdo->prepare("SELECT icon_path FROM platform_feature_cards WHERE id=? LIMIT 1");
      $cur->execute([$id]);
      $r = $cur->fetch();
      $iconPath = (string)($r['icon_path'] ?? '');
      if ($iconPath === '') $iconPath = null;

      $hasIcon = !empty($_FILES['fc_icon']['name']);
      if ($hasIcon) {
        $errCode = (int)($_FILES['fc_icon']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
          $error = normalize_upload_error($errCode);
        } else {
          $tmp = (string)$_FILES['fc_icon']['tmp_name'];
          $ext = detect_image_extension($tmp);
          if ($ext === null) {
            $error = 'صورة الكارت المرفوعة ليست صورة صحيحة.';
          } else {
            $newNameFile = random_filename($ext);
            $destAbs = $cardsUploadDirAbs . '/' . $newNameFile;

            if (!move_uploaded_file($tmp, $destAbs)) {
              $error = 'تعذر حفظ صورة الكارت على السيرفر.';
            } else {
              if (!empty($r['icon_path'])) {
                $oldAbs = __DIR__ . '/' . $r['icon_path'];
                if (is_file($oldAbs)) @unlink($oldAbs);
              }
              $iconPath = $cardsUploadDirRel . '/' . $newNameFile;
            }
          }
        }
      }

      if (!$error) {
        $stmt = $pdo->prepare("
          UPDATE platform_feature_cards
          SET is_active=?, sort_order=?, theme=?, icon_path=?, title=?, body=?
          WHERE id=?
        ");
        $stmt->execute([
          $isActive,
          $sort,
          $theme,
          $iconPath,
          $title,
          ($body === '' ? null : $body),
          $id
        ]);

        header('Location: settings.php?card_updated=1');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'تعذر تعديل الكارت.';
    }
  }
}

/* Delete logo */
if ($action === 'delete_logo') {
  try {
    $cur = $pdo->query("SELECT platform_logo FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
    $curLogo = (string)($cur['platform_logo'] ?? '');

    if ($curLogo !== '') {
      $abs = __DIR__ . '/' . $curLogo;
      if (is_file($abs)) @unlink($abs);
    }

    $stmt = $pdo->prepare("UPDATE platform_settings SET platform_logo=NULL WHERE id=1");
    $stmt->execute([]);

    header('Location: settings.php?logo_deleted=1');
    exit;
  } catch (Throwable $e) {
    $error = 'تعذر حذف اللوجو.';
  }
}

/* Delete teacher image */
if ($action === 'delete_teacher_image') {
  try {
    $cur = $pdo->query("SELECT hero_teacher_image FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
    $curImg = (string)($cur['hero_teacher_image'] ?? '');

    if ($curImg !== '') {
      $abs = __DIR__ . '/' . $curImg;
      if (is_file($abs)) @unlink($abs);
    }

    $stmt = $pdo->prepare("UPDATE platform_settings SET hero_teacher_image=NULL WHERE id=1");
    $stmt->execute([]);

    header('Location: settings.php?teacher_deleted=1');
    exit;
  } catch (Throwable $e) {
    $error = 'تعذر حذف صورة المدرس.';
  }
}

/* Delete register image */
if ($action === 'delete_register_image') {
  try {
    $cur = $pdo->query("SELECT register_image_path FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
    $curImg = (string)($cur['register_image_path'] ?? '');

    if ($curImg !== '') {
      $abs = __DIR__ . '/' . $curImg;
      if (is_file($abs)) @unlink($abs);
    }

    $stmt = $pdo->prepare("UPDATE platform_settings SET register_image_path=NULL WHERE id=1");
    $stmt->execute([]);

    header('Location: settings.php?register_deleted=1');
    exit;
  } catch (Throwable $e) {
    $error = 'تعذر حذف صورة صفحة التسجيل.';
  }
}

/* ✅ NEW: Delete login image */
if ($action === 'delete_login_image') {
  try {
    $cur = $pdo->query("SELECT login_image_path FROM platform_settings WHERE id=1 LIMIT 1")->fetch();
    $curImg = (string)($cur['login_image_path'] ?? '');

    if ($curImg !== '') {
      $abs = __DIR__ . '/' . $curImg;
      if (is_file($abs)) @unlink($abs);
    }

    $stmt = $pdo->prepare("UPDATE platform_settings SET login_image_path=NULL WHERE id=1");
    $stmt->execute([]);

    header('Location: settings.php?login_deleted=1');
    exit;
  } catch (Throwable $e) {
    $error = 'تعذر حذف صورة صفحة تسجيل الدخول.';
  }
}

/* messages */
if (isset($_GET['saved'])) $success = '✅ تم حفظ الإعدادات بنجاح.';
if (isset($_GET['logo_deleted'])) $success = '🗑️ تم حذف اللوجو بنجاح.';
if (isset($_GET['teacher_deleted'])) $success = '🗑️ تم حذف صورة المدرس بنجاح.';
if (isset($_GET['register_deleted'])) $success = '🗑️ تم حذف صورة صفحة التسجيل بنجاح.';
if (isset($_GET['login_deleted'])) $success = '🗑️ تم حذف صورة صفحة تسجيل الدخول بنجاح.';
if (isset($_GET['card_added'])) $success = '✅ تم إضافة الكارت بنجاح.';
if (isset($_GET['card_deleted'])) $success = '🗑️ تم حذف الكارت بنجاح.';
if (isset($_GET['card_updated'])) $success = '✅ تم تعديل الكارت بنجاح.';
if (isset($_GET['social_added'])) $success = '✅ تم إضافة السوشيال بنجاح.';
if (isset($_GET['social_deleted'])) $success = '🗑️ تم حذف السوشيال بنجاح.';
if (isset($_GET['social_updated'])) $success = '✅ تم تعديل السوشيال بنجاح.';

/* reload latest */
$row = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch();

$platformName = (string)($row['platform_name'] ?? 'منصتي التعليمية');
$logo = (string)($row['platform_logo'] ?? '');
if ($logo === '') $logo = null;

$heroSmallTitleVal = (string)($row['hero_small_title'] ?? '');
$heroTitleVal = (string)($row['hero_title'] ?? '');
$heroDescriptionVal = (string)($row['hero_description'] ?? '');
$heroButtonTextVal = (string)($row['hero_button_text'] ?? '');
$heroButtonUrlVal = (string)($row['hero_button_url'] ?? '');
$heroTeacherImgVal = (string)($row['hero_teacher_image'] ?? '');
$heroStatsBgTextVal = (string)($row['hero_stats_bg_text'] ?? 'ENGLISH');
$heroStat1ValueVal = (string)($row['hero_stat_1_value'] ?? '');
$heroStat1LabelVal = (string)($row['hero_stat_1_label'] ?? '');
$heroStat2ValueVal = (string)($row['hero_stat_2_value'] ?? '');
$heroStat2LabelVal = (string)($row['hero_stat_2_label'] ?? '');
$heroStat3ValueVal = (string)($row['hero_stat_3_value'] ?? '');
$heroStat3LabelVal = (string)($row['hero_stat_3_label'] ?? '');

$featureCardsEnabledVal = (int)($row['feature_cards_enabled'] ?? 1);
$featureCardsTitleVal = (string)($row['feature_cards_title'] ?? '');

$ctaEnabledVal = (int)($row['cta_enabled'] ?? 1);
$ctaTitleVal = (string)($row['cta_title'] ?? '');
$ctaSubtitleVal = (string)($row['cta_subtitle'] ?? '');
$ctaButtonTextVal = (string)($row['cta_button_text'] ?? '');
$ctaButtonUrlVal = (string)($row['cta_button_url'] ?? '');

$footerEnabledVal = (int)($row['footer_enabled'] ?? 1);
$footerLogoVal = (string)($row['footer_logo_path'] ?? '');
$footerSocialTitleVal = (string)($row['footer_social_title'] ?? 'السوشيال ميديا');
$footerContactTitleVal = (string)($row['footer_contact_title'] ?? 'تواصل معنا');
$footerPhone1Val = (string)($row['footer_phone_1'] ?? '');
$footerPhone2Val = (string)($row['footer_phone_2'] ?? '');
$footerRightsLineVal = (string)($row['footer_rights_line'] ?? '');
$footerDevLineVal = (string)($row['footer_developed_by_line'] ?? '');

$registerImageVal = (string)($row['register_image_path'] ?? '');
$loginImageVal = (string)($row['login_image_path'] ?? '');

$cards = $pdo->query("SELECT * FROM platform_feature_cards ORDER BY sort_order ASC, id ASC")->fetchAll() ?: [];
$socials = $pdo->query("SELECT * FROM platform_footer_social_links ORDER BY sort_order ASC, id ASC")->fetchAll() ?: [];

/* Sidebar menu */
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
  ['key' => 'settings', 'label' => 'الإعدادات', 'icon' => '⚙️', 'href' => 'settings.php', 'active' => true],
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
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>الإعدادات - <?php echo h($platformName); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/settings.css">
  <style>
    .cards-table{width:100%;border-collapse:collapse;margin-top:10px;}
    .cards-table th,.cards-table td{border:1px solid rgba(255,255,255,.12);padding:10px;vertical-align:top;}
    .badge{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:1000;font-size:12px}
    .b-light{background:#fff;color:#111}
    .b-dark{background:#0b7b7a;color:#fff}
    .mini-img{width:56px;height:56px;object-fit:contain;border-radius:12px;background:#fff}
    .input2.small{padding:10px}
    .soc-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    @media (max-width:980px){.soc-grid{grid-template-columns:1fr}}
  </style>
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
      <section class="st-hero">
        <div class="st-hero-title">
          <h1>⚙️ الإعدادات</h1>
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
            <span class="cardx-badge">🛠️</span>
            <h2>إعدادات المنصة</h2>
          </div>
        </div>

        <!-- ✅ FIX: remove nested forms inside this main form -->
        <form class="st-form" method="post" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="action" value="save_settings">

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">🏷️ اسم المنصة</span>
            <input class="input2" name="platform_name" required value="<?php echo h($platformName); ?>" />
          </label>

          <label class="field" style="grid-column:1 / -1;">
            <span class="label">🖼️ لوجو المنصة (اختياري)</span>
            <input class="input2" type="file" name="platform_logo" accept="image/*">
          </label>

          <div class="cardx" style="grid-column:1 / -1; margin: 6px 0 0; padding: 12px;">
            <div style="font-weight:1000; margin-bottom:10px;">🧩 إعدادات سكشن الهيرو</div>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">عنوان صغير</span>
              <input class="input2" name="hero_small_title" value="<?php echo h($heroSmallTitleVal); ?>" />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">العنوان الرئيسي</span>
              <input class="input2" name="hero_title" value="<?php echo h($heroTitleVal); ?>" />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">الشرح</span>
              <textarea class="input2" name="hero_description" style="min-height:120px; padding:12px;"><?php echo h($heroDescriptionVal); ?></textarea>
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">اسم الزر</span>
              <input class="input2" name="hero_button_text" value="<?php echo h($heroButtonTextVal); ?>" />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">رابط الزر</span>
              <input class="input2" name="hero_button_url" value="<?php echo h($heroButtonUrlVal); ?>" />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">صورة المدرس (اختياري)</span>
              <input class="input2" type="file" name="hero_teacher_image" accept="image/*">
            </label>

            <div style="font-weight:1000; margin: 16px 0 10px;">📊 إعدادات الإحصائيات</div>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">النص الخلفي</span>
              <input class="input2" name="hero_stats_bg_text" value="<?php echo h($heroStatsBgTextVal); ?>" />
            </label>

            <div class="cardx" style="grid-column:1 / -1; padding: 10px; margin-top: 6px;">
              <div style="font-weight:1000; margin-bottom:8px;">الإحصائية 1</div>
              <label class="field" style="grid-column:1 / -1;">
                <span class="label">القيمة</span>
                <input class="input2" name="hero_stat_1_value" value="<?php echo h($heroStat1ValueVal); ?>" />
              </label>
              <label class="field" style="grid-column:1 / -1;">
                <span class="label">العنوان</span>
                <input class="input2" name="hero_stat_1_label" value="<?php echo h($heroStat1LabelVal); ?>" />
              </label>
            </div>

            <div class="cardx" style="grid-column:1 / -1; padding: 10px; margin-top: 10px;">
              <div style="font-weight:1000; margin-bottom:8px;">الإحصائية 2</div>
              <label class="field" style="grid-column:1 / -1;">
                <span class="label">القيمة</span>
                <input class="input2" name="hero_stat_2_value" value="<?php echo h($heroStat2ValueVal); ?>" />
              </label>
              <label class="field" style="grid-column:1 / -1;">
                <span class="label">العنوان</span>
                <input class="input2" name="hero_stat_2_label" value="<?php echo h($heroStat2LabelVal); ?>" />
              </label>
            </div>

            <div class="cardx" style="grid-column:1 / -1; padding: 10px; margin-top: 10px;">
              <div style="font-weight:1000; margin-bottom:8px;">الإحصائية 3</div>
              <label class="field" style="grid-column:1 / -1;">
                <span class="label">القيمة</span>
                <input class="input2" name="hero_stat_3_value" value="<?php echo h($heroStat3ValueVal); ?>" />
              </label>
              <label class="field" style="grid-column:1 / -1;">
                <span class="label">العنوان</span>
                <input class="input2" name="hero_stat_3_label" value="<?php echo h($heroStat3LabelVal); ?>" />
              </label>
            </div>

            <div style="font-weight:1000; margin: 18px 0 10px;">🧩 إعدادات جزء (ليه تختار؟)</div>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">تفعيل الجزء</span>
              <select class="input2" name="feature_cards_enabled">
                <option value="1" <?php echo $featureCardsEnabledVal === 1 ? 'selected' : ''; ?>>مفعل</option>
                <option value="0" <?php echo $featureCardsEnabledVal !== 1 ? 'selected' : ''; ?>>مخفي</option>
              </select>
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">عنوان الجزء</span>
              <input class="input2" name="feature_cards_title" value="<?php echo h($featureCardsTitleVal); ?>" />
            </label>

            <div style="font-weight:1000; margin: 18px 0 10px;">🧱 إعدادات البانر أسفل الصفوف</div>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">تفعيل البانر</span>
              <select class="input2" name="cta_enabled">
                <option value="1" <?php echo $ctaEnabledVal === 1 ? 'selected' : ''; ?>>مفعل</option>
                <option value="0" <?php echo $ctaEnabledVal !== 1 ? 'selected' : ''; ?>>مخفي</option>
              </select>
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">السؤال / العنوان الكبير</span>
              <input class="input2" name="cta_title" value="<?php echo h($ctaTitleVal); ?>" placeholder="مثال: جاهز تبدأ رحلة التفوق؟" />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">النص الصغير</span>
              <input class="input2" name="cta_subtitle" value="<?php echo h($ctaSubtitleVal); ?>" placeholder="مثال: احجز حصتك دلوقتي..." />
            </label>

            <label class="field">
              <span class="label">اسم الزر</span>
              <input class="input2" name="cta_button_text" value="<?php echo h($ctaButtonTextVal); ?>" placeholder="مثال: اشترك دلوقتي" />
            </label>

            <label class="field">
              <span class="label">رابط الزر</span>
              <input class="input2" name="cta_button_url" value="<?php echo h($ctaButtonUrlVal); ?>" placeholder="مثال: register.php" />
            </label>

            <div style="font-weight:1000; margin: 18px 0 10px;">🧱 صورة صفحة حساب جديد</div>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">صورة صفحة حساب جديد (تظهر يسار الفورم)</span>
              <input class="input2" type="file" name="register_image" accept="image/*">
              <div style="opacity:.85;font-size:12px;margin-top:6px;">
                ارفع صورة (JPG/PNG/WebP). سيتم استبدال الصورة القديمة تلقائياً.
              </div>

              <?php if (!empty($registerImageVal)): ?>
                <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
                  <img src="<?php echo h($registerImageVal); ?>" alt="register preview" style="width:86px;height:86px;object-fit:cover;border-radius:14px;background:#fff;">
                  <span class="badge b-light">موجودة ✅</span>

                  <!-- ✅ FIX: no nested form -->
                  <button
                    type="button"
                    class="link st-btn-danger"
                    onclick="if(confirm('حذف صورة صفحة التسجيل؟')) document.getElementById('delRegisterImageForm').submit();"
                  >🗑️ حذف صورة صفحة التسجيل</button>
                </div>
              <?php else: ?>
                <div style="margin-top:10px;">
                  <span class="badge b-light">غير محددة</span>
                </div>
              <?php endif; ?>
            </label>

            <div style="font-weight:1000; margin: 18px 0 10px;">🧱 صورة صفحة تسجيل الدخول</div>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">صورة صفحة تسجيل الدخول (تظهر يمين الصفحة)</span>
              <input class="input2" type="file" name="login_image" accept="image/*">
              <div style="opacity:.85;font-size:12px;margin-top:6px;">
                ارفع صورة (JPG/PNG/WebP). سيتم استبدال الصورة القديمة تلقائياً.
              </div>

              <?php if (!empty($loginImageVal)): ?>
                <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
                  <img src="<?php echo h($loginImageVal); ?>" alt="login preview" style="width:86px;height:86px;object-fit:cover;border-radius:14px;background:#fff;">
                  <span class="badge b-light">موجودة ✅</span>

                  <!-- ✅ FIX: no nested form -->
                  <button
                    type="button"
                    class="link st-btn-danger"
                    onclick="if(confirm('حذف صورة صفحة تسجيل الدخول؟')) document.getElementById('delLoginImageForm').submit();"
                  >🗑️ حذف صورة تسجيل الدخول</button>
                </div>
              <?php else: ?>
                <div style="margin-top:10px;">
                  <span class="badge b-light">غير محددة</span>
                </div>
              <?php endif; ?>
            </label>

            <div style="font-weight:1000; margin: 18px 0 10px;">🦶 إعدادات الفوتر (أسفل الصفحة)</div>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">تفعيل الفوتر</span>
              <select class="input2" name="footer_enabled">
                <option value="1" <?php echo $footerEnabledVal === 1 ? 'selected' : ''; ?>>مفعل</option>
                <option value="0" <?php echo $footerEnabledVal !== 1 ? 'selected' : ''; ?>>مخفي</option>
              </select>
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">لوجو الفوتر (اختياري)</span>
              <input class="input2" type="file" name="footer_logo" accept="image/*">
            </label>

            <label class="field">
              <span class="label">عنوان السوشيال</span>
              <input class="input2" name="footer_social_title" value="<?php echo h($footerSocialTitleVal); ?>" placeholder="مثال: السوشيال ميديا" />
            </label>

            <label class="field">
              <span class="label">عنوان التواصل</span>
              <input class="input2" name="footer_contact_title" value="<?php echo h($footerContactTitleVal); ?>" placeholder="مثال: تواصل معنا" />
            </label>

            <label class="field">
              <span class="label">رقم تواصل 1</span>
              <input class="input2" name="footer_phone_1" value="<?php echo h($footerPhone1Val); ?>" placeholder="010..." />
            </label>

            <label class="field">
              <span class="label">رقم تواصل 2</span>
              <input class="input2" name="footer_phone_2" value="<?php echo h($footerPhone2Val); ?>" placeholder="010..." />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">سطر الحقوق</span>
              <input class="input2" name="footer_rights_line" value="<?php echo h($footerRightsLineVal); ?>" placeholder="مثال: © جميع الحقوق محفوظة..." />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">سطر البرمجة</span>
              <input class="input2" name="footer_developed_by_line" value="<?php echo h($footerDevLineVal); ?>" placeholder="مثال: Developed by Eng...." />
            </label>
          </div>

          <div class="st-actions" style="grid-column:1 / -1;">
            <button class="btn st-btn-save" type="submit">💾 حفظ الإعدادات</button>

            <?php if (!empty($logo)): ?>
              <!-- ✅ FIX: no nested form -->
              <button
                type="button"
                class="link st-btn-danger"
                onclick="if(confirm('هل أنت متأكد من حذف اللوجو؟')) document.getElementById('delLogoForm').submit();"
              >🗑️ حذف اللوجو</button>
            <?php endif; ?>

            <?php if (!empty($heroTeacherImgVal)): ?>
              <!-- ✅ FIX: no nested form -->
              <button
                type="button"
                class="link st-btn-danger"
                onclick="if(confirm('هل أنت متأكد من حذف صورة المدرس؟')) document.getElementById('delTeacherImageForm').submit();"
              >🗑️ حذف صورة المدرس</button>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <!-- Feature cards CRUD -->
      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">🧩</span>
            <h2>إدارة كروت (ليه تختار؟)</h2>
          </div>
        </div>

        <div style="padding: 12px 14px;">
          <h3 style="margin:0 0 10px; font-weight:1000;">➕ إضافة كارت جديد</h3>

          <form method="post" enctype="multipart/form-data" class="st-form" style="margin-top:10px;">
            <input type="hidden" name="action" value="add_feature_card">

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">عنوان الكارت</span>
              <input class="input2" name="fc_title" required />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">وصف الكارت</span>
              <textarea class="input2" name="fc_body" style="min-height:110px; padding:12px;"></textarea>
            </label>

            <label class="field">
              <span class="label">لون الكارت</span>
              <select class="input2" name="fc_theme">
                <option value="light">فاتح</option>
                <option value="dark">غامق</option>
              </select>
            </label>

            <label class="field">
              <span class="label">الترتيب</span>
              <input class="input2" name="fc_sort" value="0" />
            </label>

            <label class="field">
              <span class="label">مفعل؟</span>
              <select class="input2" name="fc_active">
                <option value="1">نعم</option>
                <option value="0">لا</option>
              </select>
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">صورة/أيقونة الكارت</span>
              <input class="input2" type="file" name="fc_icon" accept="image/*">
            </label>

            <div class="st-actions" style="grid-column:1 / -1;">
              <button class="btn st-btn-save" type="submit">✅ إضافة</button>
            </div>
          </form>

          <h3 style="margin:18px 0 8px; font-weight:1000;">📦 الكروت الحالية</h3>

          <?php if (!$cards): ?>
            <div class="st-empty">لا توجد كروت حالياً.</div>
          <?php else: ?>
            <table class="cards-table">
              <thead>
                <tr>
                  <th>الصورة</th>
                  <th>البيانات</th>
                  <th>تعديل</th>
                  <th>حذف</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cards as $c): ?>
                  <?php
                    $icon = trim((string)($c['icon_path'] ?? ''));
                    $iconUrl = $icon !== '' ? h($icon) : '';
                    $theme = ((string)($c['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
                  ?>
                  <tr>
                    <td style="width:90px;">
                      <?php if ($iconUrl !== ''): ?>
                        <img class="mini-img" src="<?php echo $iconUrl; ?>" alt="">
                      <?php else: ?>
                        <div style="width:56px;height:56px;border-radius:12px;background:#fff;opacity:.5"></div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div style="margin-bottom:6px;">
                        <span class="badge <?php echo $theme === 'dark' ? 'b-dark' : 'b-light'; ?>">
                          <?php echo $theme === 'dark' ? 'غامق' : 'فاتح'; ?>
                        </span>
                        <span class="badge b-light">ترتيب: <?php echo (int)$c['sort_order']; ?></span>
                        <span class="badge b-light"><?php echo ((int)$c['is_active'] === 1) ? 'مفعل' : 'غير مفعل'; ?></span>
                      </div>
                      <div style="font-weight:1000;"><?php echo h((string)$c['title']); ?></div>
                      <div style="opacity:.9; margin-top:6px; white-space:pre-line;"><?php echo h((string)($c['body'] ?? '')); ?></div>
                    </td>

                    <td style="width:360px;">
                      <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_feature_card">
                        <input type="hidden" name="fc_id" value="<?php echo (int)$c['id']; ?>">

                        <input class="input2 small" name="fc_title" value="<?php echo h((string)$c['title']); ?>" placeholder="العنوان" style="width:100%; margin-bottom:8px;" />
                        <textarea class="input2 small" name="fc_body" style="width:100%; min-height:90px; padding:10px;"><?php echo h((string)($c['body'] ?? '')); ?></textarea>

                        <div style="display:flex; gap:8px; margin-top:8px;">
                          <select class="input2 small" name="fc_theme">
                            <option value="light" <?php echo $theme === 'light' ? 'selected' : ''; ?>>فاتح</option>
                            <option value="dark" <?php echo $theme === 'dark' ? 'selected' : ''; ?>>غامق</option>
                          </select>

                          <select class="input2 small" name="fc_active">
                            <option value="1" <?php echo ((int)$c['is_active'] === 1) ? 'selected' : ''; ?>>مفعل</option>
                            <option value="0" <?php echo ((int)$c['is_active'] !== 1) ? 'selected' : ''; ?>>غير مفعل</option>
                          </select>

                          <input class="input2 small" name="fc_sort" value="<?php echo (int)$c['sort_order']; ?>" style="width:110px;" />
                        </div>

                        <div style="margin-top:8px;">
                          <input class="input2 small" type="file" name="fc_icon" accept="image/*">
                          <div style="opacity:.8;font-size:12px;margin-top:6px;">لو رفعت صورة هنا سيتم استبدال الصورة القديمة.</div>
                        </div>

                        <div style="margin-top:8px;">
                          <button class="btn st-btn-save" type="submit">💾 حفظ</button>
                        </div>
                      </form>
                    </td>

                    <td style="width:120px;">
                      <form method="post" onsubmit="return confirm('هل أنت متأكد من حذف الكارت؟');">
                        <input type="hidden" name="action" value="delete_feature_card">
                        <input type="hidden" name="fc_id" value="<?php echo (int)$c['id']; ?>">
                        <button class="link st-btn-danger" type="submit">🗑️ حذف</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>

      <!-- Footer socials CRUD -->
      <section class="cardx" style="margin-top:12px;">
        <div class="cardx-head">
          <div class="cardx-title">
            <span class="cardx-badge">🌐</span>
            <h2>إدارة روابط السوشيال (الفوتر)</h2>
          </div>
        </div>

        <div style="padding: 12px 14px;">
          <h3 style="margin:0 0 10px; font-weight:1000;">➕ إضافة رابط</h3>

          <form method="post" enctype="multipart/form-data" class="st-form" style="margin-top:10px;">
            <input type="hidden" name="action" value="add_social">

            <label class="field">
              <span class="label">الاسم</span>
              <input class="input2" name="soc_label" required placeholder="فيسبوك" />
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">الرابط</span>
              <input class="input2" name="soc_url" required placeholder="https://..." />
            </label>

            <label class="field">
              <span class="label">الترتيب</span>
              <input class="input2" name="soc_sort" value="0" />
            </label>

            <label class="field">
              <span class="label">مفعل؟</span>
              <select class="input2" name="soc_active">
                <option value="1">نعم</option>
                <option value="0">لا</option>
              </select>
            </label>

            <label class="field" style="grid-column:1 / -1;">
              <span class="label">أيقونة (صورة)</span>
              <input class="input2" type="file" name="soc_icon_file" accept="image/*">
              <div style="opacity:.8;font-size:12px;margin-top:6px;">
                ارفع PNG/WebP/JPG... (اختياري). لو لم ترفع، سيتم عرض أيقونة افتراضية في الموقع.
              </div>
            </label>

            <div class="st-actions" style="grid-column:1 / -1;">
              <button class="btn st-btn-save" type="submit">✅ إضافة</button>
            </div>
          </form>

          <h3 style="margin:18px 0 8px; font-weight:1000;">📦 الروابط الحالية</h3>

          <?php if (!$socials): ?>
            <div class="st-empty">لا توجد روابط حالياً.</div>
          <?php else: ?>
            <div class="soc-grid">
              <?php foreach ($socials as $s): ?>
                <?php
                  $iconDb = trim((string)($s['icon_path'] ?? ''));
                  $iconUrl = $iconDb !== '' ? h($iconDb) : '';
                ?>
                <div class="cardx" style="padding:12px;">

                  <!-- ✅ FIX: no nested form. use one form for update, and a button that submits hidden delete form -->
                  <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_social">
                    <input type="hidden" name="soc_id" value="<?php echo (int)$s['id']; ?>">

                    <div style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                      <span class="badge b-light">#<?php echo (int)$s['id']; ?></span>
                      <span class="badge b-light">ترتيب: <?php echo (int)$s['sort_order']; ?></span>
                      <span class="badge b-light"><?php echo ((int)$s['is_active'] === 1) ? 'مفعل' : 'غير مفعل'; ?></span>
                      <?php if ($iconUrl !== ''): ?>
                        <img class="mini-img" src="<?php echo $iconUrl; ?>" alt="">
                      <?php endif; ?>
                    </div>

                    <label class="field" style="grid-column:1 / -1;">
                      <span class="label">الاسم</span>
                      <input class="input2 small" name="soc_label" value="<?php echo h((string)$s['label']); ?>" />
                    </label>

                    <label class="field">
                      <span class="label">الترتيب</span>
                      <input class="input2 small" name="soc_sort" value="<?php echo (int)$s['sort_order']; ?>" />
                    </label>

                    <label class="field">
                      <span class="label">مفعل؟</span>
                      <select class="input2 small" name="soc_active">
                        <option value="1" <?php echo ((int)$s['is_active'] === 1) ? 'selected' : ''; ?>>نعم</option>
                        <option value="0" <?php echo ((int)$s['is_active'] !== 1) ? 'selected' : ''; ?>>لا</option>
                      </select>
                    </label>

                    <label class="field" style="grid-column:1 / -1;">
                      <span class="label">الرابط</span>
                      <input class="input2 small" name="soc_url" value="<?php echo h((string)$s['url']); ?>" />
                    </label>

                    <label class="field" style="grid-column:1 / -1;">
                      <span class="label">استبدال الأيقونة (اختياري)</span>
                      <input class="input2 small" type="file" name="soc_icon_file" accept="image/*">
                    </label>

                    <div class="st-actions" style="grid-column:1 / -1; display:flex; justify-content:space-between;">
                      <button class="btn st-btn-save" type="submit">💾 حفظ</button>

                      <button
                        type="button"
                        class="link st-btn-danger"
                        onclick="if(confirm('حذف الرابط؟')) document.getElementById('delSocialForm-<?php echo (int)$s['id']; ?>').submit();"
                      >🗑️ حذف</button>
                    </div>
                  </form>

                  <!-- hidden delete form (outside update form) -->
                  <form id="delSocialForm-<?php echo (int)$s['id']; ?>" method="post" style="display:none;">
                    <input type="hidden" name="action" value="delete_social">
                    <input type="hidden" name="soc_id" value="<?php echo (int)$s['id']; ?>">
                  </form>

                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>
      </section>

    </main>
  </div>

  <!-- ✅ Hidden forms to avoid nested forms inside the main save_settings form -->
  <form id="delRegisterImageForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete_register_image">
  </form>

  <form id="delLoginImageForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete_login_image">
  </form>

  <form id="delLogoForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete_logo">
  </form>

  <form id="delTeacherImageForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete_teacher_image">
  </form>

  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <script>
    (function () {
      const root = document.body;

      const themeSwitch = document.getElementById('themeSwitch');
      const stored = localStorage.getItem('admin_theme') || 'auto';

      function osPrefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      }
      function applyTheme(mode) {
        root.setAttribute('data-theme', mode);
        localStorage.setItem('admin_theme', mode);
        if (themeSwitch) themeSwitch.checked = (mode === 'dark') || (mode === 'auto' && osPrefersDark());
      }
      applyTheme(stored);

      themeSwitch && themeSwitch.addEventListener('change', () => applyTheme(themeSwitch.checked ? 'dark' : 'light'));
      if (stored === 'auto' && window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme('auto'));
      }

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
      backdrop && backdrop.addEventListener('click', (e) => { e.preventDefault(); closeSidebar(); });
      window.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
      window.addEventListener('resize', syncInitial);
    })();
  </script>
</body>
</html>