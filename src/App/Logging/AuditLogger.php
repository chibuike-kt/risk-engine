<?php
namespace App\Logging;

use PDO;

final class AuditLogger {
  public function __construct(private PDO $pdo) {}

  public function append(
    string $eventType,
    string $subjectType,
    string $subjectId,
    array $payload,
    ?string $actor = null
  ): array {
    $eventId = bin2hex(random_bytes(16));
    $createdAt = gmdate('c');

    $prevHash = $this->lastHash() ?? str_repeat('0', 64);

    $canonical = $this->canonicalPayload([
      'event_id' => $eventId,
      'event_type' => $eventType,
      'subject_type' => $subjectType,
      'subject_id' => $subjectId,
      'actor' => $actor,
      'payload' => $payload,
      'created_at' => $createdAt,
    ]);

    $hash = hash('sha256', $prevHash . $canonical);

    $stmt = $this->pdo->prepare("INSERT INTO audit_log
      (event_id,event_type,subject_type,subject_id,actor,payload_json,prev_hash,hash,created_at)
      VALUES (:eid,:et,:st,:sid,:a,:p,:ph,:h,:t)");
    $stmt->execute([
      ':eid'=>$eventId,
      ':et'=>$eventType,
      ':st'=>$subjectType,
      ':sid'=>$subjectId,
      ':a'=>$actor,
      ':p'=>json_encode($payload, JSON_UNESCAPED_SLASHES),
      ':ph'=>$prevHash,
      ':h'=>$hash,
      ':t'=>$createdAt,
    ]);

    return ['event_id'=>$eventId, 'hash'=>$hash, 'prev_hash'=>$prevHash, 'created_at'=>$createdAt];
  }

  public function verifyChain(): array {
    $rows = $this->pdo->query("SELECT id,event_id,event_type,subject_type,subject_id,actor,payload_json,prev_hash,hash,created_at
                              FROM audit_log ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $expectedPrev = str_repeat('0', 64);

    foreach ($rows as $i => $r) {
      $payload = json_decode((string)$r['payload_json'], true);
      if (!is_array($payload)) $payload = ['_corrupt'=>true];

      $canonical = $this->canonicalPayload([
        'event_id' => (string)$r['event_id'],
        'event_type' => (string)$r['event_type'],
        'subject_type' => (string)$r['subject_type'],
        'subject_id' => (string)$r['subject_id'],
        'actor' => $r['actor'] !== null ? (string)$r['actor'] : null,
        'payload' => $payload,
        'created_at' => (string)$r['created_at'],
      ]);

      $prevHash = (string)$r['prev_hash'];
      $hash = (string)$r['hash'];

      if ($prevHash !== $expectedPrev) {
        return [
          'ok' => false,
          'broken_at' => $i,
          'reason' => 'prev_hash_mismatch',
          'expected_prev_hash' => $expectedPrev,
          'found_prev_hash' => $prevHash,
          'event_id' => $r['event_id']
        ];
      }

      $expectedHash = hash('sha256', $expectedPrev . $canonical);
      if (!hash_equals($expectedHash, $hash)) {
        return [
          'ok' => false,
          'broken_at' => $i,
          'reason' => 'hash_mismatch',
          'expected_hash' => $expectedHash,
          'found_hash' => $hash,
          'event_id' => $r['event_id']
        ];
      }

      $expectedPrev = $hash;
    }

    return ['ok' => true, 'count' => count($rows), 'tip_hash' => $expectedPrev];
  }

  private function lastHash(): ?string {
    $stmt = $this->pdo->query("SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1");
    $h = $stmt->fetchColumn();
    return $h ? (string)$h : null;
  }

  private function canonicalPayload(array $data): string {
    $normalized = $this->ksortRecursive($data);
    return json_encode($normalized, JSON_UNESCAPED_SLASHES);
    }

  private function ksortRecursive(mixed $v): mixed {
    if (!is_array($v)) return $v;
    $assoc = array_keys($v) !== range(0, count($v) - 1);
    if ($assoc) {
      ksort($v);
    }
    foreach ($v as $k => $vv) {
      $v[$k] = $this->ksortRecursive($vv);
    }
    return $v;
  }
}
