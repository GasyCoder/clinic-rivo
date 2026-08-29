<?php

namespace App\Http\Requests;

use App\Enums\CatalogItemType;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodeStatus;
use App\Enums\MutualBeneficiaryType;
use App\Models\Episode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class SetReceptionEpisodeFinancialContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        $episode = $this->route('episode');

        return $this->user()?->can('episodes.update')
            && $episode instanceof Episode;
    }

    public function rules(): array
    {
        $mode = (string) $this->input('financial_mode');
        $isMutual = $mode === EpisodeFinancialMode::Mutual->value;
        $isStaff = $mode === EpisodeFinancialMode::Staff->value;
        $isPartner = $mode === EpisodeFinancialMode::Partner->value;

        return [
            'financial_mode' => ['required', new Enum(EpisodeFinancialMode::class)],
            'mutual_organization_uuid' => [
                Rule::requiredIf($isMutual),
                Rule::prohibitedIf(! $isMutual),
                'nullable',
                'uuid',
                Rule::exists('mutual_organizations', 'uuid')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ],
            'employer_name' => [
                Rule::requiredIf($isMutual),
                Rule::prohibitedIf(! $isMutual),
                'nullable', 'string', 'max:255',
            ],
            'beneficiary_type' => [
                Rule::requiredIf($isMutual),
                Rule::prohibitedIf(! $isMutual),
                'nullable', new Enum(MutualBeneficiaryType::class),
            ],
            'membership_number' => [
                Rule::prohibitedIf(! $isMutual),
                'nullable', 'string', 'max:100',
            ],
            'employee_uuid' => [
                Rule::requiredIf($isStaff),
                Rule::prohibitedIf(! $isStaff),
                'nullable',
                'uuid',
                Rule::exists('employees', 'uuid')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ],
            'partner_organization_uuid' => [
                Rule::requiredIf($isPartner),
                Rule::prohibitedIf(! $isPartner),
                'nullable',
                'uuid',
                Rule::exists('partner_organizations', 'uuid')->where(fn ($query) => $query
                    ->where('active', true)
                    ->whereNull('deleted_at')),
            ],
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.catalog_item_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('type', CatalogItemType::Service->value)
                    ->where('billable', true)
                    ->where('reception_selectable', true)
                    ->whereNotNull('reception_routing_mode')),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],

            // The browser is never permitted to supply a financial result.
            'price' => ['prohibited'],
            'total' => ['prohibited'],
            'covered_amount' => ['prohibited'],
            'patient_amount' => ['prohibited'],
            'lines.*.unit_price' => ['prohibited'],
            'lines.*.line_total' => ['prohibited'],
            'lines.*.coverage_amount' => ['prohibited'],
            'lines.*.patient_amount' => ['prohibited'],
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

            if ($episode?->status !== EpisodeStatus::Open) {
                $validator->errors()->add('financial_mode', 'Le passage doit être ouvert.');
            }

            if ($episode?->service_plan_finalized_at !== null) {
                $validator->errors()->add(
                    'financial_mode',
                    'Le contexte ne peut plus être préparé après la confirmation des prestations.',
                );
            }
        }];
    }
}
