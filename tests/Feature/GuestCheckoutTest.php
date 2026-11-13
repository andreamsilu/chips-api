<?php

namespace Tests\Feature;

use App\Models\GuestOrderAccessToken;
use App\Models\GuestSession;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Modules\Notifications\OrderSms;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestCheckoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function start(): string
    {
        return $this->postJson('/api/v1/guest/sessions', [])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data.token');
    }

    private function cart(string $token, ?Product $product = null): array
    {
        $product ??= Product::factory()->create();
        $version = $this->withHeader('X-Guest-Token', $token)->putJson('/api/v1/guest/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->json('data.version');

        return ['cart_version' => $version, 'fulfilment' => 'pickup', 'payment_method' => 'cash', 'phone' => '+255712345678', 'customer_name' => 'Guest buyer'];
    }

    private function checkout(string $token, array $body, string $key = 'guest-order-key'): TestResponse
    {
        Queue::fake();

        return $this->withHeaders(['X-Guest-Token' => $token, 'Idempotency-Key' => $key])->postJson('/api/v1/guest/checkout', $body);
    }

    public function test_guest_session_has_no_login_credentials_roles_or_sanctum_access(): void
    {
        $token = $this->start();
        $session = GuestSession::firstOrFail();
        $this->assertSame(hash('sha256', $token), $session->getRawOriginal('token_hash'));
        $this->assertNull($session->user->email);
        $this->assertNull($session->user->password);
        $this->assertTrue($session->user->is_guest);
        $this->assertSame(0, $session->user->roles()->count());
        $this->withHeader('X-Guest-Token', $token)->getJson('/api/v1/guest/cart')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_guest_carts_and_addresses_are_isolated_and_server_prices_are_authoritative(): void
    {
        $a = $this->start();
        $b = $this->start();
        $product = Product::factory()->create(['price' => 8000]);
        $body = $this->cart($a, $product);
        $this->withHeader('X-Guest-Token', $b)->getJson('/api/v1/guest/cart')->assertOk()->assertJsonCount(0, 'data.items');
        $address = $this->withHeader('X-Guest-Token', $a)->postJson('/api/v1/guest/addresses', ['label' => 'Delivery', 'details' => 'Customer street', 'latitude' => -6.8, 'longitude' => 39.2])->assertCreated()->json('data.id');
        $this->withHeader('X-Guest-Token', $b)->deleteJson('/api/v1/guest/addresses/'.$address)->assertNotFound();
        $this->withHeader('X-Guest-Token', $a)->postJson('/api/v1/guest/cart/quote', $body)->assertOk()->assertJsonPath('data.total', 8000);
        $this->checkout($a, $body + ['delivery_fee' => 0])->assertUnprocessable();
    }

    public function test_guest_checkout_issues_one_private_order_token_and_replay_is_stable(): void
    {
        $token = $this->start();
        $body = $this->cart($token);
        $first = $this->checkout($token, $body)->assertCreated();
        $orderId = $first->json('data.id');
        $access = $first->json('guest_order_token');
        $this->checkout($token, $body)->assertOk()->assertJsonPath('data.id', $orderId)->assertJsonPath('guest_order_token', $access);
        $row = GuestOrderAccessToken::firstOrFail();
        $this->assertSame(hash('sha256', $access), $row->getRawOriginal('token_hash'));
        $this->assertNotSame($access, $row->getRawOriginal('token_ciphertext'));
        $this->withHeader('X-Order-Token', $access)->getJson('/api/v1/guest/orders/'.$orderId)->assertOk()->assertJsonMissingPath('guest_order_token')->assertJsonPath('data.is_customer_order', true);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_order_capability_cannot_read_other_orders_or_account_history(): void
    {
        $a = $this->start();
        $bodyA = $this->cart($a);
        $orderA = $this->checkout($a, $bodyA)->assertCreated();
        $b = $this->start();
        $bodyB = $this->cart($b);
        $orderB = $this->checkout($b, $bodyB, 'second-guest-order')->assertCreated();
        $this->withHeader('X-Order-Token', $orderA->json('guest_order_token'))->getJson('/api/v1/guest/orders/'.$orderB->json('data.id'))->assertNotFound();
        $this->withHeader('X-Guest-Token', $a)->getJson('/api/v1/orders')->assertUnauthorized();
        $this->withHeader('X-Order-Token', $orderA->json('guest_order_token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/guest/orders')->assertNotFound();
    }

    public function test_guest_cannot_access_registered_customer_order_even_with_a_guest_token(): void
    {
        $token = $this->start();
        $body = $this->cart($token);
        $result = $this->checkout($token, $body)->assertCreated();
        $registered = User::factory()->create(['phone' => '+255712345678']);
        $registered->roles()->attach(Role::where('name', 'customer')->value('id'));
        Sanctum::actingAs($registered);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders/'.$result->json('data.id'))->assertNotFound();
        $this->assertNotSame($registered->id, Order::firstOrFail()->user_id);
        $this->assertSame('Guest buyer', data_get(Order::firstOrFail()->address_snapshot, 'customer.name'));
    }

    public function test_mobile_money_guest_requires_real_payment_email_and_stays_unpaid_until_verified(): void
    {
        $token = $this->start();
        $body = $this->cart($token);
        $body['payment_method'] = 'mpesa';
        $this->checkout($token, $body)->assertUnprocessable()->assertJsonValidationErrors('customer_email');
        config(['marketplace.gateway_url' => 'https://gateway.example', 'marketplace.gateway_token' => 'test-token', 'marketplace.webhook_secret' => str_repeat('x', 32), 'marketplace.webhook_url' => 'https://api.example/webhook']);
        $body['customer_email'] = 'guest@example.test';
        $response = $this->checkout($token, $body)->assertCreated()->assertJsonPath('data.status', 'awaiting_payment')->assertJsonPath('data.payment_status', 'pending');
        $payment = Payment::firstOrFail();
        $this->assertSame('guest@example.test', $payment->request_payload['customer']['email']);
        $this->assertNull($payment->order->customer->email);
        $this->withHeader('X-Order-Token', $response->json('guest_order_token'))->getJson('/api/v1/guest/orders/'.$response->json('data.id').'/tracking')->assertOk()->assertJsonPath('data.rider', null);
    }

    public function test_guest_session_and_order_access_expire_independently(): void
    {
        $this->freezeTime();
        config(['guest.session_minutes' => 1, 'guest.order_minutes' => 10]);
        $token = $this->start();
        $body = $this->cart($token);
        $response = $this->checkout($token, $body)->assertCreated();
        $this->travel(2)->minutes();
        $this->withHeader('X-Guest-Token', $token)->getJson('/api/v1/guest/cart')->assertUnauthorized();
        $this->withHeader('X-Order-Token', $response->json('guest_order_token'))->getJson('/api/v1/guest/orders/'.$response->json('data.id'))->assertOk();
        $this->travel(10)->minutes();
        $this->getJson('/api/v1/guest/orders/'.$response->json('data.id'))->assertNotFound();
    }

    public function test_guest_cancellation_requires_the_matching_order_token(): void
    {
        $token = $this->start();
        $body = $this->cart($token);
        $response = $this->checkout($token, $body)->assertCreated();
        $url = '/api/v1/guest/orders/'.$response->json('data.id').'/cancel';
        $this->withHeader('X-Order-Token', str_repeat('a', 64))->postJson($url, ['reason' => 'Changed plans'])->assertNotFound();
        $this->withHeader('X-Order-Token', $response->json('guest_order_token'))->postJson($url, ['reason' => 'Changed plans'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_guest_sms_uses_order_contact_only_after_verified_payment(): void
    {
        $token = $this->start();
        $body = $this->cart($token);
        $this->checkout($token, $body)->assertCreated();
        config(['services.nextsms.enabled' => true]);
        $order = Order::with('customer')->firstOrFail();
        $message = OutboxMessage::firstOrFail();
        OrderSms::stage($message, $order);
        $this->assertDatabaseCount('sms_deliveries', 0);
        $order->forceFill(['payment_status' => 'verified'])->save();
        $message->forceFill(['payload' => ['status' => $order->status, 'payment_status' => 'verified']])->save();
        OrderSms::stage($message, $order);
        $this->assertSame('255712345678', Crypt::decryptString(DB::table('sms_deliveries')->first()->recipient));
    }

    public function test_signing_in_with_both_credentials_merges_cart_without_claiming_guest_orders(): void
    {
        $token = $this->start();
        $this->cart($token);
        $registered = User::factory()->create();
        Sanctum::actingAs($registered);
        $this->withHeader('X-Guest-Token', $token)->postJson('/api/v1/cart/guest-merge', [])->assertOk()->assertJsonPath('data.merged', true);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guest_checkout_rejects_reusing_a_key_for_different_contact_details(): void
    {
        $token = $this->start();
        $body = $this->cart($token);
        $this->checkout($token, $body)->assertCreated();
        $body['phone'] = '+255712345679';
        $this->checkout($token, $body)->assertConflict();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('guest_order_access_tokens', 1);
    }

    public function test_cart_merge_conflict_preserves_both_private_carts(): void
    {
        $token = $this->start();
        $this->cart($token);
        $registered = User::factory()->create();
        Sanctum::actingAs($registered);
        $otherProduct = Product::factory()->create();
        $this->putJson('/api/v1/cart/items', ['product_id' => $otherProduct->id, 'quantity' => 2])->assertOk();
        $this->withHeader('X-Guest-Token', $token)->postJson('/api/v1/cart/guest-merge', [])->assertConflict();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.quantity', 2);
        $this->withHeader('X-Guest-Token', $token)->getJson('/api/v1/guest/cart')->assertOk()->assertJsonPath('data.items.0.quantity', 1);
    }

    public function test_anonymous_owners_are_excluded_from_administrator_accounts_and_role_changes(): void
    {
        $this->start();
        $guest = GuestSession::firstOrFail()->user;
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->value('id'));
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/users/'.$guest->public_id)->assertNotFound();
        $this->putJson('/api/v1/admin/users/'.$guest->public_id.'/roles', ['roles' => ['admin']])->assertNotFound();
        $this->assertSame(0, $guest->roles()->count());
    }

    public function test_cart_merge_preserves_account_preparation_notes_and_combines_quantities(): void
    {
        $token = $this->start();
        $product = Product::factory()->create();
        $this->cart($token, $product);
        $registered = User::factory()->create();
        Sanctum::actingAs($registered);
        $this->putJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2, 'instructions' => 'No chilli'])->assertOk();
        $this->withHeader('X-Guest-Token', $token)->postJson('/api/v1/cart/guest-merge', [])->assertOk();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.quantity', 3)->assertJsonPath('data.items.0.instructions', 'No chilli');
    }
}
