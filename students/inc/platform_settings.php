<?php
// students/inc/platform_settings.php
require_once __DIR__ . '/path_helpers.php';


function ensure_platform_settings_schema(PDO $pdo): void {
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

      -- CTA banner under grades
      cta_enabled TINYINT(1) NOT NULL DEFAULT 1,
      cta_title VARCHAR(255) DEFAULT NULL,
      cta_subtitle VARCHAR(255) DEFAULT NULL,
      cta_button_text VARCHAR(80) DEFAULT NULL,
      cta_button_url VARCHAR(255) DEFAULT NULL,

      -- Footer section
      footer_enabled TINYINT(1) NOT NULL DEFAULT 1,
      footer_logo_path VARCHAR(255) DEFAULT NULL,
      footer_social_title VARCHAR(190) DEFAULT NULL,
      footer_contact_title VARCHAR(190) DEFAULT NULL,
      footer_phone_1 VARCHAR(60) DEFAULT NULL,
      footer_phone_2 VARCHAR(60) DEFAULT NULL,
      footer_rights_line VARCHAR(255) DEFAULT NULL,
      footer_developed_by_line VARCHAR(255) DEFAULT NULL,

      -- ✅ images
      register_image_path VARCHAR(255) DEFAULT NULL,
      login_image_path VARCHAR(255) DEFAULT NULL,

      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  ");

  $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
  if ($dbName === '') return;

  $cols = [
    'hero_small_title' => "ALTER TABLE platform_settings ADD COLUMN hero_small_title VARCHAR(190) NULL AFTER platform_logo",
    'hero_title' => "ALTER TABLE platform_settings ADD COLUMN hero_title VARCHAR(255) NULL AFTER hero_small_title",
    'hero_description' => "ALTER TABLE platform_settings ADD COLUMN hero_description LONGTEXT NULL AFTER hero_title",
    'hero_button_text' => "ALTER TABLE platform_settings ADD COLUMN hero_button_text VARCHAR(80) NULL AFTER hero_description",
    'hero_button_url' => "ALTER TABLE platform_settings ADD COLUMN hero_button_url VARCHAR(255) NULL AFTER hero_button_text",
    'hero_teacher_image' => "ALTER TABLE platform_settings ADD COLUMN hero_teacher_image VARCHAR(255) NULL AFTER hero_button_url",

    'hero_stats_bg_text' => "ALTER TABLE platform_settings ADD COLUMN hero_stats_bg_text VARCHAR(60) NULL AFTER hero_teacher_image",
    'hero_stat_1_value' => "ALTER TABLE platform_settings ADD COLUMN hero_stat_1_value VARCHAR(40) NULL AFTER hero_stats_bg_text",
    'hero_stat_1_label' => "ALTER TABLE platform_settings ADD COLUMN hero_stat_1_label VARCHAR(190) NULL AFTER hero_stat_1_value",
    'hero_stat_2_value' => "ALTER TABLE platform_settings ADD COLUMN hero_stat_2_value VARCHAR(40) NULL AFTER hero_stat_1_label",
    'hero_stat_2_label' => "ALTER TABLE platform_settings ADD COLUMN hero_stat_2_label VARCHAR(190) NULL AFTER hero_stat_2_value",
    'hero_stat_3_value' => "ALTER TABLE platform_settings ADD COLUMN hero_stat_3_value VARCHAR(40) NULL AFTER hero_stat_2_label",
    'hero_stat_3_label' => "ALTER TABLE platform_settings ADD COLUMN hero_stat_3_label VARCHAR(190) NULL AFTER hero_stat_3_value",

    'feature_cards_enabled' => "ALTER TABLE platform_settings ADD COLUMN feature_cards_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER hero_stat_3_label",
    'feature_cards_title' => "ALTER TABLE platform_settings ADD COLUMN feature_cards_title VARCHAR(255) NULL AFTER feature_cards_enabled",

    // CTA
    'cta_enabled' => "ALTER TABLE platform_settings ADD COLUMN cta_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER feature_cards_title",
    'cta_title' => "ALTER TABLE platform_settings ADD COLUMN cta_title VARCHAR(255) NULL AFTER cta_enabled",
    'cta_subtitle' => "ALTER TABLE platform_settings ADD COLUMN cta_subtitle VARCHAR(255) NULL AFTER cta_title",
    'cta_button_text' => "ALTER TABLE platform_settings ADD COLUMN cta_button_text VARCHAR(80) NULL AFTER cta_subtitle",
    'cta_button_url' => "ALTER TABLE platform_settings ADD COLUMN cta_button_url VARCHAR(255) NULL AFTER cta_button_text",

    // Footer
    'footer_enabled' => "ALTER TABLE platform_settings ADD COLUMN footer_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER cta_button_url",
    'footer_logo_path' => "ALTER TABLE platform_settings ADD COLUMN footer_logo_path VARCHAR(255) NULL AFTER footer_enabled",
    'footer_social_title' => "ALTER TABLE platform_settings ADD COLUMN footer_social_title VARCHAR(190) NULL AFTER footer_logo_path",
    'footer_contact_title' => "ALTER TABLE platform_settings ADD COLUMN footer_contact_title VARCHAR(190) NULL AFTER footer_social_title",
    'footer_phone_1' => "ALTER TABLE platform_settings ADD COLUMN footer_phone_1 VARCHAR(60) NULL AFTER footer_contact_title",
    'footer_phone_2' => "ALTER TABLE platform_settings ADD COLUMN footer_phone_2 VARCHAR(60) NULL AFTER footer_phone_1",
    'footer_rights_line' => "ALTER TABLE platform_settings ADD COLUMN footer_rights_line VARCHAR(255) NULL AFTER footer_phone_2",
    'footer_developed_by_line' => "ALTER TABLE platform_settings ADD COLUMN footer_developed_by_line VARCHAR(255) NULL AFTER footer_rights_line",

    // ✅ images
    'register_image_path' => "ALTER TABLE platform_settings ADD COLUMN register_image_path VARCHAR(255) NULL AFTER footer_developed_by_line",
    'login_image_path' => "ALTER TABLE platform_settings ADD COLUMN login_image_path VARCHAR(255) NULL AFTER register_image_path",

    // ✅ timestamps if missing in some DBs
    'created_at' => "ALTER TABLE platform_settings ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_at",
  ];

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME = 'platform_settings'
      AND COLUMN_NAME = ?
    LIMIT 1
  ");

  foreach ($cols as $colName => $alterSql) {
    $stmt->execute([$dbName, $colName]);
    $exists = (int)$stmt->fetchColumn();
    if ($exists === 0) {
      $pdo->exec($alterSql);
    }
  }
}

