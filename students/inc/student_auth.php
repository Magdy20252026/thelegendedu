<?php
// students/inc/student_auth.php

if (session_status() === PHP_SESSION_NONE) session_start();

function student_require_login(): void {
  if (empty($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
  }
}

function student_redirect_if_logged_in(string $to = 'account.php'): void {
  if (!empty($_SESSION['student_id'])) {
    header('Location: ' . $to);
    exit;
  }
}

/**
 * تقليل مشكلة زر Back عبر منع الكاش
 */
function no_cache_headers(): void {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Cache-Control: post-check=0, pre-check=0', false);
  header('Pragma: no-cache');
  header('Expires: 0');
}