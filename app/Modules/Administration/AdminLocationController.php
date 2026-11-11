<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\DeliveryPricingRule;
use App\Models\DeliveryZone;
use App\Models\SystemSetting;
use App\Models\VendorLocation;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AdminLocationController extends Controller
{
    public static function serialize(DeliveryPricingRule $rule): array
    {
        return $rule->only(['id', 'vendor_id', 'pricing_method', 'fixed_fee', 'base_fee', 'price_per_km', 'minimum_fee', 'maximum_distance_meters', 'active', 'version', 'created_at']) + ['zones' => $rule->zones->map(fn (DeliveryZone $zone) => $zone->only(['id', 'delivery_pricing_rule_id', 'name', 'maximum_distance_meters', 'fee', 'active', 'created_at']))];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, DeliveryPricingRule::with('zones'), ['delivery_pricing_rules.id', 'scope_key'], ['vendor_id' => AdminQuery::identifier(), 'active' => AdminQuery::boolean()], [], fn (DeliveryPricingRule $rule): array => self::serialize($rule)));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, DeliveryPricingRule $rule): JsonResponse
    {
        return $this->save($request, $rule);
    }

    private function save(Request $request, ?DeliveryPricingRule $input): JsonResponse
    {
        $rules = ['pricing_method' => ['required', 'in:fixed,per_km,zones'], 'fixed_fee' => ['required', 'integer', 'between:0,10000000'], 'base_fee' => ['required', 'integer', 'between:0,10000000'], 'price_per_km' => ['required', 'integer', 'between:0,10000000'], 'minimum_fee' => ['required', 'integer', 'between:0,10000000'], 'maximum_distance_meters' => ['required', 'integer', 'between:1,50000'], 'active' => ['required', 'boolean']];
        if ($input === null) {
            $rules['vendor_id'] = ['required', 'ulid', 'exists:vendors,id', 'unique:delivery_pricing_rules,vendor_id'];
        } else {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }
        $data = AdminQuery::body($request, $rules);
        $model = DB::transaction(function () use ($request, $input, $data): DeliveryPricingRule {
            if ($input) {
                $model = DeliveryPricingRule::whereKey($input->id)->lockForUpdate()->firstOrFail();
                Domain::require($model->version === $data['version'], 'Pricing changed. Refresh before saving.');
            } else {
                $model = new DeliveryPricingRule;
                $model->scope_key = 'vendor:'.$data['vendor_id'];
            }
            $model->forceFill(collect($data)->except('version')->all());
            $model->version = ($input ? $model->version : 0) + 1;
            $model->updated_by = $request->user()->id;
            $model->save();
            if ($model->scope_key === 'global') {
                foreach (['delivery_base_fee' => 'base_fee', 'delivery_per_km' => 'price_per_km'] as $key => $field) {
                    $setting = SystemSetting::whereKey($key)->lockForUpdate()->first() ?? new SystemSetting;
                    $setting->forceFill(['key' => $key, 'value' => $model->$field, 'version' => (int) ($setting->version ?? 0) + 1, 'updated_by' => $request->user()->id])->save();
                    Cache::forget('marketplace-setting:'.$key);
                }
            }
            Domain::audit($request->user(), 'delivery_pricing.saved', $model, ['version' => $model->version]);

            return $model;
        }, 3);

        return response()->json(['data' => self::serialize($model->load('zones'))], $input ? 200 : 201);
    }

    public function storeZone(Request $request, DeliveryPricingRule $rule): JsonResponse
    {
        return $this->saveZone($request, $rule, null);
    }

    public function updateZone(Request $request, DeliveryZone $zone): JsonResponse
    {
        return $this->saveZone($request, DeliveryPricingRule::findOrFail($zone->delivery_pricing_rule_id), $zone);
    }

    private function saveZone(Request $request, DeliveryPricingRule $rule, ?DeliveryZone $zone): JsonResponse
    {
        $data = AdminQuery::body($request, ['name' => ['required', 'string', 'max:100'], 'maximum_distance_meters' => ['required', 'integer', 'between:1,50000'], 'fee' => ['required', 'integer', 'between:0,10000000'], 'active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $rule, $zone, $data): DeliveryZone {
            $parent = DeliveryPricingRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            Domain::require($parent->version === $data['version'], 'Pricing changed. Refresh before saving.');
            Domain::require($data['maximum_distance_meters'] <= $parent->maximum_distance_meters, 'Zone exceeds the maximum delivery distance.');
            Domain::require(! DeliveryZone::where('delivery_pricing_rule_id', $parent->id)->where('maximum_distance_meters', $data['maximum_distance_meters'])->when($zone, fn ($q) => $q->where('id', '!=', $zone->id))->exists(), 'A zone already uses this distance boundary.');
            $model = $zone ? DeliveryZone::whereKey($zone->id)->lockForUpdate()->firstOrFail() : new DeliveryZone;
            $model->forceFill(collect($data)->except('version')->all() + ['delivery_pricing_rule_id' => $parent->id])->save();
            $parent->increment('version');
            Domain::audit($request->user(), 'delivery_zone.saved', $model);

            return $model;
        }, 3);

        return response()->json(['data' => $model], $zone ? 200 : 201);
    }

    public function verify(Request $request, VendorLocation $location): JsonResponse
    {
        $data = AdminQuery::body($request, ['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180']]);
        $model = DB::transaction(function () use ($request, $location, $data): VendorLocation {
            $model = VendorLocation::whereKey($location->id)->lockForUpdate()->firstOrFail();
            Domain::require($model->latitude !== null && $model->longitude !== null && abs((float) $model->latitude) <= 90 && abs((float) $model->longitude) <= 180, 'Valid coordinates are required before verification.');
            Domain::require(abs((float) $model->latitude - (float) $data['latitude']) < 0.00000005 && abs((float) $model->longitude - (float) $data['longitude']) < 0.00000005, 'Coordinates changed. Refresh before verifying.');
            $model->forceFill(['location_verification_status' => 'verified', 'location_verified_at' => now(), 'location_verified_by' => $request->user()->id])->save();
            Domain::audit($request->user(), 'location.verified', $model);

            return $model;
        }, 3);

        return response()->json(['data' => AdminData::row($model->load('vendor'))]);
    }
}
