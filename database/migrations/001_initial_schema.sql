-- ============================================================
-- Moderation Hub - Complete Database Schema
-- MIT License
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Admin Users (OAuth-authenticated moderators/admins)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`           VARCHAR(255) NOT NULL,
    `email`          VARCHAR(255) NOT NULL UNIQUE,
    `avatar_url`     VARCHAR(512) NULL,
    `oauth_provider` ENUM('google','meta','microsoft') NOT NULL,
    `oauth_id`       VARCHAR(255) NOT NULL,
    `role`           ENUM('admin','moderator') NOT NULL DEFAULT 'moderator',
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at`  TIMESTAMP NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_oauth` (`oauth_provider`, `oauth_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Connected Facebook Pages
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `connected_pages` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page_id`          VARCHAR(64) NOT NULL UNIQUE COMMENT 'Meta page ID',
    `page_name`        VARCHAR(255) NOT NULL,
    `page_access_token` TEXT NOT NULL,
    `admin_user_id`    INT UNSIGNED NOT NULL COMMENT 'Who connected this page',
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `webhook_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `connected_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Moderation Policies (editable by admins, versioned)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `policies` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`         VARCHAR(255) NOT NULL,
    `description`  TEXT NULL,
    `system_prompt` LONGTEXT NOT NULL COMMENT 'System prompt sent to Claude for moderation',
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `version`      INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by`   INT UNSIGNED NOT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `admin_users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default policy seed
INSERT INTO `policies` (`name`, `description`, `system_prompt`, `created_by`) VALUES
('Default Policy', 'Standard community moderation policy with scam and threat detection', 
'You are an expert social media content moderator for a brand or business Facebook page.
Your task is to evaluate a single comment and decide if it violates community guidelines.

════════════════════════════════════════
RULES TO ENFORCE
════════════════════════════════════════

BASIC VIOLATIONS:
- Hate speech, racism, sexism, homophobia, or discrimination of any kind
- Threats or incitement to violence against individuals or groups
- Harassment or targeted bullying
- Doxxing (sharing personal information of others)
- Explicit sexual content
- Misinformation that could cause real-world harm

SPAM & COORDINATED BEHAVIOUR:
- Unsolicited advertising or promotional links
- Repetitive or copy-paste comments (likely bot/coordinated)
- Fake urgency to drive clicks ("Limited time!", "Act now!", suspicious shortened URLs)
- Off-topic link drops with no relation to the post

FINANCIAL SCAM PATTERNS (flag as "scam"):
- Investment opportunities promising guaranteed or unusually high returns
- Cryptocurrency solicitation, wallet addresses, or "send crypto to receive more"
- "Pig butchering" patterns: building rapport then pushing investment platforms
- Requests to move conversation to Telegram, WhatsApp, or private channels for financial topics
- Impersonation of the page admin, brand, or public figures to solicit money
- Fake giveaways requiring payment, registration fees, or personal data to claim a prize
- Urgency combined with financial request ("You won! Claim in 24h by sending...")
- Recovery scams targeting people who lost money ("We can get your money back")

GROOMING & PREDATORY BEHAVIOUR (flag as "grooming"):
- Unsolicited romantic or sexual contact directed at users who appear to be minors
- Requests to move conversation off-platform combined with personal/emotional language
- Excessive personal compliments from unknown accounts followed by requests for contact info
- Language designed to isolate a user from others ("Don\'t tell anyone", "This is just between us")

════════════════════════════════════════
CONTEXT TO CONSIDER
════════════════════════════════════════
- Irony and sarcasm exist: evaluate intent, not just surface words
- Criticism of a product or brand is NOT a violation — protect free expression
- A comment with a link is not automatically spam — evaluate the context
- When the violation is ambiguous or borderline, use "uncertain" to escalate to human review
- Always respond in the same language as the comment

════════════════════════════════════════
OUTPUT FORMAT
════════════════════════════════════════
Respond ONLY with valid JSON. No preamble, no markdown fences.

{
  "decision": "allow" | "remove" | "uncertain",
  "confidence": 0.0-1.0,
  "reason": "brief explanation (1-2 sentences) in the same language as the comment",
  "categories": array of zero or more: ["hate_speech","violence","harassment","doxxing","sexual","misinformation","spam","scam","grooming","coordinated_behaviour"],
  "severity": "low" | "medium" | "high"
}

Severity guide:
- low    → borderline, unlikely to cause real harm
- medium → clear violation, moderate impact
- high   → serious harm potential, legal risk, or predatory behaviour',
1) ON DUPLICATE KEY UPDATE `id`=`id`;

