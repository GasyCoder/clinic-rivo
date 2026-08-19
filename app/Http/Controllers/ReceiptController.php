<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function show(Receipt $receipt): Response
    {
        $receipt->load([
            'issuer:id,name',
            'payment.method:id,name',
            'payment.cashier:id,name',
            'payment.invoice:id,uuid,patient_id,episode_id,invoice_number,total_amount,paid_amount,balance_amount,currency',
            'payment.invoice.patient:id,uuid,patient_number,first_name,last_name',
            'payment.invoice.episode:id,uuid,episode_number',
        ]);

        return Inertia::render('Receipts/Show', [
            'receipt' => $receipt,
        ]);
    }
}
