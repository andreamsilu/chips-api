<?php

namespace Tests\Integration;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Models\VendorLocation;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    private function freshTestDatabase(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Real MySQL 8 is required for row-lock concurrency tests.');
        }if (! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->fail('Use a disposable database ending in _test.');
        }$this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function cart(Product $product): array
    {
        $user = User::factory()->create();
        $cart = Cart::record(['user_id' => $user->id, 'vendor_location_id' => $product->vendor_location_id]);
        CartItem::record(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        return [$user, ['cart_version' => 1, 'fulfilment' => 'pickup', 'payment_method' => 'cash']];
    }

    private function worker(User $user, string $key, array $body): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/checkout-worker.php'), (string) $user->id, $key, json_encode($body)], base_path());
        $process->setTimeout(30);

        return $process;
    }

    public function test_simultaneous_same_key_creates_exactly_one_order(): void
    {
        $this->freshTestDatabase();
        $location = VendorLocation::factory()->create();
        $product = Product::factory()->create(['vendor_location_id' => $location->id, 'stock' => 1]);
        [$user,$body] = $this->cart($product);
        $a = $this->worker($user, 'same-request-key', $body);
        $b = $this->worker($user, 'same-request-key', $body);
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
        $this->assertTrue($b->isSuccessful(), $b->getErrorOutput());
        $first = json_decode($a->getOutput(), true);
        $second = json_decode($b->getOutput(), true);
        $this->assertSame(200, $first['status']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_competing_customers_cannot_oversell_the_last_item(): void
    {
        $this->freshTestDatabase();
        $location = VendorLocation::factory()->create();
        $product = Product::factory()->create(['vendor_location_id' => $location->id, 'stock' => 1]);
        [$userA,$bodyA] = $this->cart($product);
        [$userB,$bodyB] = $this->cart($product);
        $a = $this->worker($userA, 'customer-a-request', $bodyA);
        $b = $this->worker($userB, 'customer-b-request', $bodyB);
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
        $this->assertTrue($b->isSuccessful(), $b->getErrorOutput());
        $statuses = [json_decode($a->getOutput(), true)['status'], json_decode($b->getOutput(), true)['status']];
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $product->fresh()->stock);
    }
}
