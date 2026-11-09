<?php

use App\Models\User;
use App\Modules\Checkout\CheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    throw new RuntimeException('This worker is restricted to disposable test databases.');
}
try {
    $user = User::findOrFail($argv[1]);
    $order = $app->make(CheckoutService::class)->checkout($user, $argv[2], json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR));
    echo json_encode(['id' => $order->id, 'status' => 200]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
