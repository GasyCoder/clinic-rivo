<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-113 — les demandes d'hospitalisation transmises avant ce module
 * attendaient dans une orientation que personne ne prenait. La règle
 * d'admission automatique leur est appliquée telle quelle : admis à l'heure
 * de la demande, par le médecin qui l'a faite. Seuls les passages encore
 * ouverts sont repris ; rien n'est inventé pour un passage déjà clos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $requests = DB::table('hospitalization_requests as hr')
            ->join('episodes as e', 'e.id', '=', 'hr.episode_id')
            ->join('episode_orientations as eo', 'eo.id', '=', 'hr.episode_orientation_id')
            ->leftJoin('hospital_stays as hs', 'hs.hospitalization_request_id', '=', 'hr.id')
            ->where('hr.status', 'REQUESTED')
            ->where('e.status', 'OPEN')
            ->where('eo.status', 'PENDING')
            ->whereNull('hs.id')
            ->select('hr.*', 'e.id as the_episode_id', 'eo.id as the_orientation_id')
            ->orderBy('hr.id')
            ->get();

        foreach ($requests as $request) {
            $activeKey = 'EPISODE_'.$request->the_episode_id;

            if (DB::table('hospital_stays')->where('active_key', $activeKey)->exists()) {
                continue;
            }

            DB::table('hospital_stays')->insert([
                'uuid' => (string) Str::uuid(),
                'episode_id' => $request->the_episode_id,
                'hospitalization_request_id' => $request->id,
                'episode_orientation_id' => $request->the_orientation_id,
                'status' => 'ACTIVE',
                'service' => $request->requested_service,
                'admitted_at' => $request->requested_at,
                'admitted_by' => $request->requested_by,
                'active_key' => $activeKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('episode_orientations')->where('id', $request->the_orientation_id)->update([
                'status' => 'IN_PROGRESS',
                'accepted_by' => $request->requested_by,
                'accepted_at' => $request->requested_at,
                'updated_at' => now(),
            ]);

            DB::table('episodes')->where('id', $request->the_episode_id)->update([
                'medical_status' => 'HOSPITALIZED',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irréversible sans perte : le séjour a pu recevoir sa fiche de régime.
    }
};
