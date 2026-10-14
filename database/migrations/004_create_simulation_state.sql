-- Where the catch-up simulation has got to (ADR-004). One row.
-- processed_until_us = 0 means it hasn't started yet.
CREATE TABLE simulation_state (
    id TINYINT UNSIGNED NOT NULL,
    seed BIGINT NOT NULL,
    processed_until_us BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO simulation_state (id, seed, processed_until_us) VALUES (1, 20261004, 0);

-- Periods the simulation skipped because nobody was watching for over a minute.
-- Sequence numbers continue afterwards, so a pause is never a gap.
CREATE TABLE simulation_pauses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_us BIGINT NOT NULL,
    to_us BIGINT NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
