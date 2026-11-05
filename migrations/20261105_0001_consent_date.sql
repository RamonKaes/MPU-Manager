-- Datum der unterzeichneten Datenschutzerklärung. Bestandszeilen bleiben ohne Datum.
ALTER TABLE clients ADD COLUMN IF NOT EXISTS dsgvo_consent_date DATE NULL AFTER dsgvo_consent;
