<?php

namespace Tests\Feature\Http;

use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La recherche et les points d'attention de l'en-tête.
 *
 * Les deux n'exposent que ce que le compte a déjà le droit de voir : un
 * compteur qui annonce la taille d'une file interdite la divulgue tout
 * autant que la file elle-même.
 */
class HeaderToolsTest extends TestCase
{
    use RefreshDatabase;

    /* ── Recherche ─────────────────────────────────────────────────── */

    public function test_the_search_finds_a_patient_by_name_number_and_phone(): void
    {
        $user = $this->userWith(['patients.view']);
        $patient = $this->patient(['first_name' => 'Eliana', 'last_name' => 'Ranavalona']);

        foreach (['Ranavalona', $patient->patient_number, '0340000000'] as $term) {
            $this->actingAs($user)
                ->getJson('/recherche?q='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('patients.0.uuid', $patient->uuid)
                ->assertJsonPath('patients.0.url', "/patients/{$patient->uuid}");
        }
    }

    public function test_the_search_finds_a_passage_by_its_number(): void
    {
        $user = $this->userWith(['patients.view']);
        $patient = $this->patient();
        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'M-26-0404-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson('/recherche?q=0404')
            ->assertOk()
            ->assertJsonPath('episodes.0.label', 'M-26-0404-01')
            ->assertJsonPath('episodes.0.url', "/passages/{$episode->uuid}");
    }

    /**
     * Un numéro de passage ramène son patient : l'exposer sans
     * `patients.view` contournerait la même protection.
     */
    public function test_the_search_is_closed_without_the_patient_permission(): void
    {
        $this->actingAs($this->userWith([]))
            ->getJson('/recherche?q=Ranavalona')
            ->assertForbidden();
    }

    /** Deux caractères ne discriminent rien : on n'interroge pas la base. */
    public function test_a_too_short_term_returns_nothing(): void
    {
        $user = $this->userWith(['patients.view']);
        $this->patient(['last_name' => 'Ranavalona']);

        $this->actingAs($user)
            ->getJson('/recherche?q=R')
            ->assertOk()
            ->assertJsonCount(0, 'patients')
            ->assertJsonCount(0, 'episodes');
    }

    /* ── Points d'attention ────────────────────────────────────────── */

    public function test_a_passage_awaiting_settlement_is_reported(): void
    {
        $user = $this->userWith(['episodes.settlement.view']);
        $patient = $this->patient();
        Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'M-26-0405-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'PENDING_SETTLEMENT',
            'started_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson('/points-attention')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.key', 'settlements')
            ->assertJsonPath('items.0.url', '/reception/sorties');
    }

    /**
     * La garantie centrale : un compteur ne divulgue pas la taille d'une
     * file que le compte n'a pas le droit de voir.
     */
    public function test_a_queue_is_never_counted_without_its_permission(): void
    {
        $author = $this->userWith(['episodes.settlement.view']);
        $patient = $this->patient();
        Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'M-26-0406-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'PENDING_SETTLEMENT',
            'started_at' => now(),
            'created_by' => $author->id,
        ]);

        $this->actingAs($this->userWith([]))
            ->getJson('/points-attention')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'items');
    }

    /** Rien en attente : aucune ligne inventée pour remplir le panneau. */
    public function test_nothing_pending_reports_nothing(): void
    {
        $this->actingAs($this->userWith(['episodes.settlement.view', 'stock.view']))
            ->getJson('/points-attention')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'items');
    }

    /* ── Fixtures ──────────────────────────────────────────────────── */

    /** @param array<int, string> $names */
    private function userWith(array $names): User
    {
        $role = Role::query()->create([
            'code' => 'ROLE_'.strtoupper(fake()->unique()->lexify('??????')),
            'name' => 'Rôle de test',
        ]);

        foreach ($names as $name) {
            $role->permissions()->syncWithoutDetaching([
                Permission::query()->firstOrCreate(['name' => $name])->id,
            ]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(array $overrides = []): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
            'phone' => '0340000000',
            ...$overrides,
        ]);
    }
}
