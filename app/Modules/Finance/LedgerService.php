<?php

namespace App\Modules\Finance;

use App\Models\LedgerEntry;
use App\Models\Order;

final class LedgerService
{
    public function capture(Order $order): void
    {
        if (LedgerEntry::where('order_id', $order->id)->where('kind', 'capture')->exists()) {
            return;
        }
        $net = $order->subtotal - $order->discount;
        $vendorDelivery = data_get($order->address_snapshot, 'delivery.fee_beneficiary') === 'vendor' ? $order->delivery_fee : 0;
        $accounts = $order->payment_method === 'cash'
            ? ['vendor_payable' => -$order->commission, 'platform_commission' => $order->commission]
            : ['provider_clearing' => -$order->total, 'vendor_payable' => $net - $order->commission + $vendorDelivery, 'platform_commission' => $order->commission, 'delivery_revenue' => $order->delivery_fee - $vendorDelivery];
        foreach ($accounts as $account => $amount) {
            LedgerEntry::record(['order_id' => $order->id, 'vendor_id' => $order->vendor_id, 'account' => $account, 'kind' => 'capture', 'amount' => $amount, 'currency' => 'TZS']);
        }
    }

    public function reverse(Order $order): void
    {
        $kind = LedgerEntry::where('order_id', $order->id)->where('kind', 'refund_reversed')->exists() ? 'manual_refund' : 'refund';
        if (LedgerEntry::where('order_id', $order->id)->where('kind', $kind)->exists()) {
            return;
        }
        foreach (LedgerEntry::where('order_id', $order->id)->where('kind', 'capture')->get() as $entry) {
            LedgerEntry::record(['order_id' => $order->id, 'vendor_id' => $order->vendor_id, 'account' => $entry->account, 'kind' => $kind, 'amount' => -$entry->amount, 'currency' => 'TZS']);
        }
    }

    public function reverseRefund(Order $order): void
    {
        if (LedgerEntry::where('order_id', $order->id)->where('kind', 'refund_reversed')->exists()) {
            return;
        }
        foreach (LedgerEntry::where('order_id', $order->id)->where('kind', 'refund')->get() as $entry) {
            LedgerEntry::record(['order_id' => $order->id, 'vendor_id' => $order->vendor_id, 'account' => $entry->account, 'kind' => 'refund_reversed', 'amount' => -$entry->amount, 'currency' => 'TZS']);
        }
    }
}
