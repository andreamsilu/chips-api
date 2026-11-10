<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Rider;
use App\Models\Settlement;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Modules\Orders\OrderService;
use App\Modules\Payments\PaymentOperation;
use App\Modules\Payments\RefundService;
use App\Support\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AdminOperationsController extends Controller
{
    public const ORDER_STATUSES = ['awaiting_payment', 'confirmed', 'accepted', 'preparing', 'ready', 'out_for_delivery', 'completed', 'delivered', 'cancelled', 'rejected'];

    public const PAYMENT_STATUSES = ['pending', 'verified', 'failed', 'expired', 'refund_pending', 'refunded'];

    public static function ordersQuery(): Builder
    {
        return Order::with(['customer', 'vendor' => fn ($q) => $q->withTrashed(), 'payment', 'refund', 'delivery.rider.user']);
    }

    public function orders(Request $request, ?User $user = null): JsonResponse
    {
        $query = self::ordersQuery();
        if ($user !== null) {
            abort_unless($user->roles()->where('name', 'customer')->exists(), 404);
            $query->where('user_id', $user->id);
        }

        return response()->json(AdminQuery::page($request, $query, ['orders.id', fn (Builder $q, string $term) => $q->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $term)->orWhere('email', 'like', $term)), fn (Builder $q, string $term) => $q->orWhereHas('vendor', fn (Builder $vendor) => $vendor->where('name', 'like', $term))], [
            'status' => AdminQuery::enum(self::ORDER_STATUSES),
            'payment_status' => AdminQuery::enum(self::PAYMENT_STATUSES),
            'fulfilment' => AdminQuery::enum(['pickup', 'delivery']),
            'vendor_id' => AdminQuery::identifier(),
            'customer_id' => AdminQuery::identifier() + ['apply' => fn (Builder $q, string $id) => $q->whereIn('user_id', User::where('public_id', $id)->select('id'))],
            'rider_id' => AdminQuery::identifier() + ['apply' => fn (Builder $q, string $id) => $q->whereHas('delivery', fn (Builder $delivery) => $delivery->where('rider_id', $id))],
        ], ['created_at' => 'orders.created_at', 'id' => 'orders.id', 'total' => 'orders.total', 'status' => 'orders.status']));
    }

    public function order(Request $request, Order $order): JsonResponse
    {
        $order = self::ordersQuery()->with(['items', 'histories' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])->findOrFail($order->id);

        return response()->json(['data' => AdminData::row($order)]);
    }

    public function cancel(Request $request, Order $order, OrderService $service): JsonResponse
    {
        $data = AdminQuery::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500'], 'status' => ['required', Rule::in(['cancelled', 'rejected'])]]);
        $service->cancelByAdministrator($order, $request->user(), $data['status'], $data['reason']);

        return $this->order($request, $order);
    }

    public function requestRefund(Request $request, Order $order, RefundService $service): JsonResponse
    {
        $data = AdminQuery::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => AdminData::row($service->request($order, $request->user(), $data['reason']))]);
    }

    public function payments(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, Payment::query(), ['payments.id', 'merchant_reference', 'provider_reference', 'order_id'], [
            'status' => AdminQuery::enum(self::PAYMENT_STATUSES), 'order_id' => AdminQuery::identifier(),
            'vendor_id' => AdminQuery::identifier() + ['apply' => fn (Builder $q, string $id) => $q->whereHas('order', fn (Builder $order) => $order->where('vendor_id', $id))],
        ], ['created_at' => 'payments.created_at', 'id' => 'payments.id', 'amount' => 'payments.amount', 'verified_at' => 'payments.verified_at', 'status' => 'payments.status']));
    }

    public function payment(Payment $payment): JsonResponse
    {
        return response()->json(['data' => AdminData::row($payment)]);
    }

    public function paymentEvents(Request $request, Payment $payment): JsonResponse
    {
        return response()->json(AdminQuery::page($request, PaymentEvent::where('payment_id', $payment->id), ['provider_event_id', 'type'], [], [], fn ($event) => $event->only(['id', 'payment_id', 'provider_event_id', 'type', 'created_at'])));
    }

    public function reconcile(Request $request, Payment $payment): JsonResponse
    {
        AdminQuery::body($request, []);
        abort_unless(config('marketplace.gateway_token') && str_starts_with((string) config('marketplace.gateway_url'), 'https://'), 503, 'The payment provider is not configured.');
        Domain::require($payment->provider_reference !== null, 'A provider reference is required before reconciliation.');
        Domain::require($payment->status === 'pending' || Refund::where('payment_id', $payment->id)->whereIn('status', ['approved', 'refunded'])->whereNotNull('provider_reference')->exists(), 'Only pending payments or provider-submitted refunds require reconciliation.');
        PaymentOperation::dispatch($payment->id, 'reconcile')->onQueue('payments');
        Domain::audit($request->user(), 'payment.reconciliation_requested', $payment);

        return response()->json(['message' => 'Reconciliation queued. Completion requires verified provider data.'], 202);
    }

    public function riders(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, $this->ridersQuery(), ['riders.id', 'vehicle', fn (Builder $q, string $term) => $q->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term))], ['status' => AdminQuery::enum(['pending', 'approved', 'rejected', 'suspended']), 'available' => AdminQuery::boolean()], ['created_at' => 'riders.created_at', 'id' => 'riders.id', 'vehicle' => 'riders.vehicle', 'status' => 'riders.status']));
    }

    public function rider(Rider $rider): JsonResponse
    {
        return response()->json(['data' => AdminData::row($this->ridersQuery()->findOrFail($rider->id))]);
    }

    public function updateRider(Request $request, Rider $rider): JsonResponse
    {
        $data = AdminQuery::body($request, ['status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'suspended'])], 'available' => ['sometimes', 'boolean'], 'vehicle' => ['sometimes', 'required', 'string', 'max:100']]);
        DB::transaction(function () use ($rider, $data, $request): void {
            $target = Rider::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $target->forceFill($data)->save();
            Domain::audit($request->user(), 'rider.updated', $target, $data);
        }, 3);

        return $this->rider($rider);
    }

    public function deliveries(Request $request, ?Rider $rider = null): JsonResponse
    {
        $query = Delivery::with(['order', 'rider.user']);
        if ($rider !== null) {
            $query->where('rider_id', $rider->id);
        }

        return response()->json(AdminQuery::page($request, $query, ['deliveries.id', 'order_id', 'rider_id'], ['status' => AdminQuery::enum(['unassigned', 'assigned', 'picked_up', 'delivered']), 'rider_id' => AdminQuery::identifier(), 'order_id' => AdminQuery::identifier()]));
    }

    public function refund(Refund $refund): JsonResponse
    {
        return response()->json(['data' => AdminData::row($refund)]);
    }

    public function settlement(Settlement $settlement): JsonResponse
    {
        return response()->json(['data' => AdminData::row($settlement->loadCount('entries'))]);
    }

    public function settlementEntries(Request $request, Settlement $settlement): JsonResponse
    {
        return response()->json(AdminQuery::page($request, LedgerEntry::whereIn('id', SettlementEntry::where('settlement_id', $settlement->id)->select('ledger_entry_id')), ['ledger_entries.id', 'order_id', 'account', 'kind'], ['account' => AdminQuery::enum(['provider_clearing', 'vendor_payable', 'platform_commission', 'delivery_revenue']), 'kind' => AdminQuery::enum(['capture', 'refund', 'refund_reversed', 'manual_refund'])]));
    }

    private function ridersQuery(): Builder
    {
        return Rider::with('user')->withCount(['deliveries', 'deliveries as active_deliveries_count' => fn (Builder $q) => $q->whereIn('status', ['assigned', 'picked_up']), 'deliveries as completed_deliveries_count' => fn (Builder $q) => $q->where('status', 'delivered')]);
    }
}
