-- ---------------------------------------------------------------------------
-- The Cocoon Collective™ — play side
--
-- This is the whole of what the game servers are told about a child.
--
-- Four columns. None of them identifies anybody.
--
--   minecraft_uuid   who is connecting — a Mojang account id, nothing more
--   cipher_token     an opaque lookup key. Only the parent portal can resolve
--                    it, and the portal is the only place the resolution
--                    exists anywhere
--   age_band         what the platform must know to behave correctly
--   entitlement      whether play is currently permitted
--
-- ⛔ WHAT IS DELIBERATELY ABSENT, and must never be added here:
--      parent email · parent name · child's real name · date of birth
--      billing anything · home address · school · payment reference
--
-- The platform's central claim is that a breach at the game hosting provider
-- is not a breach of children's data. That claim is only worth making if it is
-- structurally true — if it rests on the game servers never having been given
-- a name, rather than on a policy that says we try not to put one there.
--
-- This file is that claim, in four columns. If a future change wants to add a
-- fifth that identifies somebody, the change is wrong, not this file.
--
-- cipher_token is 256 random bits from a CSPRNG. It is NOT derived from
-- anything — not a hash of the parent id, not an HMAC of the email. A derived
-- token leaks its input to anybody who works out the scheme, and the entire
-- point is that this side holds nothing reversible.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS play_entitlement (
  minecraft_uuid  CHAR(36)    NOT NULL,
  cipher_token    CHAR(64)    NOT NULL,
  age_band        ENUM('6-7','8-10') NOT NULL,
  entitlement     ENUM('active','paused','revoked') NOT NULL DEFAULT 'active',
  linked_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (minecraft_uuid),
  UNIQUE KEY uq_play_cipher (cipher_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One more table, and it holds no child data at all: the redemption gate's
-- rate limiting. Six characters is guessable at volume, so attempts are
-- counted per connecting address and a grinder is locked out and reported.
CREATE TABLE IF NOT EXISTS redeem_attempt (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  addr_hash   CHAR(64)    NOT NULL,     -- hashed, so this table holds no addresses either
  attempts    INT UNSIGNED NOT NULL DEFAULT 1,
  first_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  locked_until DATETIME   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redeem_addr (addr_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
