<?php

namespace App\Modules\Payments;

use App\Models\Payment;
use App\Models\Refund;

interface PaymentGateway
{
    public function submit(Payment $payment): void;

    public function refund(Refund $refund): void;

    public function reconcile(Payment $payment): void;
}
