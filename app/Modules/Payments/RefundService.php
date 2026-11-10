<?php

namespace App\Modules\Payments;

use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Modules\Finance\LedgerService;
use App\Modules\Orders\OrderService;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;

final class RefundService
{
    public function __construct(private OrderService $orders, private LedgerService $ledger) {}

    public function request(Order $input, User $actor, string $reason): Refund
    {
        return DB::transaction(function () use ($input, $actor, $reason): Refund {
            $order = Order::whereKey($input->id)->lockForUpdate()->firstOrFail();
            Domain::require(in_array($order->status, ['cancelled', 'rejected', 'completed', 'delivered'], true), 'Cancel the order or finish fulfilment before requesting a refund.');

            return $this->orders->requestRefund($order, $actor, $reason);
        }, 3);
    }

    public function approve(Refund $input, User $actor, ?string $cashReference): Refund
    {
        return DB::transaction(function () use ($input, $actor, $cashReference): Refund {
            $order = Order::whereKey($input->order_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::whereKey($input->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasRole('admin'), 403);
            if (in_array($refund->status, ['refunded', 'approved'], true)) {
                return $refund;
            }
            $manual = $order->payment_method === 'cash' || in_array($refund->status, ['manual_required', 'failed', 'reversed'], true);
            Domain::require($cashReference === null || $manual, 'Process this refund first; a manual receipt is only valid for manual refunds.');
            if ($manual && $cashReference !== null) {
                $refund->forceFill(['status' => 'refunded', 'approved_by' => $actor->id, 'external_receipt' => $cashReference])->save();
                $order->forceFill(['payment_status' => 'refunded'])->save();
                $this->ledger->reverse($order);
            } elseif ($manual || $refund->amount < 5000 || ! config('marketplace.payouts_enabled')) {
                $refund->forceFill(['status' => 'manual_required', 'approved_by' => $actor->id])->save();
            } else {
                $payment = $refund->payment()->firstOrFail();
                $payer = $payment->request_payload['customer'];
                $payload = ['amount' => $refund->amount, 'channel' => 'mobile', 'recipient_phone' => ltrim($payment->phone, '+'), 'recipient_name' => $payer['firstname'].' '.$payer['lastname'], 'narration' => 'MloExpress refund '.$refund->id, 'webhook_url' => $payment->request_payload['webhook_url'], 'metadata' => ['order_id' => $order->id, 'refund_id' => $refund->id]];
                $refund->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'request_payload' => $payload])->save();
            }
            Domain::audit($actor, 'refund.approved', $refund);
            Domain::emit($order);

            return $refund;
        }, 3);
    }
}
