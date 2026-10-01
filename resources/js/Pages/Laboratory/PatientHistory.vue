<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import { ArrowDown, ArrowLeft, ArrowUp, History, LockKeyhole, Search, Siren } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';
import { labUrl } from '@/utilities/labUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-214 — l'historique des résultats d'un patient : chaque analyse et ses
 * valeurs d'une demande à l'autre, la plus récente à gauche. Rien n'est
 * recalculé : valeurs, unités et références sont celles figées à la saisie.
 * Une valeur non envoyée au médecin le dit ; une valeur critique se voit.
 * ADR-216 — les résultats adressés à un confrère ne sont pas servis : la page
 * dit combien il en manque, ils s'ouvrent depuis leur feuille, après confirmation.
 */
const props = defineProps({
    patient: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    total_requests: { type: Number, default: 0 },
    sealed_requests: { type: Number, default: 0 },
});

const query = ref('');
const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const visibleGroups = computed(() => {
    const needle = normalize(query.value.trim());
    if (!needle) return props.groups;
    return props.groups
        .map((group) => (normalize(group.name).includes(needle) ? group : { ...group, rows: group.rows.filter((row) => normalize(row.designation).includes(needle)) }))
        .filter((group) => group.rows.length);
});

const cellClass = (value) => cn(
    'whitespace-nowrap px-3 py-1.5 text-sm tabular-nums',
    value?.critical && 'font-bold text-destructive',
    !value?.critical && value?.interpretation === 'PATHOLOGICAL' && 'font-semibold text-amber-700 dark:text-amber-300',
    value && !value.validated && 'italic',
);

const identity = computed(() => [
    props.patient.patient_number,
    props.patient.sex ? (props.patient.sex === 'M' ? 'Homme' : 'Femme') : null,
    props.patient.age !== null && props.patient.age !== undefined ? `${props.patient.age} ans` : null,
].filter(Boolean).join(' · '));
</script>

<template>
    <Head :title="`Historique · ${formatPatientName(patient)}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <Button :as="Link" :href="labUrl('/laboratory')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><History class="h-6 w-6" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">{{ formatPatientName(patient) }}</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ identity }} · {{ total_requests }} demande(s) rendue(s)<template v-if="total_requests > columns.length"> — les {{ columns.length }} plus récentes affichées</template>
                    </p>
                </div>
            </div>
            <div v-if="groups.length" class="w-full sm:w-72"><IconInput v-model="query" :icon="Search" class="w-full" placeholder="Analyse ou paramètre" aria-label="Filtrer les analyses" /></div>
        </header>

        <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1"><Siren class="h-3.5 w-3.5 text-destructive" /> <strong class="text-destructive">Gras rouge</strong> : critique</span>
            <span><strong class="text-amber-700 dark:text-amber-300">Orange</strong> : pathologique</span>
            <span><em>Italique</em> : pas encore envoyé au médecin</span>
            <span class="inline-flex items-center gap-0.5"><ArrowUp class="h-3 w-3" /><ArrowDown class="h-3 w-3" /> au-dessus / au-dessous de la référence</span>
        </div>

        <p v-if="sealed_requests" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
            <LockKeyhole class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ sealed_requests }} demande(s) adressée(s) à un confrère ne figure(nt) pas ici : elles s’ouvrent depuis leur feuille de résultats, après confirmation.
        </p>

        <Card v-if="!groups.length" class="p-10 text-center">
            <History class="mx-auto h-8 w-8 text-muted-foreground/60" />
            <p class="mt-2 text-sm text-muted-foreground">Aucun résultat rendu pour ce patient.</p>
        </Card>

        <Card v-else class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-left">
                            <th class="sticky left-0 z-10 min-w-[15rem] bg-muted px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Analyse</th>
                            <th class="min-w-[9rem] px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Référence</th>
                            <th v-for="column in columns" :key="column.request_uuid" class="px-3 py-2 text-xs">
                                <Link :href="labUrl(`/laboratory/requests/${column.request_uuid}`)" class="block font-semibold text-foreground hover:text-primary hover:underline">{{ formatDate(column.date) }}</Link>
                                <span class="font-mono text-[10px] font-normal text-muted-foreground">{{ column.lab_number }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in visibleGroups" :key="group.name">
                            <tr class="border-b border-border bg-primary/5">
                                <td :colspan="columns.length + 2" class="sticky left-0 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-primary">{{ group.name }}</td>
                            </tr>
                            <tr v-for="row in group.rows" :key="row.key" class="border-b border-border last:border-0 hover:bg-muted/30">
                                <td class="sticky left-0 z-10 bg-card px-3 py-1.5 text-sm text-foreground">
                                    {{ row.designation }}<span v-if="row.unit" class="text-xs text-muted-foreground"> ({{ row.unit }})</span>
                                </td>
                                <td class="px-3 py-1.5 text-xs text-muted-foreground">{{ row.reference ?? '' }}</td>
                                <td v-for="column in columns" :key="column.request_uuid" :class="cellClass(row.values[column.request_uuid])" :title="row.values[column.request_uuid] && !row.values[column.request_uuid].validated ? 'Pas encore envoyé au médecin' : undefined">
                                    <template v-if="row.values[column.request_uuid]">
                                        <span class="inline-flex items-center gap-0.5">
                                            {{ row.values[column.request_uuid].text }}
                                            <ArrowUp v-if="row.values[column.request_uuid].flag === 'HIGH'" class="h-3 w-3" aria-label="au-dessus de la référence" />
                                            <ArrowDown v-else-if="row.values[column.request_uuid].flag === 'LOW'" class="h-3 w-3" aria-label="au-dessous de la référence" />
                                            <Siren v-if="row.values[column.request_uuid].critical" class="h-3 w-3" aria-label="critique" />
                                        </span>
                                    </template>
                                    <span v-else class="text-muted-foreground/50">—</span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!visibleGroups.length"><td :colspan="columns.length + 2" class="px-3 py-8 text-center text-sm text-muted-foreground">Aucune analyse ne correspond.</td></tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <p v-if="columns.length" class="text-xs text-muted-foreground">
            <Badge variant="outline">{{ columns.length }} colonne(s)</Badge>
            Cliquez une date pour ouvrir la demande.
        </p>
    </div>
</template>
