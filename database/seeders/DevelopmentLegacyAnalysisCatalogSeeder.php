<?php

namespace Database\Seeders;

use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\User;
use Database\Seeders\Concerns\LocalOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The 719 historical analyses (ctb-cover), replayed from a fixture.
 *
 * `rivo:import-legacy-analyses` reads the historical MySQL database, which is
 * not always present on a development machine. Its result was exported once
 * to data/legacy_analysis_catalog.json so a fresh database gets the full
 * catalogue without that source. Rows are matched by code, never by id:
 * running it twice changes nothing, and it never overwrites an analysis that
 * already exists.
 *
 * No tariff is created: the historical source carries none, and inventing a
 * price would be a business decision (ADR-024).
 */
class DevelopmentLegacyAnalysisCatalogSeeder extends Seeder
{
    use LocalOnly;

    public function run(): void
    {
        $this->ensureLocal();

        $path = database_path('seeders/data/legacy_analysis_catalog.json');
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $actor = User::query()->where('active', true)->orderBy('id')->first()
            ?? throw new RuntimeException('Créez d’abord un compte local actif (DevelopmentTestAccountSeeder).');

        DB::transaction(function () use ($data, $actor): void {
            $items = collect($data['catalog_items'])->mapWithKeys(fn (array $item) => [
                $item['code'] => CatalogItem::withTrashed()->firstOrCreate(
                    ['code' => $item['code']],
                    [...$item, 'created_by' => $actor->id, 'updated_by' => $actor->id],
                ),
            ]);

            $pending = collect($data['analyses']);

            // Codes are compared by the database's own collation: « UREE »
            // from the base catalogue and « Urée » from the historical source
            // are one code there, and the existing analysis is kept.
            $idFor = fn (string $code): ?int => AnalysisCatalog::withTrashed()->where('code', $code)->value('id');

            // Parents first, whatever order the fixture holds.
            while ($pending->isNotEmpty()) {
                $ready = $pending->filter(fn (array $row) => $row['parent_code'] === null || $idFor($row['parent_code']) !== null);

                if ($ready->isEmpty()) {
                    throw new RuntimeException('Relations parent/enfant irréconciliables dans le catalogue historique.');
                }

                foreach ($ready as $row) {
                    if ($idFor($row['code']) !== null) {
                        continue;
                    }

                    AnalysisCatalog::query()->create([
                        ...collect($row)->except(['parent_code', 'catalog_item_code'])->all(),
                        'predefined_values' => $this->decode($row['predefined_values']),
                        'source_metadata' => $this->decode($row['source_metadata']),
                        'catalog_item_id' => $items[$row['catalog_item_code']]->id,
                        'parent_id' => $row['parent_code'] ? $idFor($row['parent_code']) : null,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                }

                $pending = $pending->diffKeys($ready);
            }
        });
    }

    private function decode(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
