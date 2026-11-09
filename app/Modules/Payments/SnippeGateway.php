<?php

namespace App\Modules\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Domain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/** Snippe API version 2026-01-25. All financial completion is independently verified. */
final class SnippeGateway implements PaymentGateway
{
    public function __construct(private WebhookService $webhooks) {}

    private function client(): PendingRequest
    {
        $url = config('marketplace.gateway_url');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! config('marketplace.gateway_token')) {
            throw new \RuntimeException('Snippe configuration is incomplete.');
        }

        return Http::baseUrl(rtrim($url, '/'))->withToken(config('marketplace.gateway_token'))->acceptJson()->asJson()->connectTimeout(3)->timeout(15)->withOptions(['allow_redirects' => false]);
    }

    private function firstAttempt(Payment|Refund $operation): void
    {
        if ($operation->first_attempt_at === null) {
            $operation->forceFill(['first_attempt_at' => now()])->save();
        }
        if ($operation->first_attempt_at < now()->subHours(23)) {
            throw new \RuntimeException('Snippe idempotency safety window elapsed; manual reconciliation is required before any resubmission.');
        }
    }

    public function submit(Payment $payment): void
    {
        $this->firstAttempt($payment);
        $response = $this->client()->withHeaders(['Idempotency-Key' => $payment->id])->post('/v1/payments', $payment->request_payload)->throw();
        Domain::require($response->status() === 201 && $response->json('status') === 'success', 'Snippe did not acknowledge the payment intent.');
        $data = Validator::make($response->json('data') ?? [], ['reference' => ['required', 'string', 'max:160'], 'amount.value' => ['required', 'integer'], 'amount.currency' => ['required', 'in:TZS']])->validate();
        Domain::require($data['amount']['value'] === $payment->amount, 'Snippe amount does not match.');
        DB::transaction(function () use ($payment, $data): void {
            Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $current = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            Domain::require($current->provider_reference === null || $current->provider_reference === $data['reference'], 'Snippe reference changed.');
            $current->forceFill(['provider_reference' => $data['reference'], 'submitted_at' => now()])->save();
        }, 3);
    }

    public function refund(Refund $refund): void
    {
        if (! config('marketplace.payouts_enabled')) {
            throw new \RuntimeException('Snippe disbursements are disabled. An operator must configure and approve them.');
        }
        $this->firstAttempt($refund);
        $response = $this->client()->withHeaders(['Idempotency-Key' => $refund->id])->post('/v1/payouts/send', $refund->request_payload)->throw();
        Domain::require($response->status() === 201 && $response->json('status') === 'success', 'Snippe did not acknowledge the refund payout.');
        $data = Validator::make($response->json('data') ?? [], ['reference' => ['required', 'string', 'max:160'], 'amount.value' => ['required', 'integer'], 'amount.currency' => ['required', 'in:TZS']])->validate();
        Domain::require($data['amount']['value'] === $refund->amount, 'Snippe payout amount does not match.');
        DB::transaction(function () use ($refund, $data): void {
            Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $current = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            Domain::require($current->provider_reference === null || $current->provider_reference === $data['reference'], 'Snippe payout reference changed.');
            $current->forceFill(['provider_reference' => $data['reference'], 'submitted_at' => now()])->save();
        }, 3);
    }

    public function reconcile(Payment $payment): void
    {
        $refund = Refund::where('order_id', $payment->order_id)->whereIn('status', ['approved', 'refunded'])->first();
        if ($refund && $refund->provider_reference !== null) {
            $response = $this->client()->get('/v1/payouts/'.rawurlencode($refund->provider_reference))->throw();
            Domain::require($response->json('status') === 'success', 'Snippe payout reconciliation failed.');
            $this->webhooks->reconcileSnapshot($payment, $response->json('data') ?? [], $refund);
        }
        if ($payment->provider_reference !== null && $payment->status === 'pending') {
            $response = $this->client()->get('/v1/payments/'.rawurlencode($payment->provider_reference))->throw();
            Domain::require($response->json('status') === 'success', 'Snippe payment reconciliation failed.');
            $this->webhooks->reconcileSnapshot($payment, $response->json('data') ?? []);
        }
    }
}
