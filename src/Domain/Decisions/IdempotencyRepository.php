<?php
namespace Domain\Decisions;

use PDO;

final class IdempotencyRepository {
  public function __construct(private PDO $pdo) {}

  public function get(string $key): ?array {
    $stmt = $this->pdo->prepare("SELECT * FROM idempotency_keys WHERE key = :k LIMIT 1");
    $stmt->execute([':k'=>$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public function save(string $key, string $userId, string $requestHash, array $response, string $decisionId): void {
    $stmt = $this->pdo->prepare("
      INSERT INTO idempotency_keys (key, user_id, request_hash, response_json, decision_id, created_at)
      VALUES (:k,:u,:h,:r,:d,:t)
    ");
    $stmt->execute([
      ':k'=>$key,
      ':u'=>$userId,
      ':h'=>$requestHash,
      ':r'=>json_encode($response, JSON_UNESCAPED_SLASHES),
      ':d'=>$decisionId,
      ':t'=>gmdate('c'),
    ]);
  }
}
