-- MySQL schema for Real Leads Checker
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  deleted_at DATETIME NULL
);

CREATE TABLE email_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  client_id INT NULL,
  label VARCHAR(100) NOT NULL,
  imap_host VARCHAR(255) NOT NULL,
  imap_port INT NOT NULL,
  encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
  username VARCHAR(255) NOT NULL,
  password_enc TEXT NOT NULL,
  folder VARCHAR(100) NOT NULL DEFAULT 'INBOX',
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  email_account_id INT NULL,
  client_id INT NULL,
  message_id VARCHAR(255) NULL UNIQUE,
  from_email VARCHAR(255) NOT NULL,
  from_name VARCHAR(255) NULL,
  to_email VARCHAR(255) NULL,
  subject TEXT,
  body_plain MEDIUMTEXT NULL,
  body_html MEDIUMTEXT NULL,
  received_at DATETIME NOT NULL,
  fetched_at DATETIME NOT NULL,
  hash CHAR(64) NOT NULL,
  INDEX (hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (email_account_id) REFERENCES email_accounts(id) ON DELETE SET NULL
);

CREATE TABLE leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  email_id INT NOT NULL,
  client_id INT NULL,
  status ENUM('genuine','spam','unknown') NOT NULL DEFAULT 'unknown',
  score TINYINT NULL,
  mode ENUM('algorithmic','gpt') NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
);

CREATE TABLE clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  website VARCHAR(255) NULL,
  shortcode VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY unique_user_shortcode (user_id, shortcode),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE lead_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  checked_by_user_id INT NULL,
  mode ENUM('algorithmic','gpt','manual') NOT NULL,
  score TINYINT NOT NULL,
  reason TEXT,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (checked_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  filter_mode ENUM('algorithmic','gpt') NOT NULL DEFAULT 'algorithmic',
  openai_api_key_enc TEXT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  page_size INT NOT NULL DEFAULT 25,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user (user_id)
);

CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(128) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
