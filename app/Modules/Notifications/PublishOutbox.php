<?php

namespace App\Modules\Notifications;

use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class PublishOutbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(public string $id) {}

    public function uniqueId(): string
    {
        return $this->id;
    }

    public function handle(): void
    {
        $message = OutboxMessage::findOrFail($this->id);
        if ($message->published_at !== null) {
            return;
        }
        $order = Order::with(['customer', 'delivery.rider'])->findOrFail($message->order_id);
        $channels = ['customers.'.$order->customer->public_id];
        if ($order->status !== 'awaiting_payment') {
            $channels[] = 'vendors.'.$order->vendor_id;
        }
        if ($order->delivery?->rider_id) {
            $channels[] = 'riders.'.$order->delivery->rider_id;
        }
        event(new OrderChanged($message->id, $message->payload, $channels));
        DB::table('notifications')->insertOrIgnore(['id' => $message->id, 'type' => 'order.changed', 'notifiable_type' => User::class, 'notifiable_id' => $order->user_id, 'data' => json_encode(['event_id' => $message->id] + $message->payload), 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(function () use ($message, $order): void {
            OrderSms::stage($message, $order);
            $message->forceFill(['published_at' => now()])->save();
        });
    }
}
