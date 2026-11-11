<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\DeliveryPricingRule;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AdminSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $saved = SystemSetting::all()->keyBy('key');
        $defaults = ['delivery_base_fee' => (int) config('marketplace.delivery_base_fee'), 'delivery_per_km' => (int) config('marketplace.delivery_per_km'), 'support_email' => null, 'support_phone' => null, 'support_hours' => null];
        $data = [];
        foreach ($defaults as $key => $default) {
            $row = $saved->get($key);
            $data[] = ['id' => $key, 'key' => $key, 'value' => $row->value ?? $default, 'version' => $row->version ?? 0, 'updated_at' => $row?->updated_at];
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $rules = match ($key) {
            'delivery_base_fee', 'delivery_per_km' => ['required', 'integer', 'between:0,10000000'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'regex:/^\+255[67]\d{8}$/'],
            'support_hours' => ['nullable', 'string', 'max:500'],
            default => abort(404),
        };
        $data = AdminQuery::body($request, ['value' => ['present', ...$rules], 'version' => ['required', 'integer', 'min:0']]);
        $model = DB::transaction(function () use ($request, $key, $data): SystemSetting {
            Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $pricing = in_array($key, ['delivery_base_fee', 'delivery_per_km'], true) ? DeliveryPricingRule::where('scope_key', 'global')->lockForUpdate()->first() : null;
            $model = SystemSetting::whereKey($key)->lockForUpdate()->first() ?? new SystemSetting;
            Domain::require((int) ($model->version ?? 0) === $data['version'], 'Setting changed. Refresh before saving again.');
            $model->forceFill(['key' => $key, 'value' => $data['value'], 'version' => $data['version'] + 1, 'updated_by' => $request->user()->id])->save();
            if ($pricing) {
                $field = $key === 'delivery_base_fee' ? 'base_fee' : 'price_per_km';
                $pricing->forceFill([$field => $data['value'], 'version' => $pricing->version + 1, 'updated_by' => $request->user()->id])->save();
            }
            Domain::audit($request->user(), 'setting.updated', $model, ['version' => $model->version]);

            return $model;
        }, 3);
        Cache::forget('marketplace-setting:'.$key);

        return response()->json(['data' => ['id' => $key, 'key' => $key, 'value' => $model->value, 'version' => $model->version, 'updated_at' => $model->updated_at]]);
    }
}
