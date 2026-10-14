-- The simulated exchanges (FR-01). All names are fictional.
-- latency_offset_us gives each exchange a slightly different baseline delay.
CREATE TABLE exchanges (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code CHAR(3) NOT NULL,
    name VARCHAR(60) NOT NULL,
    city VARCHAR(60) NOT NULL,
    latitude DECIMAL(8,5) NOT NULL,
    longitude DECIMAL(8,5) NOT NULL,
    time_zone VARCHAR(40) NOT NULL,
    latency_offset_us SMALLINT NOT NULL,
    next_sequence BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exchanges_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO exchanges (code, name, city, latitude, longitude, time_zone, latency_offset_us) VALUES
    ('THX', 'Thames Exchange', 'London', 51.50740, -0.12780, 'Europe/London', -150),
    ('RHX', 'Rhine Exchange', 'Frankfurt', 50.11090, 8.68210, 'Europe/Berlin', -100),
    ('HDX', 'Hudson Exchange', 'New York', 40.71280, -74.00600, 'America/New_York', -50),
    ('LKX', 'Lakeshore Exchange', 'Chicago', 41.87810, -87.62980, 'America/Chicago', 0),
    ('PLX', 'Paulista Exchange', 'São Paulo', -23.55050, -46.63330, 'America/Sao_Paulo', 50),
    ('KTX', 'Kanto Exchange', 'Tokyo', 35.67620, 139.65030, 'Asia/Tokyo', 100),
    ('PRX', 'Pearl River Exchange', 'Hong Kong', 22.31930, 114.16940, 'Asia/Hong_Kong', 150),
    ('HBX', 'Harbour Exchange', 'Sydney', -33.86880, 151.20930, 'Australia/Sydney', 200);
