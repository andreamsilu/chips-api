<?php

namespace App\Modules\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

final class GoogleMapsService
{
    private function client(string $fields = ''): PendingRequest
    {
        abort_unless(is_string(config('maps.server_key')) && strlen(config('maps.server_key')) > 0, 503, 'Location services are not configured. Please choose pickup or try again later.');

        return Http::acceptJson()->connectTimeout(2)->timeout(max(1, min(10, (int) config('maps.timeout', 5))))->withHeaders(['X-Goog-Api-Key' => config('maps.server_key')] + ($fields !== '' ? ['X-Goog-FieldMask' => $fields] : []));
    }

    private function request(string $url, array $data, string $fields = '', bool $get = false): array
    {
        $budget = max(1, min(300, (int) config('maps.requests_per_minute', 300)));
        abort_if(RateLimiter::tooManyAttempts('google-maps-provider-budget', $budget), 429, 'Location services are busy. Please retry shortly.');
        RateLimiter::hit('google-maps-provider-budget', 60);
        try {
            $response = $get ? $this->client($fields)->get($url, $data) : $this->client($fields)->post($url, $data);
        } catch (ConnectionException) {
            abort(503, 'Location services could not be reached. Please retry.');
        }
        abort_unless($response->successful(), 503, 'Location services are temporarily unavailable. Please retry.');
        $json = $response->json();
        abort_unless(is_array($json), 503, 'Location services returned an invalid response. Please retry.');

        return $json;
    }

