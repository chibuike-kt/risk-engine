CREATE TABLE IF NOT EXISTS cases (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  decision_id TEXT NOT NULL,
  status TEXT NOT NULL, -- OPEN | RESOLVED
  risk_score INTEGER NOT NULL,
  risk_tier TEXT NOT NULL,
  outcome TEXT NOT NULL, -- HOLD | REVIEW
  reasons_json TEXT NOT NULL,
  opened_at TEXT NOT NULL,
  resolved_at TEXT,
  resolution TEXT, -- APPROVE | DENY
  notes TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_cases_status_time ON cases(status, opened_at);
CREATE INDEX IF NOT EXISTS idx_cases_user_time ON cases(user_id, opened_at);