-- ------------------------------------------------------------
-- Social Users (Facebook commenters being tracked)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `social_users` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `platform`         ENUM('facebook') NOT NULL DEFAULT 'facebook',
    `platform_user_id` VARCHAR(128) NOT NULL,
    `display_name`     VARCHAR(255) NULL,
    `profile_url`      VARCHAR(512) NULL,
    `ban_status`       ENUM('clean','warned','temp_banned','perm_banned') NOT NULL DEFAULT 'clean',
    `ban_expires_at`   TIMESTAMP NULL,
    `violation_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `last_violation_at` TIMESTAMP NULL,
    `notes`            TEXT NULL COMMENT 'Internal moderator notes',
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_platform_user` (`platform`, `platform_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Comments (captured from Facebook, stored for moderation)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comments` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `platform`         ENUM('facebook') NOT NULL DEFAULT 'facebook',
    `platform_comment_id` VARCHAR(128) NOT NULL UNIQUE,
    `platform_post_id` VARCHAR(128) NOT NULL,
    `page_id`          INT UNSIGNED NOT NULL,
    `social_user_id`   INT UNSIGNED NOT NULL,
    `content`          TEXT NOT NULL,
    `content_hash`     CHAR(64) NOT NULL COMMENT 'SHA256 for dedup',
    `status`           ENUM('pending','approved','removed','escalated_sonnet','escalated_human') NOT NULL DEFAULT 'pending',
    `received_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `processed_at`     TIMESTAMP NULL,
    FOREIGN KEY (`page_id`) REFERENCES `connected_pages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`social_user_id`) REFERENCES `social_users`(`id`) ON DELETE CASCADE,
    INDEX `idx_status` (`status`),
    INDEX `idx_page_status` (`page_id`, `status`),
    INDEX `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Moderation Decisions (full audit trail per comment)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `moderation_log` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `comment_id`     INT UNSIGNED NOT NULL,
    `stage`          ENUM('haiku','sonnet','human') NOT NULL,
    `policy_id`      INT UNSIGNED NOT NULL,
    -- AI fields
    `ai_decision`    ENUM('allow','remove','uncertain') NULL,
    `ai_confidence`  DECIMAL(4,3) NULL,
    `ai_reason`      TEXT NULL,
    `ai_categories`  JSON NULL,
    `ai_severity`    ENUM('low','medium','high') NULL,
    `ai_model`       VARCHAR(64) NULL COMMENT 'Exact model string used',
    `ai_latency_ms`  INT UNSIGNED NULL,
    -- Human fields
    `human_user_id`  INT UNSIGNED NULL,
    `human_decision` ENUM('allow','remove') NULL,
    `human_note`     TEXT NULL,
    `human_decided_at` TIMESTAMP NULL,
    -- Outcome
    `final_action`   ENUM('approved','removed','pending_human') NOT NULL DEFAULT 'pending_human',
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`comment_id`) REFERENCES `comments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`policy_id`) REFERENCES `policies`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`human_user_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL,
    INDEX `idx_comment` (`comment_id`),
    INDEX `idx_stage` (`stage`),
    INDEX `idx_human_pending` (`stage`, `human_decision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Ban Records (linked to moderation decisions, learning data)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ban_records` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `social_user_id`  INT UNSIGNED NOT NULL,
    `page_id`         INT UNSIGNED NULL COMMENT 'NULL = global ban across all pages',
    `ban_type`        ENUM('comment_removed','temp_ban','perm_ban') NOT NULL,
    `ban_scope`       ENUM('comment','user') NOT NULL,
    `trigger_comment_id` INT UNSIGNED NULL,
    `trigger_log_id`  INT UNSIGNED NULL COMMENT 'Which moderation_log triggered this',
    `decided_by`      ENUM('ai','human') NOT NULL,
    `admin_user_id`   INT UNSIGNED NULL COMMENT 'If human-decided',
    `reason`          TEXT NULL,
    `categories`      JSON NULL COMMENT 'Violation categories for ML learning',
    `expires_at`      TIMESTAMP NULL COMMENT 'NULL = permanent',
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`social_user_id`) REFERENCES `social_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`page_id`) REFERENCES `connected_pages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`trigger_comment_id`) REFERENCES `comments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`trigger_log_id`) REFERENCES `moderation_log`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_active` (`social_user_id`, `is_active`),
    INDEX `idx_decided_by` (`decided_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Webhook Events Log (raw Meta webhook payloads)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_events` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page_id`    VARCHAR(64) NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `payload`    LONGTEXT NOT NULL,
    `processed`  TINYINT(1) NOT NULL DEFAULT 0,
    `error`      TEXT NULL,
    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_processed` (`processed`),
    INDEX `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
