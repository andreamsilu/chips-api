<?php

namespace App\Modules\Orders;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Modules\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class OrderController extends Controller
{
    public function index(Request $request, OrderRepository $repository): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,10000']]);

        return OrderResource::collection($repository->forCustomer($request->user())->with(['items', 'delivery', 'vendor:id,name'])->orderByDesc('id')->paginate(20));
    }

    public function vendor(Request $request, OrderRepository $repository): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRole('vendor'), 403);
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,10000']]);

        return OrderResource::collection($repository->forVendor($request->user())->with(['items', 'delivery', 'vendor:id,name'])->orderByDesc('id')->paginate(20));
    }

    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless(Gate::allows('view', $order), 404);

        return $this->resource($order);
    }

    public function update(Request $request, Order $order, OrderService $service): OrderResource
    {
        abort_unless($request->user()->manages($order->vendor_id), 404);
        Gate::authorize('update', $order);
        $data = $request->validate(['status' => ['required', 'in:accepted,preparing,ready,completed,rejected'], 'reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500']]);

        return $this->resource($service->transition($order->id, $data['status'], $request->user(), $data['reason'] ?? ''));
    }

    public function cancel(Request $request, Order $order, OrderService $service): OrderResource
    {
        abort_unless(Gate::allows('cancel', $order), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->resource($service->transition($order->id, 'cancelled', $request->user(), $data['reason']));
    }

    public function cash(Request $request, Order $order, OrderService $service): OrderResource
    {
        abort_unless($request->user()->manages($order->vendor_id), 404);
        Gate::authorize('update', $order);
        $request->validate(['cash_received' => ['required', 'accepted']]);

        return $this->resource($service->collectCash($order, $request->user()));
    }

    public function refund(Request $request, Order $order, RefundService $service): JsonResponse
    {
        abort_unless(Gate::allows('cancel', $order) || $request->user()->hasRole('admin'), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $refund = $service->request($order, $request->user(), $data['reason']);

        return response()->json(['data' => $refund->only(['id', 'order_id', 'amount', 'status', 'reason'])], 202);
    }

    private function resource(Order $order): OrderResource
    {
        return new OrderResource($order->load(['items', 'histories', 'payment', 'delivery', 'refund', 'vendor:id,name']));
    }
}
