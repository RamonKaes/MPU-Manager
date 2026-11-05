-- R4-1: Zwei Sachverhalte lagen in einer Spalte.
-- Das Aufnahmeformular schrieb "Widerrufsbelehrung ausgehändigt" nach revocation_confirmed.
-- Die Akte liest diese Spalte als "Einwilligung widerrufen, Löschung ausstehend".
-- Echte Widerrufe sind daran erkennbar, dass es dazu einen Audit-Eintrag revocation_confirm gibt.
ALTER TABLE clients ADD COLUMN IF NOT EXISTS revocation_notice_confirmed TINYINT(1) DEFAULT 0 AFTER dsgvo_consent;
UPDATE clients SET revocation_notice_confirmed = 1, revocation_confirmed = 0
 WHERE revocation_confirmed = 1
   AND id NOT IN (SELECT client_id FROM audit_log WHERE action = 'revocation_confirm' AND client_id IS NOT NULL);
