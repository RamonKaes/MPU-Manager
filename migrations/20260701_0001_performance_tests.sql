-- ── F-2 Nachzügler: performance_tests-Tabelle für bestehende Datenbanken ─────
-- Die Tabelle wurde in Commit eaa7ecd zu schema.sql und (nachträglich) zur bereits
-- angewandten Baseline-Migration 20260414_0001 hinzugefügt. Datenbanken, die die
-- Baseline vor dieser Änderung angewandt hatten, überspringen sie dauerhaft und
-- besitzen die Tabelle daher nicht — akte.php und akte_handler.php laufen dort in
-- einen PDO-Fehler. Diese Migration legt sie idempotent nach (IF NOT EXISTS ->
-- No-op auf Neuinstallationen). Definition identisch zu schema.sql.
CREATE TABLE IF NOT EXISTS performance_tests (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id   INT NOT NULL,
    case_id     INT NOT NULL,
    test_date   DATE NOT NULL,
    test_name   VARCHAR(100) NOT NULL,
    percentile  TINYINT UNSIGNED NOT NULL,
    notes       TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_percentile CHECK (percentile BETWEEN 1 AND 99),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
