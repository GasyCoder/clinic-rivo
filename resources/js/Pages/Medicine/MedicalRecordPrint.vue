<script setup>
import ClinicalRichTextDisplay from '@/Components/Clinical/ClinicalRichTextDisplay.vue';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PaperSheet from '@/Components/Clinical/PaperSheet.vue';
import { Link } from '@inertiajs/vue3';
import { Baby, UserRound } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/utilities/date';

defineOptions({ layout: AppLayout });

/**
 * ADR-116 — le « DOSSIER MÉDICAL » de la clinique.
 *
 * Chaque case reprend ce qui est déjà consigné ailleurs dans le dossier
 * (identité, fiche Soins, séjour, consultation, antécédents) : rien n'est
 * ressaisi ici, et une case que personne n'a remplie reste vide plutôt que
 * « Non » ou « Normal » (ADR-074, ADR-077).
 */
const props = defineProps({
    /** `null` : un patient sans passage — un nouveau-né que la Réception n'a pas encore accueilli (ADR-145). */
    episode: { type: Object, default: null },
    /** Où retourne le bouton de l'écran : le passage, ou le dossier patient quand il n'y en a pas. */
    back: { type: Object, required: true },
    patient: { type: Object, required: true },
    vitals_visible: { type: Boolean, required: true },
    vitals: { type: Object, default: null },
    history_visible: { type: Boolean, required: true },
    allergies: { type: Array, default: () => [] },
    familial_antecedents: { type: Array, default: () => [] },
    /** Chaque section est gardée par le droit qui possède sa donnée : une feuille imprimée ne contourne rien. */
    record_visible: { type: Boolean, default: true },
    current_treatments: { type: Array, default: () => [] },
    stay_visible: { type: Boolean, default: true },
    hospitalization: { type: Object, default: null },
    diagnosis_visible: { type: Boolean, default: true },
    diagnosis: { type: String, default: null },
    /**
     * ADR-172 — le passage au bloc fait partie du même dossier. `null` : aucun ; sinon une ligne par
     * intervention, gardée par `surgery.view` ; l'anesthésie y est gardée par `anesthesia.view`.
     */
    surgery_visible: { type: Boolean, default: true },
    surgery: { type: Array, default: null },
    /**
     * ADR-143 — la Maternité fait partie du même dossier. `null` : ce passage n'en a
     * pas ; `{ restricted: true }` : elle existe mais ce compte n'a pas le droit de la lire.
     */
    maternity: { type: Object, default: null },
    /**
     * ADR-145 — la mère et ses bébés, chacun avec son dossier médical, en onglets. `null` : ni mère ni bébé.
     */
    dossiers: { type: Array, default: null },
    /**
     * ADR-144 — ce patient est un nouveau-né de la clinique : sa naissance, lue chez sa mère.
     * `null` : il ne l'est pas ; `{ restricted: true }` : sans `newborns.medical_record.view`, la fiche
     * n'est pas servie (ADR-146 amendement — le droit de son dossier, pas celui de sa mère).
     */
    birth: { type: Object, default: null },
    /**
     * ADR-165 — plusieurs dossiers réunis dans un seul document : le premier porte
     * la barre d'actions et le titre de l'onglet, les suivants ne les répètent pas.
     */
    showActions: { type: Boolean, default: true },
    pageTitle: { type: String, default: null },
});

const dateOrEmpty = (value) => (value ? formatDate(value) : '');
const dateTimeOrEmpty = (value) => (value ? formatDateTime(value) : '');
const newbornTitle = (newborn) => (props.maternity.newborns.length > 1 ? `NOUVEAU-NÉ ${newborn.rank}` : 'NOUVEAU-NÉ');

/** « Naissance unique » ou « Naissance multiple — nº 1 sur 2 » : lu chez la mère, jamais deviné. */
const birthKind = computed(() => {
    if (!props.birth || props.birth.restricted) return '';

    return props.birth.births_count > 1
        ? `Multiple — nº ${props.birth.rank} sur ${props.birth.births_count}`
        : 'Unique';
});

const sexLabel = computed(() => ({ M: 'M', F: 'F' })[props.patient.sex] ?? '');

