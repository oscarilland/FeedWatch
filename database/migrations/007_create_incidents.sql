-- Incident log (FR-06). Kept permanently: nothing prunes these tables.

-- One row per incident. closed_us is NULL while it's open; clearing_since_us
-- is set while its condition has gone but the 30-second merge window hasn't
-- passed. end_hint_us is the true end for incidents that end at an event
-- (the last message lost), rather than when the condition was seen to clear.
CREATE TABLE incidents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exchange_id SMALLINT UNSIGNED NOT NULL,
    type ENUM('feed_down', 'failover', 'data_loss', 'latency', 'line_gaps') NOT NULL,
    feed ENUM('A', 'B', 'AB') NOT NULL,
    severity ENUM('warning', 'major', 'critical') NOT NULL,
    opened_us BIGINT NOT NULL,
    closed_us BIGINT NULL,
    clearing_since_us BIGINT NULL,
    end_hint_us BIGINT NULL,
    details TEXT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_incidents_open (closed_us),
    KEY idx_incidents_opened (opened_us),
    KEY idx_incidents_exchange (exchange_id, opened_us),
    CONSTRAINT fk_incidents_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each incident's timeline (FR-06 AC-4).
CREATE TABLE incident_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id INT UNSIGNED NOT NULL,
    at_us BIGINT NOT NULL,
    kind ENUM('opened', 'escalated', 'cleared', 'returned', 'closed') NOT NULL,
    text VARCHAR(200) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_incident_events_incident (incident_id, at_us),
    CONSTRAINT fk_incident_events_incident FOREIGN KEY (incident_id) REFERENCES incidents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How long a cleared condition may return and still be the same incident.
INSERT INTO settings (name, value) VALUES ('incident_merge_s', '30');
