<?php

namespace App\Services\Assistant;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * ADR-222 — ce que l'assistant sait du logiciel : une fiche Markdown par module
 * réellement présent (`resources/ai/clinic-assistant/*.md`), écrite depuis les
 * routes, les écrans et les décisions du projet — jamais depuis la mémoire d'un
 * modèle.
 *
 * Chaque module déclare ici ses adresses (pour reconnaître la page ouverte), les
 * droits qui l'ouvrent (au moins un), ses mots-clés et ses questions proposées.
 * Un compte ne reçoit jamais la fiche d'un module qu'il ne peut pas ouvrir : le
 * filtre se fait ici, côté serveur, pour les suggestions, l'aide jointe à la
 * question et chaque outil de l'agent.
 *
 * La recherche est lexicale (mots et racines, sans accents) : la documentation
 * tient en quelques dizaines de sections. Si elle grandit, `search()` est le seul
 * point à remplacer par une recherche vectorielle.
 */
class AssistantKnowledge
{
    /**
     * `deployment` : `any` (sites et portail) ou `admin` (portail seulement).
     * `any` : au moins un de ces droits ouvre la fiche ; vide = tout compte.
     * `prefixes` : les droits qui comptent sur ces pages (contexte, GetModuleAccess).
     *
     * @var array<string, array{title: string, paths: list<string>, any: list<string>, deployment: string, prefixes: list<string>, keywords: list<string>, suggestions: list<string>}>
     */
    private const MODULES = [
        'general' => [
            'title' => 'Navigation et compte',
            'paths' => [],
            'any' => [],
            'deployment' => 'any',
            'prefixes' => [],
            'keywords' => ['menu', 'trouver', 'recherche', 'bouton', 'grisé', 'verrouillé', 'droit', 'permission', 'accès', 'refusé', 'profil', 'mot de passe', 'notification', 'messagerie', 'email', 'thème', 'corbeille', 'maintenance'],
            'suggestions' => ['Où trouver une fonctionnalité ?', 'Pourquoi un bouton est grisé ou verrouillé ?', 'Comment changer mon mot de passe ?'],
        ],
        'reception' => [
            'title' => 'Réception',
            'paths' => ['/reception'],
            'any' => ['reception.view', 'episodes.create'],
            'deployment' => 'any',
            'prefixes' => ['reception.', 'episodes.', 'patient_referrals.'],
            'keywords' => ['accueil', 'accueillir', 'arrivée', 'passage', 'nouveau patient', 'besoin', 'estimation', 'prestation', 'mutuelle', 'urgence', 'prise en charge', 'recommandation', 'cadeau', 'nouveau-né', 'bébé', 'enfant'],
            'suggestions' => ['Comment accueillir un nouveau patient ?', 'Comment classer un passage en urgence ?', 'Comment vendre seulement des médicaments ?', 'Qui voit le patient après l’accueil ?'],
        ],
        'settlement' => [
            'title' => 'Sorties & règlements',
            'paths' => ['/reception/sorties'],
            'any' => ['episodes.settlement.view'],
            'deployment' => 'any',
            'prefixes' => ['episodes.settlement', 'episodes.administrative_exit', 'debts.'],
            'keywords' => ['sortie administrative', 'sortie', 'règlement', 'régler', 'dette', 'évadé', 'créance', 'solde', 'fiche de sortie', 'facturer'],
            'suggestions' => ['Comment prononcer la sortie administrative ?', 'Pourquoi un passage n’est pas « À régler » ?', 'Que faire des prestations non facturées ?'],
        ],
        'cash' => [
            'title' => 'Caisse',
            'paths' => ['/cash', '/receipts', '/invoices', '/billing', '/payments'],
            'any' => ['cash.view', 'payments.view', 'payments.create', 'billing.view'],
            'deployment' => 'any',
            'prefixes' => ['cash.', 'payments.', 'billing.', 'receipts.', 'discounts.'],
            'keywords' => ['caisse', 'paiement', 'payer', 'encaisser', 'facture', 'reçu', 'ticket', 'remise', 'coupon', 'session', 'clôturer la caisse'],
            'suggestions' => ['Comment enregistrer un paiement ?', 'Comment retrouver une facture ?', 'Comment encaisser un ticket Pharmacie ?', 'Comment clôturer la caisse ?'],
        ],
        'patients' => [
            'title' => 'Patients',
            'paths' => ['/patients', '/passages'],
            'any' => ['patients.view'],
            'deployment' => 'any',
            'prefixes' => ['patients.', 'treatment_journal.'],
            'keywords' => ['patient', 'dossier', 'répertoire', 'dossier médical', 'journal de traitement', 'parcours', 'passage', 'vip', 'export'],
            'suggestions' => ['Comment retrouver un patient ?', 'Comment imprimer le dossier médical ?', 'Où voir le parcours d’un passage ?'],
        ],
        'care' => [
            'title' => 'Soins',
            'paths' => ['/care'],
            'any' => ['care.create', 'care.update', 'care.view'],
            'deployment' => 'any',
            'prefixes' => ['care.', 'vitals.', 'care_consumables.'],
            'keywords' => ['soins', 'infirmier', 'infirmière', 'constantes', 'tension', 'acte', 'matériel', 'consommable', 'fiche de soins', 'transmettre', 'remettre en file', 'reprendre'],
            'suggestions' => ['Comment prendre un patient en charge ?', 'Comment transmettre un patient au médecin ?', 'Comment déclarer le matériel utilisé ?', 'Comment remettre un patient en file ?'],
        ],
        'medicine' => [
            'title' => 'Médecine — consultation',
            'paths' => ['/medicine'],
            'any' => ['consultations.view'],
            'deployment' => 'any',
            'prefixes' => ['consultations.', 'diagnoses.', 'prescriptions.', 'care_orders.', 'medical_discharge.', 'hospitalization.request', 'surgery.request', 'transfer.request', 'maternity.request', 'pediatrics.request', 'episodes.mark_emergency', 'laboratory_orders.create', 'imaging_orders.create', 'clinical_protocols.'],
            'keywords' => ['consultation', 'médecin', 'diagnostic', 'ordonnance', 'prescription', 'prescrire', 'interrogatoire', 'examen clinique', 'paraclinique', 'analyse', 'échographie', 'clôturer', 'clôture', 'conduite à tenir', 'hospitaliser', 'rouvrir', 'protocole'],
            'suggestions' => ['Comment demander une analyse ?', 'Comment hospitaliser un patient ?', 'Comment enregistrer un diagnostic ?', 'Comment terminer la consultation ?'],
        ],
        'paraclinical' => [
            'title' => 'Examens — laboratoire et imagerie',
            'paths' => ['/medicine/demandes-examens', '/laboratory'],
            'any' => ['paraclinical_requests.view', 'laboratory_orders.view', 'imaging_orders.view', 'laboratory_results.create'],
            'deployment' => 'any',
            'prefixes' => ['paraclinical_requests.', 'laboratory_orders.', 'laboratory_results.', 'imaging_orders.', 'imaging_results.', 'imaging_templates.'],
            'keywords' => ['laboratoire', 'analyse', 'résultat', 'imagerie', 'échographie', 'ecg', 'compte rendu', 'demande d’examen', 'paillasse', 'archiver'],
            'suggestions' => ['Où voir les résultats d’analyse ?', 'Comment saisir un compte rendu d’échographie ?', 'Comment retirer une demande d’examen ?'],
        ],
        'hospitalization' => [
            'title' => 'Hospitalisation',
            'paths' => ['/hospitalisation'],
            'any' => ['hospitalization.view'],
            'deployment' => 'any',
            'prefixes' => ['hospitalization.', 'hospital_diet.', 'hospital_notes.', 'vitals.', 'medical_discharge.'],
            'keywords' => ['hospitalisation', 'hospitaliser', 'séjour', 'lit', 'chambre', 'régime', 'surveillance', 'note du jour', 'sortie d’hospitalisation', 'bloc', 'transfert'],
            'suggestions' => ['Comment attribuer un lit ?', 'Comment prononcer la sortie d’hospitalisation ?', 'Comment transférer le patient au bloc ?', 'Comment remplir la fiche de régime ?'],
        ],
        'surgery' => [
            'title' => 'Chirurgie — bloc opératoire',
            'paths' => ['/surgery'],
            'any' => ['surgery.view'],
            'deployment' => 'any',
            'prefixes' => ['surgery.'],
            'keywords' => ['chirurgie', 'bloc', 'intervention', 'programmer', 'feu vert', 'checklist', 'incision', 'compte rendu', 'clôturer', 'chirurgien', 'réinitialiser'],
            'suggestions' => ['Comment programmer une intervention ?', 'Pourquoi je ne peux pas démarrer l’intervention ?', 'Comment clôturer une intervention chirurgicale ?'],
        ],
        'anesthesia' => [
            'title' => 'Anesthésie',
            'paths' => ['/anesthesia'],
            'any' => ['anesthesia.view'],
            'deployment' => 'any',
            'prefixes' => ['anesthesia.'],
            'keywords' => ['anesthésie', 'anesthésiste', 'pré-anesthésique', 'autorisation', 'autoriser', 'décision', 'conduite anesthésique', 'évaluation'],
            'suggestions' => ['Comment valider l’évaluation pré-anesthésique ?', 'Comment autoriser le bloc ?', 'Quand valider le dossier d’anesthésie ?'],
        ],
        'maternity' => [
            'title' => 'Maternité',
            'paths' => ['/maternity'],
            'any' => ['maternity.view'],
            'deployment' => 'any',
            'prefixes' => ['maternity.', 'newborns.'],
            'keywords' => ['maternité', 'sage-femme', 'grossesse', 'prénatale', 'accouchement', 'nouveau-né', 'bébé', 'acte', 'panier', 'césarienne', 'rendez-vous'],
            'suggestions' => ['Comment commencer une consultation prénatale ?', 'Comment enregistrer des actes ?', 'Comment créer le dossier d’un nouveau-né ?'],
        ],
        'pharmacy' => [
            'title' => 'Pharmacie',
            'paths' => ['/pharmacy'],
            'any' => ['pharmacy.view', 'stock.view', 'medicines.view', 'care_consumables.view', 'purchase_orders.view', 'goods_receipts.view'],
            'deployment' => 'any',
            'prefixes' => ['pharmacy.', 'stock.', 'medicines.', 'care_consumables.', 'purchase_orders.', 'goods_receipts.', 'supplier_invoices.'],
            'keywords' => ['pharmacie', 'médicament', 'stock', 'délivrer', 'délivrance', 'sortie de pharmacie', 'ordonnance', 'lot', 'péremption', 'rupture', 'seuil', 'commande', 'réception', 'fournisseur', 'inventaire', 'ticket'],
            'suggestions' => ['Comment enregistrer une sortie de pharmacie ?', 'Comment consulter le stock ?', 'Où voir les produits presque en rupture ?', 'Comment réceptionner une livraison ?'],
        ],
        'transfers' => [
            'title' => 'Transferts',
            'paths' => ['/transferts'],
            'any' => ['transfers.view', 'transfer.request'],
            'deployment' => 'any',
            'prefixes' => ['transfers.', 'transfer.'],
            'keywords' => ['transfert', 'transférer', 'autre établissement', 'référence', 'départ', 'lettre'],
            'suggestions' => ['Comment transférer un patient ?', 'Comment confirmer le départ du patient ?'],
        ],
        'pediatrics' => [
            'title' => 'Pédiatrie',
            'paths' => ['/pediatrie'],
            'any' => ['pediatrics.view', 'pediatrics.request'],
            'deployment' => 'any',
            'prefixes' => ['pediatrics.'],
            'keywords' => ['pédiatrie', 'enfant', 'pédiatre'],
            'suggestions' => ['Comment orienter un enfant vers la Pédiatrie ?', 'Comment faire sortir un enfant ?'],
        ],
        'deaths' => [
            'title' => 'Registre des décès',
            'paths' => ['/deces'],
            'any' => ['death_records.view'],
            'deployment' => 'any',
            'prefixes' => ['death_records.'],
            'keywords' => ['décès', 'décédé', 'acte de constatation', 'certificat'],
            'suggestions' => ['Comment établir un acte de constatation de décès ?', 'Comment déclarer un décès ?'],
        ],
        'security' => [
            'title' => 'Gardiennage et visiteurs',
            'paths' => ['/guarding', '/reception/visitors'],
            'any' => ['guarding.view', 'visitors.view'],
            'deployment' => 'any',
            'prefixes' => ['guarding.', 'visitors.'],
            'keywords' => ['gardien', 'gardiennage', 'contrôle de sortie', 'visiteur', 'visite', 'qr'],
            'suggestions' => ['Comment contrôler la sortie d’un patient ?', 'Comment enregistrer un visiteur ?'],
        ],
        'hr' => [
            'title' => 'Ressources humaines',
            'paths' => ['/administration'],
            'any' => ['employees.view', 'contracts.view', 'leave.view', 'attendance.view', 'planning.view', 'hr_settings.view', 'generated_documents.view', 'staff_access.receive', 'bonus_awards.view'],
            'deployment' => 'any',
            'prefixes' => ['employees.', 'contracts.', 'leave.', 'attendance.', 'planning.', 'hr_settings.', 'generated_documents.', 'staff_access.', 'bonus_', 'professional_emails.'],
            'keywords' => ['employé', 'personnel', 'rh', 'contrat', 'stage', 'stagiaire', 'congé', 'présence', 'planning', 'garde', 'attestation', 'document', 'badge', 'bonus', 'accès du personnel'],
            'suggestions' => ['Comment ajouter un employé ?', 'Comment accepter une demande de congé ?', 'Comment générer une attestation ?', 'Comment imprimer les badges ?'],
        ],
        'referentials' => [
            'title' => 'Référentiels, tarifs et partenaires',
            'paths' => ['/administration/catalog', '/administration/analyses', '/partenaires'],
            'any' => ['catalog.items.view', 'analysis_catalog.view', 'partner_organizations.view'],
            'deployment' => 'any',
            'prefixes' => ['catalog.', 'analysis_catalog.', 'partner_organizations.'],
            'keywords' => ['tarif', 'prix', 'désignation', 'prestation', 'référentiel', 'catalogue', 'analyses', 'valeur de référence', 'partenaire', 'mutuelle'],
            'suggestions' => ['Comment modifier un tarif ?', 'Comment ajouter un partenaire ?', 'Où régler les valeurs de référence des analyses ?'],
        ],
        'access' => [
            'title' => 'Utilisateurs et accès',
            'paths' => ['/administration/users'],
            'any' => ['users.view'],
            'deployment' => 'any',
            'prefixes' => ['users.', 'roles.', 'permissions.'],
            'keywords' => ['utilisateur', 'compte', 'rôle', 'profil métier', 'droit', 'permission', 'désactiver', 'créer un compte'],
            'suggestions' => ['Comment créer un compte utilisateur ?', 'Pourquoi un compte n’a pas accès à un module ?'],
        ],
        'superadmin' => [
            'title' => 'Portail Super Administration',
            'paths' => ['/super-admin'],
            'any' => ['super_admin.portal.view'],
            'deployment' => 'admin',
            'prefixes' => ['settings.', 'ai_settings.', 'app_maintenance.', 'roles.', 'users.', 'staff_access.'],
            'keywords' => ['portail', 'super admin', 'paramètres', 'assistant ia', 'clé api', 'fournisseur', 'rôles', 'permissions', 'maintenance', 'site', 'accès du personnel'],
            'suggestions' => ['Comment régler l’assistant IA ?', 'Comment modifier les droits d’un rôle ?', 'Comment créer l’accès d’un nouvel employé ?', 'Comment mettre un site en maintenance ?'],
        ],
    ];

