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
    `oauth_provider` ENUM('google','meta','microsoft') NULL COMMENT 'NULL for local-only accounts',
    `oauth_id`       VARCHAR(255) NULL,
    `password_hash`  VARCHAR(255) NULL COMMENT 'bcrypt hash, NULL if only OAuth login',
    `role`           ENUM('admin','moderator','supervisor') NOT NULL DEFAULT 'moderator',
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at`  TIMESTAMP NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_oauth` (`oauth_provider`, `oauth_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration helper: if upgrading an existing installation, run:
-- ALTER TABLE `admin_users`
--   MODIFY `oauth_provider` ENUM('google','meta','microsoft') NULL,
--   MODIFY `oauth_id` VARCHAR(255) NULL,
--   ADD COLUMN `password_hash` VARCHAR(255) NULL AFTER `oauth_id`;

-- ------------------------------------------------------------
-- Connected Facebook Pages
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `connected_pages` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page_id`          VARCHAR(64) NOT NULL UNIQUE COMMENT 'Meta page ID',
    `page_name`        VARCHAR(255) NOT NULL,
    `page_access_token` TEXT NOT NULL,
    `admin_user_id`    INT UNSIGNED NOT NULL COMMENT 'Internal admin who connected this page',
    `page_owner_fb_id` VARCHAR(64) NULL COMMENT 'Facebook user ID of the page owner/connector — protected from bans',
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `webhook_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `connected_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `disconnected_at`  TIMESTAMP NULL DEFAULT NULL COMMENT 'Set when disconnected: hidden from list, moderation off, data kept for audit. NULL = connected',
    FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Moderation Policies (editable by admins, versioned)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `policies` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`              VARCHAR(255) NOT NULL,
    `description`       TEXT NULL,

    -- OPERATOR-EDITABLE: community rules, violation categories, escalation logic.
    -- Publicly visible at GET /public/policy (transparency endpoint).
    -- This is the ONLY prompt field the operator customises via the dashboard.
    -- The technical output-format block is appended at runtime by
    -- ClaudeService::composeSystemPrompt() and is NOT stored here.
    `moderation_prompt` LONGTEXT NOT NULL COMMENT 'Operator-editable moderation rules sent to Claude',

    `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
    `version`           INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by`        INT UNSIGNED NOT NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `admin_users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default policy seed
-- ─────────────────────────────────────────────────────────────────────────────
-- DEFAULT POLICY SEED
--
-- moderation_prompt  = OPERATOR-EDITABLE (stored in DB, shown at /public/policy)
--                      Contains: role definition, community rules, violation
--                      categories, editorial escalation logic, fact-check
--                      criteria, context guidance, routing summary.
--                      The operator customises this via the dashboard Policy editor.
--
-- OUTPUT FORMAT / Decision guide / Severity guide / Language rules
--                    → NOT stored here. Hardcoded in ClaudeService::TECHNICAL_PROMPT_BLOCK
--                      and appended at call time by composeSystemPrompt().
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `policies` (`name`, `description`, `moderation_prompt`, `created_by`) VALUES
('Default Policy', 'Standard community moderation policy with scam and threat detection',

-- ════════════════════════════════════════════════════════════════════
-- MODERATION_PROMPT — operator-editable section starts here
-- ════════════════════════════════════════════════════════════════════
'You are an expert social media content moderator for a news outlet or journalism brand Facebook page.
Your task is to evaluate a single comment and decide if it violates community guidelines,
or if it requires fact-checking, or if it falls under editorial/journalist criticism that needs human review.

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
- Language designed to isolate a user from others ("Don't tell anyone", "This is just between us")

════════════════════════════════════════
EDITORIAL CONTENT — ALWAYS ESCALATE TO HUMAN
════════════════════════════════════════
Some comment types must ALWAYS be escalated to human review regardless of tone or apparent intent.
For these, set decision="uncertain" and populate the editorial_category field.

JOURNALIST CRITICISM (editorial_category = "journalist_criticism"):
- Direct criticism of a journalist by name, style, method, or professional conduct
- Accusations of bias, incompetence, or dishonesty targeting a specific journalist
- Personal attacks on a journalist that are not yet insults (those go to Sonnet)

OUTLET CRITICISM (editorial_category = "outlet_criticism"):
- Criticism of the news outlet, editorial line, ownership, or general bias
- Comments questioning the outlet's credibility or independence
- Accusations of propaganda, censorship, or politically motivated coverage

Note: pure insults or harassment directed at a journalist (with no substantive criticism)
do NOT need editorial escalation — classify them as harassment and let Sonnet decide.
Note: commercial spam in comments is handled by Haiku autonomously without escalation.

════════════════════════════════════════
FACT-CHECK SUGGESTIONS
════════════════════════════════════════
When a comment contains a verifiable factual claim that appears dubious or partially false
but is NOT clearly malicious (i.e. it seems like a genuine misunderstanding or contested fact):
- Set fact_check_suggested = true
- Leave decision = "uncertain" to send to human review
- Provide up to 3 suggested sources (title + URL + one-sentence summary)
- Write a short, polite, non-confrontational draft reply in the same language as the comment,
  citing the sources, suitable for publishing as a page reply on Facebook.
  The draft must be factual, educational, and respectful — not accusatory.

Do NOT suggest fact-check for:
- Comments that are clearly and completely fabricated with no factual basis worth engaging
- Obvious satire or jokes
- Pure opinions with no verifiable factual claim

════════════════════════════════════════
CONTEXT TO CONSIDER
════════════════════════════════════════
- Irony and sarcasm exist: evaluate intent, not just surface words
- Criticism of a product or brand is NOT a violation — protect free expression
- A comment with a link is not automatically spam — evaluate the context
- When the violation is ambiguous or borderline, use "uncertain" to escalate to human review
- Always respond in the same language as the comment

════════════════════════════════════════
ROUTING SUMMARY
════════════════════════════════════════
Type                                    | Who decides
----------------------------------------|---------------------------
Spam / commercial advertising           | Haiku (autonomous)
Personal insult to journalist           | Sonnet
Scam, grooming, serious violations      | Haiku or Sonnet
Dubious factual claim (fact-checkable)  | Human (fact_check_suggested=true)
Criticism of journalist / article       | Human (journalist_criticism)
Criticism of outlet / editorial line    | Human (outlet_criticism)
Truly ambiguous                         | Human (uncertain)
Potentially illegal content             | Human (reportable) — auto-hidden immediately

-- ════════════════════════════════════════════════════════════════════
-- MODERATION_PROMPT ends here.
-- The OUTPUT FORMAT / Decision guide / Severity guide / Language rules
-- are appended automatically at runtime by ClaudeService::composeSystemPrompt()
-- and are NOT stored in this column.
-- ════════════════════════════════════════════════════════════════════',
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
    `status`           ENUM('pending','approved','removed','escalated_sonnet','escalated_human','escalated_reportable','hidden','hidden_reportable','reported_legal','appeal_pending','dev_flagged') NOT NULL DEFAULT 'pending',
    `received_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `processed_at`     TIMESTAMP NULL,
    `appeal_token`     VARCHAR(512) NULL COMMENT 'Signed token for appeal link — set on hide',
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
    `stage`          ENUM('haiku','sonnet','human','system') NOT NULL,
    `policy_id`      INT UNSIGNED NOT NULL,
    -- AI fields
    `ai_decision`    ENUM('allow','remove','uncertain','hide','reportable') NULL,
    `ai_confidence`  DECIMAL(4,3) NULL,
    `ai_reason`      TEXT NULL,
    `ai_public_reason` TEXT NULL COMMENT 'User-facing reason (no internal signals)',
    `ai_categories`  JSON NULL,
    `ai_severity`    ENUM('low','medium','high') NULL,
    `ai_model`       VARCHAR(64) NULL COMMENT 'Exact model string used',
    `ai_latency_ms`  INT UNSIGNED NULL,
    `ai_fact_check_draft`        TEXT NULL,
    `ai_fact_check_sources`      JSON NULL,
    `ai_fact_check_confidence`   DECIMAL(4,3) NULL COMMENT 'Confidence del secondo passaggio Sonnet fact-check',
    `ai_fact_check_latency_ms`   SMALLINT UNSIGNED NULL COMMENT 'Latency ms del secondo call Sonnet',
    `ai_editorial_category`      VARCHAR(64) NULL,
    `ai_fact_check_suggested`    TINYINT(1) NOT NULL DEFAULT 0,
    -- Human fields
    `human_user_id`  INT UNSIGNED NULL,
    `human_decision` ENUM('allow','remove','hide','unhide') NULL,
    `human_note`     TEXT NULL,
    `human_decided_at` TIMESTAMP NULL,
    -- Reply tracking
    `removal_reply_sent` TINYINT(1) NULL,
    `removal_reply_text` TEXT NULL,
    -- Appeal
    `appeal_submitted_at` TIMESTAMP NULL,
    `appeal_text`         TEXT NULL,
    -- Outcome
    `final_action`   ENUM('approved','removed','hidden','pending_human','comment_edited','auto_fact_checked') NOT NULL DEFAULT 'pending_human',
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

-- ------------------------------------------------------------
-- App Settings (runtime-configurable, overrides .env defaults)
-- Writable via dashboard Settings screen (admin only)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
    `key`        VARCHAR(64) NOT NULL PRIMARY KEY,
    `value`      VARCHAR(512) NOT NULL,
    `updated_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`updated_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed defaults (INSERT IGNORE keeps existing customised values on re-run)
INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES
-- Core thresholds & limits
('haiku_confidence_threshold',   '0.80'),
('sonnet_confidence_threshold',  '0.70'),
('recidivism_comment_ban_limit', '3'),
('appeal_window_days',           '30'),
('app_url',                      'https://yourdomain.com'),
-- Removal reply template (FREE)
('removal_reply_template',           'Ciao @{nome}, il tuo commento è stato rimosso perché {reason}.'),
-- Ban warning template (quando si raggiunge il limite, avviso ban imminente)
('ban_warning_template',             "Ciao {nome}, il tuo commento è stato rimosso perché non rispetta le nostre linee guida.\n\n⚠️ Ti informiamo che ulteriori violazioni comporteranno un ban temporaneo dalla pagina."),
-- Ban notification template (quando scatta il ban o utente bannato tenta di postare)
('ban_notification_template',        "Ciao {nome}, il tuo commento è stato rimosso e il tuo account è stato temporaneamente sospeso dalla pagina per {durata}.\n\nPotrai tornare a commentare il {scadenza}."),
('hide_reply_template',              'Ciao {nome}, il tuo commento è stato temporaneamente nascosto perché {reason}.\n\nSe ritieni che ci sia un errore, puoi richiedere una revisione: {appeal_url}'),
('hide_reportable_reply_template',   'Ciao {nome}, il tuo commento è stato temporaneamente nascosto perché {reason}.\n\n⚠️ Il contenuto è stato segnalato per valutazione legale da parte della redazione.\n\nSe ritieni che ci sia un errore, puoi richiedere una revisione: {appeal_url}'),
-- Data retention (PRO feature — 0 = disabled/keep forever)
('data_retention_days',          '0'),
-- Fact-check auto-publish threshold (PRO feature — 0.90 = richiede alta confidenza)
('fact_check_auto_publish_threshold', '0.90'),
-- License
('license_key',                  ''),
('license_status',               'free'),
('license_plan',                 ''),
('license_validated_at',         ''),
('license_expires_at',           ''),
('license_domain',               '');

-- ------------------------------------------------------------
-- Appeal Records (user-submitted appeal requests)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `appeal_records` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `comment_id`      INT UNSIGNED NOT NULL,
    `social_user_id`  INT UNSIGNED NOT NULL,
    `appeal_text`     TEXT NULL COMMENT 'User explanation / contestation',
    `status`          ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by`     INT UNSIGNED NULL COMMENT 'Admin who reviewed the appeal',
    `reviewed_at`     TIMESTAMP NULL,
    `reviewer_note`   TEXT NULL,
    `submitted_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`comment_id`)     REFERENCES `comments`(`id`)     ON DELETE CASCADE,
    FOREIGN KEY (`social_user_id`) REFERENCES `social_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reviewed_by`)    REFERENCES `admin_users`(`id`)  ON DELETE SET NULL,
    INDEX `idx_status`    (`status`),
    INDEX `idx_comment`   (`comment_id`),
    INDEX `idx_submitted` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Per-page AI Settings (PRO feature — overrides global thresholds per page)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `page_settings` (
    `id`                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page_id`                   INT UNSIGNED NOT NULL UNIQUE COMMENT 'FK to connected_pages.id',
    `haiku_confidence_threshold`  DECIMAL(4,3) NULL COMMENT 'NULL = use global setting',
    `sonnet_confidence_threshold` DECIMAL(4,3) NULL COMMENT 'NULL = use global setting',
    `fact_check_enabled`        TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_by`                INT UNSIGNED NULL,
    `updated_at`                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`page_id`)    REFERENCES `connected_pages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`updated_by`) REFERENCES `admin_users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- License Cache (avoids repeated remote validation calls)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `license_cache` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `license_key`  VARCHAR(128) NOT NULL,
    `status`       ENUM('valid','invalid','expired','unreachable') NOT NULL DEFAULT 'invalid',
    `plan`         VARCHAR(64)  NULL COMMENT 'e.g. pro, enterprise',
    `features`     JSON         NULL COMMENT 'Array of unlocked feature keys',
    `expires_at`   TIMESTAMP    NULL COMMENT 'License expiry from server',
    `domain`       VARCHAR(255) NULL COMMENT 'Domain the license is bound to',
    `raw_response` TEXT         NULL COMMENT 'Full JSON response from license server',
    `checked_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Last successful remote check',
    `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_key`       (`license_key`),
    INDEX `idx_checked`   (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- UPGRADE: nuove colonne fact-check su installazioni esistenti
-- ============================================================
-- ALTER TABLE `moderation_log`
--   ADD COLUMN `ai_fact_check_confidence` DECIMAL(4,3) NULL AFTER `ai_fact_check_sources`,
--   ADD COLUMN `ai_fact_check_latency_ms` SMALLINT UNSIGNED NULL AFTER `ai_fact_check_confidence`,
--   MODIFY `final_action` ENUM('approved','removed','hidden','pending_human','comment_edited','auto_fact_checked') NOT NULL DEFAULT 'pending_human';
--
-- ALTER TABLE `comments`
--   MODIFY `status` ENUM('pending','approved','removed','escalated_sonnet','escalated_human','escalated_reportable','hidden','hidden_reportable','reported_legal','appeal_pending','dev_flagged') NOT NULL DEFAULT 'pending';
--
-- INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES
-- ('fact_check_auto_publish_threshold', '0.90'),
-- ('ban_warning_template',      'Ciao {nome}, il tuo commento è stato rimosso perché non rispetta le nostre linee guida.\n\n⚠️ Ti informiamo che ulteriori violazioni comporteranno un ban temporaneo dalla pagina.'),
-- ('ban_notification_template', 'Ciao {nome}, il tuo commento è stato rimosso e il tuo account è stato temporaneamente sospeso dalla pagina per {durata}.\n\nPotrai tornare a commentare il {scadenza}.');

SET FOREIGN_KEY_CHECKS = 1;