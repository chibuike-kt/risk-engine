<?php
namespace Domain\Decisions;

use PDO;

final class DecisionRepository {
  public function __construct(private PDO $pdo) {}

  public function store(array $d): void {
    $stmt = $this->pdo->prepare("INSERT INTO decisions
      (id,user_id,action,amount_minor,currency,counterparty,country,device_id,outcome,risk_score,risk_tier,reasons_json,created_at)
      VALUES (:id,:u,:a,:amt,:cur,:cp,:country,:dev,:out,:score,:tier,:reasons,:t)");
    $stmt->execute([
      ':id'=>$d['id'],
      ':u'=>$d['user_id'],
      ':a'=>$d['action'],
      ':amt'=>$d['amount_minor'],
      ':cur'=>$d['currency'],
      ':cp'=>$d['counterparty'],
      ':country'=>$d['country'],
      ':dev'=>$d['device_id'],
      ':out'=>$d['outcome'],
      ':score'=>$d['risk_score'],
      ':tier'=>$d['risk_tier'],
      ':reasons'=>json_encode($d['reasons'], JSON_UNESCAPED_SLASHES),
      ':t'=>$d['created_at'],
    ]);
  }

  public function countRecent(string $userId, int $windowSeconds): int {
    $since = gmdate('c', time() - $windowSeconds);
    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM decisions WHERE user_id = :u AND created_at >= :since");
    $stmt->execute([':u'=>$userId, ':since'=>$since]);
    return (int)$stmt->fetchColumn();
  }

  public function sumDaily(string $userId): int {
    $start = gmdate('Y-m-d') . 'T00:00:00+00:00';
    $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM decisions WHERE user_id = :u AND created_at >= :s");
    $stmt->execute([':u'=>$userId, ':s'=>$start]);
    return (int)$stmt->fetchColumn();
  }

  public function recentAmounts(string $userId, int $n = 30): array {
    $stmt = $this->pdo->prepare("SELECT amount_minor FROM decisions WHERE user_id = :u ORDER BY created_at DESC LIMIT :n");
    $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
    $stmt->bindValue(':n', $n, PDO::PARAM_INT);
    $stmt->execute();
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  }
}
