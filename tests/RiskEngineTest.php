<?php
use PHPUnit\Framework\TestCase;

use Domain\Risk\RiskEngine;
use Domain\Users\UserRepository;
use Domain\Decisions\DecisionRepository;
use Domain\Sanctions\SanctionsRepository;

final class RiskEngineTest extends TestCase {
  private PDO $pdo;

  protected function setUp(): void {
    $this->pdo = new PDO('sqlite::memory:');
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->pdo->exec('PRAGMA foreign_keys = ON;');

    $this->pdo->exec("CREATE TABLE users (
      id TEXT PRIMARY KEY, created_at TEXT NOT NULL,
      risk_tier TEXT NOT NULL DEFAULT 'LOW', risk_score INTEGER NOT NULL DEFAULT 0,
      baseline_mean_minor REAL NOT NULL DEFAULT 0, baseline_std_minor REAL NOT NULL DEFAULT 1,
      last_device_id TEXT, last_country TEXT
    );");

    $this->pdo->exec("CREATE TABLE decisions (
      id TEXT PRIMARY KEY, user_id TEXT NOT NULL,
      action TEXT NOT NULL, amount_minor INTEGER NOT NULL, currency TEXT NOT NULL,
      counterparty TEXT NOT NULL, country TEXT, device_id TEXT,
      outcome TEXT NOT NULL, risk_score INTEGER NOT NULL, risk_tier TEXT NOT NULL,
      reasons_json TEXT NOT NULL, created_at TEXT NOT NULL
    );");

    $this->pdo->exec("CREATE TABLE sanctions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      value TEXT NOT NULL UNIQUE,
      kind TEXT NOT NULL,
      added_at TEXT NOT NULL
    );");
  }

  public function testSanctionsBlocks(): void {
    $cfg = [
      'single_tx_max_minor' => 200000,
      'daily_total_max_minor' => 500000,
      'velocity' => ['window_seconds'=>900, 'max_count'=>6],
      'score' => [
        'base'=>0,'amount_over_limit'=>70,'velocity_breach'=>50,'sanctions_hit'=>100,
        'anomaly_high'=>40,'device_mismatch'=>25,'geo_mismatch'=>25
      ],
      'tiers' => ['LOW'=>0,'MEDIUM'=>40,'HIGH'=>70]
    ];

    $users = new UserRepository($this->pdo);
    $decisions = new DecisionRepository($this->pdo);
    $sanctions = new SanctionsRepository($this->pdo);
    $sanctions->add('ADDRESS', '0xBAD');

    $engine = new RiskEngine($cfg, $users, $decisions, $sanctions);

    $res = $engine->evaluate([
      'user_id'=>'u1','action'=>'withdraw','amount_minor'=>1000,'currency'=>'USD',
      'counterparty'=>'0xBAD','country'=>'US','device_id'=>'d1'
    ]);

    $this->assertSame('BLOCK', $res['outcome']);
    $this->assertSame('HIGH', $res['risk_tier']);
  }
}
