<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function fakeRoadRouting(int $meters = 0): void
    {
        config(['maps.server_key' => 'test-only-maps-key']);
        Http::fake(['https://routes.googleapis.com/directions/v2:computeRoutes' => Http::response(['routes' => [['distanceMeters' => $meters, 'duration' => '300s']]])]);
    }
}
