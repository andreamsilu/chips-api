<?php

namespace App\Modules\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class OrderRepository
{
    public function forCustomer(User $user): Builder
    {
        return Order::query()->where('user_id', $user->id);
    }

    public function forVendor(User $user): Builder
    {
        return Order::query()->whereIn('vendor_id', function ($query) use ($user): void {
            $query->select('vendor_id')->from('vendor_members')->where('user_id', $user->id);
        })->where('status', '!=', 'awaiting_payment');
    }
}
