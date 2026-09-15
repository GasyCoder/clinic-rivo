<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import HrFigures from '@/Components/Administration/HrFigures.vue';

/**
 * ADR-066 — on the site overview, HR shows only what waits for a decision.
 * Everything else lives in the HR space, one click away: the overview is the
 * site's front page, the HR space is where HR work is done.
 */
defineProps({ summary: { type: Object, required: true } });
</script>

<template>
    <section class="space-y-3" aria-labelledby="hr-home-title">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <h2 id="hr-home-title" class="flex items-center gap-2 font-heading text-base font-bold text-slate-800 dark:text-white">
                <Icon name="briefcase" class="text-primary-600" />Ressources humaines — à traiter
            </h2>
            <Link href="/administration" class="inline-flex items-center gap-1 text-sm font-semibold text-primary-600 hover:underline">Ouvrir l’espace RH <Icon name="arrow-right" /></Link>
        </div>
        <HrFigures :summary="summary" linkable show="todo" />
    </section>
</template>
