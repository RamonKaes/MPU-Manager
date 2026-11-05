-- ── Migration: Neue Spalten für bestehende Installationen ───────────────────────
-- Erfordert MariaDB ab 10.11 (siehe README). Bestehende Daten bleiben unberührt.
ALTER TABLE clients ADD COLUMN IF NOT EXISTS revocation_confirmed TINYINT(1) DEFAULT 0;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS early_start_requested TINYINT(1) DEFAULT 0;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS street        VARCHAR(150);
ALTER TABLE clients ADD COLUMN IF NOT EXISTS gender        ENUM('m','f','d') DEFAULT 'm';
ALTER TABLE clients ADD COLUMN IF NOT EXISTS weight_kg     DECIMAL(5,2);
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS legal_validity_date DATE;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS license_classes VARCHAR(100);
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS bkf_status ENUM('nein','aktiv','angestrebt') DEFAULT 'nein';
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS authority_name       VARCHAR(150);
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS authority_file_number VARCHAR(100);
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS antrag_status        ENUM('nicht_gestellt','gestellt','aufforderung_liegt_vor','abgelehnt') DEFAULT 'nicht_gestellt';
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS mpu_target_date      DATE;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS package_type         VARCHAR(50);
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS package_price        DECIMAL(8,2);
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS hourly_rate          DECIMAL(8,2);
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS dokument_akte        TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS dokument_strafbefehl TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS dokument_register    TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS icd_kontrollverlust  TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS icd_priorisierung    TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS icd_folgeschaeden    TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS icd_craving          TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS icd_toleranz         TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS icd_entzug           TINYINT(1) DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS mehrfachdelikt       TINYINT(1) DEFAULT 0;
ALTER TABLE cases   MODIFY COLUMN suggested_hypothesis TEXT;
ALTER TABLE cases   MODIFY COLUMN suggested_abstinence TEXT;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS abstinenz_monate INT DEFAULT 0;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS status ENUM('aktiv','abgeschlossen','pausiert') DEFAULT 'aktiv';
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS notes  TEXT;

-- ── audit_log: Benutzer- und IP-Tracking ────────────────────────────────────
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS user_name  VARCHAR(100);
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45);

-- ── F1: Einspruchsfrist-Tracking ────────────────────────────────────────────
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS bussgeld_datum     DATE;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS einspruch_status   ENUM('offen','eingelegt','abgelaufen','nicht_relevant') DEFAULT 'nicht_relevant';

-- ── F14: AUDIT- und LAST-Scores ─────────────────────────────────────────────
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS audit_score        INT;
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS last_score         INT;

-- ── Optimistic Locking (version-Spalten) ────────────────────────────────────
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 0;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 0;

-- ── closed_at: Abschlusszeitpunkt ───────────────────────────────────────────
ALTER TABLE cases ADD COLUMN IF NOT EXISTS closed_at TIMESTAMP NULL DEFAULT NULL;

-- ── F-A: Teilzahlung mit echtem Betrag ─────────────────────────────────────
ALTER TABLE cases ADD COLUMN IF NOT EXISTS payment_amount_paid DECIMAL(8,2) DEFAULT NULL;

-- ── F-B: ICD drei-Zustand (NULL = nicht erhoben, 0 = verneint, 1 = bejaht) ──
-- Bestehende 0-Werte bleiben 0; nur der DEFAULT wechselt.
ALTER TABLE cases MODIFY COLUMN icd_kontrollverlust TINYINT(1) DEFAULT NULL;
ALTER TABLE cases MODIFY COLUMN icd_priorisierung   TINYINT(1) DEFAULT NULL;
ALTER TABLE cases MODIFY COLUMN icd_folgeschaeden   TINYINT(1) DEFAULT NULL;
ALTER TABLE cases MODIFY COLUMN icd_craving         TINYINT(1) DEFAULT NULL;
ALTER TABLE cases MODIFY COLUMN icd_toleranz        TINYINT(1) DEFAULT NULL;
ALTER TABLE cases MODIFY COLUMN icd_entzug          TINYINT(1) DEFAULT NULL;

