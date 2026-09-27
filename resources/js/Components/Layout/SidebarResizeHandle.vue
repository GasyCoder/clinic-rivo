<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Séparateur vertical de la sidebar du portail.
 *
 * C'est une préférence de présentation uniquement : la largeur ne quitte
 * jamais le navigateur et n'influence ni les menus permis ni les données.
 */
const props = defineProps({
    enabled: { type: Boolean, default: false },
    min: { type: Number, default: 260 },
    max: { type: Number, default: 420 },
    defaultWidth: { type: Number, default: 288 },
    storageKey: { type: String, default: 'rivo.super-admin.sidebar-width' },
});

const emit = defineEmits(['resizing']);
const width = defineModel({ type: Number, default: 288 });
const dragging = ref(false);
const clamp = (value) => Math.min(props.max, Math.max(props.min, Math.round(value)));

const setWidth = (value) => { width.value = clamp(value); };

onMounted(() => {
    if (!props.enabled) return;

    try {
        const stored = Number.parseInt(window.localStorage.getItem(props.storageKey), 10);
        if (Number.isFinite(stored) && stored >= props.min && stored <= props.max) setWidth(stored);
    } catch {
        // Stockage bloqué : la poignée fonctionne quand même pour cette visite.
    }
});

const persist = () => {
    try {
        window.localStorage.setItem(props.storageKey, String(clamp(width.value)));
    } catch {
        // Une préférence visuelle ne doit jamais empêcher d'utiliser le portail.
    }
};

const reset = () => {
    setWidth(props.defaultWidth);
    persist();
};

let frame = null;
let pendingWidth = null;
let lastPressAt = 0;
const DOUBLE_PRESS_MS = 400;

const schedule = (value) => {
    pendingWidth = value;
    if (frame !== null) return;

    frame = requestAnimationFrame(() => {
        frame = null;
        if (pendingWidth !== null) setWidth(pendingWidth);
        pendingWidth = null;
    });
};

const widthFromPointer = (event) => (
    document.documentElement.dir === 'rtl'
        ? window.innerWidth - event.clientX
        : event.clientX
);

const finish = (event) => {
    if (!dragging.value) return;

    dragging.value = false;
    emit('resizing', false);
    event.currentTarget.releasePointerCapture?.(event.pointerId);
    persist();
};

const onPointerDown = (event) => {
    if (!props.enabled || (event.pointerType === 'mouse' && event.button !== 0)) return;

    if (event.timeStamp - lastPressAt < DOUBLE_PRESS_MS) {
        lastPressAt = 0;
        reset();
        event.preventDefault();
        return;
    }

    lastPressAt = event.timeStamp;
    dragging.value = true;
    emit('resizing', true);
    event.currentTarget.setPointerCapture(event.pointerId);
    event.preventDefault();
};

const onPointerMove = (event) => {
    if (!dragging.value) return;
    schedule(widthFromPointer(event));
};

const onKeydown = (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

    if (event.key === 'Home' || event.key === 'End') {
        setWidth(event.key === 'Home' ? props.min : props.max);
    } else {
        const direction = event.key === 'ArrowLeft' ? -1 : 1;
        const rtlDirection = document.documentElement.dir === 'rtl' ? -direction : direction;
        setWidth(width.value + rtlDirection * (event.shiftKey ? 24 : 8));
    }

    persist();
    event.preventDefault();
};

watch(() => props.enabled, (enabled) => {
    if (!enabled && dragging.value) {
        dragging.value = false;
        emit('resizing', false);
    }
});

onBeforeUnmount(() => {
    if (frame !== null) cancelAnimationFrame(frame);
    if (dragging.value) emit('resizing', false);
});

const label = computed(() => `Largeur du menu : ${width.value} pixels. Flèches gauche et droite pour ajuster.`);
</script>

<template>
    <div
        v-if="enabled"
        class="group absolute inset-y-0 end-0 z-20 hidden w-4 translate-x-1/2 cursor-col-resize touch-none items-center justify-center outline-none xl:flex"
        role="separator"
        aria-orientation="vertical"
        tabindex="0"
        :aria-label="label"
        :aria-valuenow="width"
        :aria-valuemin="min"
        :aria-valuemax="max"
        :title="`${width} px — glisser, ou double-cliquer pour réinitialiser`"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="finish"
        @pointercancel="finish"
        @keydown="onKeydown"
    >
        <span :class="['h-full w-px bg-border transition-colors group-hover:bg-primary group-focus-visible:bg-primary', dragging ? 'bg-primary' : '']" aria-hidden="true" />
        <span class="absolute top-1/2 grid h-9 w-3 -translate-y-1/2 place-items-center rounded-full border border-border bg-card shadow-sm transition-colors group-hover:border-primary/50 group-focus-visible:border-primary group-focus-visible:ring-2 group-focus-visible:ring-ring/40" aria-hidden="true">
            <span class="flex flex-col gap-0.5"><i class="size-0.5 rounded-full bg-muted-foreground" /><i class="size-0.5 rounded-full bg-muted-foreground" /><i class="size-0.5 rounded-full bg-muted-foreground" /></span>
        </span>
    </div>
</template>

