<?php

namespace App\Http\Requests;

use App\Enums\ArrivalPaymentChoice;
use App\Enums\CatalogItemType;
use App\Enums\EpisodeFinancialMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\CatalogItem;
use App\Models\Episode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreEpisodeServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $episode = $this->route('episode');

        if (! $user || ! $episode instanceof Episode || ! $user->can('episodes.update')) {
            return false;
        }

        $lines = $this->input('catalog_lines', []);
        $isStaff = $episode->financial_mode === EpisodeFinancialMode::Staff;
        $hasFinancialContext = $episode->financial_mode !== null;

        $staffFinancePending = $isStaff
            && is_array($lines)
            && $this->containsUnclassifiedStaffLine($lines);

        if (is_array($lines) && $lines !== [] && $hasFinancialContext && ! $staffFinancePending) {
            if (! $user->can('billing.create') || ! $user->can('billing.validate')) {
                return false;
            }
        }

        if ($this->input('payment_choice') === ArrivalPaymentChoice::Now->value) {
            return ! $isStaff && $user->can('payments.create');
        }

        return true;
    }

    /** @param array<int, mixed> $lines */
    private function containsUnclassifiedStaffLine(array $lines): bool
    {
        $uuids = collect($lines)
            ->pluck('catalog_item_uuid')
            ->filter(fn ($uuid) => is_string($uuid) && $uuid !== '')
            ->unique();

        return $uuids->isNotEmpty()
            && CatalogItem::query()
                ->whereIn('uuid', $uuids)
                ->where('staff_coverage_policy', StaffCoveragePolicy::Unclassified->value)
                ->exists();
    }

    public function rules(): array
    {
        return [
            'defer_designation' => ['sometimes', 'boolean'],
            'catalog_lines' => ['nullable', 'array', 'max:50'],
            'catalog_lines.*.catalog_item_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('type', CatalogItemType::Service->value)
                    ->where('billable', true)
                    ->where('reception_selectable', true)),
            ],
            'catalog_lines.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'max:9999.99',
                'decimal:0,2',
            ],
            'payment_choice' => ['nullable', new Enum(ArrivalPaymentChoice::class)],
            'payment_method_id' => [
                Rule::prohibitedIf($this->input('payment_choice') !== ArrivalPaymentChoice::Now->value),
                Rule::requiredIf($this->input('payment_choice') === ArrivalPaymentChoice::Now->value),
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where('active', true),
            ],
            'payment_reference' => [
                Rule::prohibitedIf($this->input('payment_choice') !== ArrivalPaymentChoice::Now->value),
                'nullable',
                'string',
                'max:255',
            ],
            'cash_register_uuid' => [
                Rule::prohibitedIf($this->input('payment_choice') !== ArrivalPaymentChoice::Now->value),
                'nullable',
                'uuid',
                Rule::exists('cash_registers', 'uuid'),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Episode|null $episode */
            $episode = $this->route('episode');
            $lines = $this->input('catalog_lines', []);
            $hasLines = is_array($lines) && $lines !== [];

            if ($episode?->service_plan_finalized_at) {
                $validator->errors()->add(
                    'catalog_lines',
                    'Le parcours de ce passage a déjà été confirmé.',
                );
            }

            if ($hasLines && $this->boolean('defer_designation')) {
                $validator->errors()->add(
                    'defer_designation',
                    'Une prestation sélectionnée ne peut pas rester à définir.',
                );
            }

            if (! $hasLines && ! $this->boolean('defer_designation')) {
                $validator->errors()->add(
                    'catalog_lines',
                    'Sélectionnez au moins une prestation ou indiquez que le besoin reste à définir.',
                );
            }

            $isStaffMode = $episode?->financial_mode === EpisodeFinancialMode::Staff;

            if ($hasLines
                && ! $isStaffMode
                && $episode?->financial_mode !== null
                && ! $this->filled('payment_choice')) {
                $validator->errors()->add(
                    'payment_choice',
                    'Choisissez « payer maintenant » ou « payer plus tard ».',
                );
            }

            if (! $hasLines && $this->filled('payment_choice')) {
                $validator->errors()->add(
                    'payment_choice',
                    'Le règlement ne s’applique qu’à des prestations sélectionnées.',
                );
            }

            if ($isStaffMode && $this->filled('payment_choice')) {
                $validator->errors()->add(
                    'payment_choice',
                    'La couverture personnel doit être déterminée par RH / Finance avant tout encaissement.',
                );
            }
        }];
    }
}
