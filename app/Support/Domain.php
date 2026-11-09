<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Modules\Notifications\PublishOutbox;
use Illuminate\Support\Facades\DB;

final class Domain
{
    public static function require(bool $condition, string $message): void
    {
        abort_unless($condition, 409, $message);
    }

    public static function audit(?User $actor, string $action, DomainModel $subject, array $metadata = []): void
    {
        AuditLog::record(['actor_id' => $actor?->id, 'action' => $action, 'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(), 'metadata' => $metadata]);
    }

    public static function transition(Order $order, string $status, ?User $actor, ?string $reason = null): void
    {
        $from = $order->status;
        $order->forceFill(['status' => $status])->save();
        OrderHistory::record(['order_id' => $order->id, 'actor_id' => $actor?->id, 'from_status' => $from, 'to_status' => $status, 'reason' => $reason]);
        self::emit($order);
        self::audit($actor, 'order.'.$status, $order, ['from_status' => $from, 'reason' => $reason]);
    }

    public static function emit(Order $order): void
    {
        $message = OutboxMessage::record(['order_id' => $order->id, 'payload' => ['order_id' => $order->id, 'status' => $order->status, 'payment_status' => $order->payment_status]]);
        DB::afterCommit(function () use ($message): void {
            try {
                PublishOutbox::dispatch($message->id)->onQueue('notifications');
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
