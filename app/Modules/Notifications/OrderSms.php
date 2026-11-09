<?php

namespace App\Modules\Notifications;

use App\Jobs\SendOrderSms;
use App\Models\Order;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class OrderSms
{
    public static function stage(OutboxMessage $message, Order $order): void
    {
        if (! config('services.nextsms.enabled') || $message->payload['status'] !== $order->status || $message->payload['payment_status'] !== $order->payment_status) {
            return;
        }
        $recipient = ltrim((string) $order->customer->phone, '+');
        if (! preg_match('/^255[67]\d{8}$/', $recipient)) {
            return;
        }
        $status = str_replace('_', ' ', (string) $message->payload['status']);
        $payment = match ($message->payload['payment_status']) {
            'verified' => 'Payment received.',
            'refunded' => 'Payment refunded.',
            'refund_pending' => 'Refund pending.',
            'failed' => 'Payment failed.',
            default => in_array($message->payload['status'], ['cancelled', 'rejected'], true)
                ? 'No further payment authorization is needed.'
                : ($order->payment_method === 'cash' ? 'Cash payable at pickup.' : 'Payment pending. Authorize on your phone.'),
        };
        DB::table('sms_deliveries')->insertOrIgnore([
            'id' => $message->id, 'outbox_message_id' => $message->id,
            'recipient' => Crypt::encryptString($recipient),
            'body' => Crypt::encryptString('MloExpress order '.$order->id.': '.$status.'. '.$payment),
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(function () use ($message): void {
            try {
                SendOrderSms::dispatch($message->id)->onQueue('notifications');
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
