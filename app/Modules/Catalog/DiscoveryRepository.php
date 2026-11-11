<?php

namespace App\Modules\Catalog;

use App\Models\DeliveryPricingRule;
use App\Models\VendorLocation;
use App\Modules\Delivery\DeliveryPricingService;
use App\Modules\Delivery\GoogleMapsService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DiscoveryRepository
{
    public function __construct(private GoogleMapsService $maps, private DeliveryPricingService $pricing) {}

    public function nearby(float $latitude, float $longitude, int $radius, int $page, string $sort = 'recommended', string $fulfilment = 'all'): array
    {
        $latRange = $radius / 111.0;
        $lngRange = min(180, $radius / (111.0 * max(0.01, cos(deg2rad($latitude)))));
        $query = VendorLocation::query()->where('accepting_orders', true)->whereHas('vendor', fn ($q) => $q->where('status', 'approved'))->whereBetween('latitude', [max(-90, $latitude - $latRange), min(90, $latitude + $latRange)]);
        if ($longitude - $lngRange >= -180 && $longitude + $lngRange <= 180) {
            $query->whereBetween('longitude', [$longitude - $lngRange, $longitude + $lngRange]);
        }
        if ($fulfilment === 'pickup') {
            $query->where('pickup_enabled', true);
        } elseif ($fulfilment === 'delivery') {
            $query->where('delivery_enabled', true)->where('location_verification_status', 'verified');
        }
        $expression = '6371 * 2 * ASIN(SQRT(POWER(SIN(RADIANS(latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POWER(SIN(RADIANS(longitude - ?) / 2), 2)))';
        $limit = max(1, min(25, (int) config('maps.candidate_limit', 25)));
        $candidates = $query->select('vendor_locations.*')->selectRaw($expression.' AS distance_km', [$latitude, $latitude, $longitude])->whereRaw($expression.' <= ?', [$latitude, $latitude, $longitude, $radius])->with('vendor:id,name')->orderBy('distance_km')->orderBy('id')->limit($limit + 1)->get();
        $limited = $candidates->count() > $limit;
        $candidates = $candidates->take($limit);
        $rules = DeliveryPricingRule::with('zones')->where('active', true)->where(fn ($q) => $q->whereIn('vendor_id', $candidates->pluck('vendor_id'))->orWhere('scope_key', 'global'))->get();
        $overrides = $rules->whereNotNull('vendor_id')->keyBy('vendor_id');
        $global = $rules->firstWhere('scope_key', 'global');
        $origins = [];
        if ($fulfilment !== 'pickup') {
            foreach ($candidates as $location) {
                $rule = $overrides->get($location->vendor_id) ?? $global;
                if ($rule && $location->delivery_enabled && $location->location_verification_status === 'verified' && $location->location_verified_at && $location->distance_km * 1000 <= min($location->delivery_radius_km * 1000, $rule->maximum_distance_meters)) {
                    $origins[$location->id] = GoogleMapsService::point((float) $location->latitude, (float) $location->longitude);
                }
            }
        }
        $routes = [];
        $routingAvailable = true;
        if ($origins !== []) {
            try {
                $routes = $this->maps->matrix($origins, GoogleMapsService::point($latitude, $longitude));
            } catch (HttpException) {
                $routingAvailable = false;
            }
        }
        $rows = $candidates->map(function (VendorLocation $location) use ($routes, $overrides, $global): array {
            $data = $location->toArray();
            $data['straight_line_distance_km'] = (float) $location->distance_km;
            $data['delivery_eligible'] = false;
            $data['delivery_fee'] = null;
            $data['duration_seconds'] = null;
            $data['road_distance_meters'] = null;
            $data['route_available'] = false;
            $data['distance_type'] = 'straight_line';
            $data['delivery_unavailable_reason'] = 'Road delivery has not been confirmed.';
            $route = $routes[$location->id] ?? null;
            $rule = $overrides->get($location->vendor_id) ?? $global;
            if ($route && $route['route_available'] && $rule) {
                try {
                    $quote = $this->pricing->price($location, $route, false, $rule);
                    $data['delivery_eligible'] = true;
                    $data['delivery_fee'] = $quote['fee'];
                    $data['duration_seconds'] = $route['duration_seconds'];
                    $data['road_distance_meters'] = $route['distance_meters'];
                    $data['distance_km'] = $route['distance_km'];
                    $data['distance_type'] = 'road';
                    $data['route_available'] = true;
                    $data['delivery_unavailable_reason'] = null;
                } catch (HttpException) {
                    $data['delivery_unavailable_reason'] = 'Outside the configured road delivery coverage.';
                }
            }

            return $data;
        });
        if ($fulfilment === 'delivery') {
            $rows = $rows->where('delivery_eligible', true);
        }
        $rows = $rows->sort(function (array $a, array $b) use ($sort): int {
            $field = match ($sort) {
                'fastest' => 'duration_seconds','lowest_fee' => 'delivery_fee',default => 'distance_km'
            };

            return [($a[$field] ?? PHP_INT_MAX), $a['id']] <=> [($b[$field] ?? PHP_INT_MAX), $b['id']];
        })->values();

        return (new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page, ['path' => Paginator::resolveCurrentPath()]))->toArray() + ['search_limited' => $limited, 'candidate_limit' => $limit, 'routing_available' => $routingAvailable];
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * asin(sqrt(min(1, $a)));
    }
}
