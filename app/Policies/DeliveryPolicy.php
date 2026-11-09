<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;

final class DeliveryPolicy
{
    public function update(User $user, Delivery $delivery): bool
    {
        return $user->hasRole('rider') && $delivery->rider()->where('user_id', $user->id)->where('status', 'approved')->exists();
    }
}
