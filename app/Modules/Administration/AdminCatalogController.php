<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorLocation;
use App\Support\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminCatalogController extends Controller
{
    public function vendor(Vendor $vendor): JsonResponse
    {
        $data = AdminData::row($vendor->loadCount(['locations', 'orders', 'documents']));
        $data['members'] = $vendor->members()->orderBy('users.id')->limit(100)->get()->map(fn ($user) => AdminData::person($user));
        $data['performance'] = ['orders_by_status' => (object) Order::where('vendor_id', $vendor->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(), 'net_commission' => (int) LedgerEntry::where('vendor_id', $vendor->id)->where('account', 'platform_commission')->sum('amount')];

        return response()->json(['data' => $data]);
    }

    public function locations(Request $request, ?Vendor $vendor = null): JsonResponse
    {
        $query = VendorLocation::with('vendor')->withCount('products');
        if ($vendor !== null) {
            $query->where('vendor_id', $vendor->id);
        }

        return response()->json(AdminQuery::page($request, $query, ['vendor_locations.id', 'vendor_locations.name', 'address'], ['vendor_id' => AdminQuery::identifier(), 'accepting_orders' => AdminQuery::boolean(), 'delivery_enabled' => AdminQuery::boolean(), 'pickup_enabled' => AdminQuery::boolean()], ['created_at' => 'vendor_locations.created_at', 'name' => 'vendor_locations.name', 'id' => 'vendor_locations.id']));
    }

    public function storeLocation(Request $request, Vendor $vendor): JsonResponse
    {
        return $this->saveLocation($request, $vendor);
    }

    public function updateLocation(Request $request, VendorLocation $location): JsonResponse
    {
        return $this->saveLocation($request, $location->vendor, $location);
    }

    private function saveLocation(Request $request, Vendor $vendor, ?VendorLocation $location = null): JsonResponse
    {
        $required = $location === null ? 'required' : 'sometimes';
        $data = AdminQuery::body($request, ['name' => [$required, 'required', 'string', 'max:160'], 'address' => [$required, 'required', 'string', 'max:500'], 'latitude' => [$required, 'numeric', 'between:-90,90'], 'longitude' => [$required, 'numeric', 'between:-180,180'], 'accepting_orders' => [$required, 'boolean'], 'delivery_enabled' => [$required, 'boolean'], 'pickup_enabled' => [$required, 'boolean'], 'delivery_radius_km' => [$required, 'integer', 'between:1,50']]);
        $model = DB::transaction(function () use ($request, $vendor, $location, $data): VendorLocation {
            Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $model = $location === null ? VendorLocation::record($data + ['vendor_id' => $vendor->id]) : VendorLocation::whereKey($location->id)->lockForUpdate()->firstOrFail();
            if ($location !== null) {
                $model->forceFill($data)->save();
            }
            Domain::audit($request->user(), 'location.admin_saved', $model);

            return $model;
        }, 3);

        return response()->json(['data' => AdminData::row($model->load('vendor'))], $location === null ? 201 : 200);
    }

    public function categories(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, Category::withCount('products'), ['categories.id', 'name'], [], ['created_at' => 'categories.created_at', 'name' => 'categories.name', 'id' => 'categories.id']));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        return $this->saveCategory($request);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        return $this->saveCategory($request, $category);
    }

    private function saveCategory(Request $request, ?Category $category = null): JsonResponse
    {
        $data = AdminQuery::body($request, ['name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category?->id)]]);
        $model = DB::transaction(function () use ($category, $request, $data): Category {
            $model = $category ?? new Category;
            $model->forceFill($data)->save();
            Domain::audit($request->user(), 'category.saved', $model);

            return $model;
        }, 3);
        Cache::forget('catalog:categories');

        return response()->json(['data' => AdminData::row($model)], $category === null ? 201 : 200);
    }

    public function deleteCategory(Request $request, Category $category): JsonResponse
    {
        DB::transaction(function () use ($request, $category): void {
            Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
            Domain::require(! Product::withTrashed()->where('category_id', $category->id)->exists(), 'A category used by products cannot be deleted.');
            Domain::audit($request->user(), 'category.deleted', $category);
            $category->delete();
        }, 3);
        Cache::forget('catalog:categories');

        return response()->json(['message' => 'Category deleted.']);
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, Product::with('location.vendor'), ['products.id', 'products.name', 'description'], [
            'vendor_location_id' => AdminQuery::identifier(), 'category_id' => AdminQuery::identifier(), 'available' => AdminQuery::boolean(),
            'vendor_id' => AdminQuery::identifier() + ['apply' => fn (Builder $q, string $id) => $q->whereHas('location', fn (Builder $location) => $location->where('vendor_id', $id))],
        ], ['created_at' => 'products.created_at', 'name' => 'products.name', 'price' => 'products.price', 'stock' => 'products.stock', 'id' => 'products.id']));
    }

    public function product(Product $product): JsonResponse
    {
        return response()->json(['data' => AdminData::row($product->load('location.vendor'))]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->saveProduct($request);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        return $this->saveProduct($request, $product);
    }

    private function saveProduct(Request $request, ?Product $product = null): JsonResponse
    {
        $required = $product === null ? 'required' : 'sometimes';
        $rules = ['name' => [$required, 'required', 'string', 'max:160'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'], 'category_id' => [$required, 'ulid', 'exists:categories,id'], 'price' => [$required, 'integer', 'between:1,10000000'], 'stock' => [$required, 'integer', 'between:0,1000000'], 'available' => [$required, 'boolean']];
        if ($product === null) {
            $rules['vendor_location_id'] = ['required', 'ulid', 'exists:vendor_locations,id'];
        }
        if ($product !== null) {
            $rules['expected_stock'] = ['required_with:stock', 'integer', 'between:0,1000000'];
        }
        $data = AdminQuery::body($request, $rules);
        $model = DB::transaction(function () use ($product, $data, $request): Product {
            if ($product === null) {
                VendorLocation::whereKey($data['vendor_location_id'])->firstOrFail();
                $model = Product::record($data);
            } else {
                $model = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                if (array_key_exists('stock', $data)) {
                    Domain::require((int) $model->stock === (int) $data['expected_stock'], 'Stock changed since this form was loaded. Refresh before adjusting inventory.');
                }
                $model->forceFill(collect($data)->except('expected_stock')->all())->save();
            }
            Domain::audit($request->user(), 'product.admin_saved', $model, ['fields' => array_keys($data)]);

            return $model;
        }, 3);

        return response()->json(['data' => AdminData::row($model->load('location.vendor'))], $product === null ? 201 : 200);
    }

    public function deleteProduct(Request $request, Product $product): JsonResponse
    {
        DB::transaction(function () use ($request, $product): void {
            $model = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            Domain::audit($request->user(), 'product.archived', $model);
            $model->delete();
        }, 3);

        return response()->json(['message' => 'Product archived; historical order snapshots are retained.']);
    }

    public function coupons(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, Coupon::query(), ['coupons.id', 'code'], ['vendor_id' => AdminQuery::identifier(), 'campaign_id' => AdminQuery::identifier(), 'active' => AdminQuery::boolean()], ['created_at' => 'coupons.created_at', 'code' => 'coupons.code', 'discount' => 'coupons.discount', 'id' => 'coupons.id']));
    }

    public function coupon(Coupon $coupon): JsonResponse
    {
        return response()->json(['data' => AdminData::row($coupon)]);
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        return $this->saveCoupon($request);
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        return $this->saveCoupon($request, $coupon);
    }

    private function saveCoupon(Request $request, ?Coupon $coupon = null): JsonResponse
    {
        $required = $coupon === null ? 'required' : 'sometimes';
        $rules = ['code' => [$required, 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)], 'discount' => [$required, 'integer', 'between:1,10000000'], 'minimum_subtotal' => [$required, 'integer', 'between:0,10000000'], 'usage_limit' => [$required, 'integer', 'between:1,1000000'], 'starts_at' => [$required, 'date'], 'expires_at' => [$required, 'date'], 'active' => [$required, 'boolean'], 'campaign_id' => ['sometimes', 'nullable', 'ulid', 'exists:campaigns,id']];
        if ($coupon === null) {
            $rules['vendor_id'] = ['required', 'ulid', 'exists:vendors,id'];
        }
        $data = AdminQuery::body($request, $rules);
        $model = DB::transaction(function () use ($request, $data, $coupon): Coupon {
            Vendor::whereKey($coupon->vendor_id ?? $data['vendor_id'])->lockForUpdate()->firstOrFail();
            $target = $coupon === null ? new Coupon : Coupon::whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            Domain::require(($data['usage_limit'] ?? $target->usage_limit) >= ($target->used ?? 0), 'Usage limit is below the number of reserved redemptions.');
            Domain::require(($target->used ?? 0) === 0 || ! isset($data['code']) || $data['code'] === $target->code, 'A redeemed coupon code cannot be renamed.');
            $this->dates($data, $target);
            $target->forceFill($data)->save();
            Domain::audit($request->user(), 'coupon.admin_saved', $target);

            return $target;
        }, 3);

        return response()->json(['data' => AdminData::row($model)], $coupon === null ? 201 : 200);
    }

    public function deleteCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        DB::transaction(function () use ($request, $coupon): void {
            Vendor::whereKey($coupon->vendor_id)->lockForUpdate()->firstOrFail();
            $target = Coupon::whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            Domain::require(! CouponRedemption::where('coupon_id', $target->id)->exists(), 'Deactivate redeemed coupons instead of deleting them.');
            Domain::audit($request->user(), 'coupon.deleted', $target);
            $target->delete();
        }, 3);

        return response()->json(['message' => 'Coupon deleted.']);
    }

    public function campaigns(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, Campaign::withCount('coupons'), ['campaigns.id', 'name', 'description'], ['active' => AdminQuery::boolean()], ['created_at' => 'campaigns.created_at', 'name' => 'campaigns.name', 'id' => 'campaigns.id']));
    }

    public function campaign(Campaign $campaign): JsonResponse
    {
        return response()->json(['data' => AdminData::row($campaign->loadCount('coupons'))]);
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        return $this->saveCampaign($request);
    }

    public function updateCampaign(Request $request, Campaign $campaign): JsonResponse
    {
        return $this->saveCampaign($request, $campaign);
    }

    private function saveCampaign(Request $request, ?Campaign $campaign = null): JsonResponse
    {
        $required = $campaign === null ? 'required' : 'sometimes';
        $data = AdminQuery::body($request, ['name' => [$required, 'required', 'string', 'max:160'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'], 'starts_at' => [$required, 'date'], 'expires_at' => [$required, 'date'], 'active' => [$required, 'boolean']]);
        $model = DB::transaction(function () use ($request, $campaign, $data): Campaign {
            $target = $campaign === null ? new Campaign : Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->dates($data, $target);
            $target->forceFill($data)->save();
            Domain::audit($request->user(), 'campaign.saved', $target);

            return $target;
        }, 3);

        return response()->json(['data' => AdminData::row($model)], $campaign === null ? 201 : 200);
    }

    private function dates(array $data, Coupon|Campaign $model): void
    {
        $start = CarbonImmutable::parse($data['starts_at'] ?? $model->starts_at);
        $end = CarbonImmutable::parse($data['expires_at'] ?? $model->expires_at);
        if ($end <= $start) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry date must be after the start date.']);
        }
    }
}
