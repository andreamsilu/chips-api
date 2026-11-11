<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Delivery;
use App\Models\DeliveryPricingRule;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLocation;
use App\Modules\Delivery\GoogleMapsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MapsAndLocationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }

    private function fixture(int $meters = 3500): array
    {
        $this->fakeRoadRouting($meters);
        Http::preventStrayRequests();
        Queue::fake();
        config(['marketplace.gateway_url' => 'https://gateway.example', 'marketplace.gateway_token' => 'test-only', 'marketplace.webhook_secret' => str_repeat('a', 32), 'marketplace.webhook_url' => 'https://api.example/callback']);
        $customer = $this->user();
        $location = VendorLocation::factory()->create();
        $address = Address::factory()->create(['user_id' => $customer->id]);
        $product = Product::factory()->create(['vendor_location_id' => $location->id]);
        Sanctum::actingAs($customer);
        $cart = $this->putJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk()->json('data');
        $body = ['cart_version' => $cart['version'], 'fulfilment' => 'delivery', 'payment_method' => 'mpesa', 'phone' => '+255712345678', 'address_id' => $address->id];

        return compact('customer', 'location', 'address', 'product', 'body');
    }

    public function test_road_quote_uses_database_pricing_and_never_straight_line_distance(): void
    {
        $f = $this->fixture(3500);
        $this->postJson('/api/v1/cart/quote', $f['body'])->assertOk()->assertJsonPath('data.delivery_fee', 3750)->assertJsonPath('data.delivery.distance_meters', 3500)->assertJsonPath('data.delivery.source', 'google_routes');
        Http::assertSent(fn ($request) => $request->url() === 'https://routes.googleapis.com/directions/v2:computeRoutes' && $request['travelMode'] === 'DRIVE' && $request->hasHeader('X-Goog-FieldMask', 'routes.distanceMeters,routes.duration'));
    }

    public function test_checkout_snapshots_road_metrics_and_replays_without_another_google_request(): void
    {
        $f = $this->fixture(3500);
        $first = $this->withHeader('Idempotency-Key', 'maps-stable-checkout')->postJson('/api/v1/checkout', $f['body'])->assertCreated()->assertJsonPath('data.address.delivery.distance_meters', 3500)->assertJsonPath('data.delivery_fee', 3750);
        config(['maps.server_key' => null]);
        $this->postJson('/api/v1/checkout', $f['body'])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        Http::assertSentCount(1);
        DeliveryPricingRule::where('scope_key', 'global')->update(['base_fee' => 9000]);
        $this->assertSame(3750, Order::findOrFail($first->json('data.id'))->delivery_fee);
    }

    public function test_delivery_rejects_road_distance_outside_radius_even_when_coordinates_match(): void
    {
        $f = $this->fixture(20000);
        $this->postJson('/api/v1/cart/quote', $f['body'])->assertConflict()->assertJsonPath('message', 'Address is outside the road delivery zone.');
        Http::assertSentCount(1);
    }

    public function test_delivery_uses_fixed_minimum_and_zone_fees_from_the_database(): void
    {
        $f = $this->fixture(3500);
        $rule = DeliveryPricingRule::where('scope_key', 'global')->firstOrFail();
        $rule->forceFill(['pricing_method' => 'fixed', 'fixed_fee' => 1000, 'minimum_fee' => 2500])->save();
        $this->postJson('/api/v1/cart/quote', $f['body'])->assertOk()->assertJsonPath('data.delivery_fee', 2500);
        $rule->forceFill(['pricing_method' => 'zones', 'minimum_fee' => 0])->save();
        DeliveryZone::record(['delivery_pricing_rule_id' => $rule->id, 'name' => 'Near', 'maximum_distance_meters' => 2000, 'fee' => 2000, 'active' => true]);
        DeliveryZone::record(['delivery_pricing_rule_id' => $rule->id, 'name' => 'Outer', 'maximum_distance_meters' => 5000, 'fee' => 4200, 'active' => true]);
        $this->postJson('/api/v1/cart/quote', $f['body'])->assertOk()->assertJsonPath('data.delivery_fee', 4200)->assertJsonPath('data.delivery.zone_name', 'Outer');
        Http::assertSentCount(2);
    }

    public function test_google_quota_errors_are_sanitized_and_no_order_is_created(): void
    {
        $customer = $this->user();
        $location = VendorLocation::factory()->create();
        $address = Address::factory()->create(['user_id' => $customer->id]);
        Sanctum::actingAs($customer);
        config(['maps.server_key' => 'never-show-server-key']);
        Http::preventStrayRequests();
        Http::fake(['https://routes.googleapis.com/directions/v2:computeRoutes' => Http::response(['error' => ['message' => 'never-show-server-key quota']], 429)]);
        $response = $this->postJson('/api/v1/locations/'.$location->id.'/delivery-quote', ['address_id' => $address->id])->assertStatus(503);
        $this->assertStringNotContainsString('never-show-server-key', $response->getContent());
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    public function test_missing_road_route_is_not_substituted_with_geographic_distance(): void
    {
        config(['maps.server_key' => 'test-only-maps']);
        Http::preventStrayRequests();
        Http::fake(['https://routes.googleapis.com/directions/v2:computeRoutes' => Http::response(['routes' => []])]);
        $maps = app(GoogleMapsService::class);
        $result = $maps->route(GoogleMapsService::point(-6.77, 39.25), GoogleMapsService::point(-6.78, 39.26));
        $this->assertFalse($result['route_available']);
        $this->assertNull($result['distance_meters']);
        Http::assertSentCount(1);
    }

    public function test_route_cache_is_opt_in_coordinate_and_mode_scoped(): void
    {
        config(['maps.route_cache_seconds' => 60]);
        $this->fakeRoadRouting(3500);
        $maps = app(GoogleMapsService::class);
        $origin = GoogleMapsService::point(-6.77, 39.25);
        $destination = GoogleMapsService::point(-6.78, 39.26);
        $first = $maps->route($origin, $destination);
        $this->assertSame($first, $maps->route($origin, $destination));
        Http::assertSentCount(1);
        $maps->route($origin, GoogleMapsService::point(-6.79, 39.26));
        Http::assertSentCount(2);
    }

    public function test_pickup_needs_no_coordinates_or_google_configuration(): void
    {
        $f = $this->fixture();
        config(['maps.server_key' => null]);
        $this->withHeader('Idempotency-Key', 'pickup-no-maps')->postJson('/api/v1/checkout', ['cart_version' => $f['body']['cart_version'], 'fulfilment' => 'pickup', 'payment_method' => 'cash'])->assertCreated()->assertJsonPath('data.delivery_fee', 0);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_supply_a_delivery_fee_or_distance(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/cart/quote', $f['body'] + ['delivery_fee' => 1, 'distance_meters' => 1])->assertUnprocessable()->assertJsonValidationErrors(['delivery_fee', 'distance_meters']);
        Http::assertNothingSent();
    }

    public function test_address_defaults_edits_and_deletion_are_customer_scoped(): void
    {
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $body = ['label' => 'Home', 'details' => 'Customer supplied address', 'latitude' => -6.77, 'longitude' => 39.25, 'landmark' => 'Blue gate', 'delivery_instructions' => 'Call on arrival'];
        $first = $this->postJson('/api/v1/addresses', $body)->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
        $second = $this->postJson('/api/v1/addresses', array_replace($body, ['label' => 'Office', 'is_default' => true]))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('addresses', ['id' => $first, 'is_default' => false]);
        $this->patchJson('/api/v1/addresses/'.$first, $body + ['is_default' => true])->assertOk()->assertJsonPath('data.landmark', 'Blue gate');
        $this->deleteJson('/api/v1/addresses/'.$first)->assertOk();
        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonPath('data.0.id', $second)->assertJsonPath('data.0.is_default', true);
        Sanctum::actingAs($this->user());
        $this->patchJson('/api/v1/addresses/'.$second, $body)->assertNotFound();
        $this->deleteJson('/api/v1/addresses/'.$second)->assertNotFound();
    }

    public function test_google_helpers_validate_coordinates_and_never_accept_a_proxy_url(): void
    {
        Sanctum::actingAs($this->user());
        Http::preventStrayRequests();
        $this->postJson('/api/v1/location/geocode', ['latitude' => 91, 'longitude' => 39])->assertUnprocessable();
        $this->postJson('/api/v1/location/autocomplete', ['input' => 'Road', 'session_token' => 'not-a-uuid', 'url' => 'https://example.test'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_places_autocomplete_and_details_use_a_session_and_fixed_field_masks(): void
    {
        Sanctum::actingAs($this->user());
        config(['maps.server_key' => 'test-only-key']);
        $token = '3af63c91-86ea-4011-8b9d-3446fa8b450f';
        Http::preventStrayRequests();
        Http::fake(['https://places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [['placePrediction' => ['placeId' => 'ChIJtest', 'text' => ['text' => 'Selected address']]]]]), 'https://places.googleapis.com/v1/places/ChIJtest*' => Http::response(['id' => 'ChIJtest', 'formattedAddress' => 'Selected address', 'location' => ['latitude' => -6.77, 'longitude' => 39.25]])]);
        $this->postJson('/api/v1/location/autocomplete', ['input' => 'Selected', 'session_token' => $token])->assertOk()->assertJsonPath('data.0.place_id', 'ChIJtest');
        $this->postJson('/api/v1/location/place', ['place_id' => 'ChIJtest', 'session_token' => $token])->assertOk()->assertJsonPath('data.latitude', -6.77);
        Http::assertSent(fn ($r) => $r->url() === 'https://places.googleapis.com/v1/places:autocomplete' && $r['sessionToken'] === $token && $r['includedRegionCodes'] === ['tz']);
        Http::assertSentCount(2);
    }

    public function test_vendor_coordinate_changes_require_reverification_and_customers_cannot_verify(): void
    {
        $owner = $this->user('vendor');
        $location = VendorLocation::factory()->create();
        $location->vendor->members()->attach($owner->id);
        Sanctum::actingAs($owner);
        $body = $location->only(['name', 'address', 'latitude', 'longitude', 'accepting_orders', 'delivery_enabled', 'pickup_enabled', 'delivery_radius_km']);
        $body['latitude'] = -6.78;
        $this->putJson('/api/v1/vendor/businesses/'.$location->vendor_id.'/locations/'.$location->id, $body)->assertOk()->assertJsonPath('data.location_verification_status', 'pending');
        $this->postJson('/api/v1/admin/locations/'.$location->id.'/verify', ['latitude' => -6.78, 'longitude' => 39.25])->assertForbidden();
        Sanctum::actingAs($this->user('admin'));
        $this->postJson('/api/v1/admin/locations/'.$location->id.'/verify', ['latitude' => -6.77, 'longitude' => 39.25])->assertConflict();
        $this->postJson('/api/v1/admin/locations/'.$location->id.'/verify', ['latitude' => -6.78, 'longitude' => 39.25])->assertOk()->assertJsonPath('data.location_verification_status', 'verified');
    }

    public function test_pricing_updates_require_admin_access_and_current_versions(): void
    {
        $rule = DeliveryPricingRule::where('scope_key', 'global')->firstOrFail();
        $body = $rule->only(['pricing_method', 'fixed_fee', 'base_fee', 'price_per_km', 'minimum_fee', 'maximum_distance_meters', 'active', 'version']);
        Sanctum::actingAs($this->user());
        $this->patchJson('/api/v1/admin/delivery-pricing/'.$rule->id, $body)->assertForbidden();
        Sanctum::actingAs($this->user('admin'));
        $this->patchJson('/api/v1/admin/delivery-pricing/'.$rule->id, $body)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/admin/delivery-pricing/'.$rule->id, $body)->assertConflict();
    }

    public function test_rider_positions_are_visible_only_on_authorized_active_deliveries(): void
    {
        $f = $this->fixture();
        $id = $this->withHeader('Idempotency-Key', 'tracking-private-order')->postJson('/api/v1/checkout', $f['body'])->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        $order->forceFill(['status' => 'confirmed', 'payment_status' => 'verified'])->save();
        $actor = $this->user('rider');
        $rider = Rider::factory()->create(['user_id' => $actor->id, 'status' => 'approved']);
        $delivery = Delivery::where('order_id', $id)->firstOrFail();
        $delivery->forceFill(['rider_id' => $rider->id, 'status' => 'assigned'])->save();
        Sanctum::actingAs($actor);
        $this->patchJson('/api/v1/rider/deliveries/'.$delivery->id.'/location', ['latitude' => -6.78, 'longitude' => 39.26, 'accuracy_meters' => 10])->assertOk();
        Sanctum::actingAs($f['customer']);
        $this->getJson('/api/v1/orders/'.$id.'/tracking')->assertOk()->assertJsonPath('data.rider.latitude', -6.78);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/orders/'.$id.'/tracking')->assertNotFound();
        $order->forceFill(['status' => 'cancelled'])->save();
        Sanctum::actingAs($f['customer']);
        $this->getJson('/api/v1/orders/'.$id.'/tracking')->assertOk()->assertJsonPath('data.rider', null);
        Http::assertSentCount(1);
    }

    public function test_matrix_defaults_zero_indices_and_discovery_orders_by_road_fee(): void
    {
        config(['maps.server_key' => 'test-only', 'maps.candidate_limit' => 2]);
        Http::preventStrayRequests();
        $near = VendorLocation::factory()->create(['latitude' => -6.8, 'longitude' => 39.2]);
        $next = VendorLocation::factory()->create(['latitude' => -6.801, 'longitude' => 39.2]);
        VendorLocation::factory()->create(['latitude' => -8.8, 'longitude' => 39.2]);
        Http::fake(['https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix' => Http::response([
            ['condition' => 'ROUTE_EXISTS', 'distanceMeters' => 4000, 'duration' => '600s'],
            ['originIndex' => 1, 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 2000, 'duration' => '300s'],
        ])]);
        $this->getJson('/api/v1/locations?latitude=-6.8&longitude=39.2&sort=lowest_fee&fulfilment=delivery')->assertOk()->assertJsonPath('data.0.id', $next->id)->assertJsonPath('data.0.delivery_fee', 3000)->assertJsonPath('data.1.id', $near->id)->assertJsonCount(2, 'data');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => count($request['origins']) === 2 && count($request['destinations']) === 1);
    }

    public function test_connection_failure_returns_safe_service_unavailable(): void
    {
        $customer = $this->user();
        $location = VendorLocation::factory()->create();
        $address = Address::factory()->create(['user_id' => $customer->id]);
        Sanctum::actingAs($customer);
        config(['maps.server_key' => 'test-only']);
        Http::preventStrayRequests();
        Http::fake(['https://routes.googleapis.com/directions/v2:computeRoutes' => Http::failedConnection()]);
        $this->postJson('/api/v1/locations/'.$location->id.'/delivery-quote', ['address_id' => $address->id])->assertStatus(503)->assertJsonPath('message', 'Location services could not be reached. Please retry.');
    }

    public function test_route_request_precedes_checkout_transaction_and_revalidates_coordinates(): void
    {
        $f = $this->fixture();
        Http::fake(['https://routes.googleapis.com/directions/v2:computeRoutes' => function () use ($f) {
            // RefreshDatabase holds the outer test transaction; checkout must not add its lock transaction yet.
            $this->assertSame(1, DB::transactionLevel());
            $f['address']->forceFill(['latitude' => -6.81])->save();

            return Http::response(['routes' => [['distanceMeters' => 3500, 'duration' => '720s']]]);
        }]);
        $this->withHeader('Idempotency-Key', 'coordinate-race-order')->postJson('/api/v1/checkout', $f['body'])->assertConflict()->assertJsonPath('message', 'Location changed. Request a fresh delivery quote.');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_shared_provider_budget_blocks_another_billable_request(): void
    {
        $f = $this->fixture();
        config(['maps.requests_per_minute' => 1]);
        RateLimiter::clear('google-maps-provider-budget');
        $this->postJson('/api/v1/cart/quote', $f['body'])->assertOk();
        $this->postJson('/api/v1/cart/quote', $f['body'])->assertStatus(429)->assertJsonPath('message', 'Location services are busy. Please retry shortly.');
        Http::assertSentCount(1);
    }
}
