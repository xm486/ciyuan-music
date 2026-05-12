-- 次元音乐 MySQL 建表脚本
-- 在 InfinityFree 的 phpMyAdmin 中选择你的数据库后执行。

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_uid VARCHAR(32) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(50) NOT NULL,
  avatar_url MEDIUMTEXT NULL,
  github_id VARCHAR(64) NULL,
  github_login VARCHAR(100) NULL,
  github_avatar VARCHAR(255) NULL,
  github_bound_at INT UNSIGNED NULL,
  created_at INT UNSIGNED NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_uid (user_uid),
  UNIQUE KEY uniq_email (email),
  UNIQUE KEY uniq_github_id (github_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sync (
  user_id INT UNSIGNED NOT NULL,
  playlists_json MEDIUMTEXT NOT NULL,
  play_history_json MEDIUMTEXT NOT NULL,
  listen_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  today_listen_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  listen_exp_date DATE NULL,
  updated_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 老版本已建库时执行下面两行可补齐等级经验字段；已存在字段会报 Duplicate column，可忽略。
-- ALTER TABLE user_sync ADD COLUMN today_listen_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER listen_seconds;
-- ALTER TABLE user_sync ADD COLUMN listen_exp_date DATE NULL AFTER today_listen_seconds;

CREATE TABLE IF NOT EXISTS email_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(120) NOT NULL,
  purpose VARCHAR(32) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  used_at INT UNSIGNED NULL,
  send_count INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_email_purpose (email, purpose),
  KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  content TEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  admin_reply TEXT NULL,
  created_at INT UNSIGNED NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_feedback_user (user_id),
  KEY idx_feedback_status (status),
  CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friend_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  url VARCHAR(255) NOT NULL,
  description VARCHAR(255) NULL,
  icon_url VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at INT UNSIGNED NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_friend_links_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_visits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_key VARCHAR(80) NOT NULL,
  visit_path VARCHAR(255) NOT NULL DEFAULT '/',
  visit_date DATE NOT NULL,
  visited_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_site_visits_date (visit_date),
  KEY idx_site_visits_visitor_date (visitor_key, visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  content TEXT NOT NULL,
  version VARCHAR(32) NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at INT UNSIGNED NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_announcements_active_created (is_active, created_at),
  KEY idx_announcements_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;