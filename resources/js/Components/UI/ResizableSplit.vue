<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Two panes the user can resize by dragging the separator between them.
 *
 * No dependency added: the whole behaviour is a grid whose first column is
 * driven by one custom property. During a drag only `--rs-ratio` changes, so
 * the browser recomputes the track and nothing else — no per-frame style
 * writes on the panes themselves, and the rich-text editor inside is never
 * remounted.
 *
 * Pointer Events rather than mouse events: the same code covers mouse, touch
 * and stylus, and `setPointerCapture` keeps the drag alive when the pointer
 * leaves the thin handle — the usual reason a splitter feels like it "slips".
 *
 * The ratio is pure UI. It is never sent to the server, and resizing can
 * never alter what is recorded.
 */
const props = defineProps({
    /** localStorage key. Omit to make the choice last only for this visit. */
    storageKey: { type: String, default: null },
    /** Share of the width given to the first pane, 0–1. */
    defaultRatio: { type: Number, default: 0.7 },
    minRatio: { type: Number, default: 0.45 },
    maxRatio: { type: Number, default: 0.75 },
    startLabel: { type: String, default: 'panneau gauche' },
    endLabel: { type: String, default: 'panneau droit' },
    /** Rien à mettre dans le second panneau : le premier prend toute la largeur, sans poignée. */
    single: { type: Boolean, default: false },
});

const clamp = (value) => Math.min(props.maxRatio, Math.max(props.minRatio, value));

/**
 * A stored ratio is only trusted if it is a real number inside the bounds
 * this screen allows today. Bounds change with the layout, and a value saved
 * by an older version must never squeeze a pane into something unusable
 * (§9). Reading can also throw outright — private windows, blocked storage —
 * so the default has to survive that too.
 */
const readStored = () => {
    if (!props.storageKey) return null;

    try {
        const raw = Number.parseFloat(window.localStorage.getItem(props.storageKey));

        return Number.isFinite(raw) && raw >= props.minRatio && raw <= props.maxRatio ? raw : null;
    } catch {
        return null;
    }
};

const ratio = ref(clamp(props.defaultRatio));
const dragging = ref(false);

/**
 * The stored ratio is applied after mount, never during the first render.
 *
 * Reading `localStorage` while rendering is an impure render, and it showed:
 * the DOM kept the default in its `style` attribute while the component's own
 * state already held the restored value — the panes came back at 70/30 with
 * 0.52 saved. Assigning here produces a normal reactive update, which patches
 * the attribute like any other change.
 */
onMounted(() => {
    const stored = readStored();

    if (stored !== null) ratio.value = stored;
});

const persist = () => {
    if (!props.storageKey) return;

    try {
        window.localStorage.setItem(props.storageKey, ratio.value.toFixed(3));
    } catch {
        // Storage unavailable: the split still works, it just starts from the
        // default next time. Never a reason to break the screen.
    }
};

/** Double-click puts the layout back where it started (§10). */
const reset = () => {
    ratio.value = clamp(props.defaultRatio);
    persist();
};

const root = ref(null);
const DOUBLE_PRESS_MS = 400;
let lastPressAt = 0;
let frame = null;
let pending = null;

/** One write per frame: a pointermove can fire far more often than that. */
const schedule = (value) => {
    pending = value;

    if (frame !== null) return;

    frame = requestAnimationFrame(() => {
        frame = null;
        if (pending !== null) ratio.value = pending;
        pending = null;
    });
};

const ratioFromPointer = (event) => {
    const box = root.value?.getBoundingClientRect();

    if (!box || box.width === 0) return null;

    return clamp((event.clientX - box.left) / box.width);
};

const onPointerDown = (event) => {
    // Left button or touch only: a right-click must not start a drag.
    if (event.button !== 0 && event.pointerType === 'mouse') return;

    // Two presses in quick succession reset the layout (§10).
    //
    // Timed here rather than through a `dblclick` listener for two reasons:
    // preventing the default below — which is what stops a drag from
    // selecting text — also suppresses the double-click event, and
    // `PointerEvent.detail` is 0 by specification, so the click counter that
    // would normally carry this is simply not there. Timing it covers the
    // double-tap on a touch screen as well.
    if (event.timeStamp - lastPressAt < DOUBLE_PRESS_MS) {
        lastPressAt = 0;
        reset();
        event.preventDefault();

        return;
    }

    lastPressAt = event.timeStamp;

    dragging.value = true;
    event.currentTarget.setPointerCapture(event.pointerId);
    event.preventDefault();
};

const onPointerMove = (event) => {
    if (!dragging.value) return;

    const next = ratioFromPointer(event);

    if (next !== null) schedule(next);
};

const onPointerUp = (event) => {
    if (!dragging.value) return;

    dragging.value = false;
    event.currentTarget.releasePointerCapture?.(event.pointerId);
    // Saved once, at the end: writing on every frame would hammer storage
    // for a value nobody reads until the next visit (§15).
    persist();
};

/**
 * The separator is not a mouse-only control. Arrow keys move it by 2 %, with
 * Shift for a coarser step — enough to reach either end without holding a
 * key down.
 */
const onKeydown = (event) => {
    const step = (event.shiftKey ? 0.1 : 0.02) * (event.key === 'ArrowLeft' ? -1 : 1);

    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
        if (event.key === 'Home' || event.key === 'End') {
            ratio.value = event.key === 'Home' ? props.minRatio : props.maxRatio;
            persist();
            event.preventDefault();
        }

        return;
    }

    ratio.value = clamp(ratio.value + step);
    persist();
    event.preventDefault();
};

onBeforeUnmount(() => {
    if (frame !== null) cancelAnimationFrame(frame);
});

