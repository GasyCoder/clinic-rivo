<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowRight, BriefcaseBusiness } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import HrFigures from '@/Components/Administration/HrFigures.vue';

/**
 * ADR-066 — on the site overview, HR shows only what waits for a decision.
 * Everything else lives in the HR space, one click away: the overview is the
 * site's front page, the HR space is where HR work is done.
 */
defineProps({ summary: { type: Object, required: true } });
</script>

<template>
    <Card class="overflow-hidden" aria-labelledby="hr-home-title">
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                        <BriefcaseBusiness class="h-4 w-4" />
                    </span>
                    <div>
                        <h2 id="hr-home-title" class="font-heading text-base font-bold text-foreground">Ressources humaines</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">Décisions et suivis qui demandent votre attention.</p>
                    </div>
                </div>
                <Button :as="Link" href="/administration" variant="outline" size="sm" class="shadow-none">Ouvrir l’espace RH <ArrowRight class="h-4 w-4" /></Button>
            </div>
            <HrFigures :summary="summary" linkable show="todo" embedded />
        </section>
    </Card>
</template>
