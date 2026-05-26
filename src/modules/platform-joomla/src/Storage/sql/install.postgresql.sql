--
-- yt-builder-mcp Joomla — install schema (PostgreSQL)
--
-- PostgreSQL variant of install.mysql.sql. Differences:
--   * SMALLINT instead of TINYINT(1) for autoload (Postgres has no TINYINT).
--   * No COLLATE / CHARSET clause (handled at DB level).
--   * IF NOT EXISTS is supported since PG 9.1; safe on all supported Joomla DBs.
--   * BIGINT for unix-timestamps matches MySQL variant.
--   * No engine clause.
--

CREATE TABLE IF NOT EXISTS "#__ytb_mcp_options" (
  "option_key"   VARCHAR(191) NOT NULL,
  "option_value" TEXT         NOT NULL,
  "autoload"     SMALLINT     NOT NULL DEFAULT 0,
  "created_at"   BIGINT       NOT NULL,
  "updated_at"   BIGINT       NOT NULL,
  PRIMARY KEY ("option_key")
);

CREATE TABLE IF NOT EXISTS "#__ytb_mcp_transients" (
  "transient_key" VARCHAR(191) NOT NULL,
  "payload"       TEXT         NOT NULL,
  "expires_at"    BIGINT       NOT NULL,
  "created_at"    BIGINT       NOT NULL,
  PRIMARY KEY ("transient_key")
);
CREATE INDEX IF NOT EXISTS "idx_ytb_mcp_transients_expires_at"
  ON "#__ytb_mcp_transients" ("expires_at");

CREATE TABLE IF NOT EXISTS "#__ytb_mcp_lock" (
  "lock_key"    VARCHAR(191) NOT NULL,
  "lock_value"  VARCHAR(64)  NOT NULL,
  "acquired_at" BIGINT       NOT NULL,
  PRIMARY KEY ("lock_key")
);
