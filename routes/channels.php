<?php

use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('customers.{publicId}', fn (User $user, string $publicId) => $user->suspended_at === null && $user->public_id === $publicId);
Broadcast::channel('vendors.{vendorId}', fn (User $user, string $vendorId) => $user->hasRole('vendor') && $user->manages($vendorId));
Broadcast::channel('riders.{riderId}', fn (User $user, string $riderId) => $user->hasRole('rider') && Rider::whereKey($riderId)->where('user_id', $user->id)->where('status', 'approved')->exists());
