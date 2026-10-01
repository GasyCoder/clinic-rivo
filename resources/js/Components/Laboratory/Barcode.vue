<script setup>
import { computed } from 'vue';
import { code128Bars, code128Encodable } from '@/utilities/code128';

/**
 * ADR-214 — un code-barres Code 128 en SVG, avec sa zone de silence (dix
 * modules de chaque côté) et, en dessous, le code en clair pour une saisie à
 * la main si le lecteur manque.
 */
const props = defineProps({
    value: { type: String, required: true },
    height: { type: Number, default: 40 },
    showText: { type: Boolean, default: true },
});

const QUIET = 10;
const encoded = computed(() => (code128Encodable(props.value) ? code128Bars(props.value) : null));
const viewWidth = computed(() => (encoded.value ? encoded.value.width + QUIET * 2 : 0));
</script>

<template>
    <figure class="m-0 inline-flex w-full flex-col items-center">
        <svg
            v-if="encoded"
            :viewBox="`0 0 ${viewWidth} ${height}`"
            preserveAspectRatio="none"
            class="block w-full"
            :style="{ height: `${height / 4}mm` }"
            role="img"
            :aria-label="`Code-barres ${value}`"
            shape-rendering="crispEdges"
        >
            <rect :width="viewWidth" :height="height" fill="#fff" />
            <rect v-for="bar in encoded.bars" :key="bar.x" :x="bar.x + QUIET" y="0" :width="bar.width" :height="height" fill="#000" />
        </svg>
        <figcaption v-if="showText" class="mt-0.5 font-mono text-[9pt] leading-none tracking-wider text-black">{{ value }}</figcaption>
    </figure>
</template>
