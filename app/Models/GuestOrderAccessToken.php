<?php

namespace App\Models;

use App\Support\DomainModel;

class GuestOrderAccessToken extends DomainModel
{
    protected $hidden = ['token_hash', 'token_ciphertext'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'token_ciphertext' => 'encrypted'];
    }
}
