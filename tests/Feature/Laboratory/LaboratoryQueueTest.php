<?php

namespace Tests\Feature\Laboratory;

use App\Enums\LabItemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Laboratory\Concerns\BuildsLabBench;
use Tests\TestCase;

/** ADR-213 — la file du Laboratoire, par demande. */
class LaboratoryQueueTest extends TestCase
{
    use BuildsLabBench, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedRoles();
    }

    /** Une demande annulée par le médecin quitte la paillasse (ADR-079). */
    public function test_a_cancelled_request_leaves_the_laboratory_bench(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);

        $this->requestItem($this->labRequest($episode, $orientation, $technician), $nfs);
        $this->requestItem($this->labRequest($episode, $orientation, $technician, cancelled: true), $nfs);

        $this->actingAs($technician)->get('/laboratory')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Laboratory/Index', false)
                ->where('counts.to_do', 1)
                ->where('counts.all', 1)
                ->count('requests.data', 1));
    }

    /** Chaque demande est dans une seule vue, lue sur l'état de ses analyses. */
    public function test_each_request_sits_in_one_view(): void
    {
        $technician = $this->userWithRole('LABORATORY');
        [$episode, $orientation] = $this->episodeWithLabOrientation($technician);
        $nfs = $this->prestation($technician);

        $this->requestItem($this->labRequest($episode, $orientation, $technician), $nfs);
        $this->requestItem($this->labRequest($episode, $orientation, $technician), $nfs, ['status' => LabItemStatus::ToRedo]);
        $this->requestItem($this->labRequest($episode, $orientation, $technician), $nfs, ['status' => LabItemStatus::Completed, 'resulted_at' => now(), 'result_value' => 'Normal']);
        $this->requestItem($this->labRequest($episode, $orientation, $technician), $nfs, ['status' => LabItemStatus::Validated, 'resulted_at' => now(), 'validated_at' => now(), 'result_value' => 'Normal']);

        $this->actingAs($technician)->get('/laboratory?view=to_validate')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.to_do', 1)
                ->where('counts.to_redo', 1)
                ->where('counts.to_validate', 1)
                ->where('counts.validated', 1)
                ->where('counts.all', 4)
                ->where('counts.validated_today', 1)
                ->count('requests.data', 1)
                ->where('requests.data.0.state', 'to_validate'));
    }

    /** Un compte sans droit de lecture des résultats n'entre pas à la paillasse. */
    public function test_the_bench_is_guarded(): void
    {
        $this->actingAs($this->userWithRole('RECEPTION'))->get('/laboratory')->assertForbidden();
    }
}
