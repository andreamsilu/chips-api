<?php

namespace App\Modules\Engagement;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Models\Vendor;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReviewController extends Controller
{
    public function index(Vendor $vendor): JsonResponse
    {
        abort_unless($vendor->status === 'approved', 404);

        return response()->json(Review::where('vendor_id', $vendor->id)->orderByDesc('id')->paginate(20, ['id', 'rating', 'comment', 'created_at']));
    }

    public function store(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $review = DB::transaction(function () use ($request, $order, $data): Review {
            $model = Order::where('user_id', $request->user()->id)->whereKey($order)->lockForUpdate()->firstOrFail();
            Domain::require(in_array($model->status, ['completed', 'delivered'], true) && $model->payment_status === 'verified', 'Only a paid, fulfilled order can be reviewed.');
            Domain::require(! Review::where('order_id', $model->id)->exists(), 'Order already has a review.');

            return Review::record($data + ['order_id' => $model->id, 'user_id' => $request->user()->id, 'vendor_id' => $model->vendor_id]);
        });

        return response()->json(['data' => $review->only(['id', 'rating', 'comment', 'created_at'])], 201);
    }
}
