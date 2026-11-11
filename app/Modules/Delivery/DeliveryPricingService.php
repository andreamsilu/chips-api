<?php

namespace App\Modules\Delivery;

use App\Models\Address;
use App\Models\Cart;
use App\Models\DeliveryPricingRule;
use App\Models\User;
use App\Models\VendorLocation;
use App\Modules\Catalog\DiscoveryRepository;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;

final class DeliveryPricingService
{
    public function __construct(private GoogleMapsService $maps) {}

    public function rule(string $vendorId, bool $lock = false): DeliveryPricingRule
    {
        $query = DeliveryPricingRule::with('zones')->where('active', true)->where(fn ($q) => $q->where('vendor_id', $vendorId)->orWhere('scope_key', 'global'))->orderByRaw('CASE WHEN vendor_id IS NULL THEN 1 ELSE 0 END');
        if ($lock) {
            $query->lockForUpdate();
        }
        $rule = $query->first();
        Domain::require($rule !== null, 'Delivery pricing is unavailable. Please choose pickup.');

        return $rule;
    }

    public function endpoints(VendorLocation $location, Address $address): array
    {
        return [GoogleMapsService::point((float) $location->latitude, (float) $location->longitude), GoogleMapsService::point((float) $address->latitude, (float) $address->longitude)];
    }

    public function prepare(User $user, array $data): ?array
    {
        if ($data['fulfilment'] !== 'delivery') {
            return null;
        }
        $cart = Cart::where('user_id', $user->id)->first();
        if (! $cart || ! $cart->vendor_location_id) {
            return null;
        }
        Domain::require($cart->version === (int) $data['cart_version'], 'Cart changed. Review it before checkout.');
        $location = VendorLocation::findOrFail($cart->vendor_location_id);
        $address = Address::where('user_id', $user->id)->findOrFail($data['address_id']);

        return $this->prepareLocation($location, $address);
    }

    public function prepareLocation(VendorLocation $location, Address $address): array
    {
        $this->eligible($location);
        $rule = $this->rule($location->vendor_id);
        [$origin,$destination] = $this->endpoints($location, $address);
        $maximum = min($rule->maximum_distance_meters, (int) $location->delivery_radius_km * 1000);
        Domain::require(DiscoveryRepository::distance($origin['latitude'], $origin['longitude'], $destination['latitude'], $destination['longitude']) * 1000 <= $maximum, 'Address is outside the delivery zone.');

        return ['fingerprint' => GoogleMapsService::fingerprint($origin, $destination), 'route' => $this->maps->route($origin, $destination), 'prepared_at' => time()];
    }

    public function eligible(VendorLocation $location): void
    {
        Domain::require($location->delivery_enabled && $location->accepting_orders && $location->vendor()->where('status', 'approved')->exists(), 'This kitchen is unavailable for delivery.');
        Domain::require($location->location_verification_status === 'verified' && $location->location_verified_at !== null, 'The kitchen location is awaiting verification. Please choose pickup.');
    }

    public function quote(VendorLocation $location, Address $address, array $prepared): array
    {
        $this->eligible($location);
        [$origin,$destination] = $this->endpoints($location, $address);
        Domain::require(hash_equals(GoogleMapsService::fingerprint($origin, $destination), $prepared['fingerprint']) && $prepared['prepared_at'] >= time() - 60, 'Location changed. Request a fresh delivery quote.');

        return $this->price($location, $prepared['route'], DB::transactionLevel() > 0) + ['origin' => $origin, 'destination' => $destination];
    }

    public function price(VendorLocation $location, array $route, bool $lock = false, ?DeliveryPricingRule $rule = null): array
    {
        abort_unless($route['route_available'] ?? false, 503, 'A road route is unavailable. Choose pickup or retry.');
        $rule ??= $this->rule($location->vendor_id, $lock);
        $meters = (int) $route['distance_meters'];
        Domain::require($meters <= min((int) $rule->maximum_distance_meters, (int) $location->delivery_radius_km * 1000), 'Address is outside the road delivery zone.');
        $zone = null;
        if ($rule->pricing_method === 'zones') {
            $zone = $rule->zones->where('active', true)->where('maximum_distance_meters', '>=', $meters)->sortBy('maximum_distance_meters')->first();
            Domain::require($zone !== null, 'No delivery zone is configured for this road distance.');
        }
        $fee = match ($rule->pricing_method) {
            'fixed' => (int) $rule->fixed_fee,'zones' => (int) $zone->fee,default => (int) $rule->base_fee + intdiv($meters * (int) $rule->price_per_km + 999, 1000)
        };

        return $route + ['fee' => max((int) $rule->minimum_fee, $fee), 'pricing_rule_id' => $rule->id, 'pricing_version' => $rule->version, 'pricing_method' => $rule->pricing_method, 'base_fee' => $rule->base_fee, 'price_per_km' => $rule->price_per_km, 'minimum_fee' => $rule->minimum_fee, 'maximum_distance_meters' => min((int) $rule->maximum_distance_meters, (int) $location->delivery_radius_km * 1000), 'zone_id' => $zone?->id, 'zone_name' => $zone?->name, 'source' => 'google_routes'];
    }
}
