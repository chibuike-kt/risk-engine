<?php
require __DIR__ . '/../vendor/autoload.php';

$req = new App\Http\Request();
$router = App\Bootstrap::router();
$router->dispatch($req);
