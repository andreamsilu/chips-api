<?php

namespace App\Modules\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('rider'), 403);
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,10000']]);
        $deliveries = Delivery::whereHas('rider', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'approved'))->with('order')->orderByDesc('id')->paginate(20);
        $deliveries->through(fn (Delivery $delivery) => $this->serialize($delivery));

        return response()->json($deliveries);
    }

    public function update(Request $request, Delivery $delivery, DeliveryService $service): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:picked_up,delivered']]);

        return response()->json(['data' => $this->serialize($service->transition($delivery, $request->user(), $data['status'])->load('order'))]);
    }

    private function serialize(Delivery $delivery): array
    {
        return ['id' => $delivery->id, 'order_id' => $delivery->order_id, 'status' => $delivery->status, 'destination' => $delivery->order->address_snapshot, 'vendor_location_id' => $delivery->order->vendor_location_id, 'picked_up_at' => $delivery->picked_up_at, 'delivered_at' => $delivery->delivered_at];
    }
}
