<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import ClinicalSegmentedChoice from '@/Components/Clinical/ClinicalSegmentedChoice.vue';
import FormError from '@/Components/UI/FormError.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { cn } from '@/lib/cn';

/**
 * The nine body systems as a horizontal slider: one card per system, swiped
 * or scrolled through, with a chip bar on top that shows every status at a
 * glance and jumps to any system.
 *
 * Nothing is pre-selected and nothing is inferred: each card starts at
 * « Non examiné » and only the doctor's choice moves it. Choosing « Normal »
 * slides on to the next system — the common, quick path — while « Anormal »
 * stays put, because its findings are now required.
 */
const props = defineProps({
    /** [{ system_code, label, hint, status, findings }] — always all systems. */
    systems: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    /** Server errors keyed by system_code. */
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update', 'reset']);

const OPTIONS = [
    { value: 'NOT_EXAMINED', label: 'Non examiné' },
    { value: 'NORMAL', label: 'Normal', tone: 'positive' },
    { value: 'ABNORMAL', label: 'Anormal', tone: 'warning' },
];

const DOT = {
    NORMAL: 'bg-emerald-500',
    ABNORMAL: 'bg-amber-500',
    NOT_EXAMINED: 'bg-muted-foreground/40',
};

const track = ref(null);
const current = ref(0);

/**
 * Brings a card into view. Scroll-snap does the positioning, so this only
 * asks the browser to scroll — the track stays a native, swipeable scroller.
 */
const goTo = (index) => {
    const bounded = Math.max(0, Math.min(props.systems.length - 1, index));
    const card = track.value?.children[bounded];

    current.value = bounded;
    card?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
};

let scrollFrame = null;

/** The card nearest the left edge is the current one, whatever moved it. */
const onScroll = () => {
    if (scrollFrame !== null) return;

    scrollFrame = requestAnimationFrame(() => {
        scrollFrame = null;
        const el = track.value;

        if (!el) return;

        const cards = [...el.children];
        const left = el.getBoundingClientRect().left;
        let best = 0;
        let distance = Infinity;

        cards.forEach((card, index) => {
            const gap = Math.abs(card.getBoundingClientRect().left - left);
            if (gap < distance) { distance = gap; best = index; }
        });

        current.value = best;
    });
};

onBeforeUnmount(() => {
    if (scrollFrame !== null) cancelAnimationFrame(scrollFrame);
});

const setStatus = async (index, code, value) => {
    const next = value ?? 'NOT_EXAMINED';
    update(code, { status: next });

    // Normal is the quick path: move on. Anormal stays — its findings are
    // now mandatory and the field has just appeared on this card.
    if (next === 'NORMAL' && index < props.systems.length - 1) {
        await nextTick();
        goTo(index + 1);
    }
};

const onTrackKeydown = (event) => {
    if (event.target !== track.value) return;
    if (event.key === 'ArrowRight') { goTo(current.value + 1); event.preventDefault(); }
    if (event.key === 'ArrowLeft') { goTo(current.value - 1); event.preventDefault(); }
};

const examinedCount = computed(() => props.systems.filter((system) => system.status !== 'NOT_EXAMINED').length);
const abnormalCount = computed(() => props.systems.filter((system) => system.status === 'ABNORMAL').length);

const update = (code, patch) => emit('update', { system_code: code, ...patch });

/**
 * Whether a per-system examination was carried out at all.
 *
 * "Non" means *nothing was examined* — never *everything is normal*. The
 * grid is simply hidden and every system stays NOT_EXAMINED, which is the
 * truth and exactly what the record will show.
 *
 * Opens by itself when systems are already recorded, so re-opening an
 * existing examination never hides the doctor's own work.
 */
const performed = ref(examinedCount.value > 0);

/**
 * What was typed before answering "Non", kept in memory only. An accidental
 * click would otherwise throw away findings the doctor had written; coming
 * back to "Oui" restores them instead of demanding a re-entry. Nothing is
 * persisted until the form is saved.
 */
const stashed = ref(null);

const setPerformed = (value) => {
    if (props.disabled || value === performed.value) return;

    performed.value = value;

    if (!value) {
        stashed.value = props.systems.map((system) => ({ ...system }));
        emit('reset', { restore: null });

        return;
    }

    emit('reset', { restore: stashed.value });
    stashed.value = null;
};
</script>

<template>
    <section class="rounded-lg border border-border p-4 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Examen par appareil</h3>
            <p v-if="performed" class="text-[11px] text-muted-foreground">
                {{ examinedCount }}/{{ systems.length }} examiné(s)<template v-if="abnormalCount"> · {{ abnormalCount }} anomalie(s)</template>
            </p>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2.5">
            <span class="text-xs font-semibold text-foreground">Examen par appareil réalisé ?</span>
            <span class="inline-flex rounded-md border border-border bg-card p-0.5" role="radiogroup" aria-label="Un examen par appareil a-t-il été réalisé ?">
                <button
                    type="button"
                    role="radio"
                    :aria-checked="performed"
                    :disabled="disabled"
                    :class="['rounded px-3 py-1 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50', performed ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground']"
                    @click="setPerformed(true)"
                >Oui</button>
                <button
                    type="button"
                    role="radio"
                    :aria-checked="!performed"
                    :disabled="disabled"
                    :class="['rounded px-3 py-1 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50', !performed ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground']"
                    @click="setPerformed(false)"
                >Non</button>
            </span>
        </div>

        <!-- Dit explicitement pour que « Non » ne se lise jamais « tout est
             normal » : aucun appareil n'a été examiné, et c'est ce que le
             dossier montrera. -->
        <p v-if="!performed" class="mt-3 text-[11px] leading-4 text-muted-foreground">
            Aucun appareil examiné pour cette consultation. Les neuf appareils resteront « Non examiné » — ce n’est pas un examen normal.
        </p>

        <template v-else>
            <p class="mt-2 text-[11px] leading-4 text-muted-foreground">
                Chaque appareil reste « Non examiné » tant que vous ne l’avez pas renseigné. Une absence de saisie n’est jamais un examen normal.
            </p>

            <!-- Barre de repères : les neuf statuts d'un coup d'œil, et un
                 clic pour aller directement à un appareil. -->
            <div class="mt-3 flex flex-wrap gap-1.5" role="tablist" aria-label="Appareils">
                <button
                    v-for="(system, index) in systems"
                    :key="`chip-${system.system_code}`"
                    type="button"
                    role="tab"
                    :aria-selected="current === index"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition-colors',
                        current === index
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-border text-muted-foreground hover:border-primary/40 hover:text-foreground',
                    ]"
                    @click="goTo(index)"
                >
                    <span :class="['h-1.5 w-1.5 rounded-full', DOT[system.status] ?? DOT.NOT_EXAMINED]" />{{ system.label }}
                </button>
            </div>

            <div class="relative mt-3">
                <!-- Piste native : défilement tactile, molette ou trackpad,
                     calée carte par carte par scroll-snap. -->
                <div
                    ref="track"
                    class="flex items-start snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth pb-2 [scrollbar-width:thin]"
                    tabindex="0"
                    aria-label="Examen par appareil — flèches gauche et droite pour changer d’appareil"
                    @scroll.passive="onScroll"
                    @keydown="onTrackKeydown"
                >
                    <article
                        v-for="(system, index) in systems"
                        :key="system.system_code"
                        :class="[
                            'flex w-[85%] shrink-0 snap-start flex-col rounded-lg border p-4 transition-colors sm:w-[20rem]',
                            system.status === 'ABNORMAL'
                                ? 'border-amber-300 bg-amber-50/40 dark:border-amber-800 dark:bg-amber-950/10'
                                : system.status === 'NORMAL'
                                    ? 'border-emerald-200 bg-emerald-50/30 dark:border-emerald-900 dark:bg-emerald-950/10'
                                    : 'border-border bg-card',
                        ]"
                        :aria-label="`${system.label}, ${index + 1} sur ${systems.length}`"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ index + 1 }} / {{ systems.length }}</p>
                                <h4 class="mt-0.5 truncate text-sm font-bold text-foreground">{{ system.label }}</h4>
                                <p v-if="system.hint" class="mt-0.5 text-[11px] leading-4 text-muted-foreground">{{ system.hint }}</p>
                            </div>
                            <span :class="['mt-1 h-2 w-2 shrink-0 rounded-full', DOT[system.status] ?? DOT.NOT_EXAMINED]" />
                        </div>

                        <ClinicalSegmentedChoice
                            class="mt-3"
                            :name="`system_status_${system.system_code}`"
                            :model-value="system.status"
                            :options="OPTIONS"
                            :clearable="false"
                            :disabled="disabled"
                            @update:model-value="setStatus(index, system.system_code, $event)"
                        />

                        <div v-if="system.status === 'ABNORMAL'" class="mt-3">
                            <label :for="`findings_${system.system_code}`" class="mb-1 block text-[11px] font-bold text-foreground">
                                Constatations <span class="text-red-500">*</span>
                            </label>
                            <Textarea
                                :id="`findings_${system.system_code}`"
                                :model-value="system.findings ?? ''"
                                :disabled="disabled"
                                rows="3"
                                maxlength="2000"
                                placeholder="Décrivez l’anomalie constatée…"
                                @update:model-value="update(system.system_code, { findings: $event })"
                            />
                            <FormError :message="errors[system.system_code]" />
                        </div>
                    </article>
                </div>

                <div class="mt-2 flex items-center justify-between">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1.5 text-[11px] font-semibold text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground disabled:opacity-30"
                        :disabled="current === 0"
                        aria-label="Appareil précédent"
                        @click="goTo(current - 1)"
                    ><ChevronLeft class="h-3.5 w-3.5" />Précédent</button>
                    <span class="text-[11px] tabular-nums text-muted-foreground">{{ current + 1 }} / {{ systems.length }}</span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1.5 text-[11px] font-semibold text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground disabled:opacity-30"
                        :disabled="current === systems.length - 1"
                        aria-label="Appareil suivant"
                        @click="goTo(current + 1)"
                    >Suivant<ChevronRight class="h-3.5 w-3.5" /></button>
                </div>
            </div>
        </template>
    </section>
</template>
