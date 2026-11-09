<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

final class VendorPolicy
{
    public function update(User $user, Vendor $vendor): bool
    {
        return $user->hasRole('vendor') && $user->manages($vendor->id) && $vendor->status === 'approved';
    }
}