    public static function point(float $latitude, float $longitude): array
    {
        abort_unless(is_finite($latitude) && is_finite($longitude) && abs($latitude) <= 90 && abs($longitude) <= 180, 422, 'Valid coordinates are required.');

        return ['latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
    }

    public static function fingerprint(array $origin, array $destination): string
    {
        return hash('sha256', json_encode([$origin, $destination, 'DRIVE'], JSON_THROW_ON_ERROR));
    }

    private function cacheKey(array $origin, array $destination, bool $geometry = false): string
    {
        return 'maps:route:'.self::fingerprint($origin, $destination).($geometry ? ':geometry' : ':metrics');
    }

    private function ttl(): int
    {
        return max(0, min(300, (int) config('maps.route_cache_seconds', 0)));
    }

    private function normalized(array $route): array
    {
        if (! is_int($route['distanceMeters'] ?? null) || $route['distanceMeters'] < 0 || ! preg_match('/^(\d+)(?:\.\d+)?s$/', (string) ($route['duration'] ?? ''), $duration)) {
            return ['route_available' => false, 'distance_meters' => null, 'distance_km' => null, 'duration_seconds' => null, 'duration_minutes' => null];
        }
        $seconds = (int) $duration[1];
        abort_if($route['distanceMeters'] > 10000000 || $seconds > 604800, 503, 'Location services returned an invalid route.');

        return ['route_available' => true, 'distance_meters' => $route['distanceMeters'], 'distance_km' => $route['distanceMeters'] / 1000, 'duration_seconds' => $seconds, 'duration_minutes' => (int) ceil($seconds / 60)];
    }

    public function route(array $origin, array $destination, bool $geometry = false): array
    {
        $key = $this->cacheKey($origin, $destination, $geometry);
        if ($this->ttl() > 0 && is_array($cached = Cache::get($key))) {
            return $cached;
        }
        $json = $this->request('https://routes.googleapis.com/directions/v2:computeRoutes', ['origin' => ['location' => ['latLng' => $origin]], 'destination' => ['location' => ['latLng' => $destination]], 'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE', 'computeAlternativeRoutes' => false], 'routes.distanceMeters,routes.duration'.($geometry ? ',routes.polyline.encodedPolyline' : ''));
        $raw = $json['routes'][0] ?? [];
        $route = $this->normalized($raw);
        if ($geometry && $route['route_available']) {
            $route['encoded_polyline'] = isset($raw['polyline']['encodedPolyline']) && is_string($raw['polyline']['encodedPolyline']) && strlen($raw['polyline']['encodedPolyline']) <= 100000 ? $raw['polyline']['encodedPolyline'] : null;
        }
        if ($this->ttl() > 0 && $route['route_available']) {
            Cache::put($key, $route, $this->ttl());
        }

        return $route;
    }

    public function matrix(array $origins, array $destination): array
    {
        abort_if(count($origins) > 25, 422, 'Too many route candidates.');
        $result = [];
        $missing = [];
        foreach ($origins as $id => $origin) {
            $cached = $this->ttl() > 0 ? Cache::get($this->cacheKey($origin, $destination)) : null;
            if (is_array($cached)) {
                $result[$id] = $cached;
            } else {
                $missing[$id] = $origin;
            }
        }
        if ($missing === []) {
            return $result;
        }
        $ids = array_keys($missing);
        $json = $this->request('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', ['origins' => array_map(fn (array $point): array => ['waypoint' => ['location' => ['latLng' => $point]]], array_values($missing)), 'destinations' => [['waypoint' => ['location' => ['latLng' => $destination]]]], 'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE'], 'originIndex,destinationIndex,status,condition,distanceMeters,duration');
        foreach ($json as $entry) {
            if (! is_array($entry) || ! isset($ids[$entry['originIndex'] ?? 0]) || ($entry['destinationIndex'] ?? 0) !== 0) {
                continue;
            }
            $id = $ids[$entry['originIndex'] ?? 0];
            $route = ($entry['condition'] ?? '') === 'ROUTE_EXISTS' && (int) ($entry['status']['code'] ?? 0) === 0 ? $this->normalized($entry) : $this->normalized([]);
            $result[$id] = $route;
            if ($this->ttl() > 0 && $route['route_available']) {
                Cache::put($this->cacheKey($missing[$id], $destination), $route, $this->ttl());
            }
        }
        foreach ($ids as $id) {
            $result[$id] ??= $this->normalized([]);
        }

        return $result;
    }

    public function autocomplete(string $input, string $token): array
    {
        $json = $this->request('https://places.googleapis.com/v1/places:autocomplete', ['input' => $input, 'sessionToken' => $token, 'includedRegionCodes' => ['tz'], 'languageCode' => 'en'], 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text');
        $results = [];
        foreach (array_slice($json['suggestions'] ?? [], 0, 5) as $suggestion) {
            $place = $suggestion['placePrediction'] ?? [];
            if (is_string($place['placeId'] ?? null) && is_string($place['text']['text'] ?? null)) {
                $results[] = ['place_id' => $place['placeId'], 'description' => $place['text']['text']];
            }
        }

        return $results;
    }

    public function place(string $id, string $token): array
    {
        $json = $this->request('https://places.googleapis.com/v1/places/'.rawurlencode($id), ['sessionToken' => $token, 'languageCode' => 'en'], 'id,formattedAddress,location', true);
        abort_unless(isset($json['location']['latitude'],$json['location']['longitude'],$json['formattedAddress']) && $this->validCoordinates($json['location']['latitude'], $json['location']['longitude']) && is_string($json['formattedAddress']) && strlen($json['formattedAddress']) <= 500, 503, 'Location details are unavailable.');

        return ['place_id' => $json['id'] ?? $id, 'formatted_address' => $json['formattedAddress'], 'latitude' => $json['location']['latitude'], 'longitude' => $json['location']['longitude']];
    }

    private function validCoordinates(mixed $latitude, mixed $longitude): bool
    {
        return is_numeric($latitude) && is_numeric($longitude) && is_finite((float) $latitude) && is_finite((float) $longitude) && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180;
    }

    public function geocode(array $data): array
    {
        $query = isset($data['address']) ? ['address' => $data['address'], 'components' => 'country:TZ'] : ['latlng' => $data['latitude'].','.$data['longitude']];
        $json = $this->request('https://maps.googleapis.com/maps/api/geocode/json', $query + ['key' => config('maps.server_key'), 'language' => 'en'], '', true);
        abort_unless(($json['status'] ?? null) === 'OK' && isset($json['results'][0]['geometry']['location']), 503, 'No address could be resolved. You can enter the address manually.');
        $place = $json['results'][0];
        abort_unless($this->validCoordinates($place['geometry']['location']['lat'] ?? null, $place['geometry']['location']['lng'] ?? null) && is_string($place['formatted_address'] ?? null) && strlen($place['formatted_address']) <= 500, 503, 'Location details are unavailable.');

        return ['place_id' => $place['place_id'] ?? null, 'formatted_address' => $place['formatted_address'] ?? '', 'latitude' => $place['geometry']['location']['lat'], 'longitude' => $place['geometry']['location']['lng']];
    }
}
