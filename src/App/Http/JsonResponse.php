<?php
namespace App\Http;

final class JsonResponse {
  public static function send(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
  }
}
