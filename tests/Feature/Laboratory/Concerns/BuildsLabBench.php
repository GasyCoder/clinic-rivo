<?php

namespace Tests\Feature\Laboratory\Concerns;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;

/** ADR-213 — une paillasse minimale : un patient, une demande, des analyses définies. */
trait BuildsLabBench
{
    private int $patientSequence = 0;

    private int $labSequence = 0;

    protected function seedRoles(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    protected function userWithRole(string $code): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', $code)->value('id')])->fresh(['role']);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    protected function episodeWithLabOrientation(User $actor): array
    {
        $this->patientSequence++;
        $number = sprintf('A-26-%04d', 8000 + $this->patientSequence);
        $patient = Patient::query()->create([
            'patient_number' => $number, 'first_name' => 'Soa', 'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01', 'sex' => 'F',
        ]);
        $episode = Episode::query()->create([
            'patient_id' => $patient->id, 'episode_number' => $number.'-01',
            'status' => 'OPEN', 'priority' => 'NORMAL', 'started_at' => now(),
            'administrative_status' => 'IN_CARE', 'created_by' => $actor->id,
        ]);
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Medicine, CatalogModule::Laboratory, $actor, 'Bilan',
        );

        return [$episode, $orientation];
    }

    protected function prestation(User $actor, string $code = 'LAB-NFS', string $name = 'NFS'): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code, 'name' => $name, 'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory, 'unit' => 'analyse',
            'billable' => true, 'stockable' => false, 'reception_selectable' => false,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function definition(CatalogItem $prestation, array $attributes): AnalysisCatalog
    {
        return AnalysisCatalog::query()->create([
            'catalog_item_id' => $prestation->id,
            'level' => 'NORMAL',
            'result_type' => 'TEXT',
            'is_active' => true,
            'display_order' => 0,
            ...$attributes,
        ]);
    }

    /**
     * Une demande déjà reçue au laboratoire par défaut (ADR-214) : la plupart des
     * tests portent sur la paillasse, qui suit la réception. `received: false`
     * pour une demande qui attend encore sa réception.
     */
    protected function labRequest(Episode $episode, EpisodeOrientation $orientation, User $actor, bool $cancelled = false, bool $received = true): LabRequest
    {
        $this->labSequence++;

        return LabRequest::query()->create([
            'episode_id' => $episode->id, 'lab_orientation_id' => $orientation->id,
            'requested_by' => $actor->id, 'requested_at' => now(),
            ...($received ? ['received_at' => now(), 'received_by' => $actor->id, 'lab_number' => sprintf('A-L26-%05d', 90000 + $this->labSequence)] : []),
            ...($cancelled ? ['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => 'Plus nécessaire'] : []),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function requestItem(LabRequest $request, CatalogItem $prestation, array $attributes = []): LabRequestItem
    {
        return LabRequestItem::query()->create([
            'lab_request_id' => $request->id, 'catalog_item_id' => $prestation->id,
            'catalog_item_uuid' => $prestation->uuid,
            'catalog_item_name_snapshot' => $prestation->name, 'catalog_item_code_snapshot' => $prestation->code,
            ...$attributes,
        ]);
    }
}
