<?php
namespace Domain\Sanctions;

use PDO;

final class SanctionsRepository {
  public function __construct(private PDO $pdo) {}

  public function add(string $kind, string $value): void {
    $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO sanctions (kind, value, added_at) VALUES (:k,:v,:t)");
    $stmt->execute([':k'=>$kind, ':v'=>$value, ':t'=>gmdate('c')]);
  }

  public function isHit(string $value): bool {
    $stmt = $this->pdo->prepare("SELECT 1 FROM sanctions WHERE value = :v LIMIT 1");
    $stmt->execute([':v'=>$value]);
    return (bool)$stmt->fetchColumn();
  }
}
