<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Models\VendorLocation;
use App\Modules\Finance\LedgerService;
use App\Modules\Payments\PaymentOperation;
use App\Modules\Payments\RefundService;
use App\Support\MarketplaceSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }

    private function signIn(string $role = 'admin'): User
    {
        $user = $this->user($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function order(): Order
    {
        $this->fakeRoadRouting();
        config(['marketplace.webhook_secret' => str_repeat('a', 32), 'marketplace.gateway_token' => 'test-only', 'marketplace.gateway_url' => 'https://gateway.example', 'marketplace.webhook_url' => 'https://api.example/api/v1/webhooks/payments']);
        $customer = $this->user('customer');
        $vendor = Vendor::factory()->create();
        $location = VendorLocation::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_location_id' => $location->id]);
        $address = Address::factory()->create(['user_id' => $customer->id]);
        Sanctum::actingAs($customer);
        $cart = $this->putJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->json('data');
        $response = $this->withHeader('Idempotency-Key', 'admin-test-order-'.str()->ulid())->postJson('/api/v1/checkout', ['cart_version' => $cart['version'], 'fulfilment' => 'delivery', 'payment_method' => 'mpesa', 'phone' => '+255712345678', 'address_id' => $address->id])->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }

    public function test_admin_lists_require_authentication_even_without_accept_header(): void
    {
        $this->get('/api/v1/admin/customers')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
    }

    public static function deniedRoles(): array
    {
        return ['customer' => ['customer'], 'vendor' => ['vendor'], 'rider' => ['rider'], 'support' => ['support']];
    }

    #[DataProvider('deniedRoles')]
    public function test_returns_403_for_non_administrator_roles(string $role): void
    {
        $this->signIn($role);

        $this->getJson('/api/v1/admin/customers')->assertForbidden();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Restricted'])->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => 'Restricted']);
    }

    public function test_search_and_status_filters_run_before_bounded_pagination(): void
    {
        Vendor::factory()->count(22)->create(['name' => 'Other business', 'status' => 'pending']);
        foreach (['North A', 'North B', 'North C'] as $name) {
            Vendor::factory()->create(['name' => $name, 'status' => 'approved']);
        }
        $this->signIn();

        $this->getJson('/api/v1/admin/vendors?search=North&status=approved&per_page=2&page=2&sort=name&direction=asc')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('last_page', 2)->assertJsonPath('current_page', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'North C');
    }

    public function test_search_treats_sql_wildcards_as_literal_characters(): void
    {
        Vendor::factory()->create(['name' => '50% Market']);
        Vendor::factory()->create(['name' => 'Other Market']);
        $this->signIn();

        $this->getJson('/api/v1/admin/vendors?search=%25')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', '50% Market');
    }

    public static function invalidQueries(): array
    {
        return ['page size' => ['per_page=101', 'per_page'], 'negative page' => ['page=-1', 'page'], 'sort injection' => ['sort=name%3Bdrop%20table%20users', 'sort'], 'invalid status' => ['status=anything', 'status'], 'reversed dates' => ['date_from=2026-11-09&date_to=2026-11-08', 'date_to'], 'unknown filter' => ['approved_by=1', 'approved_by']];
    }

    #[DataProvider('invalidQueries')]
    public function test_returns_422_for_invalid_query_controls(string $query, string $field): void
    {
        $this->signIn();

        $this->getJson('/api/v1/admin/vendors?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_date_filters_use_east_africa_day_boundaries(): void
    {
        Vendor::factory()->create(['name' => 'Inside', 'created_at' => '2026-11-08 21:00:00']);
        Vendor::factory()->create(['name' => 'Outside', 'created_at' => '2026-11-09 21:00:00']);
        $this->signIn();

        $this->getJson('/api/v1/admin/vendors?date_from=2026-11-09&date_to=2026-11-09')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Inside');
    }

    public function test_customer_resources_expose_public_ids_and_omit_passwords(): void
    {
        $customer = $this->user('customer');
        $this->signIn();

        $response = $this->getJson('/api/v1/admin/customers?search='.urlencode($customer->email))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $customer->public_id);
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data.0'));
    }

    public function test_suspending_a_customer_revokes_sessions_and_is_audited(): void
    {
        $customer = $this->user('customer');
        $customer->createToken('existing-device');
        $actor = $this->signIn();

        $this->patchJson('/api/v1/admin/customers/'.$customer->public_id, ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'subject_id' => $customer->public_id, 'action' => 'user.updated']);
    }

    public function test_rejects_privilege_escalation_fields_on_customer_updates(): void
    {
        $customer = $this->user('customer');
        $this->signIn();

        $this->patchJson('/api/v1/admin/customers/'.$customer->public_id, ['roles' => ['admin']])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->assertFalse($customer->fresh()->hasRole('admin'));
    }

    public function test_last_administrator_cannot_be_demoted_or_restricted(): void
    {
        $actor = $this->signIn();

        $this->putJson('/api/v1/admin/users/'.$actor->public_id.'/roles', ['roles' => ['customer']])->assertConflict();
        $this->putJson('/api/v1/admin/users/'.$actor->public_id.'/permissions', ['unrestricted' => false, 'permissions' => ['overview.read']])->assertConflict();
        $this->assertTrue($actor->fresh()->hasRole('admin'));
    }

    public function test_restricted_administrators_are_checked_on_every_api_operation(): void
    {
        $root = $this->signIn();
        $limited = $this->user();
        $this->putJson('/api/v1/admin/users/'.$limited->public_id.'/permissions', ['unrestricted' => false, 'permissions' => ['vendors.read']])->assertOk();
        Sanctum::actingAs($limited);

        $this->getJson('/api/v1/admin/vendors')->assertOk();
        $this->getJson('/api/v1/admin/customers')->assertForbidden();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Not allowed'])->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.permissions', ['vendors.read']);
        $this->assertDatabaseMissing('categories', ['name' => 'Not allowed']);
    }

    public function test_global_order_filters_do_not_change_customer_owned_lists(): void
    {
        $order = $this->order();
        $other = $this->user('customer');
        $this->signIn();

        $this->getJson('/api/v1/admin/orders?status=awaiting_payment&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.customer.id', $order->customer->public_id);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    public function test_admin_cancellation_releases_stock_once_and_preserves_snapshots(): void
    {
        $order = $this->order();
        $product = Product::findOrFail($order->items()->value('product_id'));
        $total = $order->total;
        $this->signIn();

        $this->postJson('/api/v1/admin/orders/'.$order->id.'/cancellation', ['status' => 'cancelled', 'reason' => 'Customer requested cancellation'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/admin/orders/'.$order->id.'/cancellation', ['status' => 'cancelled', 'reason' => 'Repeated request'])->assertOk();
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame($total, $order->fresh()->total);
        $this->assertDatabaseCount('order_histories', 2);
    }

    public function test_payment_list_hides_gateway_payloads_and_reconciliation_is_queued(): void
    {
        $order = $this->order();
        $payment = $order->payment()->firstOrFail();
        $payment->forceFill(['provider_reference' => 'provider-test-reference'])->save();
        $this->signIn();
        config(['marketplace.gateway_token' => 'test-only', 'marketplace.gateway_url' => 'https://gateway.example']);
        Queue::fake([PaymentOperation::class]);
        Http::preventStrayRequests();

        $response = $this->getJson('/api/v1/admin/payments?order_id='.$order->id)->assertOk()->assertJsonPath('total', 1);
        $this->assertArrayNotHasKey('request_payload', $response->json('data.0'));
        $this->assertArrayNotHasKey('phone', $response->json('data.0'));
        $this->postJson('/api/v1/admin/payments/'.$payment->id.'/reconcile', [])->assertAccepted();
        Queue::assertPushed(PaymentOperation::class, fn (PaymentOperation $job) => $job->id === $payment->id && $job->operation === 'reconcile');
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_finance_uses_immutable_capture_and_refund_entries(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $order->forceFill(['status' => 'delivered', 'payment_status' => 'verified'])->save();
        app(LedgerService::class)->capture($order);
        $actor = $this->signIn();
        config(['marketplace.payouts_enabled' => false]);

        $this->getJson('/api/v1/admin/finance/summary?vendor_id='.$order->vendor_id)->assertOk()->assertJsonPath('data.gross_collections', 12000)->assertJsonPath('data.net_commission', 1000)->assertJsonPath('data.platform_revenue', 1000);
        $refund = app(RefundService::class)->request($order, $actor, 'Completed return');
        app(RefundService::class)->approve($refund, $actor, null);
        app(RefundService::class)->approve($refund->fresh(), $actor, 'verified-receipt-12345');
        $this->getJson('/api/v1/admin/finance/summary?vendor_id='.$order->vendor_id)->assertOk()->assertJsonPath('data.net_refunds', 12000)->assertJsonPath('data.net_collections', 0)->assertJsonPath('data.net_commission', 0);
        $this->assertDatabaseCount('ledger_entries', 8);
    }

    public function test_category_mutations_invalidate_the_public_catalogue_cache(): void
    {
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');
        $this->signIn();

        $id = $this->postJson('/api/v1/admin/categories', ['name' => 'New category'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.name', 'New category');
        $this->deleteJson('/api/v1/admin/categories/'.$id)->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $id]);
    }

    public function test_referenced_categories_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();
        $this->signIn();

        $this->deleteJson('/api/v1/admin/categories/'.$product->category_id)->assertConflict();
        $this->assertModelExists($product);
    }

    public function test_archiving_products_preserves_order_item_snapshots(): void
    {
        $order = $this->order();
        $item = $order->items()->firstOrFail();
        $this->signIn();

        $this->deleteJson('/api/v1/admin/products/'.$item->product_id)->assertOk();
        $this->assertSoftDeleted('products', ['id' => $item->product_id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'name' => 'Chips Kuku', 'unit_price' => 10000]);
    }

    public function test_private_verification_documents_enforce_vendor_scope(): void
    {
        $owner = $this->user('vendor');
        $vendor = Vendor::factory()->create();
        $vendor->members()->attach($owner->id);
        $otherVendor = Vendor::factory()->create();
        $document = VendorDocument::factory()->create(['vendor_id' => $vendor->id]);
        Storage::fake('local');
        Storage::disk('local')->put($document->storage_path, '%PDF-test');
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/vendor/businesses/'.$otherVendor->id.'/documents')->assertNotFound();
        $this->get('/api/v1/vendor/businesses/'.$vendor->id.'/documents/'.$document->id.'/download')->assertOk();
        $this->signIn();
        $this->getJson('/api/v1/admin/vendors/'.$otherVendor->id.'/documents/'.$document->id.'/download')->assertNotFound();
    }

    public function test_image_upload_rejects_active_content_and_stores_valid_images_privately(): void
    {
        $product = Product::factory()->create();
        $this->signIn();
        Storage::fake('local');
        $svg = UploadedFile::fake()->createWithContent('danger.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->post('/api/v1/admin/products/'.$product->id.'/image', ['image' => $svg], ['Accept' => 'application/json'])->assertUnprocessable();
        $png = UploadedFile::fake()->createWithContent('safe.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOioAAAAASUVORK5CYII='));
        $this->post('/api/v1/admin/products/'.$product->id.'/image', ['image' => $png], ['Accept' => 'application/json'])->assertOk()->assertJsonMissingPath('data.image_path');
        Storage::disk('local')->assertExists($product->fresh()->image_path);
        $this->get('/api/v1/products/'.$product->id.'/image')->assertOk();
    }

    public function test_settings_reject_secrets_and_detect_stale_updates(): void
    {
        $this->signIn();

        $this->patchJson('/api/v1/admin/settings/SNIPPE_API_KEY', ['value' => 'not-allowed', 'version' => 0])->assertNotFound();
        $this->patchJson('/api/v1/admin/settings/delivery_base_fee', ['value' => 3500, 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson('/api/v1/admin/settings/delivery_base_fee', ['value' => 4500, 'version' => 0])->assertConflict();
        $this->assertSame(3500, app(MarketplaceSettings::class)->integer('delivery_base_fee', 2000));
        $this->assertDatabaseCount('system_settings', 1);
    }

    public function test_campaigns_and_coupons_validate_expiry_and_admin_ownership(): void
    {
        $vendor = Vendor::factory()->create();
        $this->signIn();

        $campaign = $this->postJson('/api/v1/admin/campaigns', ['name' => 'Lunch campaign', 'active' => true, 'starts_at' => '2026-11-01T00:00:00Z', 'expires_at' => '2026-12-01T00:00:00Z'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/coupons', ['vendor_id' => $vendor->id, 'campaign_id' => $campaign, 'code' => 'LUNCH1000', 'discount' => 1000, 'minimum_subtotal' => 5000, 'usage_limit' => 100, 'starts_at' => '2026-11-01T00:00:00Z', 'expires_at' => '2026-12-01T00:00:00Z', 'active' => true])->assertCreated();
        $this->assertDatabaseHas('coupons', ['campaign_id' => $campaign, 'vendor_id' => $vendor->id, 'discount' => 1000]);
        $this->patchJson('/api/v1/admin/campaigns/'.$campaign, ['expires_at' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }

    public function test_stale_inventory_adjustments_return_409_without_overwriting_stock(): void
    {
        $product = Product::factory()->create(['stock' => 19]);
        $this->signIn();

        $this->patchJson('/api/v1/admin/products/'.$product->id, ['stock' => 30, 'expected_stock' => 20])->assertConflict();
        $this->assertSame(19, $product->fresh()->stock);
        $this->patchJson('/api/v1/admin/products/'.$product->id, ['stock' => 30, 'expected_stock' => 19])->assertOk()->assertJsonPath('data.stock', 30);
    }

    public function test_empty_dashboard_has_real_zero_totals_and_date_bounded_trends(): void
    {
        $this->freezeTime();
        $this->signIn();

        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.totals.customers', 0)->assertJsonPath('data.finance.platform_revenue', 0)->assertJsonCount(30, 'data.trend');
        $this->assertStringContainsString('"orders_by_status":{}', $response->getContent());
    }

    public function test_admin_created_accounts_have_hashed_passwords_and_no_secret_response_fields(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/v1/admin/users', ['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345', 'roles' => ['support']])->assertCreated()->assertJsonPath('data.roles.0', 'support');
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $user = User::where('public_id', $response->json('data.id'))->firstOrFail();
        $this->assertTrue(Hash::check('NewPassword12345', $user->password));
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $user->public_id, 'action' => 'user.created']);
    }

    public function test_ledger_export_is_audited_and_streams_csv(): void
    {
        $this->signIn();

        $response = $this->get('/api/v1/admin/finance/export')->assertOk()->assertDownload('mloexpress-ledger.csv');
        $this->assertStringContainsString('order_id,vendor_id,account,kind,amount,currency', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.exported']);
    }
}
