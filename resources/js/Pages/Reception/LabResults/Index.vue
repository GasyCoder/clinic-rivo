<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, CheckCircle2, Clock, Download, ExternalLink, FileCheck2, FileText, FlaskConical, Hourglass, Printer, Search, UserRound, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrPagination from '@/Pages/Administration/Partials/HrPagination.vue';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';

/**
 * ADR-216, amendement quater — « Résultats à remettre ».
 *
 * Le laboratoire termine et envoie ; le médecin relit et valide. Seuls les
 * résultats validés arrivent ici, et la Réception imprime leur compte rendu
 * pour le patient. Une demande dont une analyse attend encore est « partielle » :
 * on peut remettre ce qui est validé, et l'écran dit ce qui manque.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    requests: { type: Object, required: true },
    counts: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const CARDS = [
    { value: 'tous', label: 'Tous', hint: 'Demandes avec un résultat validé', icon: FileCheck2, tone: 'bg-primary/10 text-primary' },
    { value: 'complets', label: 'Complets', hint: 'Toutes les analyses validées', icon: CheckCircle2, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' },
    { value: 'partiels', label: 'Partiels', hint: 'D’autres résultats attendus', icon: Hourglass, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' },
];

const query = ref(props.filters.q ?? '');
const reload = (changes) => router.get('/reception/resultats-analyses', {
    q: query.value || undefined,
    vue: props.filters.vue !== 'tous' ? props.filters.vue : undefined,
    ...changes,
}, { preserveScroll: true, preserveState: true, replace: true });

let timer = null;
watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(() => reload({ page: undefined }), 350);
});

const rows = computed(() => props.requests.data ?? []);

// Le compte rendu s'ouvre dans la page, prêt à imprimer.
const viewing = ref(null);
const frame = ref(null);
const loaded = ref(false);
const openReport = (row) => {
    loaded.value = false;
    viewing.value = row;
};
const printReport = async () => {
    await nextTick();
    try {
        frame.value?.contentWindow?.focus();
        frame.value?.contentWindow?.print();
    } catch {
        window.open(viewing.value.pdf_url, '_blank', 'noopener');
    }
};
</script>

<template>
    <Head title="Résultats à remettre" />
    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Réception"
            title="Résultats à remettre"
            description="Les résultats d’analyses validés par le médecin. Ouvrez le compte rendu pour l’imprimer et le remettre au patient."
            :icon="FileCheck2"
        />

        <div class="grid gap-3 sm:grid-cols-3" role="group" aria-label="Filtrer les résultats">
            <button
                v-for="card in CARDS"
                :key="card.value"
                type="button"
                :aria-pressed="filters.vue === card.value"
                :class="cn(
                    'relative flex items-center gap-3 rounded-xl border bg-card p-3.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filters.vue === card.value ? 'border-primary ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40',
                )"
                @click="reload({ vue: card.value === 'tous' ? undefined : card.value, page: undefined })"
            >
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', card.tone)"><component :is="card.icon" class="h-5 w-5" /></span>
                <span class="min-w-0">
                    <span class="block text-2xl font-bold leading-none tabular-nums text-foreground">{{ counts[card.value] }}</span>
                    <span class="mt-1 block text-xs font-semibold leading-tight text-foreground">{{ card.label }}</span>
                    <span class="block text-[11px] leading-tight text-muted-foreground">{{ card.hint }}</span>
                </span>
                <Check v-if="filters.vue === card.value" class="absolute end-3 top-3 h-4 w-4 text-primary" aria-hidden="true" />
            </button>
        </div>

        <Card class="overflow-hidden">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:w-96">
                    <IconInput v-model="query" :icon="Search" type="search" placeholder="Patient, n° de dossier, de passage ou de laboratoire" aria-label="Rechercher un résultat" class="pe-9" />
                    <button v-if="query" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="query = ''">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <p class="text-xs text-muted-foreground">Les plus récemment validés d’abord.</p>
            </div>

            <div v-if="! rows.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary"><FileCheck2 class="h-6 w-6" /></span>
                <p class="text-sm font-semibold text-foreground">Aucun résultat à remettre</p>
                <p class="max-w-md text-sm text-muted-foreground">Un résultat apparaît ici dès que le médecin l’a validé. Tant qu’il est au laboratoire ou chez le médecin, il n’est pas encore à remettre.</p>
            </div>

            <ul v-else class="divide-y divide-border">
                <li v-for="row in rows" :key="row.uuid" class="grid gap-3 px-4 py-4 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)_auto] lg:items-center">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <UserRound class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <Link v-if="row.patient?.uuid" :href="`/patients/${row.patient.uuid}`" class="truncate hover:text-primary hover:underline">{{ row.patient.name }}</Link>
                            <span v-else class="truncate">{{ row.patient?.name ?? '—' }}</span>
                        </p>
                        <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                            {{ row.patient?.number }}
                            <template v-if="row.episode"> · <Link v-if="row.episode.uuid" :href="`/passages/${row.episode.uuid}`" class="hover:text-primary hover:underline">{{ row.episode.number }}</Link><span v-else>{{ row.episode.number }}</span></template>
                        </p>
                        <p v-if="row.lab_number" class="mt-0.5 flex items-center gap-1 font-mono text-xs text-muted-foreground"><FlaskConical class="h-3.5 w-3.5" aria-hidden="true" />{{ row.lab_number }}</p>
                    </div>

                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap gap-1.5">
                            <Badge v-for="item in row.approved" :key="item.uuid" variant="success" :title="`Validée ${item.approved_by ? 'par ' + item.approved_by : ''} le ${formatDateTime(item.approved_at)}`">
                                <CheckCircle2 class="h-3.5 w-3.5" />{{ item.name }}
                            </Badge>
                            <Badge v-for="item in row.pending" :key="item.uuid" variant="secondary" :title="item.state">
                                <Clock class="h-3.5 w-3.5" />{{ item.name }} · {{ item.state }}
                            </Badge>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Validé<template v-if="row.approved_by.length"> par {{ row.approved_by.join(', ') }}</template> le {{ formatDateTime(row.approved_at) }}
                            <template v-if="row.prescriber"> · prescrit par {{ row.prescriber }}</template>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <Badge v-if="row.complete" variant="success">Résultats validés</Badge>
                        <Badge v-else variant="warning">Partiel · {{ row.pending.length }} en attente</Badge>
                        <Button type="button" size="sm" @click="openReport(row)"><FileText class="h-4 w-4" />Compte rendu</Button>
                    </div>
                </li>
            </ul>
            <HrPagination :paginator="requests" />
        </Card>

        <Dialog
            :open="viewing !== null"
            :title="viewing ? `Compte rendu — ${viewing.patient?.name ?? ''}` : ''"
            :description="viewing && ! viewing.complete ? 'Seuls les résultats validés par le médecin y figurent ; les autres suivront.' : 'Les résultats validés par le médecin.'"
            size="wide"
            body-class="p-0"
            @update:open="(open) => open || (viewing = null)"
        >
            <div v-if="viewing" class="relative h-[70dvh] bg-muted">
                <p v-if="! loaded" class="absolute inset-0 grid place-items-center text-sm text-muted-foreground">Chargement du compte rendu…</p>
                <iframe ref="frame" :src="viewing.pdf_url" title="Compte rendu des résultats" class="h-full w-full border-0" @load="loaded = true" />
            </div>
            <template #footer>
                <Button v-if="viewing" as="a" :href="`${viewing.pdf_url}?telecharger=1`" variant="outline"><Download class="h-4 w-4" />Télécharger</Button>
                <Button v-if="viewing" as="a" :href="viewing.pdf_url" target="_blank" rel="noopener" variant="outline"><ExternalLink class="h-4 w-4" />Ouvrir</Button>
                <Button type="button" @click="printReport"><Printer class="h-4 w-4" />Imprimer</Button>
            </template>
        </Dialog>
    </div>
</template>
