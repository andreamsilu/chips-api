<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

final class ProductPolicy
{
    public function update(User $user, Product $product): bool
    {
        return $user->hasRole('vendor') && $product->location()->whereHas('vendor', fn ($q) => $q->where('status', 'approved')->whereHas('members', fn ($m) => $m->where('users.id', $user->id)))->exists();
    }
}
