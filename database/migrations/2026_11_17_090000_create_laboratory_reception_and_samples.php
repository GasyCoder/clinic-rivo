<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-214 — ce que la paillasse de l'ADR-213 ne couvrait pas encore, repris du
 * laboratoire de la clinique (labo-vuejs) et du CDC §14 :
 *
 *   - le référentiel des types de prélèvement et des types de tube ;
 *   - la réception d'une demande (numéro de laboratoire, contrôle du règlement)
 *     et ses prélèvements, chacun avec son code-barres ;
 *   - l'envoi d'une analyse à un laboratoire extérieur ;
 *   - des bornes critiques facultatives par analyse du catalogue ;
 *   - la conclusion générale d'une demande.
 *
 * Les demandes déjà travaillées sont réputées reçues, à la date du premier
 * geste de la paillasse : les renvoyer à la réception inventerait un contrôle
 * qui n'a pas eu lieu. Celles que personne n'a touchées restent à réceptionner.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'laboratory_orders.receive' => 'Réceptionner une demande d’analyses (contrôle du règlement)',
        'laboratory_orders.send_out' => 'Envoyer une analyse à un laboratoire extérieur',
        'laboratory_samples.create' => 'Enregistrer un prélèvement et imprimer ses étiquettes',
        'laboratory_samples.update' => 'Déclarer un prélèvement non conforme',
        'laboratory_reports.view' => 'Voir les rapports du laboratoire',
        'laboratory_reports.export' => 'Exporter les rapports du laboratoire en Excel',
        'lab_sample_types.view' => 'Voir le référentiel des prélèvements et des tubes',
        'lab_sample_types.create' => 'Ajouter un type de prélèvement ou de tube',
        'lab_sample_types.update' => 'Modifier un type de prélèvement ou de tube',
        'lab_sample_types.archive' => 'Archiver un type de prélèvement ou de tube',
        'lab_sample_types.restore' => 'Restaurer un type de prélèvement ou de tube',
    ];

    private const GRANTS = [
        'LABORATORY' => [
            'laboratory_orders.receive', 'laboratory_orders.send_out',
            'laboratory_samples.create', 'laboratory_samples.update',
            'laboratory_reports.view', 'laboratory_reports.export',
            'lab_sample_types.view', 'lab_sample_types.create', 'lab_sample_types.update',
            'lab_sample_types.archive', 'lab_sample_types.restore',
        ],
    ];

    public function up(): void
    {
        Schema::create('lab_tube_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 30);
            $table->string('normalized_code', 30)->unique();
            $table->string('name', 120);
            $table->string('cap_color', 60)->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::create('lab_sample_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('normalized_name', 150)->unique();
            $table->foreignId('tube_type_id')->nullable()->constrained('lab_tube_types')->restrictOnDelete();
            $table->string('instructions', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::create('lab_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedInteger('next_number')->default(1);
        });

        Schema::table('lab_requests', function (Blueprint $table) {
            $table->string('lab_number', 30)->nullable()->unique();
            $table->timestamp('received_at')->nullable()->index();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            // Pourquoi la demande a été reçue sans attendre le règlement (urgence, hospitalisation).
            $table->string('payment_exemption', 20)->nullable();
            $table->text('conclusion')->nullable();
            $table->timestamp('conclusion_at')->nullable();
            $table->foreignId('conclusion_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('lab_samples', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lab_request_id')->constrained('lab_requests')->restrictOnDelete();
            $table->foreignId('sample_type_id')->constrained('lab_sample_types')->restrictOnDelete();
            $table->foreignId('tube_type_id')->nullable()->constrained('lab_tube_types')->restrictOnDelete();
            // Instantanés : renommer un type plus tard ne réécrit aucune étiquette.
            $table->string('sample_type_name_snapshot', 150);
            $table->string('tube_code_snapshot', 30)->nullable();
            $table->string('tube_name_snapshot', 120)->nullable();
            $table->string('tube_color_snapshot', 60)->nullable();
            $table->string('tube_color_hex_snapshot', 7)->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->string('barcode', 40)->unique();
            $table->string('notes', 500)->nullable();
            $table->timestamp('collected_at');
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['lab_request_id', 'sequence'], 'lab_samples_request_sequence_unique');
        });

        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->string('external_lab_name', 150)->nullable();
            $table->string('external_reference', 80)->nullable();
            $table->text('sent_out_notes')->nullable();
            $table->timestamp('sent_out_at')->nullable();
            $table->foreignId('sent_out_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->json('critical_ranges')->nullable();
        });

        Schema::table('lab_results', function (Blueprint $table) {
            // AUTO : au-delà d'une borne critique du catalogue ; MANUAL : signalé
            // à la main ; DISMISSED : signal automatique retiré par le laboratoire.
            $table->string('critical_source', 12)->nullable();
            $table->string('critical_snapshot', 120)->nullable();
        });

        $this->backfillReceivedRequests();
        $this->registerPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('lab_results', function (Blueprint $table) {
            $table->dropColumn(['critical_source', 'critical_snapshot']);
        });

        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->dropColumn('critical_ranges');
        });

        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_out_by');
            $table->dropColumn(['external_lab_name', 'external_reference', 'sent_out_notes', 'sent_out_at']);
        });

        Schema::dropIfExists('lab_samples');

        Schema::table('lab_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('conclusion_by');
            $table->dropUnique(['lab_number']);
            $table->dropIndex(['received_at']);
            $table->dropColumn(['lab_number', 'received_at', 'payment_exemption', 'conclusion', 'conclusion_at']);
        });

        Schema::dropIfExists('lab_number_sequences');
        Schema::dropIfExists('lab_sample_types');
        Schema::dropIfExists('lab_tube_types');

        Cache::forget(Permission::CACHE_KEY);
    }

    /**
     * Une demande dont une analyse a été commencée ou rendue a déjà passé la
     * réception : elle reçoit la date et l'auteur du premier geste, et un numéro
     * de laboratoire dans l'ordre des demandes.
     */
    private function backfillReceivedRequests(): void
    {
        $prefix = strtoupper(trim((string) config('rivo.site.code'))) ?: 'X';
        $next = [];

        DB::table('lab_requests')
            ->whereNull('received_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('lab_request_items')
                ->whereColumn('lab_request_items.lab_request_id', 'lab_requests.id')
                ->where(fn ($started) => $started->whereNotNull('lab_request_items.started_at')->orWhereNotNull('lab_request_items.resulted_at')))
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get(['id', 'requested_at', 'created_at'])
            ->each(function ($request) use ($prefix, &$next): void {
                $first = DB::table('lab_request_items')
                    ->where('lab_request_id', $request->id)
                    ->where(fn ($started) => $started->whereNotNull('started_at')->orWhereNotNull('resulted_at'))
                    ->get(['started_at', 'started_by', 'resulted_at', 'resulted_by'])
                    ->map(fn ($item) => [
                        'at' => $item->started_at ?? $item->resulted_at,
                        'by' => $item->started_by ?? $item->resulted_by,
                    ])
                    ->sortBy('at')
                    ->first();

                $receivedAt = $first['at'] ?? $request->requested_at ?? $request->created_at ?? now();
                $firstBy = $first['by'] ?? null;
                $year = (int) date('y', strtotime((string) $receivedAt));
                $next[$year] = ($next[$year] ?? 0) + 1;

                DB::table('lab_requests')->where('id', $request->id)->update([
                    'received_at' => $receivedAt,
                    'received_by' => $firstBy,
                    'lab_number' => sprintf('%s-L%02d-%05d', $prefix, $year, $next[$year]),
                ]);
            });

        foreach ($next as $year => $last) {
            DB::table('lab_number_sequences')->insert(['year' => 2000 + $year, 'next_number' => $last + 1]);
        }
    }

    private function registerPermissions(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        foreach (self::GRANTS as $code => $names) {
            $permissionIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

            DB::table('roles')->where('code', $code)->whereNull('deleted_at')->pluck('id')
                ->each(fn (int $roleId) => $permissionIds->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])));
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
