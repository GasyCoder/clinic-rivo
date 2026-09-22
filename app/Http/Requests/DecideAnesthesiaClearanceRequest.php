<?php

namespace App\Http\Requests;

use App\Enums\AnesthesiaClearanceStatus;
use App\Models\AnesthesiaRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-170 — la décision d'autorisation du bloc.
 *
 * La FormRequest ne fait que le contrôle de forme : le motif obligatoire d'un
 * refus, la condition obligatoire d'une autorisation sous conditions et la
 * cohérence avec l'état du dossier appartiennent à
 * `DecideAnesthesiaClearanceAction`, qui les revérifie sous verrou. Un POST
 * direct rencontre donc les mêmes refus.
 */
class DecideAnesthesiaClearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('anesthesiaRecord');

        return $record instanceof AnesthesiaRecord
            && $this->user()?->can('decideClearance', $record) === true;
    }

    public function rules(): array
    {
        $decidable = array_map(
            fn (AnesthesiaClearanceStatus $status) => $status->value,
            AnesthesiaClearanceStatus::decidable(),
        );

        return [
            'status' => ['required', 'string', Rule::in($decidable)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date'],
            'conditions' => ['array', 'max:20'],
            'conditions.*' => ['string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Cette décision n’est pas une décision que l’on prononce.',
        ];
    }
}
