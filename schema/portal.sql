-- ---------------------------------------------------------------------------
-- The Cocoon Collective™ — parent portal, identity side
--
-- This database holds everything that identifies a human being. It is the ONLY
-- place any of it exists. Nothing in here is ever copied to a game server.
--
-- Its opposite number is play-side.sql: four columns, none of them identifying.
-- Read that file next; it is the shorter and more persuasive of the two.
--
-- Target: MySQL 5.7+ / MariaDB 10.3+ (a cPanel database on the web host).
-- Create the database and a user with ALL PRIVILEGES on it, then run this file.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1 · Parents
--
-- A parent who abandons signup at step one has told us nothing about a child.
-- That is deliberate: nothing about a child is collected until after consent.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parent (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(254)    NOT NULL,
  email_verified_at DATETIME        NULL,
  password_hash     VARCHAR(255)    NOT NULL,   -- password_hash(), PASSWORD_DEFAULT
  display_name      VARCHAR(80)     NOT NULL,
  status            ENUM('pending','active','paused','closed') NOT NULL DEFAULT 'pending',
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
  last_login_at     DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parent_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2 · Email verification
--
-- Single use, short lived. The token is stored hashed, so a dump of this table
-- does not let anybody verify an address they do not control.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_token (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id   BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64)        NOT NULL,          -- sha256 of the emailed token
  purpose     ENUM('verify','reset') NOT NULL DEFAULT 'verify',
  expires_at  DATETIME        NOT NULL,
  used_at     DATETIME        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_email_token_parent (parent_id),
  UNIQUE KEY uq_email_token_hash (token_hash),
  CONSTRAINT fk_email_token_parent FOREIGN KEY (parent_id)
    REFERENCES parent (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3 · Consent
--
-- Versioned, never overwritten. When the terms change materially the parent is
-- asked again and a NEW row is written; the old row stays.
--
-- This row is also the only thing that survives deletion. After a parent
-- deletes everything, what remains is the fact that consent was given and
-- withdrawn, with no way to say by whom — parent_id becomes NULL and
-- subject_ref is a one-way digest that cannot be reversed to an address.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consent (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id        BIGINT UNSIGNED NULL,          -- NULLed on deletion
  subject_ref      CHAR(64)        NOT NULL,      -- opaque, survives deletion
  terms_version    VARCHAR(32)     NOT NULL,      -- e.g. '2026-10-10'
  mechanism        ENUM('payment') NOT NULL DEFAULT 'payment',
  processor        VARCHAR(40)     NULL,          -- 'stripe', etc.
  processor_ref    VARCHAR(190)    NULL,          -- the processor's id. NEVER a card number.
  granted_at       DATETIME        NOT NULL,
  withdrawn_at     DATETIME        NULL,
  ip_at_grant      VARBINARY(16)   NULL,          -- INET6_ATON, for the consent record only
  PRIMARY KEY (id),
  KEY ix_consent_parent (parent_id),
  KEY ix_consent_subject (subject_ref),
  CONSTRAINT fk_consent_parent FOREIGN KEY (parent_id)
    REFERENCES parent (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4 · Child profiles
--
-- ⛔ WHAT IS DELIBERATELY ABSENT, and must never be added:
--      date of birth · real name · school · home address · photograph
--      anything about health, needs, or ability
--
-- age_band is a BAND, not a date. The cost is real: no birthday feature and no
-- automatic transition between bands. What it buys is that the system never
-- holds a date which, next to a display name, starts to behave like an
-- identifier.
--
-- display_name is explicitly NOT the child's real name. The form says so out
-- loud and suggests alternatives.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS child_profile (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id         BIGINT UNSIGNED NOT NULL,
  display_name      VARCHAR(40)     NOT NULL,
  age_band          ENUM('6-7','8-9') NOT NULL,
  cipher_token      CHAR(64)        NOT NULL,     -- 256 bits, hex. Random. NEVER derived.
  consent_voice     TINYINT(1)      NOT NULL DEFAULT 0,
  consent_screens   TINYINT(1)      NOT NULL DEFAULT 0,
  consent_programme TINYINT(1)      NOT NULL DEFAULT 0,
  linked_at         DATETIME        NULL,         -- set when the child redeems a code
  state             ENUM('new','linked','paused','deleted') NOT NULL DEFAULT 'new',
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_child_cipher (cipher_token),
  KEY ix_child_parent (parent_id),
  CONSTRAINT fk_child_parent FOREIGN KEY (parent_id)
    REFERENCES parent (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5 · Join codes
--
-- Six characters a parent can read down the phone. Single use, 24 hours.
-- Stored hashed: a dump of this table does not hand anybody a working code.
--
-- Hard rate limiting lives in the redemption gate, not here. Six characters is
-- guessable at volume.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS join_code (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  child_profile_id BIGINT UNSIGNED NOT NULL,
  code_hash        CHAR(64)        NOT NULL,
  issued_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at       DATETIME        NOT NULL,
  burned_at        DATETIME        NULL,
  attempts         INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_join_code_hash (code_hash),
  KEY ix_join_code_child (child_profile_id),
  CONSTRAINT fk_join_code_child FOREIGN KEY (child_profile_id)
    REFERENCES child_profile (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6 · Audit
--
-- Append-only. Every state change a parent would want explained, and every
-- action taken on their behalf. Holds no free text about a child.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_event (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id   BIGINT UNSIGNED NULL,
  kind        VARCHAR(48)     NOT NULL,   -- 'signup', 'verify', 'consent', 'child_added', 'code_issued', 'code_burned', 'paused', 'deleted'
  detail      VARCHAR(190)    NULL,       -- never a name, never free text from a child
  at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_parent_at (parent_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7 · Terms versions
--
-- The text a parent agreed to, kept so the consent record means something
-- years later. Consent points at a version; the version is never edited in
-- place — a material change is a new row.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS terms_version (
  version     VARCHAR(32)  NOT NULL,
  summary_md  MEDIUMTEXT   NOT NULL,   -- the plain-language summary shown before consent
  published_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
