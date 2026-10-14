-- Gap and silence detection (FR-02, FR-03). Detection runs inside each
-- catch-up; this is where it keeps its state and its findings.

-- How far detection has got, alongside how far the simulation has got.
ALTER TABLE simulation_state ADD COLUMN detected_until_us BIGINT NOT NULL DEFAULT 0;

-- Per exchange and feed: the next sequence number expected, when the feed
-- last delivered, when it went silent (NULL if it hasn't), and gaps still
-- inside their 2-second tolerance window, as JSON [[first, last, deadline_us], ...].
CREATE TABLE detector_state (
    exchange_id SMALLINT UNSIGNED NOT NULL,
    feed ENUM('A', 'B') NOT NULL,
    next_expected BIGINT UNSIGNED NOT NULL,
    last_received_us BIGINT NULL,
    silent_since_us BIGINT NULL,
    pending_gaps TEXT NOT NULL,
    PRIMARY KEY (exchange_id, feed),
    CONSTRAINT fk_detector_state_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missing messages, kept permanently. Scope AB means lost on both feeds.
-- The unique key stores a data loss once, though both feeds' detectors find it.
CREATE TABLE gaps (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exchange_id SMALLINT UNSIGNED NOT NULL,
    scope ENUM('A', 'B', 'AB') NOT NULL,
    classification ENUM('line_gap', 'data_loss') NOT NULL,
    first_sequence BIGINT UNSIGNED NOT NULL,
    last_sequence BIGINT UNSIGNED NOT NULL,
    message_count INT UNSIGNED NOT NULL,
    detected_us BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gaps_first (exchange_id, scope, first_sequence),
    KEY idx_gaps_detected (classification, detected_us),
    CONSTRAINT fk_gaps_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Periods a feed delivered nothing for over 2 seconds. ended_us is NULL
-- while the feed is still silent. Kept permanently.
CREATE TABLE feed_silences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exchange_id SMALLINT UNSIGNED NOT NULL,
    feed ENUM('A', 'B') NOT NULL,
    started_us BIGINT NOT NULL,
    ended_us BIGINT NULL,
    PRIMARY KEY (id),
    KEY idx_feed_silences_open (exchange_id, feed, ended_us),
    CONSTRAINT fk_feed_silences_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A/B arbitration per exchange per second of first arrival. Kept 2 hours.
CREATE TABLE feed_seconds (
    exchange_id SMALLINT UNSIGNED NOT NULL,
    second BIGINT NOT NULL,
    accepted INT UNSIGNED NOT NULL DEFAULT 0,
    a_wins INT UNSIGNED NOT NULL DEFAULT 0,
    b_wins INT UNSIGNED NOT NULL DEFAULT 0,
    duplicates INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (exchange_id, second),
    CONSTRAINT fk_feed_seconds_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
