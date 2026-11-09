<?php

namespace App\Modules\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Modules\Finance\LedgerService;
use App\Modules\Orders\OrderService;
use App\Support\Domain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WebhookService
{
    public function __construct(private LedgerService $ledger, private OrderService $orders) {}

    public function receive(string $raw, ?string $timestamp, ?string $signature): void
    {
        $secret = config('marketplace.webhook_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503, 'Webhook verifier is not configured.');
        abort_unless($timestamp !== null && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= config('marketplace.webhook_tolerance'), 401, 'Invalid webhook timestamp.');
        abort_unless(is_string($signature) && hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature), 401, 'Invalid webhook signature.');
        abort_if(strlen($raw) > 16384, 413);
        $decoded = json_decode($raw, true);
        abort_unless(is_array($decoded), 422, 'Webhook body must be a JSON object.');
        $envelope = Validator::make($decoded, ['id' => ['required', 'string', 'max:160'], 'type' => ['required', 'in:payment.completed,payment.failed,payment.expired,payment.voided,payout.completed,payout.failed,payout.reversed'], 'api_version' => ['required', 'in:2026-01-25'], 'data' => ['required', 'array']])->validate();
        $event = $this->normalize($envelope['data'], $envelope['type'], $envelope['id']);
        $this->apply($event, hash('sha256', $raw));
    }

    /** Only the authenticated Snippe API adapter may supply reconciliation snapshots. */
    public function reconcileSnapshot(Payment $payment, array $snapshot, ?Refund $refund = null): void
    {
        Domain::require(($snapshot['reference'] ?? null) === ($refund === null ? $payment->provider_reference : $refund->provider_reference), 'Reconciliation reference does not match.');
        $status = $snapshot['status'] ?? '';
        if ($status === 'pending') {
            return;
        }
        $type = ($refund ? 'payout.' : 'payment.').$status;
        $event = $this->normalize($snapshot, $type, 'poll:'.$snapshot['reference'].':'.$status, $payment->order_id, $refund?->id);
        $this->apply($event, hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)));
    }

    private function normalize(array $data, string $type, string $id, ?string $orderId = null, ?string $refundId = null): array
    {
        $data = Validator::make($data, ['reference' => ['required', 'string', 'max:160'], 'amount.value' => ['required', 'integer', 'min:1'], 'amount.currency' => ['required', 'in:TZS'], 'status' => ['required', 'string'], 'metadata' => ['sometimes', 'array'], 'recipient.phone' => ['sometimes', 'string', 'max:20']])->validate();
        $expected = explode('.', $type)[1] ?? '';
        Domain::require($data['status'] === $expected, 'Provider event and transaction status differ.');
        $mapping = ['payment.completed' => 'payment.verified', 'payment.failed' => 'payment.failed', 'payment.voided' => 'payment.failed', 'payment.expired' => 'payment.failed', 'payout.completed' => 'refund.verified', 'payout.failed' => 'refund.failed', 'payout.reversed' => 'refund.reversed'];
        abort_unless(isset($mapping[$type]), 422, 'Unsupported provider status.');

        return Validator::make(['event_id' => $id, 'merchant_reference' => $orderId ?? ($data['metadata']['order_id'] ?? null), 'provider_reference' => $data['reference'], 'status' => $mapping[$type], 'amount' => $data['amount']['value'], 'currency' => $data['amount']['currency'], 'refund_reference' => $refundId ?? ($data['metadata']['refund_id'] ?? null), 'recipient_phone' => $data['recipient']['phone'] ?? null], [
            'event_id' => ['required', 'string', 'max:160'], 'merchant_reference' => ['required', 'ulid'], 'provider_reference' => ['required', 'string', 'max:160'], 'status' => ['required', 'string'], 'amount' => ['required', 'integer', 'min:1'], 'currency' => ['required', 'in:TZS'], 'refund_reference' => ['required_if:status,refund.verified,refund.failed,refund.reversed', 'nullable', 'ulid'], 'recipient_phone' => ['required_if:status,refund.verified,refund.failed,refund.reversed', 'nullable', 'string'],
        ])->validate();
    }

    private function apply(array $event, string $hash): void
    {
        DB::transaction(function () use ($event, $hash): void {
            $order = Order::whereKey($event['merchant_reference'])->lockForUpdate()->firstOrFail();
            $payment = Payment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $previous = PaymentEvent::where('provider_event_id', $event['event_id'])->first();
            if ($previous) {
                Domain::require($previous->payment_id === $payment->id && hash_equals($previous->payload_hash, $hash), 'Provider event ID was reused.');

                return;
            }
            Domain::require($payment->amount === (int) $event['amount'] && $payment->currency === $event['currency'], 'Payment amount or currency does not match.');
            if (str_starts_with($event['status'], 'refund.')) {
                $refund = Refund::where('order_id', $order->id)->whereKey($event['refund_reference'])->lockForUpdate()->firstOrFail();
                Domain::require(in_array($refund->status, ['approved', 'refunded', 'failed', 'reversed'], true), 'Refund has not been approved.');
                Domain::require(ltrim($event['recipient_phone'], '+') === ltrim($payment->phone, '+'), 'Refund recipient does not match the original payer.');
                Domain::require($refund->provider_reference === null || $refund->provider_reference === $event['provider_reference'], 'Refund provider reference does not match.');
                if ($event['status'] === 'refund.failed' && $refund->status === 'approved') {
                    $refund->forceFill(['status' => 'failed', 'provider_reference' => $event['provider_reference']])->save();
                    Domain::audit(null, 'refund.failed', $refund);
                } elseif ($event['status'] === 'refund.reversed' && in_array($refund->status, ['approved', 'refunded'], true)) {
                    $wasRefunded = $refund->status === 'refunded';
                    $refund->forceFill(['status' => 'reversed'])->save();
                    $order->forceFill(['payment_status' => 'refund_pending'])->save();
                    if ($wasRefunded) {
                        $this->ledger->reverseRefund($order);
                    }
                    Domain::audit(null, 'refund.reversed', $refund);
                } elseif ($event['status'] === 'refund.verified' && $refund->status === 'approved') {
                    $refund->forceFill(['status' => 'refunded', 'provider_reference' => $event['provider_reference']])->save();
                    $order->forceFill(['payment_status' => 'refunded'])->save();
                    $payment->forceFill(['status' => 'refunded'])->save();
                    $this->ledger->reverse($order);
                    Domain::audit(null, 'refund.verified', $refund);
                }
            } elseif ($event['status'] === 'payment.verified') {
                Domain::require($payment->provider_reference === null || $payment->provider_reference === $event['provider_reference'], 'Provider reference does not match.');
                if (! in_array($payment->status, ['verified', 'refunded'], true)) {
                    $payment->forceFill(['status' => 'verified', 'provider_reference' => $event['provider_reference'], 'verified_at' => now()])->save();
                    $order->forceFill(['payment_status' => 'verified'])->save();
                    $this->ledger->capture($order);
                    if ($order->status === 'awaiting_payment') {
                        Domain::transition($order, 'confirmed', null);
                    } elseif (in_array($order->status, ['cancelled', 'rejected'], true)) {
                        $this->orders->requestRefund($order, null, 'Payment received after cancellation');
                    }
                }
            } elseif ($payment->status === 'pending') {
                $payment->forceFill(['status' => 'failed', 'provider_reference' => $event['provider_reference']])->save();
                $order->forceFill(['payment_status' => 'failed'])->save();
                if ($order->status === 'awaiting_payment') {
                    $this->orders->releaseStock($order);
                    Domain::transition($order, 'cancelled', null, 'Payment declined');
                }
            }
            PaymentEvent::record(['payment_id' => $payment->id, 'provider_event_id' => $event['event_id'], 'type' => $event['status'], 'payload_hash' => $hash]);
            Domain::emit($order);
        }, 3);
    }
}
