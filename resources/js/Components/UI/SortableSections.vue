<script setup>
import { computed, onMounted, ref } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

/**
 * Lets the user choose the vertical order of a screen's sections.
 *
 * Purely presentational. The order is a `localStorage` preference, it is
 * never sent anywhere, and moving a section changes nothing about what the
 * form holds, what it validates or what the workflow still requires.
 *
 * No dependency added: reordering is an array move, and the mouse drag is
 * the browser's own HTML5 drag with a compact drag image. Touch has no HTML5
 * drag at all, which is exactly why the up/down buttons are the primary
 * control rather than a fallback — they work with a finger, with a mouse and
 * with a keyboard alike.
 *
 * Sections keep their component instances across a move: the `v-for` is
 * keyed by the section id, so Vue moves the nodes instead of rebuilding
 * them. Anything a section holds locally — an open accordion, a Oui/Non
 * answer, a half-typed field — survives being dragged.
 */
const props = defineProps({
    /** [{ id, label }] in the recommended clinical order. */
    sections: { type: Array, required: true },
    storageKey: { type: String, default: null },
    /** Customising is pointless on a read-only file. */
    enabled: { type: Boolean, default: true },
    label: { type: String, default: 'Personnaliser l’ordre' },
});

const defaultOrder = computed(() => props.sections.map((section) => section.id));

/**
 * Repairs a stored order instead of trusting it (§10).
 *
 * A preference outlives the screen that produced it: sections get added,
 * renamed or removed between versions. Known ids keep the user's order,
 * anything unknown is dropped, and anything new is appended where the
 * recommended order puts it — so an old preference never leaves a section
 * invisible, and never breaks the screen.
 */
const normalize = (candidate) => {
    const known = new Set(defaultOrder.value);
    const kept = Array.isArray(candidate)
        ? candidate.filter((id) => known.has(id) && typeof id === 'string')
        : [];
    const unique = [...new Set(kept)];

    return [...unique, ...defaultOrder.value.filter((id) => !unique.includes(id))];
};

const order = ref(defaultOrder.value.slice());
const customizing = ref(false);
const draggingId = ref(null);
const movedId = ref(null);

const persist = () => {
    if (!props.storageKey) return;

    try {
        window.localStorage.setItem(props.storageKey, JSON.stringify(order.value));
    } catch {
        // Storage unavailable: the order still works for this visit.
    }
};

/**
 * Read after mount, never during render: reading storage while rendering
 * leaves the DOM holding the value the first render computed.
 */
onMounted(() => {
    if (!props.storageKey) return;

    try {
        order.value = normalize(JSON.parse(window.localStorage.getItem(props.storageKey)));
    } catch {
        order.value = defaultOrder.value.slice();
    }
});

const ordered = computed(() => order.value
    .map((id) => props.sections.find((section) => section.id === id))
    .filter(Boolean));

/** Briefly marks the section that just moved, so the eye can follow it. */
const flash = (id) => {
    movedId.value = id;
    window.setTimeout(() => {
        if (movedId.value === id) movedId.value = null;
    }, 900);
};

const moveTo = (id, index) => {
    const from = order.value.indexOf(id);

    if (from === -1 || index < 0 || index >= order.value.length || index === from) return;

    const next = order.value.slice();
    next.splice(index, 0, ...next.splice(from, 1));
    order.value = next;
};

const move = (id, delta) => {
    moveTo(id, order.value.indexOf(id) + delta);
    persist();
    flash(id);
};

const reset = () => {
    order.value = defaultOrder.value.slice();

    if (props.storageKey) {
        try {
            window.localStorage.removeItem(props.storageKey);
        } catch {
            // Nothing to clean up if storage is unavailable.
        }
    }
};

const isCustom = computed(() => order.value.join() !== defaultOrder.value.join());

/* ------------------------------------------------------------------ */
/* Glisser-déposer souris. Le tactile passe par les flèches (§16).      */
/* ------------------------------------------------------------------ */

const ghost = ref(null);
const ghostLabel = ref('');

const onDragStart = (event, section) => {
    if (!customizing.value) return;

    draggingId.value = section.id;
    ghostLabel.value = section.label;
    event.dataTransfer.effectAllowed = 'move';
    // Firefox refuses to start a drag without data on the transfer.
    event.dataTransfer.setData('text/plain', section.id);

    // A compact preview rather than an 800px-tall form dragged around (§12).
    if (ghost.value) {
        event.dataTransfer.setDragImage(ghost.value, 12, 18);
    }
};

/**
 * Live reorder: the list rearranges under the pointer, which *is* the
 * placeholder — no ghost gap to compute, and what you see during the drag is
 * already the result.
 */
const onDragOver = (event, section) => {
    if (!customizing.value || draggingId.value === null || draggingId.value === section.id) return;

    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    moveTo(draggingId.value, order.value.indexOf(section.id));
};

