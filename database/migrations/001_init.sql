CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL,
  risk_tier TEXT NOT NULL DEFAULT 'LOW',
  risk_score INTEGER NOT NULL DEFAULT 0,
  baseline_mean_minor REAL NOT NULL DEFAULT 0,
  baseline_std_minor REAL NOT NULL DEFAULT 1,
  last_device_id TEXT,
  last_country TEXT
);

CREATE TABLE IF NOT EXISTS risk_events (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  kind TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  occurred_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS decisions (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  action TEXT NOT NULL,
  amount_minor INTEGER NOT NULL,
  currency TEXT NOT NULL,
  counterparty TEXT NOT NULL,
  country TEXT,
  device_id TEXT,
  outcome TEXT NOT NULL, -- ALLOW | HOLD | REVIEW | BLOCK
  risk_score INTEGER NOT NULL,
  risk_tier TEXT NOT NULL,
  reasons_json TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_decisions_user_time ON decisions(user_id, created_at);
