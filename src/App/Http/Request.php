<?php
namespace App\Http;

final class Request {
  public function method(): string { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }

  public function path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $q = strpos($uri, '?');
    return $q === false ? $uri : substr($uri, 0, $q);
  }

  public function header(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $v = $_SERVER[$key] ?? null;
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
  }

  public function json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }
}
