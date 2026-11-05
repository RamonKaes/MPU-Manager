-- Eingaben der Triage, die bisher nicht gespeichert wurden und beim nächsten Bearbeiten verloren gingen.
ALTER TABLE cases ADD COLUMN IF NOT EXISTS persoenlichkeit TINYINT(1) DEFAULT 0;
ALTER TABLE cases ADD COLUMN IF NOT EXISTS medizinal_beikonsum TINYINT(1) DEFAULT 0;
ALTER TABLE cases ADD COLUMN IF NOT EXISTS erschwerend_faktoren TINYINT(1) DEFAULT 0;
ALTER TABLE cases ADD COLUMN IF NOT EXISTS therapie_art ENUM('keine','ambulant','stationaer') NOT NULL DEFAULT 'keine';
