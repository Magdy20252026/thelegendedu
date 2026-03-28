<?php
// students/login.php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require_once __DIR__ . '/inc/device_lock.php'; // ✅ NEW
require __DIR__ . '/inc/student_auth.php';

no_cache_headers();
student_redirect_if_logged_in('account.php');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

function normalize_phone(string $p): string {
  $p = trim((string)$p);
  return (string)preg_replace('/[^\d\+]/', '', $p);
}

/* =========================
   Platform settings (header/footer + login image)
   ========================= */
$row = get_platform_settings_row($pdo);

// login image from settings (admin)
$loginImageDb = trim((string)($row['login_image_path'] ?? ''));
$loginImageUrl = null;
if ($loginImageDb !== '') {
  $loginImageUrl = student_public_asset_url($loginImageDb);
}

/* =========================
   Footer data (same logic like register.php)
   ========================= */
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
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm7.9 9h-3.2a15.7 15.7 0 0 0-1.2-5A8.1 8.1 0 0 1 19.9 11zM12 4c.8 1 1.7 2.8 2.2 7H9.8c.5-4.2 1.4-6 2.2-7zM4.1 13h3.2a15.7 15.7 0 0 0 1.2 5A8.1 8.1 0 0 1 4.1 13zm3.2-2H4.1A8.1 8.1 0 0 1 8.5 6a15.7 15.7 0 0 0-1.2 5zm2.5 2h4.4c-.5 4.2-1.4 6-2.2 7c-.8-1-1.7-2.8-2.2-7zm5.7 5a15.7 15.7 0 0 0 1.2-5h3.2a8.1 8.1 0 0 1-4.4 5z"/></svg>';
}

/* =========================
   Handle POST
   ========================= */
$errors = [];
$toastMessage = null;
$toastOk = false;
$redirectToAccount = false;

