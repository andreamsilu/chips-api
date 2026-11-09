<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

final class AddressPolicy
{
    public function update(User $user, Address $address): bool
    {
        return $user->suspended_at === null && $user->id === $address->user_id;
    }

    public function delete(User $user, Address $address): bool
    {
        return $this->update($user, $address);
    }
}
