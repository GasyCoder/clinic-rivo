<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import { Archive, CircleCheck, CircleSlash, Clock, Eye, FileSearch, FlaskConical, LoaderCircle, PenLine, Printer, ScanLine, Search, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import ClinicalRichTextEditor from '@/Components/Clinical/ClinicalRichTextEditor.vue';
import FormError from '@/Components/UI/FormError.vue';
import { formatDateTime, formatRelativeTime } from '@/utilities/date';
import { cn } from '@/lib/cn';

defineOptions({ layout: AppLayout });

/**
 * Les demandes d'examens complémentaires du médecin, toutes consultations
 * confondues.
 *
 * Les statuts ne sont pas stockés : le serveur les dérive de
 * `cancelled_at` et du `resulted_at` de chaque ligne, et les compteurs sont
 * comptés sur ces mêmes faits. Rien n'est incrémenté ici.
 *
 * Il n'y a volontairement pas de filtre « Terminées » distinct de
 * « Résultat disponible » : rien n'enregistre qu'un médecin a pris
 * connaissance d'un résultat, et l'afficher prétendrait une lecture que
 * personne n'a faite.
 */
const props = defineProps({
    requests: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

/**
 * Trois vues, pas un onglet par statut.
 *
 * Chacune répond à une question, et le serveur décide seul de qui appartient
 * à quoi — les compteurs comme la liste sortent du même classement.
 *
 * « Rendu récemment » et non « validé » : rien dans le système ne valide un
 * résultat. Il n'existe ni `validated_at`, ni permission de validation ; un
 * résultat est saisi, point. Nommer cet onglet « Validé » afficherait un
 * contrôle que personne n'a fait.
 */
const FILTERS = [
    { key: 'active', label: 'Actives', hint: 'En attente d’un résultat', icon: Clock, tone: 'amber' },
    { key: 'recent', label: 'Rendues récemment', hint: 'Résultat des 7 derniers jours', icon: CircleCheck, tone: 'emerald' },
    { key: 'archived', label: 'Archivées', hint: 'Plus anciennes et demandes retirées', icon: Archive, tone: 'neutral' },
];

const STATUS = {
    REQUESTED: { label: 'En attente', icon: Clock, tone: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300' },
    IN_PROGRESS: { label: 'En cours', icon: LoaderCircle, tone: 'border-primary/30 bg-primary/10 text-primary' },
    COMPLETED: { label: 'Résultat disponible', icon: CircleCheck, tone: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300' },
    CANCELLED: { label: 'Retirée', icon: CircleSlash, tone: 'border-border bg-muted text-muted-foreground' },
};

const query = ref(props.filters.q ?? '');
const activeFilter = computed(() => props.filters.filter ?? 'active');

const visit = (params) => router.get('/medicine/demandes-examens', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

const selectFilter = (key) => visit({ filter: key, q: query.value || undefined });

let searchTimer = null;
watch(query, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit({ filter: activeFilter.value, q: value || undefined }), 300);
});

const familyIcon = (kind) => (kind === 'lab' ? FlaskConical : ScanLine);

/* ── Compte rendu d'imagerie ───────────────────────────────────────────── */

/**
 * Le médecin écrit le compte rendu depuis cette page.
 *
 * Il devait rouvrir la consultation pour cela, alors que c'est ici qu'il voit
 * ce qui attend un résultat. L'écriture réutilise l'endpoint existant
 * (`.../imaging-requests/{item}/result`) : même validation, même refus d'un
 * second compte rendu, même audit — aucun second chemin d'écriture.
 *
 * L'imagerie seulement : un résultat d'analyse appartient au Laboratoire.
 * Le serveur le décide (`can_record`), l'écran ne fait que le refléter.
 */
const reporting = ref(null);

const reportForm = useForm({ result_value: '', result_notes: '' });

const openReport = (request, item) => {
    reporting.value = { request, item };
    reportForm.reset();
    reportForm.clearErrors();
};

const closeReport = () => {
    reporting.value = null;
};

const submitReport = () => {
    if (!reporting.value) {
        return;
    }

    const { request, item } = reporting.value;

    reportForm.post(`/medicine/orientations/${request.orientation_uuid}/imaging-requests/${item.uuid}/result`, {
        preserveScroll: true,
        onSuccess: () => {
            closeReport();
            // La demande vient de changer de statut : elle a quitté l'onglet
            // où on la regardait. On suit le dossier plutôt que de laisser
            // le médecin devant une ligne qui a disparu sous ses yeux.
            // La demande vient de quitter « Active » : elle a un résultat.
            if (activeFilter.value === 'active') {
                selectFilter('recent');
            }
        },
    });
};

/* ── Lecture d'un compte rendu ─────────────────────────────────────────── */

/**
 * « Voir le résultat » montre le résultat.
 *
 * Le bouton renvoyait vers l'étape Paraclinique de la consultation : le
 * médecin se retrouvait dans l'assistant d'un dossier souvent déjà clôturé,
 * donc en lecture seule, à chercher ce qu'il venait de demander à voir. Un
 * libellé qui promet une chose doit faire cette chose.
 *
 * Le contenu est déjà dans la page, assaini côté serveur : aucune requête
 * supplémentaire.
 */
const viewing = ref(null);

const resultedItems = (request) => request.items.filter((item) => item.resulted_at);

const openResult = (request) => {
    viewing.value = request;
};

const closeResult = () => {
    viewing.value = null;
};

/* ── Retrait d'une demande ─────────────────────────────────────────────── */

/**
 * Retirer, jamais supprimer (ADR-010).
 *
 * La demande garde son auteur, sa date et son numéro, et reste lisible dans
 * l'onglet « Retirées ». Une demande qui porte déjà un résultat n'est jamais
 * retirée : le service a fait le travail. Le serveur le revérifie —
 * `can_withdraw` ne fait que refléter sa réponse.
 */
const withdrawing = ref(null);

const withdrawForm = useForm({ kind: '', uuid: '', reason: '' });

const openWithdraw = (request) => {
    withdrawing.value = request;
    withdrawForm.reset();
    withdrawForm.clearErrors();
};

const closeWithdraw = () => {
    withdrawing.value = null;
};

const submitWithdraw = () => {
    if (!withdrawing.value) {
        return;
    }

    const request = withdrawing.value;

    withdrawForm.kind = request.kind;
    withdrawForm.uuid = request.uuid;

    withdrawForm.post(`/medicine/orientations/${request.orientation_uuid}/paraclinical-requests/cancel`, {
        preserveScroll: true,
        onSuccess: closeWithdraw,
    });
};
</script>

<template>
    <Head title="Demandes d’examens" />

    <div class="w-full space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <FileSearch class="h-5 w-5" />
                </span>
                <div>
                    <h1 class="text-lg font-bold tracking-tight text-foreground">Demandes d’examens</h1>
                    <p class="text-sm text-muted-foreground">Analyses et imagerie demandées en consultation, et leurs résultats.</p>
                </div>
            </div>

            <IconInput
                v-model="query"
                :icon="Search"
                type="search"
                class="w-full sm:w-72"
                placeholder="Patient, n° dossier, passage ou examen"
                autocomplete="off"
                aria-label="Rechercher une demande"
            />
        </header>

        <!-- Les compteurs viennent du serveur, comptés en base : recalculés
             depuis la page affichée, ils mentiraient dès la deuxième. -->
        <QueueCounters
            class="lg:grid-cols-3"
            :tiles="FILTERS.map((filter) => ({ ...filter, value: filter.key, count: counts[filter.key] ?? 0, active: activeFilter === filter.key }))"
            @select="selectFilter"
        />

        <!-- Un tableau : mêmes colonnes pour toutes les lignes, une colonne
             d'actions, et un défilement horizontal plutôt qu'une mise en page
             qui se replie différemment selon le contenu. -->
        <Card v-if="requests.length" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[64rem] text-sm">
                    <thead class="border-b border-border bg-muted/40">
                        <tr class="text-left text-[11px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                            <th scope="col" class="px-4 py-2.5">Patient</th>
                            <th scope="col" class="px-4 py-2.5">Examen</th>
                            <th scope="col" class="px-4 py-2.5">Demandée</th>
                            <th scope="col" class="px-4 py-2.5">Statut</th>
                            <th scope="col" class="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="request in requests"
                            :key="`${request.kind}-${request.uuid}`"
                            :class="cn('align-top transition-colors hover:bg-accent/40',
                                request.status === 'COMPLETED' && 'bg-emerald-50/40 dark:bg-emerald-950/10')"
                        >
                            <!-- PATIENT -->
                            <td class="px-4 py-3">
                                <p class="font-bold text-foreground">{{ request.patient.name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ request.patient.number }}
                                    <span class="text-border" aria-hidden="true">·</span>
                                    <span class="font-medium text-foreground">{{ request.episode_number }}</span>
                                </p>
                            </td>

                            <!-- EXAMEN -->
                            <td class="px-4 py-3">
                                <p class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                    <component :is="familyIcon(request.kind)" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ request.family_label }}
                                </p>
                                <!-- Un examen par ligne : c'est lui qui porte
                                     son compte rendu, pas la demande. -->
                                <ul class="mt-0.5 space-y-0.5">
                                    <li v-for="item in request.items" :key="item.uuid">
                                        <span class="font-semibold text-foreground">{{ item.exam }}</span>
                                        <span v-if="item.resulted_at" class="ms-1.5 text-[11px] text-emerald-700 dark:text-emerald-300">
                                            · rendu le {{ formatDateTime(item.resulted_at) }}<template v-if="item.resulted_by"> par Dr {{ item.resulted_by }}</template>
                                        </span>
                                    </li>
                                </ul>
                                <p v-if="request.notes" class="mt-0.5 line-clamp-1 text-xs text-muted-foreground" :title="request.notes">
                                    {{ request.notes }}
                                </p>
                            </td>

                            <!-- DEMANDÉE -->
                            <td class="px-4 py-3">
                                <p class="font-medium text-foreground">{{ formatDateTime(request.requested_at) }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatRelativeTime(request.requested_at) }}<template v-if="request.requested_by"> · Dr {{ request.requested_by }}</template>
                                </p>
                            </td>

                            <!-- STATUT -->
                            <td class="px-4 py-3">
                                <span :class="cn('inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-bold', STATUS[request.status].tone)">
                                    <component :is="STATUS[request.status].icon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ STATUS[request.status].label }}
                                </span>
                                <p v-if="request.status === 'CANCELLED'" class="mt-1 line-clamp-1 text-xs text-muted-foreground" :title="request.cancel_reason ?? undefined">
                                    {{ request.cancel_reason ?? 'Sans motif précisé' }}
                                </p>
                            </td>

                            <!-- ACTIONS -->
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    <Button
                                        v-for="item in request.items.filter((exam) => exam.can_record)"
                                        :key="`record-${item.uuid}`"
                                        type="button"
                                        size="sm"
                                        variant="warning-outline"
                                        @click="openReport(request, item)"
                                    >
                                        <PenLine class="h-3.5 w-3.5" />Saisir le résultat
                                    </Button>

                                    <!-- Un bouton par examen rendu : c'est
                                         l'examen qui porte son compte rendu. -->
                                    <Button
                                        v-for="item in request.items.filter((exam) => exam.print_url)"
                                        :key="`print-${item.uuid}`"
                                        :as="Link"
                                        :href="item.print_url"
                                        size="sm"
                                        variant="white-outline"
                                        :title="`Imprimer le compte rendu — ${item.exam}`"
                                    >
                                        <Printer class="h-3.5 w-3.5" />Imprimer
                                    </Button>

                                    <!-- Voir le résultat le montre ici même ;
                                         la consultation reste une action à
                                         part, pour qui veut le dossier. -->
                                    <Button
                                        v-if="resultedItems(request).length"
                                        type="button"
                                        size="sm"
                                        variant="primary"
                                        @click="openResult(request)"
                                    >
                                        <Eye class="h-3.5 w-3.5" />Voir le résultat
                                    </Button>

                                    <Button
                                        v-else-if="request.consultation_url"
                                        :as="Link"
                                        :href="request.consultation_url"
                                        size="sm"
                                        variant="white-outline"
                                    >
                                        Ouvrir la consultation
                                    </Button>

                                    <!-- Retirer, jamais supprimer : la demande
                                         reste lisible dans « Retirées ». -->
                                    <Button
                                        v-if="request.can_withdraw"
                                        type="button"
                                        size="sm"
                                        variant="white-outline"
                                        icon
                                        :title="`Retirer la demande « ${request.exams.join(', ')} »`"
                                        :aria-label="`Retirer la demande ${request.exams.join(', ')}`"
                                        @click="openWithdraw(request)"
                                    >
                                        <Trash2 class="h-4 w-4 text-destructive" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card v-else class="px-4 py-12 text-center">
            <FileSearch class="mx-auto h-6 w-6 text-muted-foreground" aria-hidden="true" />
            <p class="mt-2 text-sm font-semibold text-muted-foreground">Aucune demande pour ce filtre</p>
            <p class="mt-1 text-xs text-muted-foreground">
                Les analyses et examens d’imagerie demandés en consultation apparaissent ici.
            </p>
        </Card>

        <!-- La feuille de compte rendu. Elle ne réécrit jamais : le serveur
             refuse un second compte rendu sur le même examen. -->
        <Dialog
            :open="reporting !== null"
            size="wide"
            body-class="max-h-[78vh] overflow-y-auto"
            :dismissible="false"
            :title="reporting ? `Compte rendu — ${reporting.item.exam}` : 'Compte rendu'"
            :description="reporting ? `${reporting.request.patient.name} · ${reporting.request.episode_number}` : ''"
            @update:open="(value) => value || closeReport()"
        >
            <template #icon><PenLine class="h-5 w-5" /></template>

            <!-- Une feuille en paysage : les deux champs côte à côte sur un
                 écran large, empilés en dessous. Le compte rendu occupe les
                 deux tiers — c'est lui que le médecin écrit vraiment. -->
            <form v-if="reporting" class="space-y-4" @submit.prevent="submitReport">
                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <label for="imaging_report" class="mb-1.5 block text-xs font-bold text-foreground">
                            Compte rendu <span class="text-destructive">*</span>
                        </label>
                        <ClinicalRichTextEditor
                            id="imaging_report"
                            v-model="reportForm.result_value"
                            :max-length="5000"
                            min-height-class="min-h-[48vh]"
                            placeholder="Technique, constatations, conclusion…"
                        />
                        <FormError class="mt-1" :message="reportForm.errors.result_value" />
                    </div>

                    <div>
                        <label for="imaging_notes" class="mb-1.5 block text-xs font-bold text-foreground">
                            Observations complémentaires <span class="font-normal text-muted-foreground">· facultatif</span>
                        </label>
                        <ClinicalRichTextEditor
                            id="imaging_notes"
                            v-model="reportForm.result_notes"
                            :max-length="5000"
                            min-height-class="min-h-[48vh]"
                            placeholder="Ce que le compte rendu ne porte pas."
                        />
                        <FormError class="mt-1" :message="reportForm.errors.result_notes" />
                    </div>
                </div>

                <p class="text-[11px] leading-4 text-muted-foreground">
                    Un compte rendu enregistré n’est jamais réécrit : la demande cesse d’attendre un résultat et ne peut plus être retirée.
                </p>
            </form>

            <template #footer>
                <Button type="button" variant="white-outline" size="sm" @click="closeReport">Annuler</Button>
                <Button type="button" size="sm" :disabled="reportForm.processing" @click="submitReport">
                    <CircleCheck class="h-4 w-4" />Enregistrer le compte rendu
                </Button>
            </template>
        </Dialog>

        <!-- Le compte rendu, lu sur place. Lecture seule : la correction
             d'un résultat enregistré n'existe pas encore. -->
        <Dialog
            :open="viewing !== null"
            size="xl"
            body-class="max-h-[72vh] overflow-y-auto"
            :title="viewing ? `Résultat — ${viewing.patient.name}` : 'Résultat'"
            :description="viewing ? `${viewing.patient.number} · ${viewing.episode_number}` : ''"
            @update:open="(value) => value || closeResult()"
        >
            <template #icon><Eye class="h-5 w-5" /></template>

            <div v-if="viewing" class="space-y-6">
                <section v-for="item in resultedItems(viewing)" :key="`view-${item.uuid}`" class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-foreground">{{ item.exam }}</p>
                            <p class="text-xs text-muted-foreground">
                                Rendu le {{ formatDateTime(item.resulted_at) }}<template v-if="item.resulted_by"> par Dr {{ item.resulted_by }}</template>
                            </p>
                        </div>
                        <Button v-if="item.print_url" :as="Link" :href="item.print_url" size="sm" variant="white-outline">
                            <Printer class="h-3.5 w-3.5" />Imprimer
                        </Button>
                    </div>

                    <!-- Déjà assaini par ClinicalRichTextSanitizer : mise en
                         forme seulement, ni lien, ni média, ni script. -->
                    <div class="rq-report" v-html="item.report" />

                    <div v-if="item.notes">
                        <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground">Observations complémentaires</p>
                        <div class="rq-report" v-html="item.notes" />
                    </div>
                </section>
            </div>

            <template #footer>
                <Button
                    v-if="viewing?.consultation_url"
                    :as="Link"
                    :href="viewing.consultation_url"
                    size="sm"
                    variant="white-outline"
                >
                    Ouvrir la consultation
                </Button>
                <Button type="button" size="sm" @click="closeResult">Fermer</Button>
            </template>
        </Dialog>

        <!-- Le retrait n'est jamais silencieux : on nomme ce qui part, et le
             serveur refuse une demande déjà résultée. -->
        <Dialog
            :open="withdrawing !== null"
            :title="withdrawing ? `Retirer « ${withdrawing.exams.join(', ')} » ?` : 'Retirer la demande'"
            :description="withdrawing ? `${withdrawing.patient.name} · ${withdrawing.episode_number}` : ''"
            @update:open="(value) => value || closeWithdraw()"
        >
            <template #icon><Trash2 class="h-5 w-5 text-destructive" /></template>

            <form v-if="withdrawing" class="space-y-4" @submit.prevent="submitWithdraw">
                <p class="text-sm text-foreground">
                    La demande n’est pas supprimée : elle garde son auteur et sa date, et reste consultable dans l’onglet « Retirées ». Le service cesse simplement de l’attendre.
                </p>

                <div>
                    <label for="withdraw_reason" class="mb-1.5 block text-xs font-bold text-foreground">
                        Motif <span class="font-normal text-muted-foreground">· facultatif</span>
                    </label>
                    <IconInput
                        id="withdraw_reason"
                        v-model="withdrawForm.reason"
                        :icon="PenLine"
                        placeholder="« Finalement inutile » est un énoncé complet."
                        autocomplete="off"
                    />
                    <FormError class="mt-1" :message="withdrawForm.errors.reason" />
                </div>

                <FormError :message="withdrawForm.errors.request" />
            </form>

            <template #footer>
                <Button type="button" variant="white-outline" size="sm" @click="closeWithdraw">Annuler</Button>
                <Button type="button" variant="destructive" size="sm" :disabled="withdrawForm.processing" @click="submitWithdraw">
                    <Trash2 class="h-4 w-4" />Retirer la demande
                </Button>
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.rq-report {
    font-size: 0.875rem;
    line-height: 1.65;
}

.rq-report :deep(p) {
    margin: 0 0 0.5rem;
}

.rq-report :deep(ul),
.rq-report :deep(ol) {
    margin: 0 0 0.5rem;
    padding-inline-start: 1.25rem;
}

.rq-report :deep(ul) {
    list-style: disc;
}

.rq-report :deep(ol) {
    list-style: decimal;
}
</style>
