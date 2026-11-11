<?php

namespace App\Modules\Vendors;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLocation;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vendors = Vendor::whereHas('members', fn ($q) => $q->where('users.id', $request->user()->id))->with('locations')->orderBy('id')->paginate(20);

        return response()->json($vendors);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'status' => ['prohibited'], 'commission_bps' => ['prohibited'], 'approved_by' => ['prohibited'], 'location' => ['sometimes', 'array:name,address,latitude,longitude,google_place_id'], 'location.name' => ['required_with:location', 'string', 'max:160'], 'location.address' => ['required_with:location', 'string', 'max:500'], 'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'], 'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'], 'location.google_place_id' => ['nullable', 'string', 'max:255']]);
        $vendor = DB::transaction(function () use ($request, $data): Vendor {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            Domain::require(DB::table('vendor_members')->where('user_id', $request->user()->id)->count() < 10, 'Vendor application limit reached.');
            $vendor = Vendor::record(['name' => $data['name'], 'status' => 'pending']);
            $vendor->members()->attach($request->user()->id);
            if (isset($data['location'])) {
                VendorLocation::record($data['location'] + ['vendor_id' => $vendor->id, 'delivery_radius_km' => min(50, (int) ceil(config('maps.default_maximum_distance_meters') / 1000)), 'accepting_orders' => false, 'delivery_enabled' => true, 'pickup_enabled' => true]);
            }
            $request->user()->roles()->syncWithoutDetaching([Role::where('name', 'vendor')->value('id')]);
            Domain::audit($request->user(), 'vendor.applied', $vendor);

            return $vendor;
        });

        return response()->json(['data' => $vendor], 201);
    }

    public function location(Request $request, Vendor $vendor, ?string $location = null): JsonResponse
    {
        abort_unless($request->user()->manages($vendor->id), 404);
        Gate::authorize('update', $vendor);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'address' => ['required', 'string', 'max:500'], 'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'accepting_orders' => ['required', 'boolean'], 'delivery_enabled' => ['required', 'boolean'], 'pickup_enabled' => ['required', 'boolean'], 'delivery_radius_km' => ['required', 'integer', 'between:1,50'], 'vendor_id' => ['prohibited'], 'google_place_id' => ['nullable', 'string', 'max:255'], 'location_verified_at' => ['prohibited'], 'location_verification_status' => ['prohibited']]);
        if ($location) {
            $model = $vendor->locations()->findOrFail($location);
            $model->forceFill($data);
            if ($model->isDirty(['latitude', 'longitude', 'address', 'google_place_id'])) {
                $model->forceFill(['location_verification_status' => 'pending', 'location_verified_at' => null, 'location_verified_by' => null]);
            }
            $model->save();
        } else {
            $model = VendorLocation::record($data + ['vendor_id' => $vendor->id]);
        }
        Domain::audit($request->user(), 'location.saved', $model);

        return response()->json(['data' => $model], $location ? 200 : 201);
    }
}
