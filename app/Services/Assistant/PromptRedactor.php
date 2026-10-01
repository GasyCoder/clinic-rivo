<?php

namespace App\Services\Assistant;

/**
 * ADR-222 — ce qui ne part jamais au fournisseur d'IA : les identifiants d'une
 * personne que l'utilisateur aurait collés dans sa question.
 *
 * L'assistant explique le logiciel ; il n'a besoin d'aucune donnée de patient. La
 * question est donc nettoyée avant l'envoi — et c'est la version nettoyée qui est
 * gardée dans la conversation : rien de masqué ne reste en base non plus.
 *
 * Ce filtre reconnaît des formes (adresse email, téléphone, numéro de dossier, de
 * passage, de CIN, longue suite de chiffres). Il ne reconnaît pas un nom propre :
 * l'écran demande de n'en saisir aucun, et les consignes de l'agent l'interdisent.
 */
class PromptRedactor
{
    /** @var array<string, string> motif => remplacement, dans l'ordre d'application */
    private const PATTERNS = [
        // Adresse email.
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu' => '[adresse email]',
        // Téléphone malgache (+261 / 00261 / 0, puis 3x xx xxx xx), espaces ou points permis.
        '/(?:\+|00)?261[\s.\-]?\d{2}[\s.\-]?\d{2}[\s.\-]?\d{3}[\s.\-]?\d{2}\b|\b0\s?3[2-9][\s.\-]?\d{2}[\s.\-]?\d{3}[\s.\-]?\d{2}\b/u' => '[téléphone]',
        // Numéro de dossier, de passage, de bébé, de matricule, de grossesse : lettres, puis groupes de chiffres
        // séparés (A-26-0001, A_26_001-02, A-26-0009-B1, EMP-0001, G-2026-0003).
        '/\b[A-Z]{1,4}(?:[\-\/._]\d{2,8})+(?:[\-\/._](?:B\d{1,2}|\d{1,4}))*\b/u' => '[numéro de dossier]',
        // CIN malgache (12 chiffres, espacés ou non) et toute longue suite de chiffres.
        '/\b\d{3}[\s.]?\d{3}[\s.]?\d{3}[\s.]?\d{3}\b/u' => '[numéro]',
        '/\b\d{8,}\b/u' => '[numéro]',
    ];

    /**
     * @return array{text: string, redactions: int}
     */
    public function redact(string $text): array
    {
        $count = 0;

        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text, -1, $replaced);
            $count += $replaced;
        }

        // Retours à la ligne gardés, espaces multiples resserrés, caractères de contrôle retirés.
        $text = (string) preg_replace('/[^\P{C}\n]+/u', '', $text);
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));

        return ['text' => $text, 'redactions' => $count];
    }
}
