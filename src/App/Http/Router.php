<?php
namespace App\Http;

final class Router {
  /** @var array<string, array<string, callable>> */
  private array $routes = [];

  public function add(string $method, string $path, callable $handler): void {
    $this->routes[strtoupper($method)][$path] = $handler;
  }

  public function dispatch(Request $req): void {
    $m = strtoupper($req->method());
    $p = $req->path();

    $handler = $this->routes[$m][$p] ?? null;
    if (!$handler) {
      JsonResponse::send(404, ['error' => 'not_found', 'path' => $p]);
      return;
    }
    $handler($req);
  }
}
