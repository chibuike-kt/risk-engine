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
use Domain\Decisions\CaseRepository;
use Domain\Risk\RiskEngine;
use Infrastructure\Clock\SystemClock;

final class Bootstrap {
  public static function router(): Router {
    $cfg = require __DIR__ . '/../../config/app.php';

    $pdo = (new Db($cfg['db']['path']))->pdo();

    $users = new UserRepository($pdo);
    $decisions = new DecisionRepository($pdo);
    $idempo = new IdempotencyRepository($pdo);
    $cases = new CaseRepository($pdo);
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

    // List cases
    $r->add('GET', '/cases', function(Request $req) use ($cases) {
      $status = $_GET['status'] ?? 'open';
      $status = strtolower((string)$status);
      if (!in_array($status, ['open','resolved'], true)) {
        JsonResponse::send(422, ['error'=>'validation', 'field'=>'status', 'allowed'=>['open','resolved']]);
        return;
      }
      $rows = $cases->list($status === 'open' ? 'OPEN' : 'RESOLVED', 50);
      foreach ($rows as &$row) {
        $row['reasons'] = json_decode((string)$row['reasons_json'], true);
        unset($row['reasons_json']);
      }
      JsonResponse::send(200, ['cases'=>$rows]);
    });

    // Get case by id: /cases/{id}
    $r->add('GET', '/case', function() {
      JsonResponse::send(400, ['error'=>'use /cases/{id}']);
    });

    // Resolve case: /cases/{id}/resolve
    $r->add('POST', '/case/resolve', function() {
      JsonResponse::send(400, ['error'=>'use /cases/{id}/resolve']);
    });

    // Evaluate endpoint with idempotency + case creation
    $r->add('POST', '/evaluate', function(Request $req) use ($risk, $users, $decisions, $idempo, $cases, $clock) {
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

      // Update rolling baseline
      $amounts = $decisions->recentAmounts($input['user_id'], 30);
      $amounts[] = $input['amount_minor'];

      $mean = array_sum($amounts) / max(1, count($amounts));
      $var = 0.0;
      foreach ($amounts as $a) $var += (($a - $mean) ** 2);
      $std = sqrt($var / max(1, count($amounts)));
      $users->updateBaseline($input['user_id'], (float)$mean, (float)max(1.0, $std));

      $decisionId = bin2hex(random_bytes(16));
      $createdAt = $clock->nowIso();

      // If HOLD/REVIEW, open a case
      $caseId = null;
      if (in_array($result['outcome'], ['HOLD','REVIEW'], true)) {
        $caseId = bin2hex(random_bytes(16));
        $cases->open([
          'id'=>$caseId,
          'user_id'=>$input['user_id'],
          'decision_id'=>$decisionId,
          'risk_score'=>$result['risk_score'],
          'risk_tier'=>$result['risk_tier'],
          'outcome'=>$result['outcome'],
          'reasons'=>$result['reasons'],
          'opened_at'=>$createdAt
        ]);
      }

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
        'case_id' => $caseId
      ]);

      $users->updateSignals($input['user_id'], $input['device_id'], $input['country']);
      $users->updateRisk($input['user_id'], $result['risk_score'], $result['risk_tier']);

      $response = [
        'decision_id' => $decisionId,
        'case_id' => $caseId,
        'outcome' => $result['outcome'],
        'risk_score' => $result['risk_score'],
        'risk_tier' => $result['risk_tier'],
        'reasons' => $result['reasons'],
        'signals' => $result['signals']
      ];

      $idempo->save($idempoKey, $input['user_id'], $requestHash, $response, $decisionId);

      JsonResponse::send(200, $response);
    });

    // Dynamic routes handling: we’ll do simple dispatcher based on path patterns
    $r->add('GET', '/_dynamic', function(Request $req) use ($cases) {
      $p = $req->path();
      // /cases/{id}
      if (preg_match('#^/cases/([a-f0-9]{32})$#', $p, $m)) {
        $row = $cases->get($m[1]);
        if (!$row) { JsonResponse::send(404, ['error'=>'not_found']); return; }
        $row['reasons'] = json_decode((string)$row['reasons_json'], true);
        unset($row['reasons_json']);
        JsonResponse::send(200, ['case'=>$row]);
        return;
      }
      JsonResponse::send(404, ['error'=>'not_found', 'path'=>$p]);
    });

    $r->add('POST', '/_dynamic', function(Request $req) use ($cases) {
      $p = $req->path();
      // /cases/{id}/resolve
      if (preg_match('#^/cases/([a-f0-9]{32})/resolve$#', $p, $m)) {
        $b = $req->json();
        $resolution = strtoupper((string)($b['resolution'] ?? ''));
        $notes = (string)($b['notes'] ?? '');
        if (!in_array($resolution, ['APPROVE','DENY'], true)) {
          JsonResponse::send(422, ['error'=>'validation', 'field'=>'resolution', 'allowed'=>['APPROVE','DENY']]);
          return;
        }
        $ok = $cases->resolve($m[1], $resolution, $notes);
        if (!$ok) { JsonResponse::send(409, ['error'=>'case_not_open_or_missing']); return; }
        JsonResponse::send(200, ['ok'=>true]);
        return;
      }
      JsonResponse::send(404, ['error'=>'not_found', 'path'=>$p]);
    });

    return new class($r) extends Router {
      public function __construct(private Router $inner) {}
      public function dispatch(\App\Http\Request $req): void {
        $path = $req->path();
        $method = strtoupper($req->method());

        // direct routes first
        $direct = ['/health','/sanctions','/evaluate','/cases','/case','/case/resolve'];
        if (in_array($path, $direct, true)) {
          $this->inner->dispatch($req);
          return;
        }

        // route dynamic paths through /_dynamic
        $_SERVER['REQUEST_URI'] = '/_dynamic';
        $_SERVER['REQUEST_METHOD'] = $method;
        $this->inner->dispatch($req);
      }
    };
  }
}
