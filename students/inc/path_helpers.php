<?php

if (!function_exists('student_public_asset_url')) {
  function student_public_asset_url(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') return null;

    if (preg_match('~^(?:https?:)?//~i', $path) || strpos($path, 'data:') === 0) {
      return $path;
    }

    $normalized = ltrim($path, '/');
    if (strpos($normalized, 'admin/') === 0) {
      $normalized = substr($normalized, strlen('admin/'));
    }

    $studentsRoot = dirname(__DIR__);
    if (is_file($studentsRoot . '/' . $normalized)) {
      return $normalized;
    }

    return '../admin/' . $normalized;
  }
}
