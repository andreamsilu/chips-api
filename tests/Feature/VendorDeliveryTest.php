<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Delivery;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLocation;
use App\Modules\Finance\LedgerService;
use App\Modules\Finance\SettlementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function business(): array
    {
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'vendor')->value('id'));
        $vendor = Vendor::factory()->create();
        $vendor->members()->attach($owner->id);

        return [$vendor, $owner];
    }

    private function delivery(Vendor $vendor, string $payment = 'verified', bool $vendorFee = true): Delivery
    {
        $location = VendorLocation::factory()->create(['vendor_id' => $vendor->id]);
        $order = Order::record(['user_id' => User::factory()->create()->id, 'vendor_id' => $vendor->id, 'vendor_location_id' => $location->id, 'fulfilment' => 'delivery', 'payment_method' => 'mpesa', 'payment_status' => $payment, 'status' => 'ready', 'currency' => 'TZS', 'subtotal' => 10000, 'discount' => 0, 'delivery_fee' => 2000, 'commission_bps' => 1000, 'commission' => 1000, 'total' => 12000, 'address_snapshot' => ['details' => 'Customer address', 'latitude' => -6.8, 'longitude' => 39.2] + ($vendorFee ? ['delivery' => ['fee_beneficiary' => 'vendor']] : [])]);

        return Delivery::record(['order_id' => $order->id, 'status' => 'unassigned']);
    }

    public function test_vendor_creates_a_private_rider_account_without_privilege_escalation(): void
    {
        [$vendor, $owner] = $this->business();
        Sanctum::actingAs($owner);
        $body = ['name' => 'Vendor rider', 'email' => 'rider@example.test', 'phone' => '+255712345678', 'vehicle' => 'Motorcycle', 'password' => 'SecureRider2026', 'password_confirmation' => 'SecureRider2026'];
        $url = '/api/v1/vendor/businesses/'.$vendor->id.'/riders';
        $this->postJson($url, $body + ['roles' => ['admin']])->assertUnprocessable();
        $response = $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.vendor_id', $vendor->id)->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.password');
        $rider = Rider::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check('SecureRider2026', $rider->user->password));
        $this->assertTrue($rider->user->hasRole('rider'));
        $this->assertFalse($rider->user->hasRole('admin'));
        $this->getJson($url.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('per_page', 1);
    }

    public function test_vendor_cannot_access_another_business_riders_or_deliveries(): void
    {
        [$vendor, $owner] = $this->business();
        [$other] = $this->business();
        $rider = Rider::factory()->create(['vendor_id' => $other->id]);
        $delivery = $this->delivery($other);
        Sanctum::actingAs($owner);
        $base = '/api/v1/vendor/businesses/';
        $this->getJson($base.$other->id.'/riders')->assertNotFound();
        $this->getJson($base.$other->id.'/deliveries')->assertNotFound();
        $this->patchJson($base.$vendor->id.'/riders/'.$rider->id, ['available' => false])->assertNotFound();
        $this->postJson($base.$vendor->id.'/deliveries/'.$delivery->id.'/assignment', ['rider_id' => $rider->id])->assertNotFound();
        $this->getJson($base.$vendor->id.'/riders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.$vendor->id.'/deliveries')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_vendor_assigns_own_rider_and_manages_paid_delivery_to_completion(): void
    {
        [$vendor, $owner] = $this->business();
        $rider = Rider::factory()->create(['vendor_id' => $vendor->id]);
        $delivery = $this->delivery($vendor);
        Sanctum::actingAs($owner);
        $url = '/api/v1/vendor/businesses/'.$vendor->id.'/deliveries/'.$delivery->id;
        $this->postJson($url.'/assignment', ['rider_id' => $rider->id])->assertOk()->assertJsonPath('data.rider_id', $rider->id);
        $this->patchJson($url, ['status' => 'picked_up'])->assertOk()->assertJsonPath('data.order_status', 'out_for_delivery');
        $this->patchJson($url, ['status' => 'delivered'])->assertOk()->assertJsonPath('data.order_status', 'delivered');
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery.assigned', 'subject_id' => $delivery->id]);
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_assignment_rejects_unpaid_foreign_unavailable_and_busy_riders(): void
    {
        [$vendor, $owner] = $this->business();
        $rider = Rider::factory()->create(['vendor_id' => $vendor->id]);
        $unpaid = $this->delivery($vendor, 'pending');
        $delivery = $this->delivery($vendor);
        $second = $this->delivery($vendor);
        Sanctum::actingAs($owner);
        $base = '/api/v1/vendor/businesses/'.$vendor->id.'/deliveries/';
        $this->postJson($base.$unpaid->id.'/assignment', ['rider_id' => $rider->id])->assertConflict();
        $foreign = Rider::factory()->create(['vendor_id' => Vendor::factory()->create()->id]);
        $this->postJson($base.$delivery->id.'/assignment', ['rider_id' => $foreign->id])->assertNotFound();
        $rider->forceFill(['available' => false])->save();
        $this->postJson($base.$delivery->id.'/assignment', ['rider_id' => $rider->id])->assertConflict();
        $rider->forceFill(['available' => true])->save();
        $this->postJson($base.$delivery->id.'/assignment', ['rider_id' => $rider->id])->assertOk();
        $this->postJson($base.$second->id.'/assignment', ['rider_id' => $rider->id])->assertConflict();
        $this->patchJson('/api/v1/vendor/businesses/'.$vendor->id.'/riders/'.$rider->id, ['status' => 'suspended'])->assertConflict();
    }

    public function test_suspended_vendor_and_non_vendor_members_cannot_manage_riders(): void
    {
        [$vendor, $owner] = $this->business();
        Sanctum::actingAs($owner);
        $vendor->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/v1/vendor/businesses/'.$vendor->id.'/riders')->assertForbidden();
        $vendor->forceFill(['status' => 'approved'])->save();
        $owner->roles()->detach();
        $this->getJson('/api/v1/vendor/businesses/'.$vendor->id.'/riders')->assertForbidden();
    }

    public function test_vendor_receives_food_net_plus_delivery_and_refunds_reverse_that_allocation(): void
    {
        [$vendor, $owner] = $this->business();
        $delivery = $this->delivery($vendor);
        $order = $delivery->order;
        $ledger = app(LedgerService::class);
        $ledger->capture($order);
        $this->assertDatabaseHas('ledger_entries', ['order_id' => $order->id, 'account' => 'vendor_payable', 'amount' => 11000]);
        $this->assertDatabaseHas('ledger_entries', ['order_id' => $order->id, 'account' => 'delivery_revenue', 'amount' => 0]);
        $this->assertSame(0, (int) LedgerEntry::where('order_id', $order->id)->sum('amount'));
        $order->forceFill(['status' => 'delivered'])->save();
        $settlement = app(SettlementService::class)->prepare($vendor, $owner);
        $this->assertSame(11000, $settlement->amount);
        $ledger->reverse($order);
        $this->assertDatabaseHas('ledger_entries', ['order_id' => $order->id, 'kind' => 'refund', 'account' => 'vendor_payable', 'amount' => -11000]);
    }

    public function test_historical_orders_keep_original_delivery_revenue_allocation(): void
    {
        [$vendor] = $this->business();
        $delivery = $this->delivery($vendor, vendorFee: false);
        app(LedgerService::class)->capture($delivery->order);
        $this->assertDatabaseHas('ledger_entries', ['order_id' => $delivery->order_id, 'account' => 'vendor_payable', 'amount' => 9000]);
        $this->assertDatabaseHas('ledger_entries', ['order_id' => $delivery->order_id, 'account' => 'delivery_revenue', 'amount' => 2000]);
    }

    public function test_new_delivery_checkout_snapshots_vendor_as_delivery_fee_beneficiary(): void
    {
        $this->fakeRoadRouting(3000);
        Queue::fake();
        config(['marketplace.gateway_url' => 'https://gateway.example', 'marketplace.gateway_token' => 'test-only', 'marketplace.webhook_secret' => 'test-only', 'marketplace.webhook_url' => 'https://api.example/callback']);
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'customer')->value('id'));
        $address = Address::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $version = $this->putJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->json('data.version');
        $this->withHeader('Idempotency-Key', 'vendor-delivery-fee')->postJson('/api/v1/checkout', ['cart_version' => $version, 'fulfilment' => 'delivery', 'payment_method' => 'mpesa', 'phone' => '+255712345678', 'address_id' => $address->id])->assertCreated()->assertJsonPath('data.address.delivery.fee_beneficiary', 'vendor');
    }

    public function test_admin_can_link_legacy_riders_but_cannot_transfer_an_active_assignment(): void
    {
        [$vendor] = $this->business();
        [$other] = $this->business();
        $rider = Rider::factory()->create(['vendor_id' => null, 'last_latitude' => -6.8, 'last_longitude' => 39.2, 'location_updated_at' => now()]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->value('id'));
        Sanctum::actingAs($admin);
        $url = '/api/v1/admin/riders/'.$rider->id;
        $this->patchJson($url, ['vendor_id' => $vendor->id])->assertOk()->assertJsonPath('data.vendor_id', $vendor->id);
        $this->assertNull($rider->fresh()->location_updated_at);
        $delivery = $this->delivery($vendor);
        $delivery->forceFill(['rider_id' => $rider->id, 'status' => 'assigned'])->save();
        $this->patchJson($url, ['vendor_id' => $other->id])->assertConflict();
        $this->assertSame($vendor->id, $rider->fresh()->vendor_id);
    }
}
