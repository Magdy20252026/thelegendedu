<?php
// students/inc/device_lock.php
// ✅ Device lock: allow ONLY 1 device per student (the first device).
// Admin can delete device record from admin panel to allow registering a new one.

function get_client_ip(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  return is_string($ip) ? $ip : '';
}

function ensure_device_cookie(): string {
  // ثابت لكل متصفح/جهاز (يساعد على منع تغيير User-Agent فقط)
  if (!empty($_COOKIE['device_key']) && is_string($_COOKIE['device_key'])) {
    return $_COOKIE['device_key'];
  }

  $key = bin2hex(random_bytes(16)); // 32 chars hex
  setcookie('device_key', $key, [
    'expires'  => time() + 365 * 24 * 60 * 60,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  $_COOKIE['device_key'] = $key;
  return $key;
}

function build_device_hash(): string {
  $deviceKey = ensure_device_cookie();
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $lang = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');

  $ip = get_client_ip();
  // نقلل تأثير تغيّر الـ IP: نأخذ أول جزئين فقط (لو IPv4)
  $ipPart = $ip;
  if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip)) {
    $parts = explode('.', $ip);
    $ipPart = $parts[0] . '.' . $parts[1];
  }

  $raw = $deviceKey . '|' . $ua . '|' . $lang . '|' . $ipPart;
  return hash('sha256', $raw); // 64 chars
}

function device_label_guess(): string {
  $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  if ($ua === '') return 'جهاز غير معروف';

  if (strpos($ua, 'iphone') !== false) return 'iPhone';
  if (strpos($ua, 'ipad') !== false) return 'iPad';
  if (strpos($ua, 'android') !== false) return 'Android';
  if (strpos($ua, 'windows') !== false) return 'Windows';
  if (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) return 'Mac';
  if (strpos($ua, 'linux') !== false) return 'Linux';

  return 'Device';
}

/**
 * ✅ السماح بجهاز واحد فقط:
 * - لو لا يوجد جهاز مسجل => يتم تسجيل هذا الجهاز كجهاز وحيد.
 * - لو يوجد جهاز مسجل => لازم نفس device_hash وإلا يُرفض الدخول.
 *
 * @return array{ok:bool, reason:string}
 * reason:
 * - first_device_registered
 * - device_allowed
 * - device_not_allowed
 */
function device_lock_check_and_register(PDO $pdo, int $studentId): array {
  $deviceHash = build_device_hash();
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $ip = get_client_ip();
  $label = device_label_guess();

  // هل للطالب جهاز مسجل؟
  $stmt = $pdo->prepare("
    SELECT id, device_hash
    FROM student_devices
    WHERE student_id=? AND is_active=1
    ORDER BY id ASC
    LIMIT 1
  ");
  $stmt->execute([$studentId]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    // أول جهاز: نسجله
    $ins = $pdo->prepare("
      INSERT INTO student_devices
        (student_id, device_hash, device_label, user_agent, ip_first, first_login_at, last_login_at, is_active)
      VALUES
        (?, ?, ?, ?, ?, NOW(), NOW(), 1)
    ");
    $ins->execute([
      $studentId,
      $deviceHash,
      $label,
      mb_substr($ua, 0, 255),
      mb_substr($ip, 0, 64),
    ]);

    return ['ok' => true, 'reason' => 'first_device_registered'];
  }

  if ((string)$existing['device_hash'] !== $deviceHash) {
    return ['ok' => false, 'reason' => 'device_not_allowed'];
  }

  // نفس الجهاز => تحديث آخر دخول
  $up = $pdo->prepare("UPDATE student_devices SET last_login_at=NOW() WHERE id=?");
  $up->execute([(int)$existing['id']]);

  return ['ok' => true, 'reason' => 'device_allowed'];
}