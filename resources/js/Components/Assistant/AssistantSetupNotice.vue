<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Settings2, ShieldCheck } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import AssistantRobot from '@/Components/Assistant/AssistantRobot.vue';

/**
 * ADR-222 — l'assistant n'est pas encore prêt : ce qui manque, et, pour le Super
 * Administrateur qui peut le régler, le chemin des réglages. Les autres comptes ne
 * voient jamais la bulle tant qu'il n'est pas prêt.
 */
defineProps({
    /** `{ state: 'unconfigured'|'disabled', url }` (prop partagée `assistant.setup`), ou `null`. */
    setup: { type: Object, default: null },
});
</script>

<template>
    <div class="space-y-4 p-5" data-assistant-unavailable>
        <div class="flex items-start gap-3">
            <span class="h-12 w-12 shrink-0" aria-hidden="true"><AssistantRobot /></span>
            <div class="min-w-0 space-y-1.5">
                <h3 class="text-base font-semibold text-foreground">L’assistant n’est pas encore prêt</h3>
                <p v-if="setup" class="text-sm text-muted-foreground">
                    {{ setup.state === 'disabled'
                        ? 'Il est configuré mais désactivé. Cochez « Assistant activé » dans ses réglages puis enregistrez : il apparaîtra pour les comptes qui ont le droit de s’en servir.'
                        : 'Choisissez un fournisseur et un modèle, collez la clé d’API, testez la connexion, cochez « Assistant activé » et enregistrez.' }}
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    Il n’est pas encore activé pour cet établissement. Prévenez l’administrateur de RIVO.
                </p>
            </div>
        </div>

        <ol v-if="setup && setup.state !== 'disabled'" class="list-decimal space-y-1.5 ps-5 text-sm text-muted-foreground">
            <li>Paramètres › <span class="font-medium text-foreground">Assistant IA</span>, puis le site dans l’en-tête (ou le portail).</li>
            <li>Fournisseur, modèle et clé d’API du fournisseur.</li>
            <li><span class="font-medium text-foreground">Tester la connexion</span>, cocher « Assistant activé », <span class="font-medium text-foreground">Enregistrer</span>.</li>
            <li>Recommencer pour chaque site : chacun a ses réglages et sa clé.</li>
        </ol>

        <div v-if="setup" class="space-y-3">
            <Button :as="Link" :href="setup.url" class="w-full sm:w-auto">
                <Settings2 class="h-4 w-4" aria-hidden="true" />Configurer l’assistant<ArrowRight class="h-4 w-4" aria-hidden="true" />
            </Button>
            <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
                <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                Les autres comptes ne voient la bulle qu’une fois l’assistant activé et configuré sur leur site.
            </p>
        </div>
    </div>
</template>
