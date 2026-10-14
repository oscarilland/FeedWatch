-- Latency monitoring (FR-04), idle mode and configurable thresholds (plan 004).

-- Thresholds that can be changed without a deploy. Each value is
-- range-checked when read; an invalid one falls back to its default.
CREATE TABLE settings (
    name VARCHAR(64) NOT NULL,
    value VARCHAR(64) NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value) VALUES
    ('gap_tolerance_ms', '2000'),
    ('silence_ms', '2000'),
    ('line_gaps_amber', '3'),
    ('line_gap_window_s', '300'),
    ('data_loss_red_s', '60'),
    ('latency_amber_multiple', '3'),
    ('latency_red_multiple', '10'),
    ('latency_sustain_s', '5'),
    ('baseline_window_min', '30'),
    ('idle_after_min', '30');

-- Per-second latency per feed, next to the per-second win share. Each feed's
-- latency is counted in the second it arrived.
ALTER TABLE feed_seconds
    ADD COLUMN a_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN a_sum_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN a_max_us INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN b_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN b_sum_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN b_max_us INT UNSIGNED NOT NULL DEFAULT 0;

-- Per-minute latency summary per feed, kept 7 days. minute = Unix time / 60.
-- breached: the minute's p50 was over the amber threshold, so the baseline ignores it.
CREATE TABLE feed_minutes (
    exchange_id SMALLINT UNSIGNED NOT NULL,
    feed ENUM('A', 'B') NOT NULL,
    minute INT UNSIGNED NOT NULL,
    message_count INT UNSIGNED NOT NULL,
    mean_us INT UNSIGNED NOT NULL,
    p50_us INT UNSIGNED NOT NULL,
    p95_us INT UNSIGNED NOT NULL,
    p99_us INT UNSIGNED NOT NULL,
    max_us INT UNSIGNED NOT NULL,
    breached TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (exchange_id, feed, minute),
    KEY idx_feed_minutes_minute (minute),
    CONSTRAINT fk_feed_minutes_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each feed's learned normal latency, worked out by cron once a minute.
ALTER TABLE detector_state ADD COLUMN baseline_us INT UNSIGNED NULL;

-- The last minute summarised, and when the data was last viewed (idle mode).
ALTER TABLE simulation_state
    ADD COLUMN summarised_until_minute INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_viewed_us BIGINT NOT NULL DEFAULT 0;
