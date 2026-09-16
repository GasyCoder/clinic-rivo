<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowRight, BriefcaseBusiness } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
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
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="hr-home-title" class="flex items-center gap-2 font-heading text-base font-bold text-foreground">
                    <BriefcaseBusiness class="h-4 w-4 text-primary" />Ressources humaines — à traiter
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Les décisions qui demandent votre attention.</p>
            </div>
            <Button :as="Link" href="/administration" variant="outline" size="sm">Ouvrir l’espace RH <ArrowRight class="h-4 w-4" /></Button>
        </div>
        <HrFigures :summary="summary" linkable show="todo" />
    </section>
</template>
