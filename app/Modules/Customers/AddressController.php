<?php

namespace App\Modules\Customers;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Address::where('user_id', $request->user()->id)->orderBy('id')->get(['id', 'label', 'details', 'latitude', 'longitude'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $address = Address::record($this->validated($request) + ['user_id' => $request->user()->id]);

        return response()->json(['data' => $address->only(['id', 'label', 'details', 'latitude', 'longitude'])], 201);
    }

    public function update(Request $request, string $address): JsonResponse
    {
        $model = Address::where('user_id', $request->user()->id)->findOrFail($address);
        Gate::authorize('update', $model);
        $model->forceFill($this->validated($request))->save();

        return response()->json(['data' => $model->only(['id', 'label', 'details', 'latitude', 'longitude'])]);
    }

    public function destroy(Request $request, string $address): JsonResponse
    {
        $model = Address::where('user_id', $request->user()->id)->findOrFail($address);
        Gate::authorize('delete', $model);
        $model->delete();

        return response()->json(['message' => 'Address removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate(['label' => ['required', 'string', 'max:80'], 'details' => ['required', 'string', 'max:500'], 'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'user_id' => ['prohibited']]);
    }
}
