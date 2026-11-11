<?php

namespace App\Modules\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Rider;
use App\Models\VendorLocation;
use App\Modules\Administration\AdminAccess;
use App\Modules\Administration\AdminQuery;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LocationController extends Controller
{
    public function autocomplete(Request $request, GoogleMapsService $maps): JsonResponse
    {
        $data = AdminQuery::body($request, ['input' => ['required', 'string', 'min:3', 'max:200'], 'session_token' => ['required', 'uuid']]);

        return response()->json(['data' => $maps->autocomplete($data['input'], $data['session_token'])]);
    }

    public function place(Request $request, GoogleMapsService $maps): JsonResponse
    {
        $data = AdminQuery::body($request, ['place_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'], 'session_token' => ['required', 'uuid']]);

        return response()->json(['data' => $maps->place($data['place_id'], $data['session_token'])]);
    }

    public function geocode(Request $request, GoogleMapsService $maps): JsonResponse
    {
        $data = AdminQuery::body($request, ['address' => ['required_without:latitude', 'prohibits:latitude,longitude', 'string', 'min:3', 'max:500'], 'latitude' => ['required_without:address', 'numeric', 'between:-90,90'], 'longitude' => ['required_with:latitude', 'numeric', 'between:-180,180']]);

        return response()->json(['data' => $maps->geocode($data)]);
    }

    public function quote(Request $request, VendorLocation $location, DeliveryPricingService $pricing): JsonResponse
    {
        $data = AdminQuery::body($request, ['address_id' => ['required', 'ulid']]);
        $address = Address::where('user_id', $request->user()->id)->findOrFail($data['address_id']);
        $prepared = $pricing->prepareLocation($location, $address);

        return response()->json(['data' => $pricing->quote($location, $address, $prepared)]);
    }

    public function tracking(Request $request, Order $order, GoogleMapsService $maps): JsonResponse
    {
        $order->load(['delivery.rider', 'vendorLocation']);
        $actor = $request->user();
        $rider = $order->delivery?->rider;
        abort_unless($actor->id === $order->user_id || ($actor->hasRole('admin') && count(array_intersect(['orders.read', 'riders.read'], AdminAccess::permissions($actor))) > 0) || ($actor->hasRole('vendor') && $actor->manages($order->vendor_id)) || ($actor->hasRole('rider') && $rider?->user_id === $actor->id && $rider->status === 'approved'), 404);
        $data = $request->validate(['include_route' => ['sometimes', 'boolean']]);
        $snapshot = $order->address_snapshot;
        $active = $order->fulfilment === 'delivery' && in_array($order->status, ['confirmed', 'accepted', 'preparing', 'ready', 'out_for_delivery'], true) && in_array($order->delivery?->status, ['assigned', 'picked_up'], true);
        $position = $active && $rider?->status === 'approved' && $rider->location_updated_at && $rider->location_updated_at->gte(now()->subMinutes(15)) ? ['latitude' => (float) $rider->last_latitude, 'longitude' => (float) $rider->last_longitude, 'updated_at' => $rider->location_updated_at, 'accuracy_meters' => $rider->location_accuracy_meters] : null;
        $route = null;
        $routingError = null;
        if (($data['include_route'] ?? false) && $active && isset($snapshot['delivery']['origin'],$snapshot['delivery']['destination'])) {
            try {
                $route = $maps->route($snapshot['delivery']['origin'], $snapshot['delivery']['destination'], true);
            } catch (HttpException) {
                $routingError = 'Road preview is temporarily unavailable.';
            }
        }

        return response()->json(['data' => ['order_id' => $order->id, 'status' => $order->status, 'delivery_status' => $order->delivery?->status, 'destination' => $order->fulfilment === 'delivery' ? ['latitude' => (float) $snapshot['latitude'], 'longitude' => (float) $snapshot['longitude'], 'address' => $snapshot['formatted_address'] ?? $snapshot['details']] : null, 'origin' => $snapshot['delivery']['origin'] ?? null, 'delivery' => $snapshot['delivery'] ?? null, 'rider' => $position, 'route' => $route, 'routing_error' => $routingError]]);
    }

    public function adminTracking(Request $request, Delivery $delivery, GoogleMapsService $maps): JsonResponse
    {
        return $this->tracking($request, $delivery->order, $maps);
    }

    public function position(Request $request, Delivery $delivery): JsonResponse
    {
        abort_unless($request->user()->hasRole('rider'), 403);
        $data = AdminQuery::body($request, ['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'accuracy_meters' => ['nullable', 'integer', 'between:0,2000']]);
        DB::transaction(function () use ($request, $delivery, $data): void {
            $model = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $rider = Rider::where('user_id', $request->user()->id)->where('status', 'approved')->whereKey($model->rider_id)->lockForUpdate()->first();
            abort_unless($rider !== null, 404);
            Domain::require(in_array($model->status, ['assigned', 'picked_up'], true) && Order::whereKey($model->order_id)->whereIn('status', ['confirmed', 'accepted', 'preparing', 'ready', 'out_for_delivery'])->exists(), 'Location can only be shared during an active assigned delivery.');
            $rider->forceFill(['last_latitude' => $data['latitude'], 'last_longitude' => $data['longitude'], 'location_accuracy_meters' => $data['accuracy_meters'] ?? null, 'location_updated_at' => now()])->save();
        }, 3);

        return response()->json(['message' => 'Delivery location updated.']);
    }
}
