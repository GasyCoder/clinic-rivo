<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalTeamFunction;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Services\Surgery\SurgeonRoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-168 — programmer l'intervention : un chirurgien principal, des aides.
 *
 * Le principal est `surgical_requests.surgeon_id`, lu partout. Les aides sont
 * des membres de l'équipe de bloc de fonction « Chirurgien » : aucune table
 * nouvelle. Chacun doit porter le profil Chirurgien et être au planning RH à
 * l'heure programmée (SurgeonRoster) — revérifié ici, jamais seulement à l'écran.
 */
class ScheduleSurgicalRequestAction
{
    public const MAX_ASSISTANTS = 5;

    public function __construct(
        private readonly SurgeonRoster $roster,
        private readonly AssignSurgicalTeamMemberAction $assignTeamMember,
        private readonly RemoveSurgicalTeamMemberAction $removeTeamMember,
    ) {}

    /**
     * @param  Collection<int, User>|null  $assistants  null : les aides déjà
     *                                                  inscrits restent tels quels
     *                                                  (omettre n'efface pas, ADR-074)
     */
    public function execute(
        SurgicalRequest $surgicalRequest,
        User $surgeon,
        string $scheduledAt,
        ?Collection $assistants = null,
    ): SurgicalRequest {
        $at = CarbonImmutable::parse($scheduledAt);
        $assistants = $assistants?->values();

        if ($assistants !== null) {
            $this->guardSelection($surgeon, $assistants);
        }

        $selection = ['surgeon_id' => $surgeon];
        foreach ($assistants ?? [] as $index => $assistant) {
            $selection["assistant_surgeon_ids.{$index}"] = $assistant;
        }
        $this->roster->assertSchedulable($selection, $at);

        DB::transaction(function () use ($surgicalRequest, $surgeon, $scheduledAt, $assistants): void {
            $locked = SurgicalRequest::query()->lockForUpdate()->findOrFail($surgicalRequest->getKey());
            $locked->schedule($surgeon, $scheduledAt);

            if ($assistants !== null) {
                $this->syncAssistants($locked, $surgeon, $assistants);
            }
        });

        // L'appelant relit la demande qu'il a passée, pas une copie verrouillée.
        return $surgicalRequest->refresh();
    }

    /** @param Collection<int, User> $assistants */
    private function guardSelection(User $surgeon, Collection $assistants): void
    {
        if ($assistants->count() > self::MAX_ASSISTANTS) {
            throw ValidationException::withMessages([
                'assistant_surgeon_ids' => 'Au plus '.self::MAX_ASSISTANTS.' chirurgiens aides par intervention.',
            ]);
        }

        if ($assistants->contains(fn (User $assistant) => $assistant->is($surgeon))) {
            throw ValidationException::withMessages([
                'assistant_surgeon_ids' => "« {$surgeon->name} » est déjà le chirurgien principal.",
            ]);
        }

        if ($assistants->pluck('id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'assistant_surgeon_ids' => 'Un même chirurgien ne peut être choisi deux fois.',
            ]);
        }
    }

    /**
     * Les membres « Chirurgien » de l'équipe deviennent exactement les aides
     * choisis : un aide retiré quitte l'équipe (retrait audité), un nouvel aide
     * y entre, le principal n'y figure pas deux fois.
     *
     * @param  Collection<int, User>  $assistants
     */
    private function syncAssistants(SurgicalRequest $surgicalRequest, User $surgeon, Collection $assistants): void
    {
        $wanted = $assistants->pluck('id')->map(fn ($id) => (int) $id)->all();
        $current = $surgicalRequest->teamMembers()
            ->where('function', SurgicalTeamFunction::Surgeon->value)
            ->get();

        foreach ($current as $member) {
            if ((int) $member->user_id === (int) $surgeon->id || ! in_array((int) $member->user_id, $wanted, true)) {
                $this->removeTeamMember->execute($member);
            }
        }

        $kept = $current->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        foreach ($assistants as $assistant) {
            if (! in_array((int) $assistant->id, $kept, true)) {
                $this->assignTeamMember->execute($surgicalRequest, $assistant, SurgicalTeamFunction::Surgeon);
            }
        }
    }
}
