-- Admin login, chaos panel, audit log and incident notes (plan 007).

-- Faults the admin has injected (FR-07). One row per exchange, feed and
-- kind; setting the same kind again replaces it. Ignored once expired.
CREATE TABLE chaos_faults (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exchange_id SMALLINT UNSIGNED NOT NULL,
    feed ENUM('A', 'B') NOT NULL,
    kind ENUM('silence', 'drop', 'delay', 'burst') NOT NULL,
    value INT UNSIGNED NOT NULL DEFAULT 0,
    burst_from_sequence BIGINT UNSIGNED NULL,
    set_by VARCHAR(64) NOT NULL,
    set_us BIGINT NOT NULL,
    expires_us BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chaos_faults (exchange_id, feed, kind),
    KEY idx_chaos_faults_expires (expires_us),
    CONSTRAINT fk_chaos_faults_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who did what, and when (FR-07 AC-2): chaos, settings and notes. Kept permanently.
CREATE TABLE admin_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    at_us BIGINT NOT NULL,
    username VARCHAR(64) NOT NULL,
    action VARCHAR(40) NOT NULL,
    target VARCHAR(100) NOT NULL,
    details VARCHAR(500) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_admin_log_at (at_us)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed logins, for rate limiting (NFR-01 AC-4). Only a keyed hash of the
-- IP address is stored, never the address. Pruned after a day.
CREATE TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    at_us BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_ip (ip_hash, at_us),
    KEY idx_login_attempts_at (at_us)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes an admin adds to an incident (FR-06). Kept permanently.
CREATE TABLE incident_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id INT UNSIGNED NOT NULL,
    at_us BIGINT NOT NULL,
    username VARCHAR(64) NOT NULL,
    text VARCHAR(500) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_incident_notes_incident (incident_id, at_us),
    CONSTRAINT fk_incident_notes_incident FOREIGN KEY (incident_id) REFERENCES incidents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How long a fault lasts before it restores itself (FR-07 AC-4).
INSERT INTO settings (name, value) VALUES ('chaos_expiry_min', '5');
