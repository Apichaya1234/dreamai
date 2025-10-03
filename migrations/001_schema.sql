-- migrations/001_schema.sql
-- DreamAI — Core schema (MySQL 8.0+/MariaDB 10.4+)
-- Upgraded with user profile fields and feedback reason column.

-- SET NAMES utf8mb4;
-- SET time_zone = '+00:00';

-- ============================================================================
-- 1) USERS & RESEARCH CONSENT
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  anon_id CHAR(36) NOT NULL UNIQUE,               -- UUIDv4
  consent_research TINYINT(1) NOT NULL DEFAULT 0, -- 1=yes, 0=no
  gender ENUM('male','female','other') NULL,       -- ADDED: User profile gender
  birth_date DATE NULL,                           -- ADDED: User profile birth date
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS research_consents (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  consent TINYINT(1) NOT NULL,                    -- 1=ยินยอม,0=ปฏิเสธ
  consent_text_version VARCHAR(20) NOT NULL,
  ip_hash CHAR(64) NULL,                          -- SHA-256(IP|salt)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_research_consents_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_research_consents_user_created
  ON research_consents(user_id, created_at);

-- ============================================================================
-- 2) DREAM LEXICON (seed) + SYNONYMS + CATEGORIES (M:N)
-- ============================================================================

CREATE TABLE IF NOT EXISTS dream_lexicon (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  lemma VARCHAR(255) NOT NULL,
  description TEXT NULL,
  positive_interpretation TEXT NULL,
  negative_interpretation TEXT NULL,
  lucky_numbers JSON NULL,
  culture_notes TEXT NULL,
  language CHAR(2) NOT NULL DEFAULT 'th',
  source_tag VARCHAR(50) DEFAULT 'seed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dream_lexicon_lemma (lemma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ... (dream_synonyms, dream_categories, dream_lexicon_categories tables remain the same) ...

CREATE TABLE IF NOT EXISTS dream_synonyms (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  lexicon_id BIGINT NOT NULL,
  term VARCHAR(255) NOT NULL,
  CONSTRAINT fk_synonyms_lexicon
    FOREIGN KEY (lexicon_id) REFERENCES dream_lexicon(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_dream_synonyms_term (term),
  INDEX idx_dream_synonyms_lexicon (lexicon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dream_categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dream_lexicon_categories (
  lexicon_id BIGINT NOT NULL,
  category_id INT NOT NULL,
  PRIMARY KEY (lexicon_id, category_id),
  CONSTRAINT fk_lexcat_lexicon
    FOREIGN KEY (lexicon_id) REFERENCES dream_lexicon(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_lexcat_category
    FOREIGN KEY (category_id) REFERENCES dream_categories(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_lexcat_lexicon (lexicon_id),
  INDEX idx_lexcat_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3) USER DREAM REPORTS + MEDIA
-- ============================================================================

CREATE TABLE IF NOT EXISTS dream_reports (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NULL,
  channel ENUM('text','audio','keywords') NOT NULL,
  raw_text MEDIUMTEXT NULL,
  mood ENUM('positive','negative','neutral') NULL,
  language CHAR(2) DEFAULT 'th',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reports_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX idx_reports_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dream_report_media (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  report_id BIGINT NOT NULL,
  audio_path VARCHAR(500) NULL,
  transcript MEDIUMTEXT NULL,
  stt_model VARCHAR(64) NULL,
  CONSTRAINT fk_report_media_report
    FOREIGN KEY (report_id) REFERENCES dream_reports(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_report_media_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4) MATCHES: REPORT ↔ LEXICON (keyword/embedding)
-- ============================================================================

CREATE TABLE IF NOT EXISTS report_matches (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  report_id BIGINT NOT NULL,
  lexicon_id BIGINT NOT NULL,
  match_type ENUM('keyword','embedding') NOT NULL,
  confidence DECIMAL(5,4) NOT NULL,
  CONSTRAINT fk_matches_report
    FOREIGN KEY (report_id) REFERENCES dream_reports(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_matches_lexicon
    FOREIGN KEY (lexicon_id) REFERENCES dream_lexicon(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_matches_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5) GENERATED OPTIONS (DB/GPT outputs)
-- ============================================================================

CREATE TABLE IF NOT EXISTS generated_options (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  report_id BIGINT NOT NULL,
  source ENUM('db','gpt-4.1','gpt-5') NOT NULL,
  angle VARCHAR(50) NULL,
  style_key TEXT NULL,
  diversity_method VARCHAR(50) NULL,
  model VARCHAR(64) NULL,
  temperature DECIMAL(3,2) NULL,
  top_p DECIMAL(3,2) NULL,
  penalties JSON NULL,
  prompt_version VARCHAR(20) NULL,
  content_json JSON NOT NULL,
  risk_score DECIMAL(4,3) DEFAULT 0.000,
  moderation_label VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_genopt_report
    FOREIGN KEY (report_id) REFERENCES dream_reports(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_genopt_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6) PRESENTATION SESSIONS (A–D mapping)
-- ============================================================================

CREATE TABLE IF NOT EXISTS presentation_sessions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  report_id BIGINT NOT NULL,
  slot_order JSON NOT NULL,
  A_option_id BIGINT NOT NULL,
  B_option_id BIGINT NOT NULL,
  C_option_id BIGINT NOT NULL,
  D_option_id BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ps_report
    FOREIGN KEY (report_id) REFERENCES dream_reports(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ps_A_option FOREIGN KEY (A_option_id) REFERENCES generated_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_B_option FOREIGN KEY (B_option_id) REFERENCES generated_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_C_option FOREIGN KEY (C_option_id) REFERENCES generated_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_D_option FOREIGN KEY (D_option_id) REFERENCES generated_options(id) ON DELETE CASCADE,
  INDEX idx_ps_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7) USER SELECTIONS (ground truth)
-- ============================================================================

CREATE TABLE IF NOT EXISTS selections (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  report_id BIGINT NOT NULL,
  session_id BIGINT NOT NULL,
  chosen_slot ENUM('A','B','C','D') NOT NULL,
  chosen_option_id BIGINT NOT NULL,
  feedback_reason VARCHAR(255) NULL,              -- ADDED: To store user's feedback choice
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sel_report FOREIGN KEY (report_id) REFERENCES dream_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_sel_session FOREIGN KEY (session_id) REFERENCES presentation_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sel_option FOREIGN KEY (chosen_option_id) REFERENCES generated_options(id) ON DELETE CASCADE,
  INDEX idx_sel_session (session_id),
  INDEX idx_sel_option (chosen_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8) PAIRWISE RESULTS (for Elo / Bradley–Terry)
-- ============================================================================

CREATE TABLE IF NOT EXISTS pairwise_results (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT NOT NULL,
  winner_option_id BIGINT NOT NULL,
  loser_option_id BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pair_session FOREIGN KEY (session_id) REFERENCES presentation_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pair_winner FOREIGN KEY (winner_option_id) REFERENCES generated_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_pair_loser FOREIGN KEY (loser_option_id) REFERENCES generated_options(id) ON DELETE CASCADE,
  INDEX idx_pair_winner (winner_option_id),
  INDEX idx_pair_loser (loser_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9) MODEL RATINGS (Elo/Glicko/BT parameters)
-- ============================================================================

CREATE TABLE IF NOT EXISTS model_ratings (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  arm_key VARCHAR(100) NOT NULL,
  rating DECIMAL(6,2) NOT NULL DEFAULT 1500.00,
  rating_dev DECIMAL(6,2) DEFAULT 350.00,
  sample_size INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_ratings_arm (arm_key),
  INDEX idx_model_ratings_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10) MODERATION LOGS
-- ============================================================================

CREATE TABLE IF NOT EXISTS moderation_logs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  stage ENUM('input','output') NOT NULL,
  entity_id BIGINT NOT NULL,
  model VARCHAR(64) NOT NULL,
  result_json JSON NOT NULL,
  flagged TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_moderation_stage_created (stage, created_at),
  INDEX idx_moderation_flagged (flagged)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `dream_lexicon`
ADD COLUMN `embedding` BLOB NULL DEFAULT NULL
AFTER `source_tag`;

-- END OF FILE

