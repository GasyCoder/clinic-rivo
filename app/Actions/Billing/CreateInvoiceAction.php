<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Services\Finance\FinancialNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInvoiceAction
{
    public function __construct(private readonly FinancialNumberGenerator $numbers) {}

    /**
     * @param  array{episode_uuid: string, lines: array<int, array{description: string, quantity: mixed, unit_price: mixed}>}  $data
     */
    public function execute(Patient $patient, array $data, User $actor): Invoice
    {
        $episode = $patient->episodes()->where('uuid', $data['episode_uuid'])->first();

        if (! $episode) {
            throw ValidationException::withMessages([
                'episode_uuid' => 'Le passage sélectionné n’appartient pas à ce patient.',
            ]);
        }

        $preparedLines = collect($data['lines'])->map(function (array $line) {
            $lineTotal = Money::multiply($line['quantity'], $line['unit_price']);

            return [
                'description' => trim($line['description']),
                'quantity' => number_format((float) $line['quantity'], 2, '.', ''),
                'unit_price' => Money::fromMinor(Money::toMinor($line['unit_price'])),
                'line_total' => Money::fromMinor($lineTotal),
                'line_total_minor' => $lineTotal,
            ];
        });

        $subtotalMinor = $preparedLines->sum('line_total_minor');

        if ($subtotalMinor <= 0 || $subtotalMinor > 999_999_999_999_999) {
            throw ValidationException::withMessages([
                'lines' => 'Le montant total de la facture est invalide ou dépasse la limite autorisée.',
            ]);
        }

        return DB::transaction(function () use ($patient, $episode, $preparedLines, $subtotalMinor, $actor) {
            $subtotal = Money::fromMinor($subtotalMinor);
            $invoice = Invoice::create([
                'patient_id' => $patient->id,
                'episode_id' => $episode->id,
                'invoice_number' => $this->numbers->invoice(),
                'status' => InvoiceStatus::Draft,
                'currency' => 'MGA',
                'subtotal_amount' => $subtotal,
                'discount_amount' => '0.00',
                'total_amount' => $subtotal,
                'paid_amount' => '0.00',
                'balance_amount' => $subtotal,
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'status' => 'ACTIVE',
                    'created_by' => $actor->id,
                ]);
            }

            return $invoice->load('lines', 'episode');
        });
    }
}