const birthLabel = computed(() => {
    if (props.patient.birth_date && !props.patient.birth_date_is_approximate) {
        return formatDate(props.patient.birth_date);
    }

    return props.patient.age !== null && props.patient.age !== undefined
        ? `${props.patient.age} ans (âge déclaré)`
        : '';
});

const smokerLabel = computed(() => (props.vitals?.smoker === null || props.vitals?.smoker === undefined
    ? ''
    : (props.vitals.smoker ? 'Oui' : 'Non')));

/** Une permission manquante se nomme ; elle ne se lit jamais comme « rien à signaler ». */
const restricted = (label) => `Non visible avec vos droits (${label})`;
</script>

<template>
    <PaperSheet
        :page-title="pageTitle ?? `Dossier médical — ${patient.name}`"
        document-title="Dossier médical"
        :back-href="back.href"
        :back-label="back.label"
        :show-actions="showActions"
    >
        <!-- Un seul modèle de dossier médical, des onglets : on passe de la mère à chacun de ses bébés
             sans repasser par un répertoire. Écran seulement, jamais imprimé. -->
        <template v-if="dossiers" #tabs>
            <nav aria-label="Dossiers médicaux de la mère et de ses bébés" class="flex flex-wrap gap-1.5 rounded-xl border border-border bg-card p-1.5 shadow-sm">
                <component
                    :is="tab.href && !tab.current ? Link : 'div'"
                    v-for="tab in dossiers"
                    :key="tab.key"
                    :href="tab.href && !tab.current ? tab.href : undefined"
                    :aria-current="tab.current ? 'page' : undefined"
                    :aria-disabled="!tab.href ? 'true' : undefined"
                    :title="!tab.href ? 'Le dossier patient de ce bébé n\'est pas encore créé' : undefined"
                    :class="cn(
                        'flex min-w-[9rem] flex-1 flex-col rounded-lg px-3 py-2 text-start transition-colors sm:flex-none',
                        tab.current ? 'bg-primary text-primary-foreground shadow-sm'
                            : tab.href ? 'text-foreground hover:bg-accent'
                                : 'cursor-not-allowed text-muted-foreground opacity-70',
                    )"
                >
                    <span class="flex items-center gap-1.5 text-sm font-semibold">
                        <component :is="tab.key === 'mother' ? UserRound : Baby" class="h-3.5 w-3.5" aria-hidden="true" />{{ tab.label }}
                    </span>
                    <span class="font-mono text-[11px] opacity-80">{{ tab.sub }}</span>
                </component>
            </nav>
        </template>

        <table class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-strong">N° DE DOSSIER</th>
                    <td colspan="3">
                        <template v-if="patient.patient_number">{{ patient.patient_number }}</template>
                        <!-- ADR-146 : un bébé lu depuis la fiche de sa mère n'a pas encore de numéro — il en reçoit un
                             quand la Réception l'accueille. Le dire vaut mieux qu'une case vide, qui se lirait « oublié ». -->
                        <span v-else class="ps-muted">Pas encore patient — le dossier patient s’ouvre à l’accueil, avec son numéro</span>
                        <span v-if="episode" class="ps-muted">· Passage {{ episode.episode_number }}</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- ADR-145 : un nouveau-né n'a ni situation maritale, ni profession, ni adresse, ni tabac.
             Sa feuille dit ce qui le concerne — sa naissance, son état, sa mère — et rien d'autre. -->
        <template v-if="birth">
            <table class="ps-table">
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Nom et Prénom</th>
                        <td colspan="3">{{ patient.name }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Né(e) le</th>
                        <td>{{ birth.born_at ? dateTimeOrEmpty(birth.born_at) : birthLabel }}</td>
                        <th class="ps-label ps-label-blue-soft">Sexe (M/F)</th>
                        <td>{{ sexLabel }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Lieu de naissance</th>
                        <td>Clinique Saint Georges<span v-if="birth.place"> — {{ birth.place }}</span></td>
                        <th class="ps-label ps-label-blue-soft">Naissance</th>
                        <td>{{ birthKind }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ps-table mrp-block">
                <thead><tr><th class="ps-section ps-section-blue" colspan="4">MÈRE — PERSONNE À JOINDRE</th></tr></thead>
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Nom et Prénom</th>
                        <td colspan="3">{{ birth.mother.name }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">N° de dossier</th>
                        <td>{{ birth.mother.patient_number }}</td>
                        <th class="ps-label ps-label-blue-soft">Téléphone</th>
                        <td>{{ birth.mother.phone }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Adresse</th>
                        <td colspan="3">{{ birth.mother.address }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="ps-table mrp-block">
                <thead><tr><th class="ps-section ps-section-yellow" colspan="4">NAISSANCE ET ACCOUCHEMENT</th></tr></thead>
                <tbody v-if="birth.restricted">
                    <tr><td colspan="4" class="ps-muted p-2">{{ restricted('newborns.medical_record.view') }}</td></tr>
                </tbody>
                <tbody v-else>
                    <tr>
                        <th class="ps-label ps-label-yellow">Mode d’accouchement</th>
                        <td>{{ birth.delivery_mode }}</td>
                        <th class="ps-label ps-label-yellow">Terme (semaines)</th>
                        <td>{{ birth.gestational_age_weeks }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-yellow">Complications de l’accouchement</th>
                        <td colspan="3" class="mrp-freetext-sm">{{ birth.delivery_complications }}</td>
                    </tr>
                </tbody>
            </table>

            <table v-if="!birth.restricted" class="ps-table mrp-block">
                <thead><tr><th class="ps-section ps-section-yellow" colspan="4">ÉTAT À LA NAISSANCE</th></tr></thead>
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-yellow">Poids de naissance (g)</th>
                        <td>{{ birth.birth_weight_g }}</td>
                        <th class="ps-label ps-label-yellow">Apgar</th>
                        <td>{{ birth.apgar }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-yellow">État à la naissance</th>
                        <td colspan="3" class="mrp-freetext-sm">{{ birth.condition }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-yellow">Soins du bébé</th>
                        <td colspan="3" class="mrp-freetext-sm">{{ birth.care_notes }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Ce que le bébé a lui-même, sans repli sur un adulte : allergies, et — seulement s'il a eu un
                 passage — ce que les Soins et la Médecine y ont consigné. -->
            <table class="ps-table mrp-block">
                <thead><tr><th class="ps-section ps-section-green" colspan="4">SUIVI</th></tr></thead>
                <tbody>
                    <tr>
                        <th class="ps-label ps-label-green">Allergie</th>
                        <td colspan="3">{{ history_visible ? allergies.join(', ') : restricted('patients.medical_history.view') }}</td>
                    </tr>
                    <template v-if="vitals">
                        <tr>
                            <th class="ps-label ps-label-green">Poids (Kg)</th>
                            <td>{{ vitals.weight_kg }}</td>
                            <th class="ps-label ps-label-green">Taille (cm)</th>
                            <td>{{ vitals.height_cm }}</td>
                        </tr>
                    </template>
                    <tr v-if="hospitalization">
                        <th class="ps-label ps-label-green">Motif d’hospitalisation</th>
                        <td colspan="3">{{ hospitalization.reason }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-green">Diagnostic</th>
                        <td colspan="3" :class="diagnosis_visible ? undefined : 'ps-muted'">{{ diagnosis_visible ? diagnosis : restricted('diagnoses.view') }}</td>
                    </tr>
                </tbody>
            </table>
        </template>

        <table v-if="!birth" class="ps-table">
            <tbody>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Nom et Prénom</th>
                    <td colspan="3">{{ patient.name }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Date de Naissance</th>
                    <td>{{ birthLabel }}</td>
                    <th class="ps-label ps-label-blue-soft">Lieu</th>
                    <td>{{ patient.birth_place }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Sexe (M/F)</th>
                    <td colspan="3">{{ sexLabel }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Situation Maritale</th>
                    <td>{{ patient.marital_status }}</td>
                    <th class="ps-label ps-label-blue-soft">Nombre d’enfants</th>
                    <td>{{ patient.children_count }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Profession</th>
                    <td colspan="3">{{ patient.profession }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Adresse</th>
                    <td colspan="3">{{ patient.address }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-blue-soft">Téléphone</th>
                    <td colspan="3">{{ patient.phone }}</td>
                </tr>
            </tbody>
        </table>

        <table v-if="!birth" class="ps-table">
            <tbody v-if="vitals_visible">
                <tr>
                    <th class="ps-label ps-label-green">Groupe sanguin</th>
                    <td colspan="3">{{ vitals?.blood_group }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Taille (cm)</th>
                    <td>{{ vitals?.height_cm }}</td>
                    <th class="ps-label ps-label-green">Poids (Kg)</th>
                    <td>{{ vitals?.weight_kg }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">IMC</th>
                    <td colspan="3">{{ vitals?.bmi }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Allergie</th>
                    <td>{{ history_visible ? allergies.join(', ') : restricted('patients.medical_history.view') }}</td>
                    <th class="ps-label ps-label-green">Tabac (Oui/Non)</th>
                    <td>{{ smokerLabel }}</td>
                </tr>
                <tr v-if="!stay_visible">
                    <th class="ps-label ps-label-green">Hospitalisation</th>
                    <td colspan="3" class="ps-muted">{{ restricted('hospitalization.view') }}</td>
                </tr>
                <tr v-if="hospitalization">
                    <th class="ps-label ps-label-green">Motif d’hospitalisation</th>
                    <td colspan="3">{{ hospitalization.reason }}</td>
                </tr>
                <tr v-if="hospitalization">
                    <th class="ps-label ps-label-green">Entrée hospitalisation</th>
                    <td>{{ hospitalization.admitted_at ? formatDate(hospitalization.admitted_at) : '' }}</td>
                    <th class="ps-label ps-label-green">Sortie</th>
                    <td>{{ hospitalization.discharged_at ? formatDate(hospitalization.discharged_at) : '' }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Diagnostic</th>
                    <td colspan="3" :class="diagnosis_visible ? undefined : 'ps-muted'">{{ diagnosis_visible ? diagnosis : restricted('diagnoses.view') }}</td>
                </tr>
                <tr>
                    <th class="ps-label ps-label-green">Motif de transmission</th>
                    <td colspan="3"><ClinicalRichTextDisplay v-if="vitals?.transmission_reason_html" :html="vitals.transmission_reason_html" /></td>
                </tr>
            </tbody>
            <tbody v-else>
                <tr><td colspan="4" class="ps-muted p-2">{{ restricted('vitals.view') }}</td></tr>
            </tbody>
        </table>

        <table v-if="!birth" class="ps-table mrp-two-col">
            <thead>
                <tr>
                    <th class="ps-section ps-section-yellow">TRAITEMENTS ACTUELS</th>
                    <th class="ps-section ps-section-yellow">ANTÉCÉDENTS FAMILIAUX</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="mrp-freetext">
                        <template v-if="record_visible">
                            <p v-for="(treatment, index) in current_treatments" :key="index">{{ treatment }}</p>
                        </template>
                        <p v-else class="ps-muted">{{ restricted('medical_record.view') }}</p>
                    </td>
                    <td class="mrp-freetext">
                        <template v-if="history_visible">
                            <p v-for="(antecedent, index) in familial_antecedents" :key="index">{{ antecedent }}</p>
                        </template>
                        <p v-else class="ps-muted">{{ restricted('patients.medical_history.view') }}</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- ADR-172 : le bloc opératoire est une section du dossier, jamais un second dossier. -->
        <table v-if="!birth && (!surgery_visible || surgery)" class="ps-table mrp-block">
            <thead><tr><th class="ps-section ps-section-blue" colspan="4">BLOC OPÉRATOIRE</th></tr></thead>
            <tbody v-if="!surgery_visible">
                <tr><td colspan="4" class="ps-muted p-2">{{ restricted('surgery.view') }}</td></tr>
            </tbody>
            <template v-else>
                <tbody v-for="item in surgery" :key="item.uuid">
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Intervention</th>
                        <td>{{ item.procedure }}<span v-if="item.status" class="ps-muted"> · {{ item.status }}</span></td>
                        <th class="ps-label ps-label-blue-soft">Chirurgien</th>
                        <td>{{ item.surgeon }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Début</th>
                        <td>{{ item.started_at ? formatDateTime(item.started_at) : (item.scheduled_at ? `Programmée le ${formatDateTime(item.scheduled_at)}` : '') }}</td>
                        <th class="ps-label ps-label-blue-soft">Fin</th>
                        <td>{{ item.ended_at ? formatDateTime(item.ended_at) : '' }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Anesthésiste</th>
                        <td :class="item.anesthesia_visible ? undefined : 'ps-muted'">{{ item.anesthesia_visible ? item.anesthesia?.anesthetist : restricted('anesthesia.view') }}</td>
                        <th class="ps-label ps-label-blue-soft">Classe ASA</th>
                        <td>{{ item.anesthesia_visible ? item.anesthesia?.asa_class : '' }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Décision anesthésique</th>
                        <td>{{ item.anesthesia_visible ? [item.anesthesia?.clearance, item.anesthesia?.clearance_reason].filter(Boolean).join(' — ') : '' }}</td>
                        <th class="ps-label ps-label-blue-soft">Réveil</th>
                        <td>{{ item.awakening }}</td>
                    </tr>
                    <tr>
                        <th class="ps-label ps-label-blue-soft">Résumé de l’acte</th>
                        <td colspan="3">{{ item.summary }}<span v-if="item.report_validated" class="ps-muted"> · Compte rendu validé</span></td>
                    </tr>
                </tbody>
            </template>
        </table>

        <!-- ADR-143 : la Maternité est une section du dossier, pas un second dossier. -->
        <template v-if="maternity">
            <table v-if="maternity.restricted" class="ps-table">
                <tbody>
                    <tr><th class="ps-section ps-section-blue">MATERNITÉ</th></tr>
                    <tr><td class="ps-muted p-2">{{ restricted('maternity.view') }}</td></tr>
                </tbody>
            </table>

            <template v-else>
                <table class="ps-table mrp-block">
                    <thead><tr><th class="ps-section ps-section-blue" colspan="4">MATERNITÉ — GROSSESSE</th></tr></thead>
                    <tbody>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Gestité</th>
                            <td>{{ maternity.pregnancy.gravidity }}</td>
                            <th class="ps-label ps-label-blue-soft">Parité</th>
                            <td>{{ maternity.pregnancy.parity }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Dernières règles</th>
                            <td>{{ dateOrEmpty(maternity.pregnancy.last_menstrual_period) }}</td>
                            <th class="ps-label ps-label-blue-soft">Terme estimé</th>
                            <td>{{ dateOrEmpty(maternity.pregnancy.estimated_due_date) }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Terme (semaines)</th>
                            <td>{{ maternity.prenatal.gestational_age_weeks }}</td>
                            <th class="ps-label ps-label-blue-soft">Hauteur utérine (cm)</th>
                            <td>{{ maternity.prenatal.fundal_height_cm }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Rythme fœtal (bpm)</th>
                            <td colspan="3">{{ maternity.prenatal.fetal_heart_rate }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Contexte obstétrical</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ maternity.context }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Facteurs de risque</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ maternity.pregnancy.risk_factors }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-blue-soft">Suivi prénatal</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ maternity.prenatal.notes }}</td>
                        </tr>
                    </tbody>
                </table>

                <table class="ps-table mrp-block">
                    <thead><tr><th class="ps-section ps-section-green" colspan="4">MATERNITÉ — TRAVAIL ET ACCOUCHEMENT</th></tr></thead>
                    <tbody>
                        <tr>
                            <th class="ps-label ps-label-green">Début du travail</th>
                            <td>{{ dateTimeOrEmpty(maternity.labor.started_at) }}</td>
                            <th class="ps-label ps-label-green">Membranes</th>
                            <td>{{ maternity.labor.membranes }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-green">Dilatation (cm)</th>
                            <td>{{ maternity.labor.cervical_dilation_cm }}</td>
                            <th class="ps-label ps-label-green">Contractions</th>
                            <td>{{ maternity.labor.contractions }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-green">Surveillance</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ maternity.labor.surveillance_notes }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-green">Accouchement</th>
                            <td>{{ dateTimeOrEmpty(maternity.delivery.occurred_at) }}</td>
                            <th class="ps-label ps-label-green">Mode</th>
                            <td>{{ maternity.delivery.mode }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-green">Placenta</th>
                            <td colspan="3">{{ maternity.delivery.placenta_status }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-green">Complications</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ maternity.delivery.complications }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-green">Soins de la mère</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ maternity.maternal_care_notes }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Un bloc par bébé : avec des jumeaux, chacun a son état et ses soins. -->
                <table v-for="newborn in maternity.newborns" :key="newborn.rank" class="ps-table mrp-block">
                    <thead><tr><th class="ps-section ps-section-yellow" colspan="4">{{ newbornTitle(newborn) }}</th></tr></thead>
                    <tbody>
                        <tr>
                            <th class="ps-label ps-label-yellow">Nom</th>
                            <td colspan="3">{{ newborn.name }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-yellow">Sexe</th>
                            <td>{{ newborn.sex }}</td>
                            <th class="ps-label ps-label-yellow">Poids de naissance (g)</th>
                            <td>{{ newborn.birth_weight_g }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-yellow">Apgar</th>
                            <td>{{ newborn.apgar }}</td>
                            <th class="ps-label ps-label-yellow">Dossier patient</th>
                            <td>{{ newborn.patient_number ?? 'Pas encore patient' }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-yellow">État à la naissance</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ newborn.condition }}</td>
                        </tr>
                        <tr>
                            <th class="ps-label ps-label-yellow">Soins du bébé</th>
                            <td colspan="3" class="mrp-freetext-sm">{{ newborn.care_notes }}</td>
                        </tr>
                    </tbody>
                </table>
                <table v-if="maternity.baby_care_notes_legacy" class="ps-table mrp-block">
                    <tbody>
                        <tr>
                            <th class="ps-label ps-label-yellow">Soins bébé — note générale</th>
                            <td class="mrp-freetext-sm">{{ maternity.baby_care_notes_legacy }}</td>
                        </tr>
                    </tbody>
                </table>

                <table v-if="maternity.procedures.length" class="ps-table mrp-block">
                    <thead>
                        <tr>
                            <th class="ps-section ps-section-blue">MATERNITÉ — ACTES RÉALISÉS</th>
                            <th class="ps-section ps-section-blue">Quantité</th>
                            <th class="ps-section ps-section-blue">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(procedure, index) in maternity.procedures" :key="index">
                            <td>{{ procedure.name }}<span v-if="procedure.notes" class="ps-muted"> — {{ procedure.notes }}</span></td>
                            <td>{{ procedure.quantity }}</td>
                            <td>{{ dateTimeOrEmpty(procedure.performed_at) }}</td>
                        </tr>
                    </tbody>
                </table>

                <table v-if="maternity.observations || maternity.transmission_notes" class="ps-table mrp-block">
                    <tbody>
                        <tr v-if="maternity.observations">
                            <th class="ps-label ps-label-blue-soft">Observations</th>
                            <td class="mrp-freetext-sm">{{ maternity.observations }}</td>
                        </tr>
                        <tr v-if="maternity.transmission_notes">
                            <th class="ps-label ps-label-blue-soft">Transmission</th>
                            <td class="mrp-freetext-sm">{{ maternity.transmission_notes }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </template>
    </PaperSheet>
</template>

<style>
.mrp-two-col th,
.mrp-two-col td {
    width: 50%;
}

.mrp-freetext {
    height: 140px;
    vertical-align: top;
}

.mrp-freetext p {
    margin: 0 0 3px;
}

/* Une case de texte libre garde ses retours à la ligne, comme la saisie. */
.mrp-freetext-sm {
    white-space: pre-line;
    vertical-align: top;
}

/* Un bloc Maternité ne se coupe pas entre deux pages. */
.mrp-block {
    break-inside: avoid;
}
</style>
