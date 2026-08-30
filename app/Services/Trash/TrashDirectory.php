<?php

namespace App\Services\Trash;

use App\Actions\Catalog\RestoreCatalogItemAction;
use App\Actions\Patient\RestorePatientAction;
use App\Enums\TrashCategory;
use App\Models\AddressEntry;
use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\CatalogItem;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Services\Administration\AddressEntryManager;
use App\Services\Administration\MutualOrganizationManager;
use App\Services\Cash\CashRegisterManager;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Explicit registry of records that may safely be restored from the central
 * trash. Clinical/financial records are intentionally absent: those require
 * their own cancel/correct/reverse workflow and must never reach a generic
 * restore() call.
 */
class TrashDirectory
{
    public const MAX_RESULTS = 100;

    public function __construct(
        private readonly RestorePatientAction $restorePatient,
        private readonly RestoreCatalogItemAction $restoreCatalogItem,
        private readonly AddressEntryManager $addressEntries,
        private readonly MutualOrganizationManager $mutualOrganizations,
        private readonly CashRegisterManager $cashRegisters,
    ) {}

    /**
     * @param  array{search?: string|null, category?: string|null, deleted_from?: string|null, deleted_to?: string|null}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $selected = ($filters['category'] ?? 'ALL') === 'ALL'
            ? TrashCategory::cases()
            : [TrashCategory::from($filters['category'])];

        $counts = [];
        $entities = collect();

        foreach (TrashCategory::cases() as $category) {
            $query = $this->query($category, $filters);
            $counts[$category->value] = (clone $query)->count();

            if (in_array($category, $selected, true)) {
                $entities->push(...$query
                    ->latest('deleted_at')
                    ->limit(self::MAX_RESULTS)
                    ->get()
                    ->map(fn (Model $model): array => [
                        'category' => $category,
                        'model' => $model,
                    ]));
            }
        }

        $entities = $entities
            ->sortByDesc(fn (array $entry) => $entry['model']->deleted_at?->getTimestamp() ?? 0)
            ->take(self::MAX_RESULTS)
            ->values();
        $actors = $this->deletionActors($entities);
        $selectedTotal = collect($selected)->sum(fn (TrashCategory $category): int => $counts[$category->value]);

        return [
            'data' => $entities->map(fn (array $entry): array => $this->serialize(
                $entry['category'],
                $entry['model'],
                $actors->get($this->actorKey($entry['model'])),
            ))->all(),
            'meta' => [
                'site' => [
                    'code' => config('rivo.site.code'),
                    'name' => config('rivo.site.name'),
                ],
                'summary' => [
                    'total' => $selectedTotal,
                    'displayed' => $entities->count(),
                    'limited' => $selectedTotal > self::MAX_RESULTS,
                    'categories' => $counts,
                ],
            ],
        ];
    }

    /** @return array{category: string, uuid: string, already_restored: bool} */
    public function restore(TrashCategory $category, string $uuid, CatalogActor $actor): array
    {
        if ($actor->cannot('trash.restore') || $actor->cannot($category->restorePermission())) {
            throw new AuthorizationException('Cette restauration distante n’est pas autorisée.');
        }

        return DB::transaction(function () use ($category, $uuid, $actor): array {
            /** @var Model&SoftDeletes $model */
            $model = $this->modelQuery($category)
                ->withTrashed()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();
            $alreadyRestored = ! $model->trashed();

            if (! $alreadyRestored) {
                match ($category) {
                    TrashCategory::Patient => $this->restorePatient->execute($model),
                    TrashCategory::CatalogItem => $this->restoreCatalogItem->execute($model, $actor),
                    TrashCategory::AddressEntry => $this->addressEntries->restore($model),
                    TrashCategory::MutualOrganization => $this->mutualOrganizations->restore($model),
                    TrashCategory::CashRegister => $this->cashRegisters->restore($model),
                };
            }

            return [
                'category' => $category->value,
                'uuid' => $uuid,
                'already_restored' => $alreadyRestored,
            ];
        });
    }

    /** @return Builder<Model> */
    private function query(TrashCategory $category, array $filters): Builder
    {
        $query = $this->modelQuery($category)->onlyTrashed();
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $nested) use ($category, $search): void {
                match ($category) {
                    TrashCategory::Patient => $nested
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"),
                    TrashCategory::CatalogItem => $nested
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"),
                    TrashCategory::AddressEntry => $nested
                        ->where('label', 'like', "%{$search}%"),
                    TrashCategory::MutualOrganization, TrashCategory::CashRegister => $nested
                        ->where('name', 'like', "%{$search}%"),
                };
            });
        }

        if (filled($filters['deleted_from'] ?? null)) {
            $query->whereDate('deleted_at', '>=', $filters['deleted_from']);
        }

        if (filled($filters['deleted_to'] ?? null)) {
            $query->whereDate('deleted_at', '<=', $filters['deleted_to']);
        }

        return $query;
    }

    /** @return Builder<Model> */
    private function modelQuery(TrashCategory $category): Builder
    {
        return match ($category) {
            TrashCategory::Patient => Patient::query(),
            TrashCategory::CatalogItem => CatalogItem::query(),
            TrashCategory::AddressEntry => AddressEntry::query(),
            TrashCategory::MutualOrganization => MutualOrganization::query(),
            TrashCategory::CashRegister => CashRegister::query(),
        };
    }

    /**
     * @param  Collection<int, array{category: TrashCategory, model: Model}>  $entities
     * @return Collection<string, AuditLog>
     */
    private function deletionActors(Collection $entities): Collection
    {
        return $entities
            ->groupBy(fn (array $entry): string => $entry['model']->getMorphClass())
            ->flatMap(function (Collection $entries, string $type): Collection {
                $ids = $entries->pluck('model.id')->filter()->values();

                return AuditLog::query()
                    ->with('user:id,name')
                    ->where('action', 'delete')
                    ->where('entity_type', $type)
                    ->whereIn('entity_id', $ids)
                    ->latest('id')
                    ->get()
                    ->unique('entity_id')
                    ->mapWithKeys(fn (AuditLog $log): array => [
                        $type.':'.$log->entity_id => $log,
                    ]);
            });
    }

    private function actorKey(Model $model): string
    {
        return $model->getMorphClass().':'.$model->getKey();
    }

    /** @return array<string, mixed> */
    private function serialize(TrashCategory $category, Model $model, ?AuditLog $deletion): array
    {
        [$title, $reference, $subtitle] = match ($category) {
            TrashCategory::Patient => [
                trim($model->last_name.' '.$model->first_name),
                $model->patient_number,
                $model->patient_type?->label(),
            ],
            TrashCategory::CatalogItem => [
                $model->name,
                $model->code,
                $model->module?->label(),
            ],
            TrashCategory::AddressEntry => [$model->label, null, 'Référentiel administratif'],
            TrashCategory::MutualOrganization => [
                $model->name,
                null,
                'Couverture '.$model->coverage_rate.' %',
            ],
            TrashCategory::CashRegister => [$model->name, null, 'Référentiel des caisses'],
        };

        return [
            'uuid' => $model->uuid,
            'category' => $category->value,
            'category_label' => $category->singularLabel(),
            'category_icon' => $category->icon(),
            'title' => $title,
            'reference' => $reference,
            'subtitle' => $subtitle,
            'deleted_at' => $model->deleted_at?->toIso8601String(),
            'deleted_by' => $deletion?->user?->name
                ?? $deletion?->external_actor_name
                ?? 'Système',
            'delete_reason' => $model->delete_reason,
            'can_restore' => true,
        ];
    }
}
