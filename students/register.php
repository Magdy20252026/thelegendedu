<?php
// students/register.php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require __DIR__ . '/inc/student_auth.php';

no_cache_headers();
student_redirect_if_logged_in('account.php');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

/* =========================
   Helpers (validation)
   ========================= */
function normalize_phone(string $p): string {
  $p = trim((string)$p);
  return (string)preg_replace('/[^\d\+]/', '', $p);
}

function is_arabic_name_3plus(string $name): bool {
  $name = trim((string)preg_replace('/\s+/u', ' ', $name));
  if ($name === '') return false;

  // Arabic letters + spaces only
  if (!preg_match('/^[\p{Arabic}\s]+$/u', $name)) return false;

  $parts = array_values(array_filter(explode(' ', $name), fn($p) => trim($p) !== ''));
  return count($parts) >= 3;
}

function has_required_center_barcode_prefix(string $barcode): bool {
  return strlen($barcode) > 2 && strncmp($barcode, 'WA', 2) === 0;
}

/* =========================
   Data for selects
   ========================= */
$governorates = [
  'القاهرة','الجيزة','الإسكندرية','الدقهلية','البحر الأحمر','البحيرة','الفيوم','الغربية',
  'الإسماعيلية','المنوفية','المنيا','القليوبية','الوادي الجديد','السويس','اسوان','اسيوط',
  'بني سويف','بورسعيد','دمياط','الشرقية','جنوب سيناء','كفر الشيخ','مطروح','الأقصر',
  'قنا','شمال سيناء','سوهاج'
];

