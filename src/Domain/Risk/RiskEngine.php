<?php
namespace Domain\Risk;

use Domain\Sanctions\SanctionsRepository;
use Domain\Users\UserRepository;
use Domain\Decisions\DecisionRepository;

final class RiskEngine {
  public function __construct(
    private array $cfg,
    private UserRepository $users,
    private DecisionRepository $decisions,
    private SanctionsRepository $sanctions
  ) {}

  public function evaluate(array $input): array {
    // input: user_id, action, amount_minor, currency, counterparty, country?, device_id?
    $user = $this->users->ensure($input['user_id']);

    $reasons = [];
    $score = (int)$this->cfg['score']['base'];

    // 1) Sanctions screening
    if ($this->sanctions->isHit((string)$input['counterparty'])) {
      $score += (int)$this->cfg['score']['sanctions_hit'];
      $reasons[] = ['code'=>'SANCTIONS_HIT', 'detail'=>'counterparty_on_sanctions_list'];
    }

    // 2) Amount hard limit
    if ((int)$input['amount_minor'] > (int)$this->cfg['single_tx_max_minor']) {
      $score += (int)$this->cfg['score']['amount_over_limit'];
      $reasons[] = ['code'=>'AMOUNT_OVER_LIMIT', 'detail'=>'single_tx_exceeds_configured_limit'];
    }

    // 3) Velocity
    $recent = $this->decisions->countRecent($input['user_id'], (int)$this->cfg['velocity']['window_seconds']);
    if ($recent >= (int)$this->cfg['velocity']['max_count']) {
      $score += (int)$this->cfg['score']['velocity_breach'];
      $reasons[] = ['code'=>'VELOCITY', 'detail'=>"too_many_actions_in_window count={$recent}"];
    }

    // 4) Daily total
    $daily = $this->decisions->sumDaily($input['user_id']);
    if (($daily + (int)$input['amount_minor']) > (int)$this->cfg['daily_total_max_minor']) {
      $score += 25;
      $reasons[] = ['code'=>'DAILY_TOTAL', 'detail'=>'daily_total_exceeds_configured_limit'];
    }

    // 5) Device / geo mismatch (simulated signals)
    $lastDevice = $user['last_device_id'] ?? null;
    $lastCountry = $user['last_country'] ?? null;

    if ($lastDevice && ($input['device_id'] ?? null) && $lastDevice !== $input['device_id']) {
      $score += (int)$this->cfg['score']['device_mismatch'];
      $reasons[] = ['code'=>'DEVICE_MISMATCH', 'detail'=>'device_changed_since_last_action'];
    }

    if ($lastCountry && ($input['country'] ?? null) && $lastCountry !== $input['country']) {
      $score += (int)$this->cfg['score']['geo_mismatch'];
      $reasons[] = ['code'=>'GEO_MISMATCH', 'detail'=>'country_changed_since_last_action'];
    }

    // 6) Simple anomaly check using baseline (z-score)
    $mean = (float)($user['baseline_mean_minor'] ?? 0);
    $std = max(1.0, (float)($user['baseline_std_minor'] ?? 1));
    $z = abs(((int)$input['amount_minor'] - $mean) / $std);

    if ($z >= 3.0 && $mean > 0) {
      $score += (int)$this->cfg['score']['anomaly_high'];
      $reasons[] = ['code'=>'ANOMALY', 'detail'=>"z_score={$z} mean={$mean} std={$std}"];
    }

    $tier = $this->tierFor($score);

    // outcome policy
    $outcome = 'ALLOW';
    if ($this->hasReason($reasons, 'SANCTIONS_HIT')) $outcome = 'BLOCK';
    else if ($tier === 'HIGH') $outcome = 'HOLD';
    else if ($tier === 'MEDIUM') $outcome = 'REVIEW';

    return [
      'risk_score' => $score,
      'risk_tier' => $tier,
      'outcome' => $outcome,
      'reasons' => $reasons,
      'signals' => [
        'recent_count_in_window' => $recent,
        'daily_total_before' => $daily,
        'z_score' => $z
      ]
    ];
  }

  private function tierFor(int $score): string {
    $tiers = $this->cfg['tiers'];
    if ($score >= (int)$tiers['HIGH']) return 'HIGH';
    if ($score >= (int)$tiers['MEDIUM']) return 'MEDIUM';
    return 'LOW';
  }

  private function hasReason(array $reasons, string $code): bool {
    foreach ($reasons as $r) if (($r['code'] ?? '') === $code) return true;
    return false;
  }
}
