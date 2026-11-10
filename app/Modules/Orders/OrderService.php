<?php

namespace App\Modules\Orders;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vendor;
use App\Modules\Finance\LedgerService;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(private LedgerService $ledger) {}

    public function expire(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'awaiting_payment' && $order->payment_expires_at <= now()) {
                $this->releaseStock($order);
                Domain::transition($order, 'cancelled', null, 'Payment reservation expired');
            }
        }, 3);
    }

    public function transition(string $id, string $status, ?User $actor, string $reason = ''): Order
    {
        return DB::transaction(function () use ($id, $status, $actor, $reason): Order {
            $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
            $customer = $actor !== null && $actor->id === $order->user_id;
            $vendor = $actor !== null && $actor->manages($order->vendor_id) && $actor->hasRole('vendor');
            if ($order->status === $status && ($customer || $vendor || $actor === null)) {
                return $order;
            }
            if (in_array($status, ['cancelled', 'rejected'], true)) {
                Domain::require(($status === 'cancelled' && ($customer || $actor === null)) || ($status === 'rejected' && $vendor), 'Cancellation is not allowed.');
                Domain::require(in_array($order->status, ['awaiting_payment', 'confirmed'], true), 'Order can no longer be cancelled or rejected.');
                $this->releaseStock($order);
                if ($order->payment_status === 'verified') {
                    $this->requestRefund($order, $actor, $reason ?: 'Order '.$status);
                }
            } else {
                abort_unless($vendor, 403);
                Domain::require(Vendor::findOrFail($order->vendor_id)->status === 'approved', 'Vendor is not approved.');
                $next = ['confirmed' => 'accepted', 'accepted' => 'preparing', 'preparing' => 'ready', 'ready' => 'completed'];
                Domain::require(($next[$order->status] ?? null) === $status, 'Invalid order transition.');
                Domain::require($order->payment_status === 'verified' || ($order->fulfilment === 'pickup' && $order->payment_method === 'cash'), 'Verified mobile-money payment is required.');
                Domain::require($status !== 'completed' || ($order->fulfilment === 'pickup' && $order->payment_status === 'verified'), 'Paid pickup collection must be confirmed first.');
            }
            Domain::transition($order, $status, $actor, $reason);

            return $order;
        }, 3);
    }

    public function cancelByAdministrator(Order $input, User $actor, string $status, string $reason): Order
    {
        abort_unless($actor->hasRole('admin'), 403);

        return DB::transaction(function () use ($input, $actor, $status, $reason): Order {
            $order = Order::whereKey($input->id)->lockForUpdate()->firstOrFail();
            Domain::require(in_array($status, ['cancelled', 'rejected'], true), 'Unsupported administrative transition.');
            if ($order->status === $status) {
                return $order;
            }
            Domain::require(in_array($order->status, ['awaiting_payment', 'confirmed'], true), 'Order fulfilment has already started.');
            $this->releaseStock($order);
            if ($order->payment_status === 'verified') {
                $this->requestRefund($order, $actor, $reason);
            }
            Domain::transition($order, $status, $actor, $reason);

            return $order;
        }, 3);
    }

    public function collectCash(Order $input, User $actor): Order
    {
        return DB::transaction(function () use ($input, $actor): Order {
            $order = Order::whereKey($input->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasRole('vendor') && $actor->manages($order->vendor_id), 403);
            Domain::require($order->fulfilment === 'pickup' && $order->payment_method === 'cash' && in_array($order->status, ['confirmed', 'accepted', 'preparing', 'ready', 'completed'], true), 'Cash cannot be collected for this order.');
            if ($order->cash_collected_at !== null) {
                return $order;
            }
            $order->forceFill(['payment_status' => 'verified', 'cash_collected_at' => now(), 'cash_collected_by' => $actor->id])->save();
            $this->ledger->capture($order);
            Domain::audit($actor, 'cash.collected', $order);
            Domain::emit($order);

            return $order;
        }, 3);
    }

    public function releaseStock(Order $order): void
    {
        if ($order->stock_released_at !== null) {
            return;
        }
        foreach ($order->items()->orderBy('product_id')->get() as $item) {
            Product::withTrashed()->whereKey($item->product_id)->increment('stock', $item->quantity);
        }
        $redemption = CouponRedemption::where('order_id', $order->id)->lockForUpdate()->first();
        if ($redemption && $redemption->released_at === null) {
            Coupon::whereKey($redemption->coupon_id)->decrement('used');
            $redemption->forceFill(['released_at' => now()])->save();
        }
        $order->forceFill(['stock_released_at' => now()])->save();
    }

    public function requestRefund(Order $order, ?User $actor, string $reason): Refund
    {
        $existing = Refund::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }
        Domain::require($order->payment_status === 'verified', 'Only verified payments can be refunded.');
        $refund = Refund::record(['order_id' => $order->id, 'payment_id' => $order->payment()->value('id'), 'amount' => $order->total, 'status' => 'requested', 'reason' => $reason, 'requested_by' => $actor?->id]);
        $order->forceFill(['payment_status' => 'refund_pending'])->save();
        Domain::audit($actor, 'refund.requested', $refund);
        Domain::emit($order);

        return $refund;
    }
}
