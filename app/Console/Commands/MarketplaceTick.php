<?php

namespace App\Console\Commands;

use App\Jobs\SendOrderSms;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Payment;
use App\Models\Refund;
use App\Modules\Notifications\PublishOutbox;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\PaymentOperation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketplaceTick extends Command
{
    protected $signature = 'marketplace:tick';

    protected $description = 'Dispatch durable outbox and payment work and expire unpaid reservations';

    public function handle(OrderService $orders): int
    {
        foreach (Order::where('status', 'awaiting_payment')->where('payment_expires_at', '<=', now())->orderBy('id')->limit(200)->get() as $order) {
            $orders->expire($order->id);
        }
        foreach (Payment::where('status', 'pending')->whereNull('submitted_at')->whereHas('order', fn ($q) => $q->where('status', 'awaiting_payment'))->orderBy('id')->limit(200)->get() as $payment) {
            PaymentOperation::dispatch($payment->id)->onQueue('payments');
        }
        foreach (Refund::where('status', 'approved')->whereNull('submitted_at')->orderBy('id')->limit(200)->get() as $refund) {
            PaymentOperation::dispatch($refund->id, 'refund')->onQueue('payments');
        }
        foreach (Payment::whereNotNull('provider_reference')->where('updated_at', '<', now()->subMinute())->where(function ($q) {
            $q->where('status', 'pending')->orWhereHas('order.refund', fn ($r) => $r->where('status', 'approved')->orWhere(fn ($recent) => $recent->where('status', 'refunded')->where('updated_at', '>=', now()->subDays(7))));
        })->orderBy('updated_at')->limit(200)->get() as $payment) {
            PaymentOperation::dispatch($payment->id, 'reconcile')->onQueue('payments');
        }
        foreach (OutboxMessage::whereNull('published_at')->orderBy('id')->limit(200)->get() as $message) {
            PublishOutbox::dispatch($message->id)->onQueue('notifications');
        }

        if (config('services.nextsms.enabled')) {
            foreach (DB::table('sms_deliveries')->where('status', 'queued')->where('updated_at', '<=', now()->subMinute())->orderBy('created_at')->limit(200)->get(['id']) as $delivery) {
                SendOrderSms::dispatch($delivery->id)->onQueue('notifications');
            }
        }

        return self::SUCCESS;
    }
}
