<?php

namespace App\Modules\Finance;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;

final class SettlementService
{
    public function prepare(Vendor $input, User $actor): Settlement
    {
        return DB::transaction(function () use ($input, $actor): Settlement {
            $vendor = Vendor::whereKey($input->id)->lockForUpdate()->firstOrFail();
            Domain::require(! Settlement::where('vendor_id', $vendor->id)->where('status', 'prepared')->exists(), 'This vendor already has a prepared settlement.');
            $entries = LedgerEntry::where('vendor_id', $vendor->id)->where('account', 'vendor_payable')
                ->whereHas('order', fn ($q) => $q->whereIn('status', ['completed', 'delivered', 'cancelled', 'rejected'])->whereIn('payment_status', ['verified', 'refunded']))
                ->whereNotIn('id', SettlementEntry::select('ledger_entry_id')->whereHas('settlement', fn ($q) => $q->where('status', '!=', 'voided')))->orderBy('id')->limit(10001)->lockForUpdate()->get();
            Domain::require($entries->isNotEmpty() && $entries->count() <= 10000, 'No eligible entries, or settlement requires an operator to reduce the batch size.');
            $amount = (int) $entries->sum('amount');
            Domain::require($amount > 0, 'Vendor balance is not positive; cash commission debt is carried forward.');
            $settlement = Settlement::record(['vendor_id' => $vendor->id, 'amount' => $amount, 'status' => 'prepared']);
            foreach ($entries as $entry) {
                SettlementEntry::record(['settlement_id' => $settlement->id, 'ledger_entry_id' => $entry->id]);
            }
            Domain::audit($actor, 'settlement.prepared', $settlement);

            return $settlement;
        }, 3);
    }

    public function paid(Settlement $input, User $actor, string $reference): Settlement
    {
        return DB::transaction(function () use ($input, $actor, $reference): Settlement {
            $orderIds = LedgerEntry::whereIn('id', $input->entries()->select('ledger_entry_id'))->pluck('order_id')->unique();
            Order::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
            $settlement = Settlement::whereKey($input->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status === 'paid') {
                Domain::require($settlement->external_reference === $reference, 'Settlement was recorded with another receipt.');

                return $settlement;
            }
            Domain::require($settlement->status === 'prepared', 'Settlement is not payable.');
            Domain::require(! DB::table('settlement_entries')->join('ledger_entries', 'ledger_entries.id', '=', 'settlement_entries.ledger_entry_id')->join('orders', 'orders.id', '=', 'ledger_entries.order_id')->where('settlement_entries.settlement_id', $settlement->id)->where('orders.payment_status', 'refund_pending')->exists(), 'Settlement contains a pending refund.');
            Domain::require(! LedgerEntry::whereIn('order_id', $orderIds)->where('account', 'vendor_payable')->whereNotIn('id', SettlementEntry::select('ledger_entry_id')->whereHas('settlement', fn ($q) => $q->where('status', '!=', 'voided')))->exists(), 'Financial entries changed after preparation. Void and prepare the settlement again.');
            $settlement->forceFill(['status' => 'paid', 'external_reference' => $reference, 'paid_at' => now(), 'paid_by' => $actor->id])->save();
            Domain::audit($actor, 'settlement.paid', $settlement, ['external_reference' => $reference]);

            return $settlement;
        }, 3);
    }

    public function void(Settlement $input, User $actor): Settlement
    {
        return DB::transaction(function () use ($input, $actor): Settlement {
            Vendor::whereKey($input->vendor_id)->lockForUpdate()->firstOrFail();
            $settlement = Settlement::whereKey($input->id)->lockForUpdate()->firstOrFail();
            Domain::require(in_array($settlement->status, ['prepared', 'voided'], true), 'Paid settlements cannot be voided.');
            $settlement->forceFill(['status' => 'voided'])->save();
            Domain::audit($actor, 'settlement.voided', $settlement);

            return $settlement;
        }, 3);
    }
}
