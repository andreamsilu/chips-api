<?php

namespace App\Modules\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class OrderChanged implements ShouldBroadcastNow
{
    public function __construct(public string $eventId, public array $payload, private array $channels) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $channel) => new PrivateChannel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    public function broadcastWith(): array
    {
        return ['event_id' => $this->eventId] + $this->payload;
    }
}
