CREATE TABLE IF NOT EXISTS audit_checkpoints (
  day TEXT PRIMARY KEY, -- YYYY-MM-DD (UTC)
  tip_hash TEXT NOT NULL,
  audit_count INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
