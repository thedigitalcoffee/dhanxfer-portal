CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role VARCHAR(20) NOT NULL DEFAULT 'agent',
  username VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(160) NULL,
  office VARCHAR(40) NULL,
  ref_code VARCHAR(30) NULL UNIQUE,
  commission_note VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  telegram_chat_id VARCHAR(40) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_no VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  dob DATE NULL,
  nationality VARCHAR(80) NULL,
  occupation VARCHAR(120) NULL,
  employer VARCHAR(160) NULL,
  office VARCHAR(40) NULL,
  phone VARCHAR(40) NOT NULL,
  whatsapp VARCHAR(40) NULL,
  email VARCHAR(160) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  province VARCHAR(100) NULL,
  postal VARCHAR(20) NULL,
  country VARCHAR(80) NULL,
  id_type VARCHAR(40) NULL,
  id_number VARCHAR(255) NULL,
  id_country VARCHAR(80) NULL,
  id_expiry DATE NULL,
  id_file VARCHAR(120) NULL,
  kyc ENUM('Pending','Verified','Rejected','Expired','Info requested','Escalated') NOT NULL DEFAULT 'Pending',
  risk ENUM('Low','Medium','High') NOT NULL DEFAULT 'Low',
  purpose VARCHAR(80) NULL,
  source_of_funds VARCHAR(80) NULL,
  monthly_volume VARCHAR(40) NULL,
  destination VARCHAR(80) NULL,
  referral VARCHAR(60) NULL,
  referred_by VARCHAR(160) NULL,
  agent_id INT UNSIGNED NULL,
  source ENUM('admin','agent','online','import') NOT NULL DEFAULT 'admin',
  consent TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX (agent_id), INDEX (phone), INDEX (kyc), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS beneficiaries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  relation VARCHAR(60) NULL,
  country VARCHAR(80) NULL,
  bank VARCHAR(120) NULL,
  account VARCHAR(255) NULL,
  phone VARCHAR(40) NULL,
  INDEX (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  ref_code VARCHAR(30) NULL,
  agent_id INT UNSIGNED NULL,
  id_file VARCHAR(120) NULL,
  status ENUM('new','approved','rejected') NOT NULL DEFAULT 'new',
  client_id INT UNSIGNED NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status), INDEX (agent_id), INDEX (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transfers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref_no VARCHAR(30) NOT NULL UNIQUE,
  client_id INT UNSIGNED NULL,
  client_name VARCHAR(160) NOT NULL,
  agent_id INT UNSIGNED NULL,
  beneficiary VARCHAR(255) NULL,
  send_amount DECIMAL(14,2) NULL,
  send_currency VARCHAR(5) NULL,
  rate DECIMAL(14,6) NULL,
  receive_amount DECIMAL(16,2) NULL,
  receive_currency VARCHAR(5) NULL,
  fee DECIMAL(12,2) NULL,
  payment_method VARCHAR(40) NULL,
  office VARCHAR(40) NULL,
  transfer_date DATE NOT NULL,
  status ENUM('pending','accepted','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
  receipt_no VARCHAR(60) NULL,
  notes TEXT NULL,
  requested_by INT UNSIGNED NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(255) NULL,
  commission_per_unit DECIMAL(10,4) NULL,
  commission DECIMAL(14,2) NULL,
  commission_currency VARCHAR(5) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (client_id), INDEX (agent_id), INDEX (status), INDEX (transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  act_date DATE NOT NULL,
  type VARCHAR(40) NOT NULL,
  client_id INT UNSIGNED NULL,
  client_name VARCHAR(160) NULL,
  office VARCHAR(40) NULL,
  status ENUM('Open','Done') NOT NULL DEFAULT 'Done',
  follow_up DATE NULL,
  notes TEXT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (act_date), INDEX (follow_up)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id INT UNSIGNED NULL,
  detail VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at), INDEX (user_id, created_at), INDEX (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(60) NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, at), INDEX (username, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_pairs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  send_currency VARCHAR(5) NOT NULL,
  receive_currency VARCHAR(5) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  UNIQUE KEY pair (send_currency, receive_currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_date DATE NOT NULL,
  send_currency VARCHAR(5) NOT NULL,
  receive_currency VARCHAR(5) NOT NULL,
  market_rate DECIMAL(14,6) NULL,
  buy_rate DECIMAL(14,6) NOT NULL,
  sell_rate DECIMAL(14,6) NOT NULL,
  status ENUM('proposed','active','superseded','discarded') NOT NULL DEFAULT 'proposed',
  source ENUM('auto','telegram','portal') NOT NULL DEFAULT 'auto',
  set_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (rate_date), INDEX (status), INDEX pair (send_currency, receive_currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_state (
  chat_id VARCHAR(40) NOT NULL PRIMARY KEY,
  state VARCHAR(40) NOT NULL,
  data TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_commissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id INT UNSIGNED NOT NULL,
  send_currency VARCHAR(5) NOT NULL,
  receive_currency VARCHAR(5) NOT NULL,
  per_unit DECIMAL(10,4) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY agent_pair (agent_id, send_currency, receive_currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role VARCHAR(20) NOT NULL,
  perm VARCHAR(40) NOT NULL,
  PRIMARY KEY (role, perm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_date DATE NOT NULL,
  send_currency VARCHAR(5) NOT NULL,
  receive_currency VARCHAR(5) NOT NULL,
  rate DECIMAL(14,6) NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'open.er-api.com',
  fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY day_pair (rate_date, send_currency, receive_currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  doc_kind ENUM('identity','address_proof','other') NOT NULL DEFAULT 'identity',
  doc_type VARCHAR(40) NULL,
  status ENUM('current','superseded') NOT NULL DEFAULT 'current',
  current_version_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (client_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  doc_type VARCHAR(40) NULL,
  number_enc VARCHAR(255) NULL,
  number_hash CHAR(64) NULL,
  issuing_country VARCHAR(80) NULL,
  expires_on DATE NULL,
  has_address TINYINT(1) NULL,
  address_json TEXT NULL,
  file_name VARCHAR(120) NULL,
  file_sha256 CHAR(64) NULL,
  source ENUM('admin','agent','online','upload_link','migration') NOT NULL DEFAULT 'admin',
  outcome ENUM('unverified','verified','rejected') NOT NULL DEFAULT 'unverified',
  outcome_by INT UNSIGNED NULL,
  outcome_at DATETIME NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY doc_ver (document_id, version),
  INDEX (client_id), INDEX (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kyc_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  case_type ENUM('initial','rekyc') NOT NULL DEFAULT 'initial',
  rekyc_case_id INT UNSIGNED NULL,
  document_version_id INT UNSIGNED NULL,
  decision ENUM('approve','request_info','reject','escalate') NOT NULL,
  checklist TEXT NULL,
  risk VARCHAR(10) NULL,
  address_result VARCHAR(20) NULL,
  notes TEXT NOT NULL,
  evidence TEXT NULL,
  reviewer_id INT UNSIGNED NOT NULL,
  reviewer_role VARCHAR(20) NOT NULL,
  decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (client_id, decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rekyc_cases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  reason ENUM('doc_expiring','doc_expired','periodic','manual') NOT NULL,
  document_version_id INT UNSIGNED NULL,
  status ENUM('required','in_progress','completed','escalated','cancelled') NOT NULL DEFAULT 'required',
  due_date DATE NULL,
  open_key VARCHAR(80) NULL,
  opened_by INT UNSIGNED NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  escalated_at DATETIME NULL,
  closed_at DATETIME NULL,
  closed_review_id INT UNSIGNED NULL,
  UNIQUE KEY open_key (open_key),
  INDEX (client_id, status), INDEX (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offset_days INT NOT NULL,
  channel ENUM('email','staff') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY rule (offset_days, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  document_version_id INT UNSIGNED NOT NULL,
  event_key VARCHAR(20) NOT NULL,
  channel ENUM('email','staff') NOT NULL,
  due_on DATE NOT NULL,
  status ENUM('pending','sent','failed','acknowledged','skipped') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error VARCHAR(160) NULL,
  sent_at DATETIME NULL,
  ack_at DATETIME NULL,
  ack_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY one_per_event (document_version_id, event_key, channel),
  INDEX (status, next_attempt_at), INDEX (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reminder_id INT UNSIGNED NOT NULL,
  attempt_no INT NOT NULL,
  channel VARCHAR(10) NOT NULL,
  ok TINYINT(1) NOT NULL,
  error VARCHAR(160) NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (reminder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upload_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  rekyc_case_id INT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  verify_fails INT NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  used_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intake_drafts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  data MEDIUMTEXT NOT NULL,
  phone_last4_hash CHAR(64) NOT NULL,
  agent_id INT UNSIGNED NULL,
  fails INT NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  INDEX (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id INT UNSIGNED NOT NULL,
  code VARCHAR(60) NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at DATETIME NULL,
  UNIQUE KEY code (code),
  INDEX (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS beneficiary_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  beneficiary_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  fields VARCHAR(255) NULL,
  note VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (beneficiary_id), INDEX (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(20) NOT NULL,
  channel VARCHAR(10) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (client_id, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id INT UNSIGNED NOT NULL,
  slug VARCHAR(40) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changed_by INT UNSIGNED NULL,
  changed_at DATETIME NULL,
  UNIQUE KEY slug (slug),
  INDEX (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  reason VARCHAR(255) NULL,
  detail VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
