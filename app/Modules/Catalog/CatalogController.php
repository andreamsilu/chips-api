<?php

namespace App\Modules\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\VendorLocation;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

final class CatalogController extends Controller
{
    public function location(VendorLocation $location): JsonResponse
    {
        abort_unless($location->vendor()->where('status', 'approved')->exists(), 404);

        return response()->json(['data' => $location->load('vendor:id,name,status,commission_bps')]);
    }

    public function ownProducts(Request $request, VendorLocation $location): JsonResponse
    {
        abort_unless($request->user()->manages($location->vendor_id), 404);
        Gate::authorize('update', $location->vendor()->firstOrFail());

        return response()->json($location->products()->orderBy('name')->orderBy('id')->paginate(20));
    }

    public function discovery(Request $request, DiscoveryRepository $repository): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'radius' => ['sometimes', 'integer', 'between:1,50'], 'page' => ['sometimes', 'integer', 'between:1,10000'], 'sort' => ['sometimes', 'in:recommended,nearest,fastest,lowest_fee'], 'fulfilment' => ['sometimes', 'in:all,delivery,pickup']]);

        return response()->json($repository->nearby((float) $data['latitude'], (float) $data['longitude'], (int) ($data['radius'] ?? 15), (int) ($data['page'] ?? 1), $data['sort'] ?? 'recommended', $data['fulfilment'] ?? 'all'));
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => Cache::remember('catalog:categories', 300, fn () => Category::orderBy('name')->get(['id', 'name']))]);
    }

    public function products(Request $request, VendorLocation $location): JsonResponse
    {
        abort_unless($location->vendor()->where('status', 'approved')->exists(), 404);
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100'], 'category_id' => ['sometimes', 'ulid'], 'page' => ['sometimes', 'integer', 'between:1,10000']]);

        return response()->json($location->products()->where('available', true)->when($data['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))->when($data['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))->orderBy('name')->orderBy('id')->paginate(20));
    }

    public function save(Request $request, VendorLocation $location, ?string $product = null): JsonResponse
    {
        abort_unless($request->user()->manages($location->vendor_id), 404);
        Gate::authorize('update', $location->vendor()->firstOrFail());
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:5000'], 'category_id' => ['required', 'ulid', 'exists:categories,id'], 'price' => ['required', 'integer', 'between:1,10000000'], 'stock' => ['required', 'integer', 'between:0,1000000'], 'available' => ['required', 'boolean'], 'vendor_location_id' => ['prohibited']]);
        if ($product) {
            $model = $location->products()->findOrFail($product);
            Gate::authorize('update', $model);
            $model->forceFill($data)->save();
        } else {
            $model = Product::record($data + ['vendor_location_id' => $location->id]);
        }
        Domain::audit($request->user(), 'product.saved', $model);

        return response()->json(['data' => $model], $product ? 200 : 201);
    }
}
