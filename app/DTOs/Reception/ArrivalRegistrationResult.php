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
        /**
         * ADR-104 — le ticket Pharmacie du panier, distinct de la facture du
         * passage : sa délivrance attend le règlement (ADR-049), ce qu'une
         * facture mixte partiellement payée rendrait impossible à décider.
         */
        public ?Invoice $pharmacyInvoice = null,
        /** Le passage ne contenait que des médicaments : rien n'a été routé. */
        public bool $pharmacyOnly = false,
    ) {}
}
