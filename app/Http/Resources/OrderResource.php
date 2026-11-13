<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_customer_order' => $request->user()?->id === $this->user_id,
            'commission' => $this->when($request->routeIs('api.v1.vendor.*'), $this->commission),
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor->name),
            'customer_phone' => data_get($this->address_snapshot, 'customer.phone'),
            'vendor_id' => $this->vendor_id,
            'vendor_location_id' => $this->vendor_location_id,
            'fulfilment' => $this->fulfilment,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,
            'address' => $this->address_snapshot,
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => $item->only(['product_id', 'name', 'unit_price', 'quantity', 'line_total', 'instructions']))),
            'history' => $this->whenLoaded('histories', fn () => $this->histories->map(fn ($history) => $history->only(['from_status', 'to_status', 'reason', 'created_at']))),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment?->only(['id', 'status', 'verified_at'])),
            'refund' => $this->whenLoaded('refund', fn () => $this->refund?->only(['id', 'amount', 'status', 'reason'])),
            'delivery' => $this->whenLoaded('delivery', fn () => $this->delivery?->only(['id', 'status', 'picked_up_at', 'delivered_at'])),
        ];
    }
}
