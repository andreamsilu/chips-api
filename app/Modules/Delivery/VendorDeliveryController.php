<?php

namespace App\Modules\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Rider;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Modules\Administration\AdminQuery;
use App\Support\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

final class VendorDeliveryController extends Controller
{
    private function authorizeVendor(Request $request, Vendor $vendor): void
    {
        abort_unless($request->user()->manages($vendor->id), 404);
        Gate::authorize('update', $vendor);
    }

    public static function riderData(Rider $rider): array
    {
        return $rider->only(['id', 'vendor_id', 'vehicle', 'status', 'available', 'created_at']) + ['name' => $rider->user->name, 'email' => $rider->user->email, 'phone' => $rider->user->phone];
    }

    public static function deliveryData(Delivery $delivery): array
    {
        return $delivery->only(['id', 'order_id', 'rider_id', 'status', 'picked_up_at', 'delivered_at', 'created_at']) + ['order_status' => $delivery->order->status, 'payment_status' => $delivery->order->payment_status, 'destination' => $delivery->order->address_snapshot, 'rider' => $delivery->rider ? self::riderData($delivery->rider) : null];
    }

    public function riders(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);

        return response()->json(AdminQuery::page($request, Rider::with('user')->where('vendor_id', $vendor->id), ['riders.id', 'vehicle', fn (Builder $q, string $term) => $q->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)->orWhere('phone', 'like', $term))], ['status' => AdminQuery::enum(['approved', 'suspended']), 'available' => AdminQuery::boolean()], [], fn (Rider $rider): array => self::riderData($rider)));
    }

    public function storeRider(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);
        $data = AdminQuery::body($request, ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'phone' => ['required', 'regex:/^\+255[67]\d{8}$/', 'unique:users,phone'], 'vehicle' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(12)->mixedCase()->numbers()], 'password_confirmation' => ['required', 'string']]);
        $rider = DB::transaction(function () use ($request, $vendor, $data): Rider {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $locked);
            $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $user->forceFill(['phone' => $data['phone']])->save();
            $user->roles()->attach(Role::where('name', 'rider')->firstOrFail()->id);
            $rider = Rider::record(['vendor_id' => $vendor->id, 'user_id' => $user->id, 'vehicle' => $data['vehicle'], 'status' => 'approved', 'available' => true]);
            Domain::audit($request->user(), 'vendor.rider.created', $rider, ['vendor_id' => $vendor->id]);

            return $rider;
        }, 3);

        return response()->json(['data' => self::riderData($rider->load('user'))], 201);
    }

    public function updateRider(Request $request, Vendor $vendor, Rider $rider): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);
        abort_unless($rider->vendor_id === $vendor->id, 404);
        $data = AdminQuery::body($request, ['vehicle' => ['sometimes', 'required', 'string', 'max:100'], 'available' => ['sometimes', 'boolean'], 'status' => ['sometimes', 'in:approved,suspended']]);
        $target = DB::transaction(function () use ($request, $rider, $vendor, $data): Rider {
            $lockedVendor = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $lockedVendor);
            $model = Rider::where('vendor_id', $vendor->id)->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            if (($data['status'] ?? null) === 'suspended') {
                Domain::require(! $model->deliveries()->whereIn('status', ['assigned', 'picked_up'])->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'rejected', 'delivered']))->exists(), 'Reassign or finish active deliveries before suspending this rider.');
            }
            $model->forceFill($data)->save();
            Domain::audit($request->user(), 'vendor.rider.updated', $model, $data);

            return $model;
        }, 3);

        return response()->json(['data' => self::riderData($target->load('user'))]);
    }

    public function deliveries(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);
        $query = Delivery::with(['order', 'rider.user'])->whereHas('order', fn ($q) => $q->where('vendor_id', $vendor->id));

        return response()->json(AdminQuery::page($request, $query, ['deliveries.id', 'order_id'], ['status' => AdminQuery::enum(['unassigned', 'assigned', 'picked_up', 'delivered']), 'rider_id' => AdminQuery::identifier(), 'order_id' => AdminQuery::identifier()], [], fn (Delivery $delivery): array => self::deliveryData($delivery)));
    }

    public function assign(Request $request, Vendor $vendor, Delivery $delivery, DeliveryService $service): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);
        abort_unless($delivery->order()->where('vendor_id', $vendor->id)->exists(), 404);
        $data = AdminQuery::body($request, ['rider_id' => ['required', 'ulid']]);
        $rider = Rider::where('vendor_id', $vendor->id)->findOrFail($data['rider_id']);
        $model = $service->assign($delivery, $rider, $request->user());

        return response()->json(['data' => self::deliveryData($model->load(['order', 'rider.user']))]);
    }

    public function update(Request $request, Vendor $vendor, Delivery $delivery, DeliveryService $service): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);
        abort_unless($delivery->order()->where('vendor_id', $vendor->id)->exists(), 404);
        $data = AdminQuery::body($request, ['status' => ['required', 'in:picked_up,delivered']]);

        return response()->json(['data' => self::deliveryData($service->transition($delivery, $request->user(), $data['status'])->load(['order', 'rider.user']))]);
    }
}
