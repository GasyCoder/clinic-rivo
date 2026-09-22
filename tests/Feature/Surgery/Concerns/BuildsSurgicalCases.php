<?php

namespace Tests\Feature\Surgery\Concerns;

use App\Enums\AnesthesiaClearanceStatus;
use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalChecklistRole;
use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTeamFunction;
use App\Models\AnesthesiaRecord;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Support\SurgicalSafetyChecklistItems;

/**
 * ADR-170 — amener un dossier jusqu'au point où l'incision est réellement
 * autorisée demande désormais plusieurs gestes de plusieurs métiers. Les
 * rassembler ici évite de les recopier dans chaque test, et rend lisible ce
 * qu'un test **retire** volontairement pour vérifier qu'il bloque.
 */
trait BuildsSurgicalCases
{
    protected function surgeonUser(): User
    {
        return $this->caseUser('SURGERY', 'Chirurgie', 'SURGEON', 'Chirurgien / Chirurgienne', [
            'surgery.view', 'surgery.update', 'surgery.schedule', 'surgery.preparation.update',
            'surgery.preoperative.validate', 'surgery.intervention.create', 'surgery.intervention.update',
            'surgery.report.create', 'surgery.report.validate',
        ]);
    }

    /** Un chirurgien sans supervision : il n'opère que ses propres dossiers. */
    protected function otherSurgeonUser(): User
    {
        return $this->caseUser('SURGERY', 'Chirurgie', 'SURGEON', 'Chirurgien / Chirurgienne', [
            'surgery.view', 'surgery.intervention.create', 'surgery.report.validate',
        ]);
    }

    protected function anesthetistUser(): User
    {
        return $this->caseUser('NURSE', 'Soins', 'ANESTHETIST', 'Anesthésiste', [
            'anesthesia.view', 'anesthesia.create', 'anesthesia.update', 'anesthesia.validate',
        ]);
    }

    protected function orNurseUser(): User
    {
        return $this->caseUser('SURGERY', 'Chirurgie', 'OR_NURSE', 'Infirmier de bloc', [
            'surgery.view', 'surgery.preparation.update',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function caseUser(string $roleCode, string $roleName, string $profileCode, string $profileName, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleName]);
        $profile = ProfessionalProfile::query()->firstOrCreate(
            ['code' => $profileCode],
            ['role_id' => $role->id, 'name' => $profileName, 'active' => true],
        );

        $user = User::factory()->create([
            'role_id' => $role->id,
            'professional_profile_id' => $profile->id,
        ]);

        $this->grant($user, $permissions);

        return $user;
    }

    /** @param  array<int, string>  $permissions */
    protected function grant(User $user, array $permissions): void
    {
        $ids = [];

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                ['label' => $name],
            );
            $ids[$permission->id] = ['effect' => 'allow'];
        }

        $user->permissions()->syncWithoutDetaching($ids);
    }

    protected function makeSurgicalEpisode(): Episode
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        return Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
        ]);
    }

    /** Un dossier programmé, préopératoire validé, sans anesthésie ni checklist. */
    protected function preparedCase(User $surgeon): SurgicalRequest
    {
        $case = $this->makeSurgicalEpisode()->surgicalRequests()->create([
            'requested_by' => $surgeon->id,
            'created_by' => $surgeon->id,
            'status' => SurgicalRequestStatus::Pending,
            'procedure_name' => 'Appendicectomie',
        ]);

        $case->schedule($surgeon, now()->addDay()->toDateTimeString());
        $case->teamMembers()->create([
            'user_id' => $surgeon->id,
            'function' => SurgicalTeamFunction::Surgeon,
            'assigned_by' => $surgeon->id,
            'assigned_at' => now(),
        ]);
        $case->validatePreoperative($surgeon);

        return $case->fresh();
    }

    /** Le dossier d'anesthésie, évaluation validée et autorisation prononcée. */
    protected function anesthesiaCleared(
        SurgicalRequest $case,
        User $anesthetist,
        AnesthesiaClearanceStatus $status = AnesthesiaClearanceStatus::Cleared,
    ): AnesthesiaRecord {
        $case->teamMembers()->create([
            'user_id' => $anesthetist->id,
            'function' => SurgicalTeamFunction::Anesthetist,
            'assigned_by' => $anesthetist->id,
            'assigned_at' => now(),
        ]);

        $record = $case->anesthesiaRecord()->create([
            'anesthetist_id' => $anesthetist->id,
            'notes' => 'AG standard',
            'assessment_validated_by' => $anesthetist->id,
            'assessment_validated_at' => now(),
            'clearance_status' => $status,
            'clearance_decided_by' => $anesthetist->id,
            'clearance_decided_at' => now(),
            'clearance_reason' => $status->requiresReason() ? 'Motif consigné pour le test.' : null,
        ]);

        return $record->fresh();
    }

    /** Un temps de checklist complet : tous les points requis, toutes les confirmations. */
    protected function completeChecklist(SurgicalRequest $case, SurgicalChecklistPhase $phase, User $surgeon, User $anesthetist, ?User $nurse = null): void
    {
        $items = [];

        foreach (SurgicalSafetyChecklistItems::requiredKeys($phase) as $key) {
            $items[$key] = true;
        }

        $checklist = $case->safetyChecklists()->firstOrCreate(
            ['phase' => $phase->value],
            ['checked_items' => [], 'created_by' => $surgeon->id],
        );

        $checklist->checked_items = array_merge($checklist->checked_items ?? [], $items);
        $checklist->save();

        foreach ($phase->requiredRoles() as $role) {
            $by = match ($role) {
                SurgicalChecklistRole::Anesthesia => $anesthetist,
                SurgicalChecklistRole::Surgeon => $surgeon,
                SurgicalChecklistRole::Nursing => $nurse ?? $surgeon,
            };

            $checklist->confirmations()->firstOrCreate(
                ['role' => $role->value],
                ['confirmed_by' => $by->id, 'confirmed_at' => now()],
            );
        }

        $checklist->load('confirmations');
        $checklist->refreshCompletion();
    }

    /**
     * Le dossier tel qu'il doit être pour que l'incision soit autorisée :
     * préopératoire validé, anesthésiste affecté, autorisation prononcée,
     * SIGN IN et TIME OUT confirmés par chaque métier.
     */
    protected function caseReadyForIncision(User $surgeon, User $anesthetist): SurgicalRequest
    {
        $case = $this->preparedCase($surgeon);
        $this->anesthesiaCleared($case, $anesthetist);
        $this->completeChecklist($case, SurgicalChecklistPhase::SignIn, $surgeon, $anesthetist);
        $this->completeChecklist($case, SurgicalChecklistPhase::TimeOut, $surgeon, $anesthetist);

        return $case->fresh();
    }
}
