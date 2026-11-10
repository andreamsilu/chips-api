<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

final class MarketplaceSettings
{
    public function integer(string $key, int $fallback): int
    {
        return (int) Cache::remember('marketplace-setting:'.$key, 60, fn () => SystemSetting::whereKey($key)->value('value') ?? $fallback);
    }
}