$gradesList = [];
$centersList = [];
try {
  $gradesList = $pdo->query("SELECT id, name FROM grades WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $gradesList = []; }

try {
  $centersList = $pdo->query("SELECT id, name FROM centers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $centersList = []; }

/* =========================
   Platform settings (header/footer + register image)
   ========================= */
$row = get_platform_settings_row($pdo);

// ✅ register image from settings
$registerImageDb = trim((string)($row['register_image_path'] ?? ''));
$registerImageUrl = null;
if ($registerImageDb !== '') {
  $registerImageUrl = student_public_asset_url($registerImageDb);
}

/* =========================
   Footer data
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
   Handle POST (create student)
   ========================= */
$errors = [];
$successMessage = null;
$createdOk = false;

$fullName = trim((string)($_POST['full_name'] ?? ''));
$studentPhone = normalize_phone((string)($_POST['student_phone'] ?? ''));
$parentPhone = normalize_phone((string)($_POST['parent_phone'] ?? ''));
$governorate = trim((string)($_POST['governorate'] ?? ''));
$gradeId = (int)($_POST['grade_id'] ?? 0);
$status = (string)($_POST['status'] ?? 'اونلاين');
$centerId = (int)($_POST['center_id'] ?? 0);
$groupId = (int)($_POST['group_id'] ?? 0);
$barcode = trim((string)($_POST['barcode'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!is_arabic_name_3plus($fullName)) $errors[] = 'اسم الطالب يجب أن يكون ثلاثي (3 كلمات أو أكثر) وباللغة العربية.';
  if ($studentPhone === '') $errors[] = 'رقم هاتف الطالب مطلوب.';
  if ($password === '') $errors[] = 'كلمة السر مطلوبة.';
  if ($governorate === '' || !in_array($governorate, $governorates, true)) $errors[] = 'من فضلك اختر المحافظة.';
  if ($gradeId <= 0) $errors[] = 'من فضلك اختر الصف الدراسي.';
  if (!in_array($status, ['اونلاين','سنتر'], true)) $errors[] = 'حالة الطالب غير صحيحة.';

  if ($status === 'سنتر') {
    if ($centerId <= 0) $errors[] = 'من فضلك اختر السنتر.';
    if ($groupId <= 0) $errors[] = 'من فضلك اختر المجموعة.';
    if ($barcode === '') $errors[] = 'باركود الحضور مطلوب عند اختيار "سنتر".';
    if ($barcode !== '' && !has_required_center_barcode_prefix($barcode)) $errors[] = 'باركود الحضور يجب أن يبدأ بـ WA بحروف كبيرة.';
  } else {
    $centerId = 0; $groupId = 0; $barcode = '';
  }

  if (!$errors) {
    try {
      $stmt = $pdo->prepare("SELECT id FROM students WHERE student_phone=? LIMIT 1");
      $stmt->execute([$studentPhone]);
      if ($stmt->fetch()) $errors[] = 'رقم هاتف الطالب مسجل من قبل ولا يمكن تكراره.';

      if ($status === 'سنتر' && $barcode !== '') {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE barcode=? LIMIT 1");
        $stmt->execute([$barcode]);
        if ($stmt->fetch()) $errors[] = 'باركود الطالب مسجل من قبل ولا يمكن تكراره.';
      }

      $stmt = $pdo->prepare("SELECT id FROM grades WHERE id=? AND is_active=1 LIMIT 1");
      $stmt->execute([$gradeId]);
      if (!$stmt->fetch()) $errors[] = 'الصف الدراسي غير موجود.';

      if ($status === 'سنتر') {
        $stmt = $pdo->prepare("SELECT id FROM centers WHERE id=? LIMIT 1");
        $stmt->execute([$centerId]);
        if (!$stmt->fetch()) $errors[] = 'السنتر غير موجود.';

        $stmt = $pdo->prepare("SELECT id FROM `groups` WHERE id=? AND grade_id=? AND center_id=? LIMIT 1");
        $stmt->execute([$groupId, $gradeId, $centerId]);
        if (!$stmt->fetch()) $errors[] = 'المجموعة غير صحيحة (لا تتبع الصف الدراسي والسنتر المختارين).';
      }
    } catch (Throwable $e) {
      $errors[] = 'حدث خطأ أثناء التحقق من البيانات. حاول مرة أخرى.';
    }
  }

  if (!$errors) {
    try {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      $stmt = $pdo->prepare("
        INSERT INTO students
          (full_name, student_phone, parent_phone, grade_id, governorate, status, center_id, group_id, barcode, wallet_balance, password_hash, password_plain)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
      ");
      $stmt->execute([
        $fullName,
        $studentPhone,
        ($parentPhone !== '' ? $parentPhone : null),
        $gradeId,
        $governorate,
        $status,
        ($status === 'سنتر' ? $centerId : null),
        ($status === 'سنتر' ? $groupId : null),
        ($status === 'سنتر' ? $barcode : null),
        $hash,
        $password
      ]);

      $createdOk = true;
      $successMessage = 'اهلا بيك ياضنايا يلا ورنا شغل كتير أووي سوا ❤️';
    } catch (Throwable $e) {
      $errors[] = 'تعذر إنشاء الحساب (تحقق من البيانات: رقم الهاتف/الباركود ربما مكرر).';
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

  <title>حساب جديد</title>
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

      <?php if ($createdOk && $successMessage): ?>
        <div class="auth-toast" id="successToast" role="status" aria-live="polite">
          <?php echo h($successMessage); ?>
        </div>
      <?php endif; ?>

      <section class="auth-card" aria-label="إنشاء حساب جديد">
        <div class="auth-grid">

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

              <h1 class="auth-head__title">انشئ حسابك الآن :</h1>
              <p class="auth-head__sub">ادخل بياناتك بشكل صحيح للحصول على أفضل تجربة داخل الموقع</p>
            </div>

            <form class="auth-form" method="post" autocomplete="off" novalidate>
              <div class="fgrid">
                <label class="field">
                  <span class="label">اسم الطالب</span>
                  <input class="input" name="full_name" value="<?php echo h($fullName); ?>" placeholder="مثال: محمد أحمد علي" required>
                </label>

                <label class="field">
                  <span class="label">المحافظة</span>
                  <select class="input" name="governorate" required>
                    <option value="">— اختر المحافظة —</option>
                    <?php foreach ($governorates as $gov): ?>
                      <option value="<?php echo h($gov); ?>" <?php echo ($governorate === $gov) ? 'selected' : ''; ?>>
                        <?php echo h($gov); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>

              <div class="fgrid">
                <label class="field">
                  <span class="label">رقم هاتف الطالب</span>
                  <input class="input" name="student_phone" inputmode="numeric" pattern="[0-9]*"
                         value="<?php echo h($studentPhone); ?>" placeholder="010xxxxxxxx" required>
                </label>

                <label class="field">
                  <span class="label">رقم هاتف ولي الأمر</span>
                  <input class="input" name="parent_phone" inputmode="numeric" pattern="[0-9]*"
                         value="<?php echo h($parentPhone); ?>" placeholder="010xxxxxxxx">
                </label>
              </div>

              <div class="fgrid">
                <label class="field">
                  <span class="label">الصف الدراسي</span>
                  <select class="input" name="grade_id" id="gradeSelect" required>
                    <option value="0">— اختر الصف —</option>
                    <?php foreach ($gradesList as $g): ?>
                      <option value="<?php echo (int)$g['id']; ?>" <?php echo ($gradeId === (int)$g['id']) ? 'selected' : ''; ?>>
                        <?php echo h((string)$g['name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>

                <label class="field">
                  <span class="label">حالة الطالب</span>
                  <select class="input" name="status" id="statusSelect" required>
                    <option value="اونلاين" <?php echo ($status === 'اونلاين') ? 'selected' : ''; ?>>اونلاين</option>
                    <option value="سنتر" <?php echo ($status === 'سنتر') ? 'selected' : ''; ?>>سنتر</option>
                  </select>
                </label>
              </div>

              <div class="center-area" id="centerArea">
                <div class="fgrid">
                  <label class="field">
                    <span class="label">السنتر</span>
                    <select class="input" name="center_id" id="centerSelect">
                      <option value="0">— اختر السنتر —</option>
                      <?php foreach ($centersList as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo ($centerId === (int)$c['id']) ? 'selected' : ''; ?>>
                          <?php echo h((string)$c['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>

                  <label class="field">
                    <span class="label">المجموعة</span>
                    <select class="input" name="group_id" id="groupSelect">
                      <option value="0">— اختر المجموعة —</option>
                    </select>
                  </label>
                </div>

                <label class="field">
                  <span class="label">باركود الحضور</span>
                  <input class="input" name="barcode" id="barcodeInput" value="<?php echo h($barcode); ?>" placeholder="مثال: WA123456789" pattern="WA.+" title="يجب أن يبدأ الباركود بـ WA بحروف كبيرة">
                </label>
              </div>

              <label class="field">
                <span class="label">كلمة السر</span>
                <input class="input" name="password" type="password" required placeholder="••••••••">
              </label>

              <button class="btn-submit" type="submit">إنشاء الحساب</button>

              <div class="auth-foot">
                <span>لدي حساب؟</span>
                <a class="auth-link" href="login.php">ادخل إلى حسابك الآن!</a>
              </div>
            </form>
          </div>

          <div class="auth-imgPane" aria-hidden="true">
            <?php if ($registerImageUrl): ?>
              <img class="auth-img" src="<?php echo h($registerImageUrl); ?>" alt="">
            <?php else: ?>
              <div class="auth-imgFallback">
                <div class="auth-imgFallback__txt">صورة التسجيل من الإعدادات</div>
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
      const statusSelect = document.getElementById('statusSelect');
      const gradeSelect = document.getElementById('gradeSelect');
      const centerSelect = document.getElementById('centerSelect');
      const groupSelect = document.getElementById('groupSelect');
      const centerArea = document.getElementById('centerArea');
      const barcodeInput = document.getElementById('barcodeInput');

      function toggleCenter(){
        const st = statusSelect ? statusSelect.value : 'اونلاين';
        const show = (st === 'سنتر');
        if (centerArea) centerArea.style.display = show ? '' : 'none';

        if (centerSelect) centerSelect.required = show;
        if (groupSelect) groupSelect.required = show;
        if (barcodeInput) barcodeInput.required = show;
      }

      async function fetchGroups(){
        if (!gradeSelect || !centerSelect || !groupSelect) return;

        const gradeId = parseInt(gradeSelect.value || '0', 10);
        const centerId = parseInt(centerSelect.value || '0', 10);

        groupSelect.innerHTML = '<option value="0">— اختر المجموعة —</option>';
        if (!gradeId || !centerId) return;

        try{
          const url = 'students_groups_api.php?grade_id=' + encodeURIComponent(gradeId) + '&center_id=' + encodeURIComponent(centerId);
          const res = await fetch(url, { credentials: 'same-origin' });
          const data = await res.json();
          if (!data || !Array.isArray(data.groups)) return;

          data.groups.forEach(g => {
            const opt = document.createElement('option');
            opt.value = String(g.id);
            opt.textContent = g.name;
            groupSelect.appendChild(opt);
          });

          const selectedGroupId = <?php echo (int)$groupId; ?>;
          if (selectedGroupId > 0) groupSelect.value = String(selectedGroupId);
        }catch(e){}
      }

      statusSelect && statusSelect.addEventListener('change', () => {
        toggleCenter();
        fetchGroups();
      });
      gradeSelect && gradeSelect.addEventListener('change', fetchGroups);
      centerSelect && centerSelect.addEventListener('change', fetchGroups);

      toggleCenter();
      fetchGroups();

      <?php if ($createdOk): ?>
      window.setTimeout(function(){
        window.location.href = 'login.php';
      }, 3000);
      <?php endif; ?>
    })();
  </script>

  <script src="assets/js/theme.js"></script>
</body>
</html>
