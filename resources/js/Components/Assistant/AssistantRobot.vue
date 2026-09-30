<script setup>
import { cn } from '@/lib/cn';

/**
 * ADR-222 — le petit robot qui représente l'assistant : la bulle, l'en-tête de sa
 * fenêtre et l'avatar de ses réponses. Dessiné en SVG aux couleurs du thème
 * (`--primary`), il suit le mode sombre et les couleurs réglées par site (ADR-191).
 * Ses yeux clignent de temps en temps ; « Réduire les animations » les arrête.
 */
defineProps({
    /** `talking` : l'assistant répond — ses yeux et son antenne s'animent. */
    talking: { type: Boolean, default: false },
    class: { type: String, default: '' },
});
</script>

<template>
    <svg
        viewBox="0 0 64 64"
        :class="cn('rivo-robot h-full w-full', talking && 'is-talking', $props.class)"
        aria-hidden="true"
        focusable="false"
    >
        <!-- Antenne -->
        <line x1="32" y1="8" x2="32" y2="15" class="stroke-primary" stroke-width="3" stroke-linecap="round" />
        <circle cx="32" cy="7" r="4" class="rivo-robot-light fill-amber-400 stroke-card" stroke-width="1.5" />
        <!-- Oreilles -->
        <rect x="5" y="27" width="7" height="14" rx="3.5" class="fill-primary/70" />
        <rect x="52" y="27" width="7" height="14" rx="3.5" class="fill-primary/70" />
        <!-- Tête -->
        <rect x="10" y="14" width="44" height="40" rx="14" class="fill-primary" />
        <rect x="10" y="14" width="44" height="18" rx="14" class="fill-white/10" />
        <!-- Écran du visage -->
        <rect x="16" y="22" width="32" height="24" rx="9" class="fill-card" />
        <!-- Yeux -->
        <g class="rivo-robot-eyes">
            <ellipse cx="25.5" cy="32" rx="3.4" ry="3.8" class="fill-primary" />
            <ellipse cx="38.5" cy="32" rx="3.4" ry="3.8" class="fill-primary" />
            <circle cx="26.6" cy="30.7" r="1.1" class="fill-card" />
            <circle cx="39.6" cy="30.7" r="1.1" class="fill-card" />
        </g>
        <!-- Sourire -->
        <path d="M27 39.5 Q32 43.5 37 39.5" class="stroke-primary" fill="none" stroke-width="2.4" stroke-linecap="round" />
        <!-- Joues -->
        <circle cx="21" cy="39" r="1.8" class="fill-rose-300/70" />
        <circle cx="43" cy="39" r="1.8" class="fill-rose-300/70" />
    </svg>
</template>

<style scoped>
.rivo-robot-eyes {
    transform-box: fill-box;
    transform-origin: center;
    animation: rivo-robot-blink 5.5s infinite;
}

.is-talking .rivo-robot-eyes {
    animation-duration: 1.6s;
}

.is-talking .rivo-robot-light {
    animation: rivo-robot-light 0.9s ease-in-out infinite;
}

@keyframes rivo-robot-blink {
    0%, 92%, 100% { transform: scaleY(1); }
    95% { transform: scaleY(0.1); }
}

@keyframes rivo-robot-light {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.35; }
}
</style>
