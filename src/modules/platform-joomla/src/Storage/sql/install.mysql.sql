--
-- yt-builder-mcp Joomla — install schema (MySQL / MariaDB)
--
-- Three light-weight key-value tables that mirror the WP wp_options /
-- transient surface so the platform-joomla adapters can satisfy the
-- platform-agnostic Storage interfaces (OptionStore, TransientStore,
-- StateLock) used by the shared yt-builder-mcp modules.
--
-- Rationale (Joomla-port-cookbook §4.13.2):
--   * `add_option` on WP is the only race-safe atomic primitive. Joomla
--     equivalent for the lock channel is `INSERT IGNORE` on a dedicated
--     lock-table with PRIMARY KEY on (template_id, scope).
--   * `set_transient` granularity issue (`Sentry-Gotcha JCache TTL`) →
--     we DO NOT use `JCache`; we use this dedicated transients table
--     with an explicit TTL column so we control expiry semantics.
--   * Column types follow Joomla 5/6 conventions
--     (CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, BIGINT for
--     timestamps to survive Year-2038 on 32-bit hosts).
--
-- Forward-compatibility: schema-version stamped in
-- `wp_option('ytb_mcp_schema_version')` analogue —
-- `#__ytb_mcp_options(key='schema_version', ...)`.
--

--
-- Plugin options key-value store (replaces WP wp_options for our prefix).
-- Used by SchemaVersion, SigningSecret, KeyStore, StateRevision,
-- PagesMetaStore, and the published-state-etag snapshot.
--
CREATE TABLE IF NOT EXISTS `#__ytb_mcp_options` (
  `option_key`    VARCHAR(191) NOT NULL,
  `option_value`  LONGTEXT     NOT NULL,
  `autoload`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    BIGINT       NOT NULL,
  `updated_at`    BIGINT       NOT NULL,
  PRIMARY KEY (`option_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Plugin transients (TTL-bound key-value).
-- Used by RateLimiter (`ytb_mcp_rate_<kid>` 60s window, `pickup_rl_<ip-hash>` 60s)
-- and PickupChannel (`ytb_mcp_pickup_<nonce>` 300s one-shot).
--
-- TTL semantics: row is considered expired when `expires_at < UNIX_TIMESTAMP()`.
-- Reader treats expired rows as absent and may delete opportunistically.
-- A daily Joomla scheduled-task cleans up the table (see Migration module
-- — Wave 6 work).
--
CREATE TABLE IF NOT EXISTS `#__ytb_mcp_transients` (
  `transient_key` VARCHAR(191) NOT NULL,
  `payload`       LONGTEXT     NOT NULL,
  `expires_at`    BIGINT       NOT NULL,
  `created_at`    BIGINT       NOT NULL,
  PRIMARY KEY (`transient_key`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Per-template advisory locks (mirrors WP add_option-based StateLock).
-- INSERT IGNORE on the PRIMARY KEY is the only Joomla-portable
-- create-if-absent atomic primitive (cookbook §4.5.1 — atomicity-by-shape).
--
-- Lock value stores `pid:microtime(float)` so contender can detect
-- stale orphans (age > LOCK_TTL_SECONDS = 5s) and reclaim.
--
CREATE TABLE IF NOT EXISTS `#__ytb_mcp_lock` (
  `lock_key`    VARCHAR(191) NOT NULL,
  `lock_value`  VARCHAR(64)  NOT NULL,
  `acquired_at` BIGINT       NOT NULL,
  PRIMARY KEY (`lock_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
