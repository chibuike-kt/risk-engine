<?php
namespace App;

use App\Db\Db;
use App\Http\Router;
use App\Http\JsonResponse;
use App\Http\Request;

use App\Logging\AuditLogger;

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
    $audit = new AuditLogger($pdo);

    $checkpoint = new \App\Logging\AuditCheckpoint($pdo);
    $signer = null;
    try {
      $signer = new \App\Logging\CheckpointSigner(__DIR__ . '/../../storage/keys/audit_ed25519.key', __DIR__ . '/../../storage/keys/audit_ed25519.pub');
    } catch (\Throwable $e) {
      $signer = null;
    }

    $r = new Router();

    $r->add('GET', '/health', function() {
      JsonResponse::send(200, ['ok' => true]);
    });

    $r->add('GET', '/audit/verify', function() use ($audit) {
      JsonResponse::send(200, $audit->verifyChain());


    $r->add('POST', '/audit/checkpoint/signed', function() use ($audit, $checkpoint, $signer) {
      if (!$signer) { \App\Http\JsonResponse::send(503, ['error'=>'signer_unavailable']); return; }
      $v = $audit->verifyChain();
      if (!($v['ok'] ?? false)) { \App\Http\JsonResponse::send(409, ['error'=>'audit_chain_invalid', 'details'=>$v]); return; }
      $row = $checkpoint->upsertToday((string)$v['tip_hash'], (int)$v['count']);
      $sig = $signer->signCheckpoint(['day'=>$row['day'], 'tip_hash'=>$row['tip_hash'], 'audit_count'=>(int)$row['audit_count']]);
      \App\Http\JsonResponse::send(200, [
        'checkpoint'=>$row,
        'signature'=>$sig,
        'public_key_pem'=>$signer->publicKeyPem()
      ]);
    });

    $r->add('POST', '/audit/checkpoint/verify', function(\App\Http\Request $req) use ($signer) {
      $b = $req->json();
      $required = ['day','tip_hash','audit_count','signature'];
      foreach ($required as $k) { if (!isset($b[$k])) { \App\Http\JsonResponse::send(422, ['error'=>'validation', 'missing'=>$k]); return; } }
      $checkpoint = ['day'=>(string)$b['day'], 'tip_hash'=>(string)$b['tip_hash'], 'audit_count'=>(int)$b['audit_count']];
      $sig = (string)$b['signature'];
      $pub = isset($b['public_key_pem']) ? (string)$b['public_key_pem'] : null;

      // If caller supplies pubkey, verify against that. Else verify against local pubkey if signer exists.
      if (!$pub && !$signer) { \App\Http\JsonResponse::send(503, ['error'=>'no_public_key_available']); return; }

      $verifier = $signer;
      if (!$verifier) {
        // Create a temporary verifier using supplied public key only.
        try {
          $tmpPriv = __DIR__ . '/../../storage/keys/_tmp_missing.key';
          $tmpPub  = __DIR__ . '/../../storage/keys/_tmp_missing.pub';
          file_put_contents($tmpPub, $pub);
          $verifier = new \App\Logging\CheckpointSigner($tmpPriv, $tmpPub);
        } catch (\Throwable $e) {
          // fallback below
          $verifier = null;
        }
      }

      $ok = false;
      if ($signer) {
        $ok = $signer->verifyCheckpoint($checkpoint, $sig, $pub);
      } else {
        // If no local signer, we require pubkey and verify via OpenSSL directly.
        $pubKey = openssl_pkey_get_public($pub);
        if ($pubKey !== false) {
          $msg = json_encode(['day'=>$checkpoint['day'], 'tip_hash'=>$checkpoint['tip_hash'], 'audit_count'=>(int)$checkpoint['audit_count']], JSON_UNESCAPED_SLASHES);
          $sigRaw = base64_decode($sig, true);
          if ($sigRaw !== false) {
            $ok = (openssl_verify($msg, $sigRaw, $pubKey, OPENSSL_ALGO_ED25519) === 1);
          }
          openssl_free_key($pubKey);
        }
      }

      \App\Http\JsonResponse::send(200, ['ok'=>$ok]);
    });

    $checkpoint = new \App\Logging\AuditCheckpoint($pdo);

    $r->add('POST', '/audit/checkpoint', function() use ($audit, $checkpoint) {
      $v = $audit->verifyChain();
      if (!($v['ok'] ?? false)) { \App\Http\JsonResponse::send(409, ['error'=>'audit_chain_invalid', 'details'=>$v]); return; }
      $row = $checkpoint->upsertToday((string)$v['tip_hash'], (int)$v['count']);
      \App\Http\JsonResponse::send(200, ['checkpoint'=>$row]);
    });

    $r->add('GET', '/audit/checkpoint', function() use ($checkpoint) {
      $day = (string)($_GET['day'] ?? gmdate('Y-m-d'));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}', $day)) { \App\Http\JsonResponse::send(422, ['error'=>'validation', 'field'=>'day']); return; }
      $row = $checkpoint->get($day);
      if (!$row) { \App\Http\JsonResponse::send(404, ['error'=>'not_found']); return; }
      \App\Http\JsonResponse::send(200, ['checkpoint'=>$row]);
    });
    });

    $r->add('POST', '/sanctions', function(Request $req) use ($sanctions, $audit) {
      $body = $req->json();
      $kind = (string)($body['kind'] ?? 'ADDRESS');
      $value = (string)($body['value'] ?? '');
      if ($value === '') {
        JsonResponse::send(422, ['error'=>'validation', 'field'=>'value']);
        return;
      }
      $sanctions->add($kind, $value);

      $audit->append('SANCTIONS_ADDED', 'sanctions', $value, ['kind'=>$kind,'value'=>$value], 'system');

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

    // Evaluate endpoint with idempotency + case creation + audit trail
    $r->add('POST', '/evaluate', function(Request $req) use ($risk, $users, $decisions, $idempo, $cases, $clock, $audit) {
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

      // Audit entries
      $audit->append('DECISION_CREATED', 'decision', $decisionId, [
        'user_id'=>$input['user_id'],
        'action'=>$input['action'],
        'amount_minor'=>$input['amount_minor'],
        'currency'=>$input['currency'],
        'counterparty'=>$input['counterparty'],
        'outcome'=>$result['outcome'],
        'risk_score'=>$result['risk_score'],
        'risk_tier'=>$result['risk_tier'],
        'case_id'=>$caseId
      ], 'system');

      if ($caseId) {
        $audit->append('CASE_OPENED', 'case', $caseId, [
          'user_id'=>$input['user_id'],
          'decision_id'=>$decisionId,
          'outcome'=>$result['outcome'],
          'risk_score'=>$result['risk_score'],
          'risk_tier'=>$result['risk_tier'],
          'reasons'=>$result['reasons']
        ], 'system');
      }

      JsonResponse::send(200, $response);
    });

    // Dynamic routes for /cases/{id} and /cases/{id}/resolve
    $r->add('GET', '/_dynamic', function(Request $req) use ($cases) {
      $p = $req->path();
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

    $r->add('POST', '/_dynamic', function(Request $req) use ($cases, $audit) {
      $p = $req->path();
      if (preg_match('#^/cases/([a-f0-9]{32})/resolve$#', $p, $m)) {
        $b = $req->json();
        $resolution = strtoupper((string)($b['resolution'] ?? ''));
        $notes = (string)($b['notes'] ?? '');
        $actor = $req->header('X-Actor') ?? 'analyst';

        if (!in_array($resolution, ['APPROVE','DENY'], true)) {
          JsonResponse::send(422, ['error'=>'validation', 'field'=>'resolution', 'allowed'=>['APPROVE','DENY']]);
          return;
        }

        $ok = $cases->resolve($m[1], $resolution, $notes);
        if (!$ok) { JsonResponse::send(409, ['error'=>'case_not_open_or_missing']); return; }

        $audit->append('CASE_RESOLVED', 'case', $m[1], [
          'resolution'=>$resolution,
          'notes'=>$notes
        ], $actor);

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

        $direct = ['/health','/sanctions','/evaluate','/cases','/audit/verify'];
        if (in_array($path, $direct, true)) {
          $this->inner->dispatch($req);
          return;
        }

        $_SERVER['REQUEST_URI'] = '/_dynamic';
        $_SERVER['REQUEST_METHOD'] = $method;
        $this->inner->dispatch($req);
      }
    };
  }
}
