<?php

namespace App\Modules\Administration;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Delivery;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Rider;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Models\VendorLocation;
use Illuminate\Database\Eloquent\Model;

final class AdminData
{
    public static function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
    }

    public static function row(Model $model): array
    {
        if ($model instanceof User) {
            return self::person($model) + ['roles' => $model->roles->pluck('name')->all(), 'status' => $model->suspended_at === null ? 'active' : 'suspended', 'suspended_at' => $model->suspended_at, 'orders_count' => (int) ($model->orders_count ?? 0), 'created_at' => $model->created_at];
        }
        if ($model instanceof Order) {
            $data = $model->only(['id', 'vendor_id', 'vendor_location_id', 'fulfilment', 'status', 'payment_method', 'payment_status', 'currency', 'subtotal', 'discount', 'delivery_fee', 'commission_bps', 'commission', 'total', 'created_at']);
            $data['address'] = $model->address_snapshot;
            $data['customer'] = self::person($model->customer);
            $data['vendor_name'] = $model->vendor?->name;
            $data['payment'] = $model->payment?->only(['id', 'merchant_reference', 'provider_reference', 'status', 'verified_at']);
            $data['refund'] = $model->refund?->only(['id', 'amount', 'status', 'reason', 'external_receipt', 'provider_reference']);
            $data['delivery'] = $model->delivery?->only(['id', 'status', 'rider_id', 'picked_up_at', 'delivered_at']);
            $data['rider'] = $model->delivery?->rider === null ? null : ['id' => $model->delivery->rider->id, 'vehicle' => $model->delivery->rider->vehicle, 'user' => self::person($model->delivery->rider->user)];
            if ($model->relationLoaded('items')) {
                $data['items'] = $model->items->map(fn ($item) => $item->only(['product_id', 'name', 'unit_price', 'quantity', 'line_total', 'instructions']));
            }
            if ($model->relationLoaded('histories')) {
                $data['history'] = $model->histories->map(fn ($history) => $history->only(['from_status', 'to_status', 'reason', 'created_at']));
            }

            return $data;
        }
        if ($model instanceof Rider) {
            return $model->only(['id', 'status', 'vehicle', 'available', 'created_at', 'deliveries_count', 'active_deliveries_count', 'completed_deliveries_count']) + ['user' => self::person($model->user)];
        }
        if ($model instanceof Delivery) {
            return $model->only(['id', 'order_id', 'rider_id', 'status', 'picked_up_at', 'delivered_at', 'created_at']) + ['rider' => $model->rider === null ? null : self::row($model->rider), 'order_status' => $model->order?->status, 'payment_status' => $model->order?->payment_status];
        }
        if ($model instanceof Vendor) {
            return $model->only(['id', 'name', 'status', 'commission_bps', 'approved_at', 'created_at', 'locations_count', 'orders_count', 'documents_count']);
        }
        if ($model instanceof VendorLocation) {
            return $model->only(['id', 'vendor_id', 'name', 'address', 'latitude', 'longitude', 'accepting_orders', 'delivery_enabled', 'pickup_enabled', 'delivery_radius_km', 'google_place_id', 'location_verification_status', 'location_verified_at', 'created_at', 'products_count']) + ['vendor_name' => $model->vendor?->name];
        }
        if ($model instanceof Product) {
            return $model->only(['id', 'vendor_location_id', 'category_id', 'name', 'description', 'price', 'stock', 'available', 'created_at', 'deleted_at']) + ['vendor_name' => $model->location?->vendor?->name, 'location_name' => $model->location?->name, 'image_url' => $model->image_path === null ? null : route('api.v1.admin.products.image', ['product' => $model->id])];
        }
        if ($model instanceof Payment) {
            return $model->only(['id', 'order_id', 'merchant_reference', 'provider_reference', 'status', 'amount', 'currency', 'submitted_at', 'verified_at', 'created_at']);
        }
        if ($model instanceof Refund) {
            return $model->only(['id', 'order_id', 'payment_id', 'amount', 'status', 'reason', 'external_receipt', 'provider_reference', 'submitted_at', 'created_at']);
        }
        if ($model instanceof Settlement) {
            return $model->only(['id', 'vendor_id', 'amount', 'status', 'external_reference', 'paid_at', 'created_at', 'entries_count']);
        }
        if ($model instanceof LedgerEntry) {
            return $model->only(['id', 'order_id', 'vendor_id', 'account', 'kind', 'amount', 'currency', 'created_at']);
        }
        if ($model instanceof AuditLog) {
            return $model->only(['id', 'actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'created_at']);
        }
        if ($model instanceof Category) {
            return $model->only(['id', 'name', 'created_at', 'products_count']);
        }
        if ($model instanceof Coupon) {
            return $model->only(['id', 'vendor_id', 'campaign_id', 'code', 'discount', 'minimum_subtotal', 'usage_limit', 'used', 'starts_at', 'expires_at', 'active', 'created_at']);
        }
        if ($model instanceof Campaign) {
            return $model->only(['id', 'name', 'description', 'starts_at', 'expires_at', 'active', 'created_at', 'coupons_count']);
        }
        if ($model instanceof VendorDocument) {
            return $model->only(['id', 'vendor_id', 'kind', 'status', 'review_note', 'reviewed_at', 'created_at']);
        }
        throw new \LogicException('No administrative serializer for '.get_class($model));
    }
}
