-- ── Notiz-Journal statt einzelnem Freitextfeld ──────────────────────────────
-- Bisher hing die gesamte Beratungsnotiz als ein einziger Text an cases.notes und
-- war nur über das Fall-Bearbeitungsformular änderbar. Ersetzt durch case_notes:
-- mehrere datierte Einträge mit Überschrift, direkt in der Akte pflegbar.
--
-- Bestandsnotizen werden als ein Eintrag je Fall übernommen, danach entfällt die
-- Altspalte. Die Prüfung auf information_schema hält die Migration auf
-- Neuinstallationen (schema.sql kennt cases.notes nicht mehr) fehlerfrei.

CREATE TABLE IF NOT EXISTS case_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT NOT NULL,
    case_id    INT NOT NULL,
    note_date  DATE NOT NULL,
    title      VARCHAR(120) NOT NULL,
    body       TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_case_id   (case_id),
    INDEX idx_client_id (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id)   REFERENCES cases(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @hat_notes := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'notes');

SET @sql := IF(@hat_notes > 0,
    "INSERT INTO case_notes (client_id, case_id, note_date, title, body)
     SELECT client_id, id, DATE(created_at), 'Übernommene Notiz', notes
     FROM cases WHERE notes IS NOT NULL AND TRIM(notes) <> ''",
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@hat_notes > 0, 'ALTER TABLE cases DROP COLUMN notes', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
