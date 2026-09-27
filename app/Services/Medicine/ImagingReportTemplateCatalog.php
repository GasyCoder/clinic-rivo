<?php

namespace App\Services\Medicine;

use App\Models\ImagingExamReportTemplate;
use App\Models\ImagingReportTemplate;
use App\Models\ImagingRequestItem;
use App\Models\User;
use App\Support\ImagingReportTemplates;

/**
 * Les feuilles proposées au médecin : celles de la clinique (papier), puis
 * celles que les médecins du site ont ajoutées (ADR-108).
 *
 * Une seule source pour la consultation et pour « Demandes d'examens » : deux
 * écrans qui ne proposeraient pas les mêmes feuilles finiraient par produire
 * deux comptes rendus différents pour le même examen.
 */
class ImagingReportTemplateCatalog
{
    public const CUSTOM_KEY_PREFIX = 'custom-';

    /** @var array<int, string|null>|null  catalog_item_id => feuille réglée par le site (nulle : aucune) */
    private ?array $overrides = null;

    /** @var array<int, string>|null */
    private ?array $offeredKeys = null;

    /**
     * @return array<int, array{key: string, label: string, title: string, description: string, body_html: string, validated: bool, custom: bool, uuid: string|null}>
     */
    public function all(): array
    {
        $builtIn = array_map(
            static fn (array $template): array => $template + ['custom' => false, 'uuid' => null],
            ImagingReportTemplates::all(),
        );

        $custom = ImagingReportTemplate::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (ImagingReportTemplate $template): array => [
                'key' => self::CUSTOM_KEY_PREFIX.$template->uuid,
                'label' => $template->name,
                'title' => mb_strtoupper($template->name),
                'validated' => true,
                'description' => $template->description ?? 'Feuille ajoutée par les médecins du site',
                'body_html' => $template->body_html,
                'custom' => true,
                'uuid' => $template->uuid,
            ])
            ->all();

        return [...$builtIn, ...$custom];
    }

    /**
     * Ce que le compte connecté peut faire des feuilles : l'écran n'affiche
     * « + » et « Retirer » qu'à qui en a le droit, le serveur le revérifie.
     *
     * @return array{create: bool, update: bool, archive: bool}
     */
    public function rightsFor(User $user): array
    {
        return [
            'create' => $user->can('imaging_templates.create'),
            'update' => $user->can('imaging_templates.update'),
            'archive' => $user->can('imaging_templates.archive'),
        ];
    }

    /** Un libellé déjà pris, par une feuille de la clinique ou une feuille ajoutée. */
    public function nameIsTaken(string $name, ?ImagingReportTemplate $except = null): bool
    {
        $needle = mb_strtolower(trim($name));

        foreach (ImagingReportTemplates::all() as $template) {
            if (mb_strtolower($template['label']) === $needle) {
                return true;
            }
        }

        return ImagingReportTemplate::query()
            ->whereRaw('LOWER(name) = ?', [$needle])
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }

    /**
     * Le titre du bandeau pour une feuille choisie, ou `null` si elle n'existe
     * plus. C'est ce titre qu'on fige sur le compte rendu (instantané).
     */
    public function titleFor(string $key): ?string
    {
        foreach ($this->all() as $template) {
            if ($template['key'] === $key) {
                return $template['title'];
            }
        }

        return null;
    }

    public function keyExists(string $key): bool
    {
        return in_array($key, $this->offeredKeys(), true);
    }

    /**
     * La feuille à pré-appliquer quand on ouvre la saisie d'un examen, ou
     * `null`. Le réglage du site l'emporte — y compris « aucune » — puis la
     * liste explicite par code (`ImagingReportTemplates::DEFAULT_BY_EXAM_CODE`).
     * Une feuille retirée depuis n'est plus proposée : mieux vaut aucune
     * feuille qu'une feuille qui n'existe plus.
     *
     * Rien n'est déduit du nom ou du code de l'examen (ADR-052).
     */
    public function defaultKeyFor(ImagingRequestItem $item): ?string
    {
        $this->overrides ??= ImagingExamReportTemplate::query()->pluck('template_key', 'catalog_item_id')->all();

        $key = array_key_exists($item->catalog_item_id, $this->overrides)
            ? $this->overrides[$item->catalog_item_id]
            : (ImagingReportTemplates::DEFAULT_BY_EXAM_CODE[$item->catalog_item_code_snapshot] ?? null);

        return $key !== null && $this->keyExists($key) ? $key : null;
    }

    /** @return array<int, string> */
    private function offeredKeys(): array
    {
        return $this->offeredKeys ??= array_column($this->all(), 'key');
    }
}
