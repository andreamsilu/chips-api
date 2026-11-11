<?php

namespace App\Modules\Customers;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\User;
use App\Modules\Administration\AdminQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AddressController extends Controller
{
    private const FIELDS = ['id', 'label', 'details', 'latitude', 'longitude', 'formatted_address', 'google_place_id', 'landmark', 'delivery_instructions', 'is_default'];

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Address::where('user_id', $request->user()->id)->orderByDesc('is_default')->orderBy('id')->get(self::FIELDS)]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, string $address): JsonResponse
    {
        return $this->save($request, $address);
    }

    private function save(Request $request, ?string $id): JsonResponse
    {
        $data = AdminQuery::body($request, ['label' => ['required', 'string', 'max:80'], 'details' => ['required', 'string', 'max:500'], 'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'formatted_address' => ['nullable', 'string', 'max:500'], 'google_place_id' => ['nullable', 'string', 'max:255'], 'landmark' => ['nullable', 'string', 'max:255'], 'delivery_instructions' => ['nullable', 'string', 'max:500'], 'is_default' => ['sometimes', 'boolean']]);
        $model = DB::transaction(function () use ($request, $id, $data): Address {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $model = $id ? Address::where('user_id', $request->user()->id)->findOrFail($id) : new Address;
            $hasDefault = Address::where('user_id', $request->user()->id)->where('is_default', true)->exists();
            $default = (bool) ($data['is_default'] ?? $model->is_default ?? false) || ! $hasDefault;
            if ($default) {
                Address::where('user_id', $request->user()->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $model->forceFill($data + ['user_id' => $request->user()->id]);
            $model->is_default = $default;
            $model->save();

            return $model;
        }, 3);

        return response()->json(['data' => $model->only(self::FIELDS)], $id ? 200 : 201);
    }

    public function destroy(Request $request, string $address): JsonResponse
    {
        DB::transaction(function () use ($request, $address): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $model = Address::where('user_id', $request->user()->id)->findOrFail($address);
            $default = $model->is_default;
            $model->delete();
            if ($default) {
                Address::where('user_id', $request->user()->id)->orderBy('id')->first()?->forceFill(['is_default' => true])->save();
            }
        }, 3);

        return response()->json(['message' => 'Address removed.']);
    }
}
