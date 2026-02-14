<?php
namespace App\Db;

use PDO;

final class Db {
  private PDO $pdo;

  public function __construct(string $sqlitePath) {
    $dir = dirname($sqlitePath);
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $this->pdo = new PDO('sqlite:' . $sqlitePath);
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->pdo->exec('PRAGMA foreign_keys = ON;');
    $this->pdo->exec('PRAGMA journal_mode = WAL;');
  }

  public function pdo(): PDO { return $this->pdo; }
}