    /** Mots trop fréquents pour distinguer une section d'une autre. */
    private const STOPWORDS = [
        'les', 'des', 'une', 'pour', 'dans', 'est', 'sur', 'par', 'pas', 'que', 'qui', 'quoi', 'comment', 'faire', 'fait',
        'avec', 'mon', 'mes', 'son', 'ses', 'aux', 'est', 'sont', 'peux', 'peut', 'puis', 'pourquoi', 'quand', 'ou', 'cette',
        'ces', 'cet', 'ce', 'je', 'il', 'elle', 'nous', 'vous', 'leur', 'tout', 'tous', 'plus', 'moins', 'bien', 'aussi', 'the',
        'comme', 'entre', 'depuis', 'apres', 'avant', 'encore', 'deja', 'etre', 'avoir', 'une', 'ete', 'lui', 'mais',
    ];

    /** Taille maximale de la fiche du module courant jointe à la question. */
    private const CURRENT_MODULE_BUDGET = 7000;

    /** Taille maximale des sections d'autres modules jointes à la question. */
    private const RELATED_BUDGET = 5000;

    /** @var array<string, list<array{module: string, heading: string, text: string, stems: array<string, true>, headingStems: array<string, true>}>> */
    private array $sections = [];

    /** @var array<string, string> */
    private array $documents = [];

