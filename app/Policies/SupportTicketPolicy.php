<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

final class SupportTicketPolicy
{
    public function view(User $user, SupportTicket $ticket): bool
    {
        return $user->suspended_at === null && ($user->id === $ticket->user_id || $user->hasRole('support') || $user->hasRole('admin'));
    }
}
