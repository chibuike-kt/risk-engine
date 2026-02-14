<?php
namespace Domain\Decisions;

use PDO;

final class CaseRepository {
  public function __construct(private PDO $pdo) {}

  public function open(array $c): void {
    $stmt = $this->pdo->prepare("INSERT INTO cases
      (id,user_id,decision_id,status,risk_score,risk_tier,outcome,reasons_json,opened_at)
      VALUES (:id,:u,:d,'OPEN',:s,:t,:o,:r,:at)");
    $stmt->execute([
      ':id'=>$c['id'],
      ':u'=>$c['user_id'],
      ':d'=>$c['decision_id'],
      ':s'=>$c['risk_score'],
      ':t'=>$c['risk_tier'],
      ':o'=>$c['outcome'],
      ':r'=>json_encode($c['reasons'], JSON_UNESCAPED_SLASHES),
      ':at'=>$c['opened_at'],
    ]);
  }

  public function list(string $status, int $limit = 50): array {
    $status = strtoupper($status);
    $stmt = $this->pdo->prepare("SELECT * FROM cases WHERE status = :s ORDER BY opened_at DESC LIMIT :n");
    $stmt->bindValue(':s', $status, PDO::PARAM_STR);
    $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public function get(string $id): ?array {
    $stmt = $this->pdo->prepare("SELECT * FROM cases WHERE id = :id LIMIT 1");
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public function resolve(string $id, string $resolution, string $notes): bool {
    $stmt = $this->pdo->prepare("
      UPDATE cases
      SET status='RESOLVED', resolved_at=:t, resolution=:r, notes=:n
      WHERE id = :id AND status='OPEN'
    ");
    $stmt->execute([
      ':t'=>gmdate('c'),
      ':r'=>strtoupper($resolution),
      ':n'=>$notes,
      ':id'=>$id,
    ]);
    return $stmt->rowCount() === 1;
  }
}
