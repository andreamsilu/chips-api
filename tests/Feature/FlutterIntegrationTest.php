<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlutterIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'customer')->value('id'));

        return $user;
    }

    private function preparedCart(User $customer, Product $product): array
    {
        Sanctum::actingAs($customer);
        $version = $this->putJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->json('data.version');

        return ['cart_version' => $version, 'fulfilment' => 'pickup', 'payment_method' => 'cash'];
    }

    public function test_quote_does_not_reserve_stock_or_create_orders(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create();
        $body = $this->preparedCart($user, $product);
        $this->postJson('/api/v1/cart/quote', $body)->assertOk()->assertJsonPath('data.total', 10000)->assertJsonPath('data.payment_available', true);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_price_change_after_review_does_not_initiate_payment(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create();
        $body = $this->preparedCart($user, $product);
        $this->postJson('/api/v1/cart/quote', $body)->assertOk();
        $product->forceFill(['price' => 11000])->save();
        $this->withHeader('Idempotency-Key', 'price-review-key')->postJson('/api/v1/checkout', $body + ['expected_total' => 10000])->assertUnprocessable()->assertJsonValidationErrors(['expected_total']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_quote_rejects_mobile_amounts_below_provider_minimum(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['price' => 400]);
        $body = $this->preparedCart($user, $product);
        $body['payment_method'] = 'mpesa';
        $body['phone'] = '+255712345678';
        $this->postJson('/api/v1/cart/quote', $body)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_checkout_replay_returns_the_same_order_despite_later_price_changes(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create();
        $body = $this->preparedCart($user, $product) + ['expected_total' => 10000];
        $first = $this->withHeader('Idempotency-Key', 'stable-review-key')->postJson('/api/v1/checkout', $body)->assertCreated();
        $product->forceFill(['price' => 12000])->save();
        $this->postJson('/api/v1/checkout', $body)->assertOk()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.total', 10000);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_quote_hides_gateway_credentials_and_reports_unavailable_payments(): void
    {
        config(['marketplace.gateway_token' => null, 'marketplace.webhook_secret' => null]);
        $user = $this->customer();
        $product = Product::factory()->create();
        $body = $this->preparedCart($user, $product);
        $body['payment_method'] = 'mpesa';
        $body['phone'] = '+255712345678';
        $this->postJson('/api/v1/cart/quote', $body)->assertOk()->assertJsonPath('data.payment_available', false)->assertJsonMissingPath('data.gateway_token')->assertJsonMissingPath('data.webhook_secret');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cart_removal_works_after_a_product_or_kitchen_becomes_unavailable(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create();
        $this->preparedCart($user, $product);
        $product->forceFill(['available' => false])->save();
        $product->location()->firstOrFail()->forceFill(['accepting_orders' => false])->save();
        $this->putJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 0])->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_profile_updates_name_without_granting_roles_or_changing_credentials(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/auth/me', ['name' => 'Updated name'])->assertOk()->assertJsonPath('data.name', 'Updated name');
        $this->assertSame('Updated name', $user->fresh()->name);
        $this->patchJson('/api/v1/auth/me', ['name' => 'Admin', 'roles' => ['admin']])->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasRole('admin'));
    }

    public function test_private_menu_includes_unavailable_products_and_refuses_other_vendors(): void
    {
        $product = Product::factory()->create(['available' => false]);
        $location = $product->location()->firstOrFail();
        $owner = $this->customer();
        $owner->roles()->attach(Role::where('name', 'vendor')->value('id'));
        $location->vendor()->firstOrFail()->members()->attach($owner->id);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/vendor/locations/'.$location->id.'/products')->assertOk()->assertJsonPath('data.0.id', $product->id);
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/v1/vendor/locations/'.$location->id.'/products')->assertNotFound();
    }

    public function test_order_resource_distinguishes_customer_ownership_and_vendor_commissions(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create();
        $body = $this->preparedCart($user, $product);
        $id = $this->withHeader('Idempotency-Key', 'resource-ownership-key')->postJson('/api/v1/checkout', $body)->assertCreated()->assertJsonPath('data.is_customer_order', true)->json('data.id');
        $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonMissingPath('data.commission');
        $owner = $this->customer();
        $owner->roles()->attach(Role::where('name', 'vendor')->value('id'));
        $product->location()->firstOrFail()->vendor()->firstOrFail()->members()->attach($owner->id);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/vendor/orders')->assertOk()->assertJsonPath('data.0.is_customer_order', false)->assertJsonPath('data.0.commission', 1000);
    }
}
