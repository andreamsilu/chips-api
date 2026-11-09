<?php

namespace App\Modules\Catalog;

use App\Models\VendorLocation;
use Illuminate\Support\Facades\Cache;

final class DiscoveryRepository
{
    public function nearby(float $latitude, float $longitude, int $radius, int $page): array
    {
        $key = 'discovery:'.hash('sha256', json_encode([$latitude, $longitude, $radius, $page]));

        return Cache::remember($key, 30, function () use ($latitude, $longitude, $radius, $page): array {
            $latRange = $radius / 111.0;
            $lngRange = min(180, $radius / (111.0 * max(0.01, cos(deg2rad($latitude)))));
            $query = VendorLocation::query()->where('accepting_orders', true)
                ->whereHas('vendor', fn ($q) => $q->where('status', 'approved'))
                ->whereBetween('latitude', [max(-90, $latitude - $latRange), min(90, $latitude + $latRange)]);
            if ($longitude - $lngRange >= -180 && $longitude + $lngRange <= 180) {
                $query->whereBetween('longitude', [$longitude - $lngRange, $longitude + $lngRange]);
            }
            $distance = '6371 * 2 * ASIN(SQRT(POWER(SIN(RADIANS(latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POWER(SIN(RADIANS(longitude - ?) / 2), 2)))';

            return $query->select('vendor_locations.*')->selectRaw($distance.' AS distance_km', [$latitude, $latitude, $longitude])
                ->whereRaw($distance.' <= ?', [$latitude, $latitude, $longitude, $radius])
                ->with('vendor:id,name')->orderBy('distance_km')->orderBy('id')->paginate(20, ['*'], 'page', $page)->toArray();
        });
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * asin(sqrt(min(1, $a)));
    }
}
