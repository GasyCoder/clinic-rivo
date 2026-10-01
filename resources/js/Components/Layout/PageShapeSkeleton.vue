<script setup>
import { computed } from 'vue';

/**
 * Le squelette d'un écran déjà vu, à sa forme exacte (ADR-185) : la photo que
 * Boneyard en a prise (`usePageShapes`), redessinée avec les tokens shadcn.
 *
 * Boneyard ne peint que les blocs de contenu ; ici les surfaces sont dessinées
 * aussi — une carte arrondie redevient une carte bordée, une ligne de tableau
 * ou un bandeau une surface plate — pour que le squelette ait la disposition
 * de la page : ses cartes, ses compteurs, son tableau.
 */
const props = defineProps({
    shape: { type: Object, required: true },
});

const radius = (value) => {
    if (typeof value === 'number') return `${value}px`;

    // Seules des valeurs de rayon sûres passent dans le style.
    return /^[0-9.%pxrem\s/]+$/.test(String(value)) ? String(value) : '0px';
};

const isRounded = (value) => (typeof value === 'number' ? value >= 4 : String(value) !== '0px' && String(value) !== '0');

const place = ([x, y, w, h, r]) => ({
    left: `${x}%`,
    top: `${y}px`,
    width: `${w}%`,
    height: `${h}px`,
    borderRadius: radius(r),
});

const surfaces = computed(() => props.shape.bones
    .filter((bone) => bone[5])
    .map((bone, index) => ({ key: `s${index}`, card: isRounded(bone[4]), style: place(bone) })));

const blocks = computed(() => props.shape.bones
    .filter((bone) => ! bone[5])
    .map((bone, index) => ({ key: `b${index}`, style: place(bone) })));
</script>

<template>
    <div class="page-shape relative w-full overflow-hidden" :style="{ height: `${shape.height}px` }" aria-hidden="true" data-page-shape>
        <div
            v-for="surface in surfaces"
            :key="surface.key"
            :class="['absolute', surface.card ? 'border border-border bg-card shadow-sm' : 'bg-muted/60']"
            :style="surface.style"
        />
        <div v-for="block in blocks" :key="block.key" class="skeleton-bone absolute" :style="block.style" />
    </div>
</template>
