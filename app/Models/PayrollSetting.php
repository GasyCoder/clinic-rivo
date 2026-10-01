<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-233 — les paramètres de paie du site : une seule ligne par base. Tant qu'aucune ligne
 * n'existe, `current()` rend la proposition (barème Madagascar), **désactivée** : le RH la
 * relit, la corrige et l'active. Aucune retenue n'est appliquée en silence, et une paie
 * marquée payée fige les paramètres qu'elle a utilisés (`snapshot()`).
 */
#[Fillable([
    'legal_deductions_enabled', 'cnaps_employee_rate', 'cnaps_employer_rate', 'cnaps_ceiling',
    'health_label', 'health_employee_rate', 'health_employer_rate', 'health_ceiling',
    'irsa_brackets', 'irsa_minimum', 'irsa_child_reduction', 'irsa_base_rounding', 'allowance_subject',
    'updated_by', 'external_updated_by_uuid', 'external_updated_by_name',
])]
class PayrollSetting extends Model
{
    use Auditable;

    /**
     * La proposition de départ : barème courant à Madagascar, **à faire vérifier par le
     * comptable de la clinique** avant activation. Le plafond CNAPS / organisme médical
     * (8 fois le salaire minimum) n'est pas proposé : il change avec le salaire minimum.
     */
    public const PROPOSAL = [
        'legal_deductions_enabled' => false,
        'cnaps_employee_rate' => '1.00',
        'cnaps_employer_rate' => '13.00',
        'cnaps_ceiling' => null,
        'health_label' => 'OSTIE / SMIE',
        'health_employee_rate' => '1.00',
        'health_employer_rate' => '5.00',
        'health_ceiling' => null,
        'irsa_brackets' => [
            ['up_to' => '350000.00', 'rate' => '0.00'],
            ['up_to' => '400000.00', 'rate' => '5.00'],
            ['up_to' => '500000.00', 'rate' => '10.00'],
            ['up_to' => '600000.00', 'rate' => '15.00'],
            ['up_to' => null, 'rate' => '20.00'],
        ],
        'irsa_minimum' => '3000.00',
        'irsa_child_reduction' => '2000.00',
        'irsa_base_rounding' => null,
        'allowance_subject' => false,
    ];

    protected function casts(): array
    {
        return [
            'legal_deductions_enabled' => 'boolean',
            'cnaps_employee_rate' => 'decimal:2',
            'cnaps_employer_rate' => 'decimal:2',
            'cnaps_ceiling' => 'decimal:2',
            'health_employee_rate' => 'decimal:2',
            'health_employer_rate' => 'decimal:2',
            'health_ceiling' => 'decimal:2',
            'irsa_brackets' => 'array',
            'irsa_minimum' => 'decimal:2',
            'irsa_child_reduction' => 'decimal:2',
            'irsa_base_rounding' => 'integer',
            'allowance_subject' => 'boolean',
        ];
    }

    /** La ligne du site, sinon la proposition non enregistrée et désactivée. */
    public static function current(): self
    {
        $row = Schema::hasTable('payroll_settings') ? self::query()->orderBy('id')->first() : null;

        return $row ?? new self(self::PROPOSAL);
    }

    /** Ce que la paie fige : les règles exactes qui ont produit ses retenues. */
    public function snapshot(): array
    {
        return [
            'legal_deductions_enabled' => (bool) $this->legal_deductions_enabled,
            'cnaps_employee_rate' => (string) $this->cnaps_employee_rate,
            'cnaps_employer_rate' => (string) $this->cnaps_employer_rate,
            'cnaps_ceiling' => $this->cnaps_ceiling !== null ? (string) $this->cnaps_ceiling : null,
            'health_label' => $this->healthLabel(),
            'health_employee_rate' => (string) $this->health_employee_rate,
            'health_employer_rate' => (string) $this->health_employer_rate,
            'health_ceiling' => $this->health_ceiling !== null ? (string) $this->health_ceiling : null,
            'irsa_brackets' => $this->brackets(),
            'irsa_minimum' => (string) $this->irsa_minimum,
            'irsa_child_reduction' => (string) $this->irsa_child_reduction,
            'irsa_base_rounding' => $this->irsa_base_rounding,
            'allowance_subject' => (bool) $this->allowance_subject,
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function healthLabel(): string
    {
        return filled($this->health_label) ? (string) $this->health_label : 'Organisme médical';
    }

    /** @return list<array{up_to: ?string, rate: string}> */
    public function brackets(): array
    {
        return array_values(array_map(
            fn (array $bracket) => [
                'up_to' => isset($bracket['up_to']) && $bracket['up_to'] !== '' ? number_format((float) $bracket['up_to'], 2, '.', '') : null,
                'rate' => number_format((float) ($bracket['rate'] ?? 0), 2, '.', ''),
            ],
            is_array($this->irsa_brackets) ? $this->irsa_brackets : [],
        ));
    }
}
