<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeliveryPolicy
{
    public function update(User $user, Delivery $delivery): bool
    {
        if ($user->hasRole('vendor') && $delivery->order()->whereHas('vendor', fn ($q) => $q->where('status', 'approved'))->whereIn('vendor_id', DB::table('vendor_members')->where('user_id', $user->id)->select('vendor_id'))->exists()) {
            return true;
        }

        return $user->hasRole('rider') && $delivery->rider()->where('user_id', $user->id)->where('status', 'approved')->exists();
    }
}
