<?php

namespace App\Support;

use App\Models\MedicalReferral;
use App\Services\Medicine\ClinicalRichTextSanitizer;

/**
 * ADR-114 — ce qu'une demande de transfert donne à lire.
 *
 * Les champs rédigés (motif, diagnostic, résumé, traitements,
 * recommandations, observations) sont en texte riche. Ils sont servis deux
 * fois : bruts pour l'éditeur, et en HTML assaini pour l'affichage et la
 * lettre de référence. Un texte ancien sans balise garde ses retours à la
 * ligne. Une seule classe, pour que la page Transferts et la lettre ne
 * puissent pas montrer différemment la même demande.
 */
class MedicalReferralDocument
{
    /** @var list<string> */
    public const RICH_FIELDS = ['reason', 'diagnosis', 'clinical_summary', 'treatments_given', 'recommendations', 'notes'];

    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /** @return array<string, mixed> */
    public function present(MedicalReferral $referral): array
    {
        $fields = ['facility' => $referral->facility];

        foreach (self::RICH_FIELDS as $field) {
            $fields[$field] = $referral->{$field};
            $fields[$field.'_html'] = $this->richText->displayHtml($referral->{$field});
        }

        return $fields;
    }

    /** Une ligne lisible dans une liste : jamais de balises. */
    public function plain(?string $value): ?string
    {
        return $this->richText->plainText($value) ?: null;
    }
}
