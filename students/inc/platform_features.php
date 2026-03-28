<?php

if (!function_exists('platform_features_ensure_tables')) {
  function platform_features_ensure_tables(PDO $pdo): void {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS platform_posts (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        admin_id INT(10) UNSIGNED DEFAULT NULL,
        body LONGTEXT DEFAULT NULL,
        image_path VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_platform_posts_active (is_active, created_at),
        KEY idx_platform_posts_admin (admin_id),
        CONSTRAINT fk_platform_posts_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS platform_post_reactions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id INT(10) UNSIGNED NOT NULL,
        student_id INT(10) UNSIGNED NOT NULL,
        reaction_type ENUM('like','love','care','wow','haha','sad','angry') NOT NULL DEFAULT 'like',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_platform_post_student (post_id, student_id),
        KEY idx_platform_post_reactions_student (student_id),
        CONSTRAINT fk_platform_post_reactions_post FOREIGN KEY (post_id) REFERENCES platform_posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_platform_post_reactions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS platform_post_comments (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id INT(10) UNSIGNED NOT NULL,
        student_id INT(10) UNSIGNED DEFAULT NULL,
        admin_id INT(10) UNSIGNED DEFAULT NULL,
        parent_comment_id BIGINT(20) UNSIGNED DEFAULT NULL,
        comment_text LONGTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_platform_post_comments_post (post_id),
        KEY idx_platform_post_comments_parent (parent_comment_id),
        KEY idx_platform_post_comments_student (student_id),
        KEY idx_platform_post_comments_admin (admin_id),
        CONSTRAINT fk_platform_post_comments_post FOREIGN KEY (post_id) REFERENCES platform_posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_platform_post_comments_parent FOREIGN KEY (parent_comment_id) REFERENCES platform_post_comments(id) ON DELETE CASCADE,
        CONSTRAINT fk_platform_post_comments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        CONSTRAINT fk_platform_post_comments_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS admin_chat_profiles (
        admin_id INT(10) UNSIGNED NOT NULL,
        display_name VARCHAR(190) NOT NULL,
        image_path VARCHAR(255) DEFAULT NULL,
        is_online TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (admin_id),
        CONSTRAINT fk_admin_chat_profiles_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS student_chat_conversations (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        student_id INT(10) UNSIGNED NOT NULL,
        admin_id INT(10) UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_student_admin_conversation (student_id, admin_id),
        KEY idx_student_chat_student (student_id),
        KEY idx_student_chat_admin (admin_id),
        CONSTRAINT fk_student_chat_conversations_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_chat_conversations_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS student_chat_messages (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id BIGINT(20) UNSIGNED NOT NULL,
        sender_type ENUM('student','admin') NOT NULL,
        sender_id INT(10) UNSIGNED NOT NULL,
        message_text LONGTEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_student_chat_messages_conversation (conversation_id, created_at),
        KEY idx_student_chat_messages_read (conversation_id, is_read),
        CONSTRAINT fk_student_chat_messages_conversation FOREIGN KEY (conversation_id) REFERENCES student_chat_conversations(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS student_chat_message_reactions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id BIGINT(20) UNSIGNED NOT NULL,
        student_id INT(10) UNSIGNED NOT NULL,
        reaction_type ENUM('like','love','care','wow','haha','sad','angry') NOT NULL DEFAULT 'like',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_student_chat_message_reaction (message_id, student_id),
        KEY idx_student_chat_message_reactions_student (student_id),
        CONSTRAINT fk_student_chat_message_reactions_message FOREIGN KEY (message_id) REFERENCES student_chat_messages(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_chat_message_reactions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS attendance_sessions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(190) NOT NULL,
        grade_id INT(10) UNSIGNED NOT NULL,
        center_id INT(10) UNSIGNED DEFAULT NULL,
        group_id INT(10) UNSIGNED NOT NULL,
        course_id INT(10) UNSIGNED DEFAULT NULL,
        lecture_id INT(10) UNSIGNED DEFAULT NULL,
        attendance_date DATE NOT NULL,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        created_by_admin_id INT(10) UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_attendance_sessions_grade (grade_id),
        KEY idx_attendance_sessions_center (center_id),
        KEY idx_attendance_sessions_group (group_id),
        KEY idx_attendance_sessions_course (course_id),
        KEY idx_attendance_sessions_lecture (lecture_id),
        CONSTRAINT fk_attendance_sessions_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
        CONSTRAINT fk_attendance_sessions_center FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE SET NULL,
        CONSTRAINT fk_attendance_sessions_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
        CONSTRAINT fk_attendance_sessions_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
        CONSTRAINT fk_attendance_sessions_lecture FOREIGN KEY (lecture_id) REFERENCES lectures(id) ON DELETE SET NULL,
        CONSTRAINT fk_attendance_sessions_admin FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS attendance_records (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        student_id INT(10) UNSIGNED NOT NULL,
        attendance_status ENUM('present','absent') NOT NULL DEFAULT 'present',
        scan_method ENUM('barcode','camera','manual') NOT NULL DEFAULT 'barcode',
        notes VARCHAR(255) DEFAULT NULL,
        scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_attendance_session_student (session_id, student_id),
        KEY idx_attendance_records_student (student_id),
        KEY idx_attendance_records_status (attendance_status),
        CONSTRAINT fk_attendance_records_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_attendance_records_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
  }
}

if (!function_exists('platform_attempt_status_label')) {
  function platform_attempt_status_label(string $status): string {
    if ($status === 'submitted') return 'تم الحل';
    if ($status === 'expired') return 'انتهى الوقت';
    if ($status === 'in_progress') return 'جاري الحل';
    return 'لم يبدأ';
  }
}
