<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function require_login(): void {
  if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
  }
}

function get_platform_settings(PDO $pdo): array {
  $stmt = $pdo->query("SELECT platform_name, platform_logo FROM settings WHERE id = 1 LIMIT 1");
  $row = $stmt->fetch();
  return $row ?: ['platform_name' => 'منصتي التعليمية', 'platform_logo' => null];
}

/**
 * ✅ keys المسموحة للقائمة (الأزرار/الروابط)
 */
function get_allowed_menu_keys(PDO $pdo, int $adminId, string $role): array {
  if ($role === 'مدير') return ['*'];

  $stmt = $pdo->prepare("SELECT allowed_menu FROM admin_permissions WHERE admin_id=? LIMIT 1");
  $stmt->execute([$adminId]);
  $row = $stmt->fetch();

  if (!$row || empty($row['allowed_menu'])) return ['dashboard'];

  $decoded = json_decode((string)$row['allowed_menu'], true);
  if (!is_array($decoded)) return ['dashboard'];

  $out = [];
  foreach ($decoded as $k) if (is_string($k) && $k !== '') $out[] = $k;
  return $out ?: ['dashboard'];
}

function menu_allowed(array $allowedKeys, string $key): bool {
  return in_array('*', $allowedKeys, true) || in_array($key, $allowedKeys, true);
}

/**
 * ✅ widgets المسموحة لإحصائيات dashboard
 */
function get_allowed_widget_keys(PDO $pdo, int $adminId, string $role): array {
  if ($role === 'مدير') return ['*'];

  $stmt = $pdo->prepare("SELECT allowed_widgets FROM admin_dashboard_widgets WHERE admin_id=? LIMIT 1");
  $stmt->execute([$adminId]);
  $row = $stmt->fetch();

  if (!$row || empty($row['allowed_widgets'])) {
    return ['users_count'];
  }

  $decoded = json_decode((string)$row['allowed_widgets'], true);
  if (!is_array($decoded)) return ['users_count'];

  $out = [];
  foreach ($decoded as $k) if (is_string($k) && $k !== '') $out[] = $k;
  return $out ?: ['users_count'];
}

function widget_allowed(array $allowedWidgetKeys, string $key): bool {
  return in_array('*', $allowedWidgetKeys, true) || in_array($key, $allowedWidgetKeys, true);
}

/* =========================================================
   ✅ تشفير/إخفاء iframe (للفيديوهات)
   - الهدف: تخزين iframe مشفّر في DB
   - هذا ليس DRM ولا يمنع التصوير 100%
   ========================================================= */

/**
 * ✅ المفتاح السري للتشفير (32 حرف بالضبط)
 * لازم يكون معرف في config.php باسم APP_EMBED_SECRET_KEY
 */
function platform_secret_key(): string {
  $k = defined('APP_EMBED_SECRET_KEY') ? (string)APP_EMBED_SECRET_KEY : '';

  // لازم 32 حرف بالضبط
  if (strlen($k) !== 32) return '';

  return $k;
}

/**
 * ✅ تشفير iframe باستخدام AES-256-CBC
 * @return array{0:string,1:string} [cipherBase64, ivHex]
 */
function encrypt_text(string $plain): array {
  $plain = (string)$plain;
  if ($plain === '') return ['', ''];

  $secret = platform_secret_key();
  if ($secret === '') return ['', ''];

  if (!function_exists('openssl_encrypt')) return ['', ''];

  // مفتاح 32 بايت (AES-256) من secret (32 حرف)
  $key = hash('sha256', $secret, true);

  // IV 16 بايت
  $iv = random_bytes(16);

  $cipherRaw = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
  if ($cipherRaw === false) return ['', ''];

  return [base64_encode($cipherRaw), bin2hex($iv)];
}

/**
 * ✅ فك التشفير
 */
function decrypt_text(string $cipherBase64, string $ivHex): string {
  $cipherBase64 = (string)$cipherBase64;
  $ivHex = (string)$ivHex;

  if ($cipherBase64 === '' || $ivHex === '') return '';

  $secret = platform_secret_key();
  if ($secret === '') return '';

  if (!function_exists('openssl_decrypt')) return '';

  $cipherRaw = base64_decode($cipherBase64, true);
  if ($cipherRaw === false) return '';

  $iv = hex2bin($ivHex);
  if ($iv === false || strlen($iv) !== 16) return '';

  $key = hash('sha256', $secret, true);

  $plain = openssl_decrypt($cipherRaw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
  return ($plain === false) ? '' : (string)$plain;
}