const onDragEnd = () => {
    if (draggingId.value === null) return;

    flash(draggingId.value);
    draggingId.value = null;
    persist();
};

const positionLabel = (id) => `${order.value.indexOf(id) + 1} sur ${order.value.length}`;
</script>

<template>
    <div>
        <!-- Action discrète : rien ne bouge tant qu'elle n'est pas activée,
             pour qu'une section ne parte jamais par accident en pleine
             consultation (§2). -->
        <div v-if="enabled" class="mb-3 flex flex-wrap items-center justify-end gap-2">
            <p v-if="customizing" class="me-auto text-[11px] text-slate-400">
                Glissez la poignée, ou utilisez les flèches. L’ordre est propre à votre poste ; il ne change rien au dossier.
            </p>
            <button
                v-if="customizing && isCustom"
                type="button"
                class="rounded-md border border-gray-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-500 transition-colors hover:border-gray-300 hover:text-slate-700 dark:border-gray-800 dark:text-slate-300"
                @click="reset"
            >Réinitialiser</button>
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-[11px] font-semibold transition-colors',
                    customizing
                        ? 'border-primary-300 bg-primary-50 text-primary-700 dark:border-primary-800 dark:bg-primary-950/30 dark:text-primary-300'
                        : 'border-gray-200 text-slate-500 hover:border-gray-300 hover:text-slate-700 dark:border-gray-800 dark:text-slate-400',
                ]"
                :aria-pressed="customizing"
                @click="customizing = !customizing"
            >
                <Icon class="text-sm" :name="customizing ? 'check' : 'sort'" />{{ customizing ? 'Terminer' : label }}
            </button>
        </div>

        <!-- L'aperçu compact emporté par le curseur pendant le glissement.
             Hors écran plutôt que masqué : `display:none` ne peut pas servir
             d'image de drag. -->
        <div ref="ghost" class="pointer-events-none fixed -left-[9999px] top-0 flex items-center gap-2 rounded-md border border-primary-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-lg">
            <Icon class="text-sm text-primary-600" name="move" />{{ ghostLabel }}
        </div>

        <div class="space-y-4">
            <!-- Clé = identifiant de section, jamais l'index : Vue déplace
                 les nœuds au lieu de les reconstruire, et ce que chaque
                 section garde en propre survit au déplacement (§5). -->
            <div
                v-for="(section, index) in ordered"
                :key="section.id"
                :class="[
                    'transition-opacity',
                    draggingId === section.id ? 'opacity-40' : '',
                ]"
                @dragover="onDragOver($event, section)"
                @drop.prevent
            >
                <div
                    v-if="customizing"
                    :class="[
                        'flex items-center gap-2 rounded-t-lg border border-b-0 px-3 py-1.5 transition-colors',
                        movedId === section.id
                            ? 'border-primary-300 bg-primary-50 dark:border-primary-800 dark:bg-primary-950/30'
                            : 'border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-gray-1000/40',
                    ]"
                >
                    <span
                        class="flex cursor-grab items-center text-slate-400 transition-colors hover:text-primary-600 active:cursor-grabbing dark:hover:text-primary-300"
                        draggable="true"
                        :aria-hidden="true"
                        @dragstart="onDragStart($event, section)"
                        @dragend="onDragEnd"
                    >
                        <Icon class="text-base" name="move" />
                    </span>

                    <span class="min-w-0 flex-1 truncate text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-300">
                        {{ section.label }}
                    </span>

                    <span class="text-[10px] text-slate-400">{{ positionLabel(section.id) }}</span>

                    <!-- Les flèches sont le moyen principal, pas un repli :
                         elles fonctionnent au doigt, à la souris et au
                         clavier, là où le glisser-déposer HTML5 n'existe pas
                         sur tactile (§16, §17). -->
                    <span class="flex items-center gap-1">
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded border border-gray-200 text-slate-500 transition-colors hover:border-primary-300 hover:text-primary-600 disabled:opacity-30 dark:border-gray-800 dark:text-slate-300"
                            :disabled="index === 0"
                            :aria-label="`Déplacer ${section.label} vers le haut`"
                            @click="move(section.id, -1)"
                        ><Icon class="text-sm" name="chevron-up" /></button>
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded border border-gray-200 text-slate-500 transition-colors hover:border-primary-300 hover:text-primary-600 disabled:opacity-30 dark:border-gray-800 dark:text-slate-300"
                            :disabled="index === ordered.length - 1"
                            :aria-label="`Déplacer ${section.label} vers le bas`"
                            @click="move(section.id, 1)"
                        ><Icon class="text-sm" name="chevron-down" /></button>
                    </span>
                </div>

                <slot :name="section.id" />
            </div>
        </div>
    </div>
</template>