$studentPhone = normalize_phone((string)($_POST['student_phone'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$action = (string)($_POST['action'] ?? 'login');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($action === 'login') {
    if ($studentPhone === '') $errors[] = '��قم التليفون مطلوب.';
    if ($password === '') $errors[] = 'كلمة السر مطلوبة.';

    if (!$errors) {
      try {
        $stmt = $pdo->prepare("SELECT id, full_name, password_hash, is_active FROM students WHERE student_phone=? LIMIT 1");
        $stmt->execute([$studentPhone]);
        $st = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$st) {
          $errors[] = 'رقم التليفون أو كلمة السر غير صحيحة.';
        } elseif ((int)($st['is_active'] ?? 1) !== 1) {
          $errors[] = 'هذا الحساب غير مفعل.';
        } else {
          $hash = (string)($st['password_hash'] ?? '');
          if ($hash === '' || !password_verify($password, $hash)) {
            $errors[] = 'رقم التليفون أو كلمة السر غير صحيحة.';
          } else {
            $check = device_lock_check_and_register($pdo, (int)$st['id']);
            if (!$check['ok']) {
              $errors[] = 'لا يمكن تسجيل الدخول من هذا الجهاز. الحساب مرتبط بجهاز واحد فقط. تواصل مع الإدارة لحذف الجهاز من لوحة التحكم ثم جرّب مرة أخرى.';
            } else {
              $_SESSION['student_id'] = (int)$st['id'];
              $_SESSION['student_name'] = (string)$st['full_name'];

              $toastOk = true;
              $toastMessage = 'ليك وحشه ياضنايا يلا وخليك مركز معايا  ❤️';
              $redirectToAccount = true;
            }
          }
        }
      } catch (Throwable $e) {
        $errors[] = 'حدث خطأ أثناء تسجيل الدخول. حاول مرة أخرى.';
      }
    }
  } elseif ($action === 'forgot') {
    if ($studentPhone === '') $errors[] = 'رقم التليفون مطلوب.';

    if (!$errors) {
      try {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE student_phone=? LIMIT 1");
        $stmt->execute([$studentPhone]);
        $st = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$st) {
          $errors[] = 'رقم التليفون غير موجود.';
        } else {
          $newPass = '123456';
          $newHash = password_hash($newPass, PASSWORD_DEFAULT);

          $up = $pdo->prepare("UPDATE students SET password_hash=?, password_plain=? WHERE id=?");
          $up->execute([$newHash, $newPass, (int)$st['id']]);

          $toastOk = true;
          $toastMessage = 'ولا يهمك ياضنايا ❤️ الباسورد بتاعك بقا 123456 وتقدر تغيره من إعدادت الحساب بس ابقا ركز المرة الجاية';
        }
      } catch (Throwable $e) {
        $errors[] = 'تعذر إعادة تعيين كلمة السر. حاول مرة أخرى.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="stylesheet" href="assets/css/header.css">
  <link rel="stylesheet" href="assets/css/footer.css">
  <link rel="stylesheet" href="assets/css/register.css">

  <title>تسجيل الدخول</title>
</head>
<body>

  <?php require __DIR__ . '/inc/header.php'; ?>

  <main class="auth-page">
    <div class="container">

      <?php if (!empty($errors)): ?>
        <div class="auth-alert" role="alert">
          <div class="auth-alert__title">⚠️ يوجد أخطاء:</div>
          <ul class="auth-alert__list">
            <?php foreach ($errors as $er): ?>
              <li><?php echo h($er); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($toastOk && $toastMessage): ?>
        <div class="auth-toast" id="successToast" role="status" aria-live="polite">
          <?php echo h($toastMessage); ?>
        </div>
      <?php endif; ?>

      <section class="auth-card" aria-label="تسجيل الدخول">
        <div class="auth-grid">

          <!-- ✅ FORM -->
          <div class="auth-formPane">
            <div class="auth-step">
              <span class="auth-step__line" aria-hidden="true"></span>
            </div>

            <div class="auth-head">
              <div class="auth-head__logo">
                <?php
                  $platLogoDb = trim((string)($row['platform_logo'] ?? ''));
                  $platLogoUrl = null;
                  if ($platLogoDb !== '') $platLogoUrl = student_public_asset_url($platLogoDb);
                ?>
                <?php if ($platLogoUrl): ?>
                  <img src="<?php echo h($platLogoUrl); ?>" alt="Logo">
                <?php else: ?>
                  <div class="auth-head__logoFallback" aria-hidden="true"></div>
                <?php endif; ?>
              </div>

              <h1 class="auth-head__title">سجل دخولك الآن :</h1>
              <p class="auth-head__sub">ادخل رقم التليفون وكلمة السر للدخول إلى حسابك</p>
            </div>

            <form class="auth-form" method="post" autocomplete="off" novalidate>
              <input type="hidden" name="action" value="login">

              <label class="field">
                <span class="label">رقم التليفون</span>
                <input class="input" name="student_phone" inputmode="numeric" pattern="[0-9]*"
                       value="<?php echo h($studentPhone); ?>" placeholder="010xxxxxxxx" required>
              </label>

              <label class="field">
                <span class="label">كلمة السر</span>
                <input class="input" name="password" type="password" required placeholder="••••••••">
              </label>

              <button class="btn-submit" type="submit">تسجيل الدخول</button>

              <div class="auth-foot" style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                <button type="submit"
                        name="action"
                        value="forgot"
                        class="auth-link"
                        style="background:transparent;border:0;padding:0;cursor:pointer;">
                  نسيت كلمة السر؟
                </button>
              </div>

              <div class="auth-foot" style="margin-top:10px;">
                <span>لا يوجد لديك حساب؟</span>
                <a class="auth-link" href="register.php">انشئ حسابك الآن !</a>
              </div>
            </form>
          </div>

          <!-- ✅ IMAGE -->
          <div class="auth-imgPane" aria-hidden="true">
            <?php if ($loginImageUrl): ?>
              <img class="auth-img" src="<?php echo h($loginImageUrl); ?>" alt="">
            <?php else: ?>
              <div class="auth-imgFallback">
                <div class="auth-imgFallback__txt">صورة تسجيل الدخول من الإعدادات</div>
              </div>
            <?php endif; ?>
          </div>

        </div>
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

  <script>
    (function(){
      const ok = <?php echo $toastOk ? 'true' : 'false'; ?>;
      const shouldRedirect = <?php echo $redirectToAccount ? 'true' : 'false'; ?>;

      if (ok && shouldRedirect) {
        window.setTimeout(function(){
          window.location.href = 'account.php';
        }, 3000);
      }
    })();
  </script>

  <script src="assets/js/theme.js"></script>
</body>
</html>