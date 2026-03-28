<?php
// Database config
define('DB_HOST', 'sql211.infinityfree.com');
define('DB_NAME', 'if0_41288472_thelegendedu_0');
define('DB_USER', 'if0_41288472');
define('DB_PASS', 'h4c4tpciQbPNV9');
define('DB_PORT', '3306');

// App
define('APP_TIMEZONE', 'Africa/Cairo');
define('APP_EMBED_SECRET_KEY_MIN_LENGTH', 32);
$embedSecret = getenv('APP_EMBED_SECRET_KEY');
if (!is_string($embedSecret) || strlen($embedSecret) < APP_EMBED_SECRET_KEY_MIN_LENGTH) {
  $embedSecret = '';
  $adminConfigPath = dirname(__DIR__, 2) . '/admin/inc/config.php';
  if (is_file($adminConfigPath) && is_readable($adminConfigPath)) {
    $adminConfigContents = file_get_contents($adminConfigPath);
    if (
      is_string($adminConfigContents) &&
      preg_match('/define\(\s*[\'"]APP_EMBED_SECRET_KEY[\'"]\s*,\s*[\'"]([A-Za-z0-9_-]{32,})[\'"]\s*\)\s*;/', $adminConfigContents, $matches)
    ) {
      $embedSecret = (string)($matches[1] ?? '');
    }
  }
}
if (strlen($embedSecret) < APP_EMBED_SECRET_KEY_MIN_LENGTH) {
  $embedSecret = '';
}
define('APP_EMBED_SECRET_KEY', $embedSecret);