const percent = computed(() => Math.round(ratio.value * 100));
</script>

<template>
    <div class="rs-container" :style="{ '--rs-ratio': ratio }">
        <div
            ref="root"
            class="rs-root"
            :class="{ 'rs-dragging': dragging, 'rs-single': single }"
        >
        <div class="rs-pane min-w-0">
            <slot name="start" />
        </div>

        <!-- Ligne fine, zone de préhension large : la cible cliquable fait
             20px alors que le trait n'en fait que 2 (§4). -->
        <div
            v-if="!single"
            class="rs-handle"
            role="separator"
            aria-orientation="vertical"
            tabindex="0"
            :aria-valuenow="percent"
            :aria-valuemin="Math.round(minRatio * 100)"
            :aria-valuemax="Math.round(maxRatio * 100)"
            :aria-label="`Largeur du ${startLabel} : ${percent} %. Flèches gauche et droite pour ajuster.`"
            :title="`${percent} % / ${100 - percent} % — glisser, ou double-cliquer pour réinitialiser`"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @keydown="onKeydown"
        >
            <span class="rs-grip" aria-hidden="true">
                <span class="rs-pad">
                    <i /><i /><i />
                </span>
            </span>
        </div>

        <div v-if="!single" class="rs-pane min-w-0">
            <slot name="end" />
        </div>
        </div>
    </div>
</template>

<style scoped>
/* Le conteneur mesuré est l'enveloppe, jamais la grille elle-même : une
   container query ne s'applique pas à son propre conteneur, et la grille se
   serait retrouvée empilée avec sa poignée visible — descendante, elle, du
   conteneur. Bug constaté puis corrigé ici. */
.rs-container {
    container-type: inline-size;
}

/* Empilé par défaut : c'est l'état sûr, et le palier large ne fait que
   l'améliorer là où il y a la place. */
.rs-root {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
}

.rs-handle {
    display: none;
}

/* 54rem (864px) : mesuré sur la boîte de contenu, padding déduit. À 1280px
   de viewport cette carte dispose de 891px — au-dessus du palier, donc deux
   panneaux ; à 900px elle n'a que 799px et repasse à une colonne, ce que
   demande le §12. */
@container (min-width: 54rem) {
    .rs-root {
        /* Une seule variable pilote la mise en page : pendant le drag, c'est
           la seule chose que le navigateur ait à recalculer. */
        grid-template-columns:
            minmax(0, calc((100% - var(--rs-gutter)) * var(--rs-ratio)))
            var(--rs-gutter)
            minmax(0, 1fr);
        gap: 0;
    }

    .rs-handle {
        display: flex;
        align-items: center;
        justify-content: center;
        /* `stretch` et non `center` : la poignée doit courir sur toute la
           hauteur de la rangée, sinon elle ne se saisit qu'au milieu. */
        align-self: stretch;
        cursor: col-resize;
        touch-action: none;
        outline: none;
    }
}

.rs-container {
    --rs-gutter: 1.25rem;
}

/* Un seul panneau : toute la largeur, quelle que soit celle du conteneur. */
.rs-root.rs-single {
    grid-template-columns: minmax(0, 1fr);
}

/* Le trait : discret au repos, à la couleur de l'application au survol,
   franc pendant le drag.

   Les couleurs passent par les tokens sémantiques (ADR-099) plutôt que par
   un bleu codé en dur : la poignée suivait sa propre palette, et il fallait
   deux blocs de surcharge pour rattraper le mode sombre — qui manquaient dès
   qu'un thème changeait. */
.rs-grip {
    position: relative;
    display: block;
    width: 2px;
    height: 100%;
    min-height: 2.5rem;
    border-radius: 9999px;
    background-color: hsl(var(--border));
    transition: background-color 120ms ease;
}

.rs-handle:hover .rs-grip,
.rs-handle:focus-visible .rs-grip,
.rs-dragging .rs-grip {
    background-color: hsl(var(--primary));
}

/* Les trois points au centre : seule marque qui dit « ça se glisse ».
   Posés sur une petite pastille opaque plutôt que dessinés dans le trait —
   sur une ligne de 2px, des points de la même couleur ne se voient pas, et
   au survol ils disparaissaient dans le bleu. */
.rs-pad {
    position: absolute;
    inset-block-start: 50%;
    inset-inline-start: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    width: 11px;
    height: 28px;
    transform: translate(-50%, -50%);
    border: 1px solid hsl(var(--border));
    border-radius: 9999px;
    background-color: hsl(var(--card));
    transition: border-color 120ms ease;
}

.rs-pad i {
    width: 2px;
    height: 2px;
    border-radius: 9999px;
    background-color: hsl(var(--muted-foreground));
    transition: background-color 120ms ease;
}

.rs-handle:hover .rs-pad,
.rs-handle:focus-visible .rs-pad,
.rs-dragging .rs-pad {
    border-color: hsl(var(--primary) / 0.5);
}

.rs-handle:hover .rs-pad i,
.rs-handle:focus-visible .rs-pad i,
.rs-dragging .rs-pad i {
    background-color: hsl(var(--primary));
}

.rs-handle:focus-visible {
    border-radius: 0.375rem;
    box-shadow: 0 0 0 2px hsl(var(--ring) / 0.45);
}

/* Pendant le drag : pas de sélection accidentelle, et le curseur reste
   col-resize même quand le pointeur quitte le trait (§5). */
.rs-dragging {
    cursor: col-resize;
    user-select: none;
}

.rs-dragging .rs-pane {
    pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
    .rs-grip,
    .rs-pad,
    .rs-pad i {
        transition: none;
    }
}
</style>
