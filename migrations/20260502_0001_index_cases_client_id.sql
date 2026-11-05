-- ── B-5: Expliziter Index auf cases.client_id ────────────────────────────────
-- InnoDB legt beim FK zwar implizit einen Index an, macht ihn aber nicht sichtbar.
-- Diese Migration ergänzt den benannten Index, der von der korrellierten Subquery
-- in index.php, akte.php, finanzen.php und klienten.php genutzt wird.
ALTER TABLE cases ADD INDEX IF NOT EXISTS idx_client_id (client_id);
