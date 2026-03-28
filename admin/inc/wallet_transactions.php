<?php

if (!defined('WALLET_TRANSACTION_TYPES')) {
  define('WALLET_TRANSACTION_TYPES', ['credit', 'debit', 'course_purchase', 'lecture_purchase']);
}

if (!function_exists('wallet_transaction_course_description')) {
  function wallet_transaction_course_description(string $courseName = '', int $courseId = 0): string {
    $courseName = trim($courseName);
    return 'شراء كورس: ' . ($courseName !== '' ? $courseName : ('كورس #' . $courseId));
  }
}

if (!function_exists('wallet_transaction_lecture_description')) {
  function wallet_transaction_lecture_description(string $lectureName = '', int $lectureId = 0): string {
    $lectureName = trim($lectureName);
    return 'شراء محاضرة: ' . ($lectureName !== '' ? $lectureName : ('محاضرة #' . $lectureId));
  }
}

if (!function_exists('wallet_transactions_ensure_table')) {
  function wallet_transactions_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS wallet_transactions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        student_id INT(10) UNSIGNED NOT NULL,
        transaction_type VARCHAR(30) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        description VARCHAR(255) DEFAULT NULL,
        related_course_id INT(10) UNSIGNED DEFAULT NULL,
        related_lecture_id INT(10) UNSIGNED DEFAULT NULL,
        reference_type VARCHAR(30) DEFAULT NULL,
        reference_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_wallet_transactions_student (student_id, created_at),
        KEY idx_wallet_transactions_reference (reference_type, reference_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $done = true;
  }
}

if (!function_exists('wallet_transactions_record')) {
  function wallet_transactions_record(PDO $pdo, array $data): void {
    wallet_transactions_ensure_table($pdo);

    $studentId = (int)($data['student_id'] ?? 0);
    $type = trim((string)($data['transaction_type'] ?? ''));
    /* Amounts are always stored as positive values; transaction_type determines whether the row is a credit or deduction. */
    $amount = round((float)($data['amount'] ?? 0), 2);

    if ($studentId <= 0) throw new InvalidArgumentException('Wallet transaction student_id is required.');
    if ($amount <= 0) throw new InvalidArgumentException('Wallet transaction amount must be greater than zero.');
    if (!in_array($type, WALLET_TRANSACTION_TYPES, true)) throw new InvalidArgumentException('Wallet transaction type is invalid.');

    $description = trim((string)($data['description'] ?? ''));
    $relatedCourseId = (int)($data['related_course_id'] ?? 0);
    $relatedLectureId = (int)($data['related_lecture_id'] ?? 0);
    $referenceType = trim((string)($data['reference_type'] ?? ''));
    $referenceId = (int)($data['reference_id'] ?? 0);
    $createdAt = trim((string)($data['created_at'] ?? ''));
    if ($createdAt === '') $createdAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
      INSERT INTO wallet_transactions
        (student_id, transaction_type, amount, description, related_course_id, related_lecture_id, reference_type, reference_id, created_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $studentId,
      $type,
      $amount,
      ($description !== '' ? $description : null),
      ($relatedCourseId > 0 ? $relatedCourseId : null),
      ($relatedLectureId > 0 ? $relatedLectureId : null),
      ($referenceType !== '' ? $referenceType : null),
      ($referenceId > 0 ? $referenceId : null),
      $createdAt,
    ]);
  }
}
