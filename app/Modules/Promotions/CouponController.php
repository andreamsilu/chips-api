<?php

namespace App\Modules\Promotions;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Vendor;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CouponController extends Controller
{
    public function index(Request $request, Vendor $vendor): JsonResponse
    {
        abort_unless($request->user()->manages($vendor->id), 404);
        Gate::authorize('update', $vendor);

        return response()->json(Coupon::where('vendor_id', $vendor->id)->orderByDesc('id')->paginate(20));
    }

    public function store(Request $request, Vendor $vendor): JsonResponse
    {
        abort_unless($request->user()->manages($vendor->id), 404);
        Gate::authorize('update', $vendor);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z0-9_-]{3,40}$/', 'unique:coupons'], 'discount' => ['required', 'integer', 'between:1,10000000'], 'minimum_subtotal' => ['required', 'integer', 'between:0,10000000'], 'usage_limit' => ['required', 'integer', 'between:1,1000000'], 'starts_at' => ['required', 'date'], 'expires_at' => ['required', 'date', 'after:starts_at']]);
        $coupon = Coupon::record($data + ['vendor_id' => $vendor->id]);
        Domain::audit($request->user(), 'coupon.created', $coupon);

        return response()->json(['data' => $coupon], 201);
    }
}