    /** @return list<string> */
    public function moduleKeys(): array
    {
        return array_keys(self::MODULES);
    }

    public function has(string $module): bool
    {
        return isset(self::MODULES[$module]);
    }

    public function title(string $module): string
    {
        return self::MODULES[$module]['title'] ?? $module;
    }

    /** @return list<string> */
    public function prefixes(string $module): array
    {
        return self::MODULES[$module]['prefixes'] ?? [];
    }

    /** Le chemin d'entrée d'un module, pour qu'on sache où cliquer. */
    public function entryPath(string $module): ?string
    {
        return self::MODULES[$module]['paths'][0] ?? null;
    }

    /** Le compte peut-il ouvrir ce module, sur ce déploiement ? */
    public function accessible(string $module, User $user): bool
    {
        $definition = self::MODULES[$module] ?? null;

        if ($definition === null) {
            return false;
        }

        if ($definition['deployment'] === 'admin' && config('rivo.site.type') !== 'admin') {
            return false;
        }

        if ($definition['any'] === []) {
            return true;
        }

        foreach ($definition['any'] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function accessibleModules(User $user): array
    {
        return array_values(array_filter($this->moduleKeys(), fn (string $module) => $this->accessible($module, $user)));
    }

    /** Le module d'une adresse : le chemin déclaré le plus long qui la contient. */
    public function moduleForPath(?string $path): ?string
    {
        $path = '/'.trim((string) $path, '/');
        $best = null;
        $bestLength = 0;

        foreach (self::MODULES as $module => $definition) {
            foreach ($definition['paths'] as $prefix) {
                $matches = $path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/');

                if ($matches && mb_strlen($prefix) > $bestLength) {
                    $best = $module;
                    $bestLength = mb_strlen($prefix);
                }
            }
        }

        return $best;
    }

    /** @return list<string> */
    public function suggestions(?string $module, User $user): array
    {
        $module = $module !== null && $this->accessible($module, $user) ? $module : 'general';

        return self::MODULES[$module]['suggestions'];
    }

    /**
     * La documentation jointe à une question : la fiche du module ouvert, puis les
     * sections d'autres modules que la question évoque — seulement ceux que le
     * compte peut ouvrir.
     */
    public function relevantHelp(?string $currentModule, string $question, User $user): string
    {
        $parts = [];
        $current = $currentModule !== null && $this->accessible($currentModule, $user) ? $currentModule : null;

        if ($current !== null) {
            $parts[] = $this->truncate($this->document($current), self::CURRENT_MODULE_BUDGET);
        }

        $budget = self::RELATED_BUDGET;

        foreach ($this->search($question, $user, 4, $current) as $section) {
            if ($section['module'] === $current) {
                continue;
            }

            $block = '## '.$this->title($section['module']).' — '.$section['heading']."\n".$section['text'];

            if (mb_strlen($block) > $budget) {
                break;
            }

            $parts[] = $block;
            $budget -= mb_strlen($block);
        }

        if ($parts === []) {
            $parts[] = $this->truncate($this->document('general'), self::CURRENT_MODULE_BUDGET);
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Les sections qui répondent le mieux à une question, parmi les modules que le
     * compte peut ouvrir.
     *
     * @return list<array{module: string, heading: string, text: string, score: float}>
     */
    public function search(string $query, User $user, int $limit = 4, ?string $boostModule = null): array
    {
        $terms = $this->stems($query);

        if ($terms === []) {
            return [];
        }

        $results = [];

        foreach ($this->accessibleModules($user) as $module) {
            $keywordStems = $this->stems(implode(' ', self::MODULES[$module]['keywords']));

            foreach ($this->sectionsOf($module) as $section) {
                $score = 0.0;

                foreach (array_keys($terms) as $term) {
                    $score += isset($keywordStems[$term]) ? 2 : 0;
                    $score += isset($section['headingStems'][$term]) ? 3 : 0;
                    $score += isset($section['stems'][$term]) ? 1 : 0;
                }

                if ($score <= 0) {
                    continue;
                }

                if ($module === $boostModule) {
                    $score *= 1.5;
                }

                $results[] = ['module' => $module, 'heading' => $section['heading'], 'text' => $section['text'], 'score' => $score];
            }
        }

        usort($results, fn (array $left, array $right) => $right['score'] <=> $left['score']);

        return array_slice($results, 0, max(1, $limit));
    }

    public function document(string $module): string
    {
        if (isset($this->documents[$module])) {
            return $this->documents[$module];
        }

        $path = resource_path('ai/clinic-assistant/'.$module.'.md');

        return $this->documents[$module] = is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    /** @return list<array{module: string, heading: string, text: string, stems: array<string, true>, headingStems: array<string, true>}> */
    private function sectionsOf(string $module): array
    {
        if (isset($this->sections[$module])) {
            return $this->sections[$module];
        }

        $sections = [];
        $chunks = preg_split('/^## /m', $this->document($module)) ?: [];

        foreach ($chunks as $index => $chunk) {
            $lines = explode("\n", trim($chunk), 2);
            $heading = $index === 0 ? ltrim($lines[0], '# ') : $lines[0];
            $text = trim($lines[1] ?? '');

            if ($text === '') {
                continue;
            }

            $sections[] = [
                'module' => $module,
                'heading' => trim($heading),
                'text' => $text,
                'stems' => $this->stems($heading.' '.$text),
                'headingStems' => $this->stems($heading),
            ];
        }

        return $this->sections[$module] = $sections;
    }

    /**
     * Les racines d'un texte : minuscules, sans accents, mots de trois lettres au
     * moins hors mots vides, tronqués à six caractères (« hospitaliser » et
     * « hospitalisation » se rejoignent).
     *
     * @return array<string, true>
     */
    private function stems(string $text): array
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))) ?? '';
        $stems = [];

        foreach (explode(' ', $normalized) as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $stems[mb_substr($word, 0, 6)] = true;
        }

        return $stems;
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit))."\n[…]";
    }
}
