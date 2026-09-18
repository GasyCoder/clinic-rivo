<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { BookMarked, Check, History, Sparkles, X } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';

/**
 * Diagnostics proposés à partir des données de la clinique (ADR-111).
 *
 * Deux sources, toutes deux locales — aucune donnée ne quitte le site :
 *
 *   - PROTOCOL         un protocole écrit par les médecins, dont les signes
 *                      évocateurs se retrouvent dans le dossier ;
 *   - CLINIC_PRACTICE  les consultations passées de la clinique, dont le
 *                      vocabulaire ressemble à celui de ce dossier.
 *
 * Une proposition n'est jamais un diagnostic : elle en devient un quand le
 * médecin la **retient**, et il reste alors corrigeable et retirable comme
 * tout autre (ADR-035, ADR-106). Chaque carte montre sa preuve, pour que le
 * médecin la vérifie au lieu de la croire.
 */
const props = defineProps({
    suggestions: { type: Array, default: () => [] },
    orientationUuid: { type: String, required: true },
    returnStep: { type: String, required: true },
    protocolCount: { type: Number, default: 0 },
    practiceCases: { type: Number, default: 0 },
    practiceMinCases: { type: Number, default: 3 },
    canManageProtocols: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

// « Ignorer » est un choix d'affichage, pas une donnée : rien n'est
// enregistré, la proposition revient au prochain chargement si les signes
// sont toujours là.
const dismissed = ref(new Set());
const visible = computed(() => props.suggestions.filter((item) => !dismissed.value.has(item.diagnostic_catalog_uuid)));

const fromProtocol = (suggestion) => suggestion.source === 'PROTOCOL';

const evidence = (suggestion) => (fromProtocol(suggestion)
    ? `Signes retrouvés : ${suggestion.matched_signs.join(', ')}`
    : `Mots retrouvés dans ${suggestion.cases} consultations passées : ${suggestion.matched_terms.map((item) => `${item.term} (${item.count})`).join(', ')}`);

/**
 * Rien à proposer, et la raison : pas de protocole, et une pratique encore
 * trop courte pour apprendre. Le silence se lirait « aucun diagnostic
 * évoqué ».
 */
const nothingToLearnFrom = computed(() => props.protocolCount === 0 && props.practiceCases < props.practiceMinCases);

const retaining = ref(null);
const retain = (suggestion) => {
    retaining.value = suggestion.diagnostic_catalog_uuid;

    router.post(`/medicine/orientations/${props.orientationUuid}/diagnoses`, {
        type: 'FINAL',
        diagnostic_catalog_uuid: suggestion.diagnostic_catalog_uuid,
        suggestion_source: suggestion.source,
        suggestion_protocol_uuid: suggestion.protocol_uuid,
        return_step: props.returnStep,
    }, {
        preserveScroll: true,
        onFinish: () => { retaining.value = null; },
    });
};

const dismiss = (suggestion) => {
    dismissed.value = new Set([...dismissed.value, suggestion.diagnostic_catalog_uuid]);
};
</script>

<template>
    <div v-if="visible.length" class="rounded-xl border border-primary/25 bg-primary/5 p-3">
        <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.12em] text-primary">
            <Sparkles class="h-3.5 w-3.5" aria-hidden="true" />Diagnostics proposés
        </p>
        <p class="mt-0.5 text-[11px] leading-4 text-muted-foreground">
            D’après ce dossier, les protocoles et la pratique de la clinique — calculé sur place, sans service externe. Rien n’est enregistré tant que vous ne retenez pas.
        </p>

        <ul class="mt-2.5 space-y-2">
            <li
                v-for="suggestion in visible"
                :key="suggestion.diagnostic_catalog_uuid"
                class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-border bg-card px-3 py-2.5"
            >
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2">
                        <strong class="text-sm font-semibold text-foreground">{{ suggestion.name }}</strong>
                        <span v-if="suggestion.code" class="text-xs text-muted-foreground">{{ suggestion.code }}</span>
                        <Badge v-if="fromProtocol(suggestion)" tone="info" class="px-2 py-0.5 text-[10px]">
                            <BookMarked class="h-3 w-3" aria-hidden="true" />Protocole · {{ suggestion.matched_signs.length }}/{{ suggestion.total_signs }} signes
                        </Badge>
                        <Badge v-else tone="neutral" class="px-2 py-0.5 text-[10px]">
                            <History class="h-3 w-3" aria-hidden="true" />Pratique de la clinique
                        </Badge>
                    </p>
                    <p class="mt-1 text-[11px] leading-4 text-muted-foreground">{{ evidence(suggestion) }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-1.5">
                    <Button type="button" size="sm" :disabled="disabled || retaining !== null" @click="retain(suggestion)">
                        <Check class="h-4 w-4" aria-hidden="true" />{{ retaining === suggestion.diagnostic_catalog_uuid ? 'Enregistrement…' : 'Retenir' }}
                    </Button>
                    <Button type="button" size="sm" icon variant="ghost" :title="`Ignorer ${suggestion.name}`" :aria-label="`Ignorer ${suggestion.name}`" @click="dismiss(suggestion)">
                        <X class="h-4 w-4" aria-hidden="true" />
                    </Button>
                </div>
            </li>
        </ul>
    </div>

    <p v-else-if="nothingToLearnFrom" class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-dashed border-border px-3 py-2 text-[11px] leading-4 text-muted-foreground">
        <BookMarked class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        Aucune proposition pour l’instant : aucun protocole rédigé, et la clinique compte {{ practiceCases }} consultation{{ practiceCases > 1 ? 's' : '' }} conclue{{ practiceCases > 1 ? 's' : '' }} — le système apprend de sa pratique à mesure qu’elle s’enrichit.
        <Link v-if="canManageProtocols" href="/medicine/protocoles/nouveau" class="font-semibold text-primary hover:underline">Rédiger un protocole</Link>
    </p>
</template>
