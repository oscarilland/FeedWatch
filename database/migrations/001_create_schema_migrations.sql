-- Records which migrations have been applied. Used by MigrationRunner.
CREATE TABLE schema_migrations (
    version VARCHAR(100) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
