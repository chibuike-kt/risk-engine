<?php
namespace Domain\Users;

use PDO;

final class UserRepository {
  public function __construct(private PDO $pdo) {}

  public function ensure(string $userId): array {
    $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id'=>$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $this->pdo->prepare("INSERT INTO users (id, created_at) VALUES (:id,:t)")
      ->execute([':id'=>$userId, ':t'=>gmdate('c')]);

    $stmt->execute([':id'=>$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  }

  public function updateRisk(string $userId, int $score, string $tier): void {
    $stmt = $this->pdo->prepare("UPDATE users SET risk_score = :s, risk_tier = :t WHERE id = :id");
    $stmt->execute([':s'=>$score, ':t'=>$tier, ':id'=>$userId]);
  }

  public function updateSignals(string $userId, ?string $deviceId, ?string $country): void {
    $stmt = $this->pdo->prepare("UPDATE users SET last_device_id = :d, last_country = :c WHERE id = :id");
    $stmt->execute([':d'=>$deviceId, ':c'=>$country, ':id'=>$userId]);
  }

  public function updateBaseline(string $userId, float $mean, float $std): void {
    $stmt = $this->pdo->prepare("UPDATE users SET baseline_mean_minor = :m, baseline_std_minor = :s WHERE id = :id");
    $stmt->execute([':m'=>$mean, ':s'=>$std, ':id'=>$userId]);
  }
}
