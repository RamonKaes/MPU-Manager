-- MPU-Manager Datenbank-Setup
-- Erstellt die vollständige Struktur für Neuinstallationen.
-- Stand: synchronisiert mit migrations/ (letzte: 20260713_0001_case_notes)
-- Beim Anlegen einer Migration diese Zeile mitziehen – sie ist der einzige
-- Hinweis darauf, ob schema.sql den Migrationsstand noch abbildet.

-- 1. Klienten-Stammdaten
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    date_of_birth DATE,
    street VARCHAR(150),
    zip_code VARCHAR(10),
    city VARCHAR(100),
    phone VARCHAR(50),
    email VARCHAR(100),
    gender ENUM('m', 'f', 'd') DEFAULT 'm',
    weight_kg DECIMAL(5,2),
    dsgvo_consent TINYINT(1) DEFAULT 0,
    dsgvo_consent_date DATE NULL,
    revocation_notice_confirmed TINYINT(1) DEFAULT 0,
    revocation_confirmed TINYINT(1) DEFAULT 0,
    early_start_requested TINYINT(1) DEFAULT 0,
    version INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. MPU-Fallakten
CREATE TABLE IF NOT EXISTS cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    offense_date DATE,
    legal_validity_date DATE,
    suspension_end_date DATE,
    prior_mpu TINYINT(1) DEFAULT 0,
    offense_category VARCHAR(255),
    license_classes VARCHAR(100),
    bkf_status ENUM('nein','aktiv','angestrebt') DEFAULT 'nein',
    bak_value DECIMAL(4,2),
    symptoms_recorded TINYINT(1) DEFAULT 0,
    therapy_completed TINYINT(1) DEFAULT 0,
    drug_types VARCHAR(255),
    thc_value DECIMAL(5,2),
    persoenlichkeit TINYINT(1) DEFAULT 0,
    medizinal_beikonsum TINYINT(1) DEFAULT 0,
    erschwerend_faktoren TINYINT(1) DEFAULT 0,
    therapie_art ENUM('keine','ambulant','stationaer') NOT NULL DEFAULT 'keine',
    thc_cooh_value DECIMAL(7,2),
    flensburg_points INT DEFAULT 0,
    aggression_involved TINYINT(1) DEFAULT 0,

    -- Triage
    suggested_hypothesis TEXT,
    suggested_abstinence TEXT,
    abstinenz_monate INT DEFAULT 0,

    -- Human Override
    override_active TINYINT(1) DEFAULT 0,
    override_reason TEXT,

    -- Behörde & Fristen
    authority_name VARCHAR(150),
    authority_file_number VARCHAR(100),
    antrag_status ENUM('nicht_gestellt','gestellt','aufforderung_liegt_vor','abgelehnt') DEFAULT 'nicht_gestellt',
    mpu_target_date DATE,
    bussgeld_datum DATE,
    einspruch_status ENUM('offen','eingelegt','abgelaufen','nicht_relevant') DEFAULT 'nicht_relevant',

    -- Screening-Scores
    audit_score INT,
    last_score INT,

    -- Verträge & Finanzen
    package_type VARCHAR(50),
    package_price DECIMAL(8,2),
    hourly_rate DECIMAL(8,2),
    payment_status ENUM('offen','teilweise','beglichen') NOT NULL DEFAULT 'offen',
    payment_date DATE,
    payment_amount_paid DECIMAL(8,2) DEFAULT NULL,

    -- Vorliegende Dokumente
    dokument_akte        TINYINT(1) DEFAULT 0,
    dokument_strafbefehl TINYINT(1) DEFAULT 0,
    dokument_register    TINYINT(1) DEFAULT 0,

    -- ICD-Quick-Check (NULL = nicht erhoben, 0 = verneint, 1 = bejaht)
    icd_kontrollverlust TINYINT(1) DEFAULT NULL,
    icd_priorisierung   TINYINT(1) DEFAULT NULL,
    icd_folgeschaeden   TINYINT(1) DEFAULT NULL,
    icd_craving         TINYINT(1) DEFAULT NULL,
    icd_toleranz        TINYINT(1) DEFAULT NULL,
    icd_entzug          TINYINT(1) DEFAULT NULL,
    mehrfachdelikt      TINYINT(1) DEFAULT 0,

    -- Status (Notizen: siehe Tabelle case_notes)
    status ENUM('aktiv','abgeschlossen','pausiert') DEFAULT 'aktiv',

    version    INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at  TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_client_id (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Abstinenz-Einzelnachweise
CREATE TABLE IF NOT EXISTS abstinence_probes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT NOT NULL,
    case_id     INT,
    probe_date  DATE NOT NULL,
    method      ENUM('urin','haar') NOT NULL,
    result      ENUM('negativ','positiv') NOT NULL,
    lab_name    VARCHAR(150),
    notes       TEXT,
    kreatinin_mg_dl DECIMAL(6,2),
    peth_value      DECIMAL(7,2),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Delikt-abhängige Klienten-Aufgaben (Checkliste)
CREATE TABLE IF NOT EXISTS client_tasks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    client_id   INT NOT NULL,
    case_id     INT,
    task_key    VARCHAR(100) NOT NULL,
    status      ENUM('offen','angefordert','liegt_vor') DEFAULT 'offen',
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_case_task (case_id, task_key),
    INDEX idx_client_id (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Audit-Log
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    action      VARCHAR(50)  NOT NULL,
    table_name  VARCHAR(50)  NOT NULL,
    record_id   INT          NOT NULL,
    client_id   INT,
    description TEXT,
    user_name   VARCHAR(100),
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Sitzungsprotokolle
CREATE TABLE IF NOT EXISTS sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    client_id    INT NOT NULL,
    case_id      INT,
    session_date DATE NOT NULL,
    duration_min INT DEFAULT 60,
    phase        ENUM('anamnese','analyse','check') NOT NULL,
    notes        TEXT,
    sorkc_s      TEXT,
    sorkc_o      TEXT,
    sorkc_r      TEXT,
    sorkc_k      TEXT,
    sorkc_c      TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Behörden-Korrespondenz-Log
CREATE TABLE IF NOT EXISTS case_events (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    case_id      INT NOT NULL,
    client_id    INT NOT NULL,
    event_date   DATE NOT NULL,
    event_type   ENUM('aufforderung','antrag','widerspruch','fristverlängerung','bescheid','sonstiges') NOT NULL DEFAULT 'sonstiges',
    description  TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_case_id   (case_id),
    INDEX idx_client_id (client_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id)   REFERENCES cases(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7b. Beratungsnotizen (Journal, fallbezogen – unabhängig von den Sitzungen)
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

-- 8. BKF-Leistungstests (psychophysische Testergebnisse)
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

-- 9. Brute-Force-Schutz
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45)  NOT NULL,
    attempted_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Authentifizierungs-Audit-Log (DSGVO Art. 32)
CREATE TABLE IF NOT EXISTS auth_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type  ENUM('login_ok','login_fail','logout','timeout') NOT NULL,
    username    VARCHAR(100)  DEFAULT NULL,
    ip_address  VARCHAR(45)   NOT NULL,
    user_agent  VARCHAR(255)  DEFAULT NULL,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Migrations-Tracking
CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(14) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
