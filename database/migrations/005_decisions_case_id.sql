ALTER TABLE decisions ADD COLUMN case_id TEXT;
CREATE INDEX IF NOT EXISTS idx_decisions_case_id ON decisions(case_id);
