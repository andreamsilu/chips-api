<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Coupon;
use App\Models\Delivery;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Rider;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLocation;
use App\Modules\Notifications\OrderChanged;
use App\Modules\Notifications\PublishOutbox;
use App\Modules\Payments\PaymentGateway;
use App\Modules\Payments\PaymentOperation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.webhook_url' => 'https://api.example/api/v1/webhooks/payments', 'marketplace.payouts_enabled' => true, 'marketplace.gateway_url' => 'https://gateway.example', 'marketplace.gateway_token' => 'test-token', 'marketplace.webhook_secret' => str_repeat('a', 32)]);
    }

    private function role(User $user, string $name): User
    {
        $user->roles()->syncWithoutDetaching([Role::where('name', $name)->value('id')]);

        return $user;
    }

    private function fixture(): array
    {
        $this->fakeRoadRouting();
        $customer = $this->role(User::factory()->create(), 'customer');
        $owner = $this->role(User::factory()->create(), 'vendor');
        $vendor = Vendor::factory()->create();
        $vendor->members()->attach($owner->id);
        $location = VendorLocation::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_location_id' => $location->id]);
        $address = Address::factory()->create(['user_id' => $customer->id]);

        return compact('customer', 'owner', 'vendor', 'location', 'product', 'address');
    }

    private function checkout(array $f, string $fulfilment = 'delivery', string $method = 'mpesa', string $key = 'checkout-test-key', array $extra = []): TestResponse
    {
        Sanctum::actingAs($f['customer']);
        $cart = $this->putJson('/api/v1/cart/items', ['product_id' => $f['product']->id, 'quantity' => 1])->assertOk()->json('data');

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', array_merge(['cart_version' => $cart['version'], 'fulfilment' => $fulfilment, 'payment_method' => $method, 'phone' => '+255712345678', 'address_id' => $f['address']->id], $extra));
    }

    private function webhook(Order $order, string $status = 'payment.verified', string $event = 'evt-1', array $extra = []): TestResponse
    {
        $type = ['payment.verified' => 'payment.completed', 'payment.failed' => 'payment.failed', 'refund.verified' => 'payout.completed', 'refund.failed' => 'payout.failed', 'refund.reversed' => 'payout.reversed'][$status];
        $raw = json_encode(['id' => $event, 'api_version' => '2026-01-25', 'type' => $type, 'data' => ['reference' => $extra['provider_reference'] ?? 'provider-'.$order->id, 'status' => explode('.', $type)[1], 'amount' => ['value' => $extra['amount'] ?? $order->total, 'currency' => $extra['currency'] ?? 'TZS'], 'metadata' => ['order_id' => $order->id, 'refund_id' => $extra['refund_reference'] ?? null], 'recipient' => ['phone' => '255712345678']]]);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/webhooks/payments', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp, 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$raw, config('marketplace.webhook_secret'))], $raw);
    }

    public function test_requires_authentication_and_prevents_admin_access_and_role_escalation(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/audit')->assertForbidden();
        $this->postJson('/api/v1/auth/register', ['name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'Password12345', 'password_confirmation' => 'Password12345', 'role' => 'admin'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'asha@example.com']);
    }

    public function test_register_login_issues_expiring_revocable_sanctum_token(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'Password12345', 'password_confirmation' => 'Password12345'])->assertCreated()->assertJsonPath('data.roles.0', 'customer');
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'asha@example.com', 'password' => 'Password12345', 'device_name' => 'Flutter'])->assertOk();
        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'asha@example.com');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_delivery_is_payment_gated_and_webhook_is_idempotent(): void
    {
        $f = $this->fixture();
        $response = $this->checkout($f)->assertCreated()->assertJsonPath('data.status', 'awaiting_payment')->assertJsonPath('data.total', 12000);
        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame(19, $f['product']->fresh()->stock);
        Sanctum::actingAs($f['owner']);
        $this->patchJson('/api/v1/vendor/orders/'.$order->id, ['status' => 'accepted'])->assertConflict();
        $this->webhook($order)->assertOk();
        $this->webhook($order)->assertOk();
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseCount('ledger_entries', 4);
        $this->assertSame(0, (int) LedgerEntry::sum('amount'));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed', 'payment_status' => 'verified']);
        $this->patchJson('/api/v1/vendor/orders/'.$order->id, ['status' => 'accepted'])->assertOk();
        $this->patchJson('/api/v1/vendor/orders/'.$order->id, ['status' => 'preparing'])->assertOk();
    }

    public function test_checkout_replays_once_and_rejects_changed_input(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['customer']);
        $cart = $this->putJson('/api/v1/cart/items', ['product_id' => $f['product']->id, 'quantity' => 2])->json('data');
        $body = ['cart_version' => $cart['version'], 'fulfilment' => 'pickup', 'payment_method' => 'cash'];
        $first = $this->withHeader('Idempotency-Key', 'checkout-retry-key')->postJson('/api/v1/checkout', $body)->assertCreated();
        $this->postJson('/api/v1/checkout', $body)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/checkout', array_merge($body, ['cart_version' => $body['cart_version'] + 1]))->assertConflict();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(18, $f['product']->fresh()->stock);
    }

    public function test_cash_pickup_requires_explicit_vendor_collection_before_completion(): void
    {
        $f = $this->fixture();
        $id = $this->checkout($f, 'pickup', 'cash')->assertCreated()->assertJsonPath('data.payment_status', 'pending')->json('data.id');
        Sanctum::actingAs($f['owner']);
        foreach (['accepted', 'preparing', 'ready'] as $status) {
            $this->patchJson('/api/v1/vendor/orders/'.$id, ['status' => $status])->assertOk();
        }$this->patchJson('/api/v1/vendor/orders/'.$id, ['status' => 'completed'])->assertConflict();
        $this->postJson('/api/v1/vendor/orders/'.$id.'/cash', ['cash_received' => false])->assertUnprocessable();
        $this->postJson('/api/v1/vendor/orders/'.$id.'/cash', ['cash_received' => true])->assertOk();
        $this->postJson('/api/v1/vendor/orders/'.$id.'/cash', ['cash_received' => true])->assertOk();
        $this->patchJson('/api/v1/vendor/orders/'.$id, ['status' => 'completed'])->assertOk();
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseHas('ledger_entries', ['order_id' => $id, 'account' => 'vendor_payable', 'amount' => -1000]);
    }

    public function test_delivery_cash_and_client_prices_are_rejected(): void
    {
        $f = $this->fixture();
        $this->checkout($f, 'delivery', 'cash')->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->checkout($f, 'delivery', 'mpesa', 'new-checkout-key', ['total' => 1])->assertUnprocessable();
        $this->assertSame(20, $f['product']->fresh()->stock);
    }

    public function test_missing_gateway_configuration_cannot_create_mobile_money_order(): void
    {
        $f = $this->fixture();
        config(['marketplace.gateway_token' => null]);
        $this->checkout($f)->assertServiceUnavailable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(20, $f['product']->fresh()->stock);
    }

    public function test_cross_customer_addresses_orders_and_cart_are_isolated(): void
    {
        $f = $this->fixture();
        $orderId = $this->checkout($f, 'pickup', 'cash')->json('data.id');
        $other = $this->role(User::factory()->create(), 'customer');
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/orders/'.$orderId)->assertNotFound();
        $this->deleteJson('/api/v1/addresses/'.$f['address']->id)->assertNotFound();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_foreign_vendor_cannot_manage_orders_or_locations(): void
    {
        $f = $this->fixture();
        $orderId = $this->checkout($f, 'pickup', 'cash')->json('data.id');
        $other = $this->role(User::factory()->create(), 'vendor');
        Sanctum::actingAs($other);
        $this->patchJson('/api/v1/vendor/orders/'.$orderId, ['status' => 'accepted'])->assertNotFound();
        $this->postJson('/api/v1/vendor/locations/'.$f['location']->id.'/products', [])->assertNotFound();
        $this->getJson('/api/v1/vendor/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_vendor_approval_and_mass_assignment_are_enforced(): void
    {
        $f = $this->fixture();
        $f['vendor']->forceFill(['status' => 'pending'])->save();
        Sanctum::actingAs($f['customer']);
        $this->putJson('/api/v1/cart/items', ['product_id' => $f['product']->id, 'quantity' => 1])->assertConflict();
        Sanctum::actingAs($f['owner']);
        $this->postJson('/api/v1/vendor/businesses/'.$f['vendor']->id.'/locations', [])->assertForbidden();
        $this->postJson('/api/v1/vendor/businesses', ['name' => 'Kitchen', 'status' => 'approved'])->assertUnprocessable();
    }

    public function test_invalid_stale_and_mismatched_webhooks_cannot_verify_payment(): void
    {
        $f = $this->fixture();
        $id = $this->checkout($f)->json('data.id');
        $order = Order::findOrFail($id);
        $this->postJson('/api/v1/webhooks/payments', ['status' => 'payment.verified'])->assertUnauthorized();
        $raw = '{}';
        $time = (string) (time() - 600);
        $this->call('POST', '/api/v1/webhooks/payments', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $time, 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $time.'.'.$raw, str_repeat('a', 32))], $raw)->assertUnauthorized();
        $this->webhook($order, 'payment.verified', 'wrong-amount', ['amount' => 1])->assertConflict();
        $this->assertDatabaseCount('payment_events', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseHas('orders', ['id' => $id, 'payment_status' => 'pending']);
    }

    public function test_failed_payment_restores_inventory_and_preserves_history(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $this->webhook($order, 'payment.failed')->assertOk();
        $this->webhook($order, 'payment.failed')->assertOk();
        $this->assertSame(20, $f['product']->fresh()->stock);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled', 'payment_status' => 'failed']);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_late_payment_after_expiry_requests_refund_without_consuming_stock(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $this->travel(16)->minutes();
        $this->artisan('marketplace:tick')->assertSuccessful();
        $this->webhook($order)->assertOk();
        $this->assertSame(20, $f['product']->fresh()->stock);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled', 'payment_status' => 'refund_pending']);
        $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'status' => 'requested']);
    }

    public function test_refund_needs_approval_and_signed_success_and_reverses_ledger_once(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $this->webhook($order)->assertOk();
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => 'Changed plans'])->assertOk();
        $refund = Refund::where('order_id', $order->id)->firstOrFail();
        $this->webhook($order, 'refund.verified', 'refund-evt', ['refund_reference' => $refund->id, 'provider_reference' => 'refund-provider'])->assertConflict();
        $admin = $this->role(User::factory()->create(), 'admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund->id.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'refund_pending']);
        $this->webhook($order, 'refund.verified', 'refund-evt', ['refund_reference' => $refund->id, 'provider_reference' => 'refund-provider'])->assertOk();
        $this->webhook($order, 'refund.verified', 'refund-evt', ['refund_reference' => $refund->id, 'provider_reference' => 'refund-provider'])->assertOk();
        $this->assertDatabaseCount('ledger_entries', 8);
        $this->assertSame(0, (int) LedgerEntry::sum('amount'));
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'refunded']);
    }

    public function test_riders_only_access_their_assignments_and_paid_ready_deliveries(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $admin = $this->role(User::factory()->create(), 'admin');
        $rider = Rider::factory()->create();
        $this->role($rider->user()->firstOrFail(), 'rider');
        $delivery = Delivery::where('order_id', $order->id)->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/deliveries/'.$delivery->id.'/assignment', ['rider_id' => $rider->id])->assertConflict();
        $this->webhook($order)->assertOk();
        Sanctum::actingAs($f['owner']);
        foreach (['accepted', 'preparing', 'ready'] as $status) {
            $this->patchJson('/api/v1/vendor/orders/'.$order->id, ['status' => $status])->assertOk();
        }Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/deliveries/'.$delivery->id.'/assignment', ['rider_id' => $rider->id])->assertOk();
        Sanctum::actingAs($this->role(User::factory()->create(), 'rider'));
        $this->patchJson('/api/v1/rider/deliveries/'.$delivery->id, ['status' => 'picked_up'])->assertNotFound();
        $this->getJson('/api/v1/rider/deliveries')->assertJsonCount(0, 'data');
        Sanctum::actingAs($rider->user()->firstOrFail());
        $this->patchJson('/api/v1/rider/deliveries/'.$delivery->id, ['status' => 'picked_up'])->assertOk();
        $this->patchJson('/api/v1/rider/deliveries/'.$delivery->id, ['status' => 'delivered'])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered']);
    }

    public function test_stock_and_address_scope_are_rechecked_during_checkout(): void
    {
        $f = $this->fixture();
        $f['product']->forceFill(['stock' => 0])->save();
        $this->checkout($f)->assertConflict();
        $f['product']->forceFill(['stock' => 20])->save();
        $foreign = Address::factory()->create();
        $this->checkout($f, 'delivery', 'mpesa', 'other-key', ['address_id' => $foreign->id])->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_gateway_acknowledgement_does_not_verify_payment_and_uses_stable_key(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        Http::preventStrayRequests();
        Http::fake(['https://gateway.example/v1/payments' => Http::response(['status' => 'success', 'data' => ['reference' => 'provider-ack', 'amount' => ['value' => 12000, 'currency' => 'TZS'], 'status' => 'pending']], 201)]);
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        (new PaymentOperation($payment->id))->handle(app(PaymentGateway::class));
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $payment->id) && $r['details']['amount'] === 12000);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_coupon_limits_and_server_side_discount_calculation(): void
    {
        $f = $this->fixture();
        Coupon::record(['code' => 'LUNCH', 'vendor_id' => $f['vendor']->id, 'discount' => 1000, 'minimum_subtotal' => 5000, 'usage_limit' => 1, 'starts_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
        $this->checkout($f, 'pickup', 'cash', 'coupon-order-key', ['coupon' => 'LUNCH'])->assertCreated()->assertJsonPath('data.total', 9000);
        $other = $this->role(User::factory()->create(), 'customer');
        $this->checkout(array_merge($f, ['customer' => $other]), 'pickup', 'cash', 'coupon-second-key', ['coupon' => 'LUNCH'])->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_order_snapshots_survive_catalogue_price_changes(): void
    {
        $f = $this->fixture();
        $id = $this->checkout($f, 'pickup', 'cash')->json('data.id');
        $f['product']->forceFill(['price' => 900000, 'name' => 'Renamed'])->save();
        $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('data.items.0.unit_price', 10000)->assertJsonPath('data.items.0.name', 'Chips Kuku');
    }

    public function test_support_ticket_and_reviews_are_scoped(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['customer']);
        $ticket = $this->postJson('/api/v1/support/tickets', ['subject' => 'Delivery', 'body' => 'Please help'])->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/support/tickets/'.$ticket)->assertNotFound();
        $this->postJson('/api/v1/support/tickets/'.$ticket.'/messages', ['body' => 'Intrusion'])->assertNotFound();
        $id = $this->checkout($f, 'pickup', 'cash')->json('data.id');
        $this->postJson('/api/v1/orders/'.$id.'/review', ['rating' => 5])->assertConflict();
    }

    public function test_suspended_accounts_and_rate_limits_are_enforced(): void
    {
        $user = User::factory()->create(['suspended_at' => now()]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/cart')->assertForbidden();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'none@example.com', 'password' => 'incorrect', 'device_name' => 'test'])->assertUnprocessable();
        }$this->postJson('/api/v1/auth/login', ['email' => 'none@example.com', 'password' => 'incorrect', 'device_name' => 'test'])->assertTooManyRequests();
    }

    public function test_settlement_cannot_pay_after_a_refund_changes_the_batch(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f, 'pickup', 'mpesa')->json('data.id'));
        $this->webhook($order)->assertOk();
        Sanctum::actingAs($f['owner']);
        foreach (['accepted', 'preparing', 'ready', 'completed'] as $status) {
            $this->patchJson('/api/v1/vendor/orders/'.$order->id, ['status' => $status])->assertOk();
        }
        $admin = $this->role(User::factory()->create(), 'admin');
        Sanctum::actingAs($admin);
        $settlement = $this->postJson('/api/v1/admin/vendors/'.$f['vendor']->id.'/settlements')->assertCreated()->assertJsonPath('data.amount', 9000)->json('data.id');
        Sanctum::actingAs($f['customer']);
        $refundId = $this->postJson('/api/v1/orders/'.$order->id.'/refund', ['reason' => 'Quality issue'])->assertAccepted()->json('data.id');
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/settlements/'.$settlement.'/paid', ['external_reference' => 'bank-receipt-001', 'transfer_verified' => true])->assertConflict();
        $this->postJson('/api/v1/admin/refunds/'.$refundId.'/approve')->assertOk();
        $this->webhook($order, 'refund.verified', 'refund-event', ['refund_reference' => $refundId, 'provider_reference' => 'payout-refund'])->assertOk();
        $this->postJson('/api/v1/admin/settlements/'.$settlement.'/paid', ['external_reference' => 'bank-receipt-001', 'transfer_verified' => true])->assertConflict();
        $this->postJson('/api/v1/admin/settlements/'.$settlement.'/void')->assertOk();
        $this->assertDatabaseHas('settlements', ['id' => $settlement, 'status' => 'voided']);
        $this->assertDatabaseCount('settlement_entries', 1);
    }

    public function test_completed_settlement_requires_a_receipt_and_cannot_be_paid_twice(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f, 'pickup', 'mpesa')->json('data.id'));
        $this->webhook($order)->assertOk();
        Sanctum::actingAs($f['owner']);
        foreach (['accepted', 'preparing', 'ready', 'completed'] as $status) {
            $this->patchJson('/api/v1/vendor/orders/'.$order->id, ['status' => $status])->assertOk();
        }$admin = $this->role(User::factory()->create(), 'admin');
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/admin/vendors/'.$f['vendor']->id.'/settlements')->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/settlements/'.$id.'/paid', [])->assertUnprocessable();
        $body = ['external_reference' => 'bank-receipt-002', 'transfer_verified' => true];
        $this->postJson('/api/v1/admin/settlements/'.$id.'/paid', $body)->assertOk();
        $this->postJson('/api/v1/admin/settlements/'.$id.'/paid', $body)->assertOk();
        $this->postJson('/api/v1/admin/settlements/'.$id.'/paid', ['external_reference' => 'bank-other-receipt', 'transfer_verified' => true])->assertConflict();
        $this->assertDatabaseCount('settlements', 1);
    }

    public function test_reversed_refund_keeps_history_and_requires_manual_recovery(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $this->webhook($order)->assertOk();
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => 'Cancel'])->assertOk();
        $refund = Refund::where('order_id', $order->id)->firstOrFail();
        $admin = $this->role(User::factory()->create(), 'admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund->id.'/approve')->assertOk();
        $extra = ['refund_reference' => $refund->id, 'provider_reference' => 'reversible-payout'];
        $this->webhook($order, 'refund.verified', 'refund-completed', $extra)->assertOk();
        $this->webhook($order, 'refund.reversed', 'refund-reversed', $extra)->assertOk();
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'reversed']);
        $this->assertDatabaseCount('ledger_entries', 12);
        $this->postJson('/api/v1/admin/refunds/'.$refund->id.'/approve', ['external_receipt' => 'manual-return-001', 'receipt_verified' => true])->assertOk();
        $this->assertDatabaseCount('ledger_entries', 16);
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'refunded', 'provider_reference' => 'reversible-payout', 'external_receipt' => 'manual-return-001']);
    }

    public function test_outbox_queues_notifications_and_can_be_replayed_without_duplicates(): void
    {
        $f = $this->fixture();
        Queue::fake([PublishOutbox::class]);
        $this->checkout($f, 'pickup', 'cash')->assertCreated();
        $message = OutboxMessage::firstOrFail();
        $this->artisan('marketplace:tick')->assertSuccessful();
        Queue::assertPushed(PublishOutbox::class, fn ($job) => $job->id === $message->id);
        Event::fake([OrderChanged::class]);
        $job = new PublishOutbox($message->id);
        $job->handle();
        $job->handle();
        Event::assertDispatchedTimes(OrderChanged::class, 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($message->fresh()->published_at);
    }

    public function test_snippe_status_poll_is_authoritative_and_checks_the_reference(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $payment->forceFill(['provider_reference' => 'known-reference', 'submitted_at' => now()])->save();
        Http::preventStrayRequests();
        Http::fake(['https://gateway.example/v1/payments/known-reference' => Http::response(['status' => 'success', 'data' => ['reference' => 'known-reference', 'status' => 'completed', 'amount' => ['value' => 12000, 'currency' => 'TZS']]])]);
        (new PaymentOperation($payment->id, 'reconcile'))->handle(app(PaymentGateway::class));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'verified', 'status' => 'confirmed']);
    }

    public function test_snippe_refund_payout_is_not_marked_complete_by_an_acknowledgement(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $this->webhook($order)->assertOk();
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => 'Cancel'])->assertOk();
        $refund = Refund::where('order_id', $order->id)->firstOrFail();
        Sanctum::actingAs($this->role(User::factory()->create(), 'admin'));
        $this->postJson('/api/v1/admin/refunds/'.$refund->id.'/approve')->assertOk();
        Http::preventStrayRequests();
        Http::fake(['https://gateway.example/v1/payouts/send' => Http::response(['status' => 'success', 'data' => ['reference' => 'payout-accepted', 'amount' => ['value' => 12000, 'currency' => 'TZS']]], 201)]);
        (new PaymentOperation($refund->id, 'refund'))->handle(app(PaymentGateway::class));
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $refund->id) && $r['recipient_phone'] === '255712345678');
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'approved']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'refund_pending']);
    }

    public function test_geographic_discovery_filters_unapproved_and_distant_vendors(): void
    {
        $f = $this->fixture();
        if (DB::getDriverName() === 'sqlite') {
            foreach (['SQRT' => 'sqrt', 'POWER' => 'pow', 'SIN' => 'sin', 'COS' => 'cos', 'ASIN' => 'asin', 'RADIANS' => 'deg2rad'] as $sql => $php) {
                DB::connection()->getPdo()->sqliteCreateFunction($sql, $php);
            }
        }

        VendorLocation::factory()->create(['latitude' => -3.38, 'longitude' => 36.68]);
        $this->getJson('/api/v1/locations?latitude=-6.77&longitude=39.25&radius=5')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['location']->id);
        $this->getJson('/api/v1/locations?latitude=999&longitude=39')->assertUnprocessable();
    }

    public function test_snippe_retry_window_cannot_create_a_second_charge_after_24_hours(): void
    {
        $f = $this->fixture();
        $order = Order::findOrFail($this->checkout($f)->json('data.id'));
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $payment->forceFill(['first_attempt_at' => now()->subDay()])->save();
        Http::preventStrayRequests();
        Http::fake(['https://gateway.example/v1/payments' => Http::response([], 500)]);
        try {
            (new PaymentOperation($payment->id))->handle(app(PaymentGateway::class));
            $this->fail('An old uncertain payment must not be resubmitted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('manual reconciliation', $e->getMessage());
        }Http::assertNothingSent();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }
}