-- ── F8: THC-COOH-Metabolit ───────────────────────────────────────────────────
ALTER TABLE cases   ADD COLUMN IF NOT EXISTS thc_cooh_value     DECIMAL(7,2);

-- ── F9: SORKC-Strukturfelder pro Sitzung ─────────────────────────────────────
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS sorkc_s TEXT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS sorkc_o TEXT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS sorkc_r TEXT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS sorkc_k TEXT;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS sorkc_c TEXT;

-- ── F7 + F13: Abstinenz-Qualitätsfelder (Kreatinin + PEth) ──────────────────
ALTER TABLE abstinence_probes ADD COLUMN IF NOT EXISTS kreatinin_mg_dl DECIMAL(6,2);
ALTER TABLE abstinence_probes ADD COLUMN IF NOT EXISTS peth_value      DECIMAL(7,2);

-- ── F10: Mehrere Fallakten pro Klient (1:n) ──────────────────────────────────
ALTER TABLE sessions          ADD COLUMN IF NOT EXISTS case_id INT;
ALTER TABLE abstinence_probes ADD COLUMN IF NOT EXISTS case_id INT;
ALTER TABLE client_tasks      ADD COLUMN IF NOT EXISTS case_id INT;
-- Migration: case_id aus dem neuesten Fall pro Klient ableiten (deterministisch).
UPDATE sessions s
    JOIN (
        SELECT ca1.client_id, ca1.id
        FROM cases ca1
        WHERE ca1.created_at = (SELECT MAX(ca2.created_at) FROM cases ca2 WHERE ca2.client_id = ca1.client_id)
    ) newest ON s.client_id = newest.client_id
    SET s.case_id = newest.id
    WHERE s.case_id IS NULL;
UPDATE abstinence_probes ap
    JOIN (
        SELECT ca1.client_id, ca1.id
        FROM cases ca1
        WHERE ca1.created_at = (SELECT MAX(ca2.created_at) FROM cases ca2 WHERE ca2.client_id = ca1.client_id)
    ) newest ON ap.client_id = newest.client_id
    SET ap.case_id = newest.id
    WHERE ap.case_id IS NULL;
UPDATE client_tasks ct
    JOIN (
        SELECT ca1.client_id, ca1.id
        FROM cases ca1
        WHERE ca1.created_at = (SELECT MAX(ca2.created_at) FROM cases ca2 WHERE ca2.client_id = ca1.client_id)
    ) newest ON ct.client_id = newest.client_id
    SET ct.case_id = newest.id
    WHERE ct.case_id IS NULL;
ALTER TABLE client_tasks ADD INDEX IF NOT EXISTS idx_client_id (client_id);
ALTER TABLE client_tasks DROP INDEX IF EXISTS unique_client_task;
ALTER TABLE client_tasks ADD UNIQUE KEY IF NOT EXISTS unique_case_task (case_id, task_key);

-- ── F-C: case_events FK zu cases(id) ──────────────────────────────────────────
-- Auf Neuinstallationen legt CREATE TABLE diesen FK bereits an.
-- Bestehende Installationen: einmalig manuell ausführen (kein IF NOT EXISTS für FK in MariaDB):
--   ALTER TABLE case_events ADD FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE;

-- ── H-1: Brute-Force-Schutz (Login-Fehlversuche) ────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45)  NOT NULL,
    attempted_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── H-4: Authentifizierungs-Audit-Log (DSGVO Art. 32) ───────────────────────
CREATE TABLE IF NOT EXISTS auth_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type  ENUM('login_ok','login_fail','logout','timeout') NOT NULL,
    username    VARCHAR(100)  DEFAULT NULL,
    ip_address  VARCHAR(45)   NOT NULL,
    user_agent  VARCHAR(255)  DEFAULT NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── F-2: BKF-Leistungstests (psychophysische Testergebnisse) ────────────────
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