function get_platform_settings_row(PDO $pdo): array {
  try {
    ensure_platform_settings_schema($pdo);

    $row = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      $pdo->exec("INSERT INTO platform_settings (id, platform_name, platform_logo) VALUES (1, 'منصتي التعليمية', NULL)");
      $row = $pdo->query("SELECT * FROM platform_settings WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    return is_array($row) ? $row : [];
  } catch (Throwable $e) {
    return [
      'platform_name' => 'منصتي التعليمية',
      'platform_logo' => null,

      'hero_small_title' => null,
      'hero_title' => null,
      'hero_description' => null,
      'hero_button_text' => null,
      'hero_button_url' => null,
      'hero_teacher_image' => null,

      'hero_stats_bg_text' => 'ENGLISH',
      'hero_stat_1_value' => null,
      'hero_stat_1_label' => null,
      'hero_stat_2_value' => null,
      'hero_stat_2_label' => null,
      'hero_stat_3_value' => null,
      'hero_stat_3_label' => null,

      'feature_cards_enabled' => 1,
      'feature_cards_title' => null,

      'cta_enabled' => 1,
      'cta_title' => null,
      'cta_subtitle' => null,
      'cta_button_text' => null,
      'cta_button_url' => null,

      'footer_enabled' => 1,
      'footer_logo_path' => null,
      'footer_social_title' => 'السوشيال ميديا',
      'footer_contact_title' => 'تواصل معنا',
      'footer_phone_1' => null,
      'footer_phone_2' => null,
      'footer_rights_line' => null,
      'footer_developed_by_line' => null,

      'register_image_path' => null,
      'login_image_path' => null,
    ];
  }
}