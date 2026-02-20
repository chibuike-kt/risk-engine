Risk & Fraud Simulation Engine

This project is a lightweight risk decisioning service for financial actions such as withdrawals, transfers, and cash-outs.
It produces deterministic outcomes with decision logs, reasons, and a configurable scoring model.

Features
- Sanctions screening (counterparty match)
- Velocity limits (actions per time window)
- Daily total caps
- Device and geo mismatch signals
- Simple anomaly detection using rolling baseline (mean/std)
- Immutable decision logging

Requirements
- PHP 8.2+
- Composer

Setup
1) Install dependencies
   composer install

2) Run migrations
   ./bin/migrate

3) Start server
   php -S 127.0.0.1:8000 -t public

Endpoints
GET /health

POST /sanctions
Body:
{
  "kind": "ADDRESS",
  "value": "0xBAD"
}

POST /evaluate
Body:
{
  "user_id": "user_1",
  "action": "withdraw",
  "amount_minor": 25000,
  "currency": "USD",
  "counterparty": "0xabc...",
  "country": "US",
  "device_id": "device_a"
}

Response includes outcome, risk score, tier, reasons, and a decision_id.

Testing
composer test

Case Management
When an evaluation outcome is HOLD or REVIEW, a case is automatically opened and linked to the decision.

List open cases
GET /cases?status=open

List resolved cases
GET /cases?status=resolved

Get a case
GET /cases/{case_id}

Resolve a case
POST /cases/{case_id}/resolve
Body:
{
  "resolution": "APPROVE",
  "notes": "KYC verified, allow withdrawal"
}

Audit Trail
The service maintains a tamper-evident audit log using a hash chain.
Each audit record includes prev_hash and hash, where hash = sha256(prev_hash + canonical_event_payload).

Verify audit chain
GET /audit/verify

Signed Checkpoints
Generate signed checkpoint (requires local Ed25519 keys in storage/keys)
POST /audit/checkpoint/signed

Verify checkpoint signature
POST /audit/checkpoint/verify
Body:
{
  "day": "2026-02-20",
  "tip_hash": "....",
  "audit_count": 123,
  "signature": "base64...",
  "public_key_pem": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n"
}
