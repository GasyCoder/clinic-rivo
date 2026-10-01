<?php

use App\Enums\LabEntryMode;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-238 — la discipline d'une analyse (Hématologie, Biochimie…) devient un
 * référentiel du site au lieu d'un texte libre, et le type de résultat d'une
 * analyse s'accorde à la façon dont la paillasse la saisit.
 *
 *   - `lab_disciplines` : une ligne par discipline, son ordre d'impression ;
 *   - `analysis_catalogs.lab_discipline_id` : la discipline d'une analyse,
 *     celle de son groupe pour une sous-analyse. `exam_category` reste, en
 *     copie du nom, pour tout ce qui le lit déjà ;
 *   - reprise : une discipline par texte déjà écrit, casse, accents et espaces
 *     ignorés (« HEMATOLOGIE » et « Hématologie » ne font qu'une). Une faute de
 *     frappe (« BIOCHIME ») reste une discipline à part : la fusionner serait
 *     deviner, elle se fusionne à la main ;
 *   - le type de résultat d'une analyse dont la saisie réelle ne lui va pas
 *     (« Texte » saisi en numérique, « Choix » sans valeur saisi en texte…) est
 *     aligné sur cette saisie. La paillasse ne change pas : seul le type affiché
 *     dit enfin ce qu'elle fait.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'lab_disciplines.view' => 'Voir les disciplines du laboratoire (Hématologie, Biochimie…)',
        'lab_disciplines.create' => 'Ajouter une discipline du laboratoire',
        'lab_disciplines.update' => 'Modifier, réordonner ou fusionner une discipline du laboratoire',
        'lab_disciplines.archive' => 'Archiver une discipline du laboratoire',
        'lab_disciplines.restore' => 'Restaurer une discipline du laboratoire',
    ];

    /** Le Laboratoire et l'Administration, qui tient le catalogue des analyses. */
    private const GRANTS = ['LABORATORY', 'ADMINISTRATION'];

    public function up(): void
    {
        Schema::create('lab_disciplines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();
        });

        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->foreignId('lab_discipline_id')->nullable()->after('exam_category')
                ->constrained('lab_disciplines', indexName: 'analysis_catalogs_lab_discipline_fk')->restrictOnDelete();
        });

        $this->alignResultTypes();
        $this->backfillDisciplines();
        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->dropForeign('analysis_catalogs_lab_discipline_fk');
            $table->dropColumn('lab_discipline_id');
        });
        Schema::dropIfExists('lab_disciplines');

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Cache::forget(Permission::CACHE_KEY);
    }

    /**
     * Le mode réellement saisi à la paillasse avant cette migration (l'ancienne
     * règle de LabEntryMode::for, écrite ici pour que la reprise ne dépende pas
     * d'une version future), et le type de résultat qui lui va.
     */
    private function alignResultTypes(): void
    {
        DB::table('analysis_catalogs')
            ->select(['id', 'result_type', 'entry_mode', 'predefined_values', 'source_metadata'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $values = json_decode((string) $row->predefined_values, true);
                $hasChoices = is_array($values) && count($values) > 0;
                $metadata = json_decode((string) $row->source_metadata, true);
                $legacyType = is_array($metadata) ? ($metadata['type_name'] ?? null) : null;

                $mode = LabEntryMode::tryFrom((string) $row->entry_mode)
                    ?? LabEntryMode::fromLegacyType($legacyType, $hasChoices)
                    ?? match ($row->result_type) {
                        'NUMERIC' => LabEntryMode::Numeric,
                        'CHOICE' => $hasChoices ? LabEntryMode::Choice : LabEntryMode::Text,
                        'BOOLEAN' => LabEntryMode::NegativePositive,
                        default => $hasChoices ? LabEntryMode::Choice : LabEntryMode::Text,
                    };

                if (! $mode->fitsResultType($row->result_type)) {
                    DB::table('analysis_catalogs')->where('id', $row->id)->update(['result_type' => $mode->homeResultType()]);
                }
            });
    }

    private function backfillDisciplines(): void
    {
        $rows = DB::table('analysis_catalogs')
            ->select(['id', 'parent_id', 'exam_category', 'display_order'])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $normalize = fn (?string $text): string => Str::of((string) $text)->ascii()->lower()->squish()->toString();

        // Une discipline par texte normalisé, sous l'orthographe la plus écrite.
        $spellings = [];
        foreach ($rows as $row) {
            $text = Str::squish((string) $row->exam_category);
            if ($text !== '') {
                $spellings[$normalize($text)][$text] = ($spellings[$normalize($text)][$text] ?? 0) + 1;
            }
        }

        $names = collect($spellings)->map(function (array $counts): string {
            arsort($counts);

            return (string) array_key_first($counts);
        })->sort(fn ($a, $b) => strcmp($a, $b));

        $now = now();
        $ids = [];
        $order = 0;
        foreach ($names as $normalized => $name) {
            $order += 10;
            $ids[$normalized] = DB::table('lab_disciplines')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'normalized_name' => $normalized,
                'display_order' => $order,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Une sous-analyse prend la discipline de son groupe : celle du groupe
        // principal, sinon la première écrite plus bas (comme la paillasse la lit).
        $children = $rows->groupBy('parent_id');
        $descendants = function (int $id) use (&$descendants, $children): array {
            $found = [];
            foreach ($children->get($id, collect()) as $child) {
                $found[] = $child;
                array_push($found, ...$descendants($child->id));
            }

            return $found;
        };

        foreach ($rows->whereNull('parent_id') as $root) {
            $family = [$root, ...$descendants($root->id)];
            $category = collect($family)->map(fn ($row) => Str::squish((string) $row->exam_category))->first(fn ($text) => $text !== '');
            $disciplineId = $category !== null ? ($ids[$normalize($category)] ?? null) : null;

            if ($disciplineId === null) {
                continue;
            }

            DB::table('analysis_catalogs')
                ->whereIn('id', array_map(fn ($row) => $row->id, $family))
                ->update(['lab_discipline_id' => $disciplineId, 'exam_category' => $names[$normalize($category)]]);
        }
    }

    private function grantPermissions(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('roles')->whereIn('code', self::GRANTS)->whereNull('deleted_at')->pluck('id')
            ->each(fn (int $roleId) => $permissionIds->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ])));

        Cache::forget(Permission::CACHE_KEY);
    }
};
