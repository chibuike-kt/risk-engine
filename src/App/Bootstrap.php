<?php
namespace App;

use App\Db\Db;
use App\Http\Router;
use App\Http\JsonResponse;
use App\Http\Request;

use Domain\Sanctions\SanctionsRepository;
use Domain\Users\UserRepository;
use Domain\Decisions\DecisionRepository;
use Domain\Decisions\IdempotencyRepository;
use Domain\Risk\RiskEngine;
use Infrastructure\Clock\SystemClock;

final class Bootstrap {
  public static function router(): Router {
    $cfg = require __DIR__ . '/../../config/app.php';

    $pdo = (new Db($cfg['db']['path']))->pdo();

    $users = new UserRepository($pdo);
    $decisions = new DecisionRepository($pdo);
    $idempo = new IdempotencyRepository($pdo);
    $sanctions = new SanctionsRepository($pdo);
    $risk = new RiskEngine($cfg['risk'], $users, $decisions, $sanctions);
    $clock = new SystemClock();

    $r = new Router();

    $r->add('GET', '/health', function() {
      JsonResponse::send(200, ['ok' => true]);
    });

    $r->add('POST', '/sanctions', function(Request $req) use ($sanctions) {
      $body = $req->json();
      $kind = (string)($body['kind'] ?? 'ADDRESS');
      $value = (string)($body['value'] ?? '');
      if ($value === '') {
        JsonResponse::send(422, ['error'=>'validation', 'field'=>'value']);
        return;
      }
      $sanctions->add($kind, $value);
      JsonResponse::send(201, ['ok'=>true]);
    });

    $r->add('POST', '/evaluate', function(Request $req) use ($risk, $users, $decisions, $idempo, $clock) {
      $b = $req->json();

      $required = ['user_id','action','amount_minor','currency','counterparty'];
      foreach ($required as $k) {
        if (!isset($b[$k]) || $b[$k] === '') {
          JsonResponse::send(422, ['error'=>'validation', 'missing'=>$k]);
          return;
        }
      }

      $input = [
        'user_id' => (string)$b['user_id'],
        'action' => (string)$b['action'],
        'amount_minor' => (int)$b['amount_minor'],
        'currency' => (string)$b['currency'],
        'counterparty' => (string)$b['counterparty'],
        'country' => isset($b['country']) ? (string)$b['country'] : null,
        'device_id' => isset($b['device_id']) ? (string)$b['device_id'] : null,
      ];

      // Idempotency
      $idempoKey = $req->header('Idempotency-Key') ?? (isset($b['idempotency_key']) ? (string)$b['idempotency_key'] : null);
      if (!$idempoKey) {
        JsonResponse::send(422, ['error'=>'validation', 'missing'=>'Idempotency-Key']);
        return;
      }

      $canonical = json_encode([
        'user_id'=>$input['user_id'],
        'action'=>$input['action'],
        'amount_minor'=>$input['amount_minor'],
        'currency'=>$input['currency'],
        'counterparty'=>$input['counterparty'],
        'country'=>$input['country'],
        'device_id'=>$input['device_id'],
      ], JSON_UNESCAPED_SLASHES);

      $requestHash = hash('sha256', $canonical);

      $existing = $idempo->get($idempoKey);
      if ($existing) {
        if (($existing['request_hash'] ?? '') !== $requestHash) {
          JsonResponse::send(409, [
            'error' => 'idempotency_conflict',
            'detail' => 'Idempotency-Key was already used with a different request payload'
          ]);
          return;
        }
        $resp = json_decode((string)$existing['response_json'], true);
        JsonResponse::send(200, is_array($resp) ? $resp : ['error'=>'corrupt_idempotency_record']);
        return;
      }

      $result = $risk->evaluate($input);

      // Update baseline using recent history (simple rolling stats)
      $amounts = $decisions->recentAmounts($input['user_id'], 30);
      $amounts[] = $input['amount_minor'];

      $mean = array_sum($amounts) / max(1, count($amounts));
      $var = 0.0;
      foreach ($amounts as $a) $var += (($a - $mean) ** 2);
      $std = sqrt($var / max(1, count($amounts)));
      $users->updateBaseline($input['user_id'], (float)$mean, (float)max(1.0, $std));

      $decisionId = bin2hex(random_bytes(16));
      $createdAt = $clock->nowIso();

      $decisions->store([
        'id' => $decisionId,
        'user_id' => $input['user_id'],
        'action' => $input['action'],
        'amount_minor' => $input['amount_minor'],
        'currency' => $input['currency'],
        'counterparty' => $input['counterparty'],
        'country' => $input['country'],
        'device_id' => $input['device_id'],
        'outcome' => $result['outcome'],
        'risk_score' => $result['risk_score'],
        'risk_tier' => $result['risk_tier'],
        'reasons' => $result['reasons'],
        'created_at' => $createdAt,
      ]);

      $users->updateSignals($input['user_id'], $input['device_id'], $input['country']);
      $users->updateRisk($input['user_id'], $result['risk_score'], $result['risk_tier']);

      $response = [
        'decision_id' => $decisionId,
        'outcome' => $result['outcome'],
        'risk_score' => $result['risk_score'],
        'risk_tier' => $result['risk_tier'],
        'reasons' => $result['reasons'],
        'signals' => $result['signals']
      ];

      // store idempotency record
      $idempo->save($idempoKey, $input['user_id'], $requestHash, $response, $decisionId);

      JsonResponse::send(200, $response);
    });

    return $r;
  }
}
