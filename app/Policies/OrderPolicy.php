<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->suspended_at === null && ($order->user_id === $user->id || $user->manages($order->vendor_id) || $user->hasRole('admin'));
    }

    public function update(User $user, Order $order): bool
    {
        return $user->hasRole('vendor') && $user->manages($order->vendor_id);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->suspended_at === null && $order->user_id === $user->id;
    }
}
