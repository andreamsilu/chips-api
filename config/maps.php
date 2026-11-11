<?php

return [
    'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
    'route_cache_seconds' => (int) env('GOOGLE_MAPS_ROUTE_CACHE_SECONDS', 0),
    'candidate_limit' => (int) env('GOOGLE_MAPS_CANDIDATE_LIMIT', 25),
    'default_maximum_distance_meters' => (int) env('DELIVERY_MAXIMUM_DISTANCE_METERS', 50000),
    'timeout' => 5,
    'requests_per_minute' => (int) env('GOOGLE_MAPS_REQUESTS_PER_MINUTE', 300),
];
