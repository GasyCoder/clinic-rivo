<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import {
    Check, CircleAlert, Clock, Eye, Loader2, RotateCw, Sparkles, Square, X,
} from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { cn } from '@/lib/cn';
import { jsonRequest } from '@/utilities/jsonRequest';
import { AI_BATCH_STATES, nextBatch, runSummary, startRun } from '@/utilities/aiMatchingRun';

/**
 * ADR-242 — « Rapprocher avec l'IA », lot par lot, avec son état à l'écran.
 * Le portail prépare les lots (libellés seulement), puis chaque lot part seul :
 * un lot lent ou refusé n'emporte pas les autres, et se relance d'un clic.
 */
const props = defineProps({
    siteCode: { type: String, required: true },
});
const emit = defineEmits(['proposals', 'busy']);

const run = ref(null);
const preparing = ref(false);
const notice = ref('');
const failure = ref('');
const summary = computed(() => runSummary(run.value));
let alive = true;

const base = computed(() => `/super-admin/pharmacy-suppliers/${props.siteCode}/commander/ia`);

const setBusy = (value) => emit('busy', value);

async function pump() {
    setBusy(true);

    for (let batch = nextBatch(run.value); batch && alive; batch = nextBatch(run.value)) {
        batch.state = AI_BATCH_STATES.RUNNING;
        const response = await jsonRequest(`${base.value}/${run.value.run}/${batch.index}`, { method: 'POST' });

        if (!alive) return;

        batch.state = response.ok ? AI_BATCH_STATES.DONE : AI_BATCH_STATES.FAILED;
        batch.proposed = response.ok ? (response.data?.proposed ?? 0) : 0;
        batch.message = response.message;

        // Le rapprochement a expiré : les lots suivants le seraient aussi.
        if (!response.ok && response.status === 404) {
            run.value.stopped = true;
        }
    }

    setBusy(false);

    if (summary.value.proposed > 0) emit('proposals', summary.value.proposed);
}

async function start({ suppliers, family }) {
    if (preparing.value || summary.value.running) return;

    preparing.value = true;
    failure.value = '';
    notice.value = '';
    run.value = null;
    setBusy(true);

    const response = await jsonRequest(base.value, { method: 'POST', body: { suppliers, family } });
    preparing.value = false;

    if (!response.ok) {
        failure.value = response.message;
        setBusy(false);

        return;
    }

    if (!response.data?.run) {
        notice.value = response.message;
        setBusy(false);

        return;
    }

    run.value = startRun(response.data);
    await pump();
}

const stop = () => { if (run.value) run.value.stopped = true; };
const retryFailed = async () => {
    run.value.stopped = false;
    run.value.batches.filter((batch) => batch.state === AI_BATCH_STATES.FAILED).forEach((batch) => {
        batch.state = AI_BATCH_STATES.WAITING;
        batch.message = '';
    });
    await pump();
};
const close = () => {
    run.value = null;
    notice.value = '';
    failure.value = '';
};

onBeforeUnmount(() => { alive = false; });
defineExpose({ start });

const stateMeta = {
    WAITING: { icon: Clock, label: 'En attente', tone: 'text-muted-foreground' },
    RUNNING: { icon: Loader2, label: 'En cours', tone: 'text-primary' },
    DONE: { icon: Check, label: 'Terminé', tone: 'text-emerald-600 dark:text-emerald-400' },
    FAILED: { icon: CircleAlert, label: 'Échec', tone: 'text-destructive' },
};
const visible = computed(() => preparing.value || Boolean(run.value) || Boolean(notice.value) || Boolean(failure.value));
</script>

<template>
    <section v-if="visible" class="border-b border-border bg-muted/30 px-5 py-4" aria-live="polite">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Sparkles class="h-4.5 w-4.5" /></span>
                <div>
                    <p class="font-semibold text-foreground">Rapprochement par l’IA</p>
                    <p v-if="preparing" class="text-sm text-muted-foreground">Préparation des lots : lecture du comparateur du site…</p>
                    <p v-else-if="failure" class="text-sm font-medium text-destructive">{{ failure }}</p>
                    <p v-else-if="notice" class="text-sm text-muted-foreground">{{ notice }}</p>
                    <p v-else-if="run" class="text-sm text-muted-foreground">
                        {{ summary.done + summary.failed }} / {{ summary.total }} lot{{ summary.total > 1 ? 's' : '' }}
                        · <strong class="text-foreground">{{ summary.proposed }}</strong> proposition{{ summary.proposed > 1 ? 's' : '' }}
                        <span v-if="summary.failed" class="text-destructive"> · {{ summary.failed }} en échec</span>
                        <span v-if="run.stopped && !summary.over"> · arrêt après le lot en cours</span>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="run && !summary.over" type="button" variant="outline" size="sm" :disabled="run.stopped" @click="stop"><Square class="h-3.5 w-3.5" />Arrêter</Button>
                <Button v-if="run && summary.over && summary.failed" type="button" variant="outline" size="sm" @click="retryFailed"><RotateCw class="h-3.5 w-3.5" />Relancer les lots en échec</Button>
                <Button v-if="run && summary.over && summary.proposed" type="button" size="sm" @click="emit('proposals', summary.proposed)"><Eye class="h-3.5 w-3.5" />Voir les propositions</Button>
                <Button v-if="!preparing && (!run || summary.over)" type="button" variant="ghost" size="sm" aria-label="Fermer" @click="close"><X class="h-4 w-4" /></Button>
            </div>
        </div>

        <template v-if="run">
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="summary.percent" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${summary.percent}%` }" />
            </div>
            <ol class="mt-3 grid gap-1.5 sm:grid-cols-2 xl:grid-cols-4">
                <li v-for="batch in run.batches" :key="batch.index" class="flex items-start gap-2 rounded-md border border-border bg-card px-2.5 py-1.5 text-xs" :title="batch.preview.join(' · ')">
                    <component :is="stateMeta[batch.state].icon" :class="cn('mt-0.5 h-3.5 w-3.5 shrink-0', stateMeta[batch.state].tone, batch.state === 'RUNNING' && 'animate-spin')" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="font-semibold text-foreground">Lot {{ batch.index + 1 }}</span>
                        <span class="text-muted-foreground"> · {{ batch.lines }} lignes · {{ stateMeta[batch.state].label }}</span>
                        <span v-if="batch.state === 'DONE'" class="text-emerald-700 dark:text-emerald-400"> · {{ batch.proposed }} proposée{{ batch.proposed > 1 ? 's' : '' }}</span>
                        <span v-if="batch.state === 'FAILED'" class="block text-destructive">{{ batch.message }}</span>
                    </span>
                </li>
            </ol>
            <p v-if="run.remaining || run.alone" class="mt-2 text-xs text-muted-foreground">
                <span v-if="run.alone">{{ run.alone }} produit{{ run.alone > 1 ? 's' : '' }} sans aucune ligne ressemblante chez un autre fournisseur : non envoyé{{ run.alone > 1 ? 's' : '' }}. </span>
                <span v-if="run.remaining">{{ run.remaining }} produit{{ run.remaining > 1 ? 's' : '' }} attendent une prochaine passe : relancez après avoir décidé de ces propositions.</span>
            </p>
        </template>
    </section>
</template>
