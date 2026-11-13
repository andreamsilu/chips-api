<?php

namespace App\Modules\Delivery;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeliveryService
{
    public function assign(Delivery $input, Rider $rider, User $actor): Delivery
    {
        return DB::transaction(function () use ($input, $rider, $actor): Delivery {
            $order = Order::whereKey($input->order_id)->lockForUpdate()->firstOrFail();
            $delivery = Delivery::whereKey($input->id)->lockForUpdate()->firstOrFail();
            $rider = Rider::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasRole('admin') || ($actor->hasRole('vendor') && $actor->manages($order->vendor_id) && $rider->vendor_id === $order->vendor_id && $order->vendor()->where('status', 'approved')->exists()), 403);
            Domain::require($rider->vendor_id === null || $rider->vendor_id === $order->vendor_id, 'Rider belongs to another vendor.');
            Domain::require(! Delivery::where('rider_id', $rider->id)->where('id', '!=', $delivery->id)->whereIn('status', ['assigned', 'picked_up'])->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'rejected', 'delivered']))->exists(), 'Rider already has an active delivery.');
            Domain::require($order->status === 'ready' && $order->payment_status === 'verified', 'Only paid, ready orders may be assigned.');
            Domain::require($rider->status === 'approved' && $rider->available && $rider->user()->whereNull('suspended_at')->exists(), 'Rider is unavailable.');
            Domain::require(in_array($delivery->status, ['unassigned', 'assigned'], true), 'Delivery has already started.');
            $delivery->forceFill(['rider_id' => $rider->id, 'status' => 'assigned'])->save();
            Domain::audit($actor, 'delivery.assigned', $delivery, ['rider_id' => $rider->id]);
            Domain::emit($order);

            return $delivery;
        }, 3);
    }

    public function transition(Delivery $input, User $actor, string $status): Delivery
    {
        return DB::transaction(function () use ($input, $actor, $status): Delivery {
            $order = Order::whereKey($input->order_id)->lockForUpdate()->firstOrFail();
            $delivery = Delivery::whereKey($input->id)->lockForUpdate()->firstOrFail();
            abort_unless(Gate::forUser($actor)->allows('update', $delivery), 404);
            if ($delivery->status === $status) {
                return $delivery;
            }
            Domain::require($order->payment_status === 'verified', 'Delivery requires verified payment.');
            Domain::require(($delivery->status === 'assigned' && $status === 'picked_up' && $order->status === 'ready') || ($delivery->status === 'picked_up' && $status === 'delivered' && $order->status === 'out_for_delivery'), 'Invalid delivery transition.');
            $delivery->forceFill(['status' => $status, $status.'_at' => now()])->save();
            Domain::transition($order, $status === 'picked_up' ? 'out_for_delivery' : 'delivered', $actor);

            return $delivery;
        }, 3);
    }
}
