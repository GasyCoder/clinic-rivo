<?php

namespace App\DTOs\Reception;

use App\Models\Episode;
use App\Models\Invoice;
use App\Models\Payment;

final readonly class ArrivalRegistrationResult
{
    public function __construct(
        public Episode $episode,
        public ?Invoice $invoice = null,
        public ?Payment $payment = null,
        public ?string $billingWarning = null,
    ) {}
}
