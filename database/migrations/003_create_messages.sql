-- Raw simulated messages, kept for 15 minutes (ADR-003). One row per message
-- holds both feeds' delivery times; NULL means the feed didn't deliver it.
-- Times are integer microseconds since the Unix epoch (UTC).
CREATE TABLE messages (
    exchange_id SMALLINT UNSIGNED NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    message_type ENUM('order', 'cancel', 'trade') NOT NULL,
    sent_us BIGINT NOT NULL,
    a_received_us BIGINT NULL,
    b_received_us BIGINT NULL,
    PRIMARY KEY (exchange_id, sequence),
    KEY idx_messages_sent (sent_us),
    CONSTRAINT fk_messages_exchange FOREIGN KEY (exchange_id) REFERENCES exchanges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
