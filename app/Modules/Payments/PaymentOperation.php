<?php

namespace App\Modules\Payments;

use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PaymentOperation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $id, public string $operation = 'charge') {}

    public function uniqueId(): string
    {
        return $this->operation.':'.$this->id;
    }

    public function handle(PaymentGateway $gateway): void
    {
        if ($this->operation === 'refund') {
            $refund = Refund::findOrFail($this->id);
            if ($refund->status === 'approved') {
                $gateway->refund($refund);
            }

            return;
        }
        $payment = Payment::with('order')->findOrFail($this->id);
        if ($this->operation === 'reconcile') {
            $gateway->reconcile($payment);
            $payment->touch();
        } elseif ($payment->status === 'pending' && $payment->submitted_at === null && $payment->order->status === 'awaiting_payment') {
            $gateway->submit($payment);
        }
    }
}
