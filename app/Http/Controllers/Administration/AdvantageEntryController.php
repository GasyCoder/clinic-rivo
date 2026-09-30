<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Payroll\SaveAdvantageEntriesAction;
use App\Http\Controllers\Controller;
use App\Models\AdvantageEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-227 — saisir des avantages pour les médecins depuis le module Bonus : plusieurs
 * lignes par personne (montant, motif, mois de paie), enregistrées d'un geste. Chaque
 * geste passe par son action, qui revérifie son droit.
 */
class AdvantageEntryController extends Controller
{
    private const MONTH = 'regex:/^\d{4}-(0[1-9]|1[0-2])$/';

    public function store(Request $request, SaveAdvantageEntriesAction $action): RedirectResponse
    {
        $this->normalizeAmounts($request, 'lines');
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.employee_uuid' => ['required', 'uuid'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'lines.*.reason' => ['required', 'string', 'max:160'],
            'lines.*.period' => ['required', self::MONTH],
        ], [
            'lines.required' => 'Ajoutez au moins un avantage.',
            'lines.*.amount.required' => 'Le montant est obligatoire.',
            'lines.*.amount.gt' => 'Le montant doit être supérieur à zéro.',
            'lines.*.reason.required' => 'Le motif est obligatoire.',
            'lines.*.period.required' => 'Le mois de paie est obligatoire.',
        ], ['lines.*.amount' => 'montant', 'lines.*.reason' => 'motif', 'lines.*.period' => 'mois de paie']);

        $created = $action->create(array_values($data['lines']), $request->user());
        $people = collect($created)->pluck('employee_id')->unique()->count();
        $count = count($created);

        return back()->with('status', $count.' avantage'.($count > 1 ? 's' : '').' enregistré'.($count > 1 ? 's' : '').' pour '.$people.' personne'.($people > 1 ? 's' : '').'.');
    }

    public function update(Request $request, AdvantageEntry $entry, SaveAdvantageEntriesAction $action): RedirectResponse
    {
        $this->normalizeAmounts($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:160'],
        ], ['amount.gt' => 'Le montant doit être supérieur à zéro.'], ['amount' => 'montant', 'reason' => 'motif']);

        $action->update($entry, $data, $request->user());

        return back()->with('status', 'Avantage modifié.');
    }

    public function destroy(Request $request, AdvantageEntry $entry, SaveAdvantageEntriesAction $action): RedirectResponse
    {
        $action->delete($entry, $request->user());

        return back()->with('status', 'Avantage supprimé.');
    }

    /** « 150 000,50 » → « 150000.50 » : l'écran peut envoyer un montant écrit à la française. */
    private function normalizeAmounts(Request $request, ?string $list = null): void
    {
        $clean = fn ($value) => is_string($value)
            ? str_replace(',', '.', preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value))
            : $value;

        if ($list === null) {
            $request->merge(['amount' => $clean($request->input('amount'))]);

            return;
        }

        $lines = $request->input($list);
        if (is_array($lines)) {
            $request->merge([$list => array_map(fn ($line) => is_array($line) ? [...$line, 'amount' => $clean($line['amount'] ?? null)] : $line, $lines)]);
        }
    }
}
