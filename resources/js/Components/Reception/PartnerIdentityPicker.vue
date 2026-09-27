<script setup>
import { computed } from 'vue';
import { ArrowRight, CircleAlert, FileUser, Handshake, LoaderCircle, Phone, Search, Stethoscope, UserRoundPlus } from 'lucide-vue-next';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import FormError from '@/Components/UI/FormError.vue';
import { useReceptionLookup } from '@/composables/useReceptionLookup';
import { cn } from '@/lib/cn';
import { formatPatientInitials } from '@/utilities/patient';

/**
 * ADR-211 — un partenaire médical (un médecin, un infirmier…) vient se faire
 * soigner : l'accueil le retrouve dans le module Partenaires. S'il a déjà un
 * dossier patient, il est repris tel quel ; sinon le nouveau dossier est
 * prérempli depuis sa fiche, à vérifier et compléter.
 *
 * Seuls les partenaires Médical sont proposés : un partenaire « Autre » (une
 * école, une entreprise) n'est pas une personne soignée, il se choisit à la
 * prise en charge du passage.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
});
const emit = defineEmits(['update:modelValue']);

const { query, results, loading, performed, error, search } = useReceptionLookup('/reception/partners/patient-lookup');

const blockedReason = (partner) => (partner.linked_patient_archived ? 'Son dossier patient est archivé : restaurez-le d’abord' : null);

const choose = (partner) => {
    if (blockedReason(partner)) return;
    emit('update:modelValue', partner);
};
const change = () => emit('update:modelValue', null);
const selected = computed(() => props.modelValue);
</script>

<template>
    <div>
        <div v-if="! selected" class="rounded-md border border-border bg-muted/25 p-4 sm:p-5">
            <div class="mb-3">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><Handshake class="h-4 w-4 text-primary" />Rechercher un partenaire médical</h3>
                <p class="mt-1 text-xs text-muted-foreground">Médecins, infirmiers, laborantins… inscrits dans le module Partenaires, par nom, prénom ou téléphone. Leur fiche remplit le dossier patient.</p>
            </div>
            <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="search">
                <IconInput v-model="query" size="lg" :icon="Search" placeholder="Ex. Rakoto, 034…" autocomplete="off" aria-label="Rechercher un partenaire médical" />
                <Button size="lg" type="submit" class="justify-center" :disabled="query.trim().length < 2 || loading">
                    <component :is="loading ? LoaderCircle : Search" :class="cn('h-4 w-4', loading && 'animate-spin')" />{{ loading ? 'Recherche…' : 'Rechercher' }}
                </Button>
            </form>
            <FormError v-if="error" class="mt-2">{{ error }}</FormError>

            <div v-if="performed && results.length" class="mt-4 overflow-hidden rounded-md border border-border bg-card">
                <div class="border-b border-border bg-muted/35 px-4 py-2.5 text-xs font-semibold text-muted-foreground">
                    {{ results.length }} partenaire{{ results.length > 1 ? 's' : '' }} trouvé{{ results.length > 1 ? 's' : '' }}
                </div>
                <button
                    v-for="partner in results"
                    :key="partner.uuid"
                    type="button"
                    :disabled="Boolean(blockedReason(partner))"
                    class="grid w-full gap-3 border-b border-border px-4 py-3.5 text-start transition last:border-0 enabled:hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-60 sm:grid-cols-[44px_minmax(0,1fr)_auto] sm:items-center"
                    @click="choose(partner)"
                >
                    <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(partner)" />
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span class="truncate text-sm font-bold text-foreground">{{ partner.name }}</span>
                            <Badge v-if="partner.profession_label" variant="outline"><Stethoscope class="h-3 w-3" />{{ partner.profession_label }}</Badge>
                            <Badge v-if="partner.linked_patient" variant="secondary"><FileUser class="h-3 w-3" />Dossier {{ partner.linked_patient.patient_number }}</Badge>
                        </span>
                        <span class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"><Phone class="h-3.5 w-3.5" />{{ partner.phone || 'Téléphone non renseigné' }}</span>
                    </span>
                    <span v-if="blockedReason(partner)" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 dark:text-amber-300"><CircleAlert class="h-4 w-4" />{{ blockedReason(partner) }}</span>
                    <span v-else class="inline-flex items-center text-xs font-bold text-primary">Choisir<ArrowRight class="h-4 w-4" /></span>
                </button>
            </div>

            <div v-else-if="performed && ! loading && ! error" class="mt-4 rounded-md border border-dashed border-border px-5 py-7 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground"><Search class="h-4 w-4" /></span>
                <p class="mt-3 text-sm font-semibold text-foreground">Aucun partenaire médical trouvé</p>
                <p class="mt-1 text-xs text-muted-foreground">Vérifiez la saisie. Un partenaire absent du module s’enregistre comme un nouveau patient.</p>
            </div>
        </div>

        <div v-else class="flex flex-col gap-3 rounded-md border border-primary/30 bg-primary/5 p-4 sm:flex-row sm:items-center">
            <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(selected)" />
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2">
                    <span class="truncate text-base font-bold text-foreground">{{ selected.name }}</span>
                    <Badge variant="secondary"><Handshake class="h-3 w-3" />Partenaire médical</Badge>
                    <Badge v-if="selected.profession_label" variant="outline">{{ selected.profession_label }}</Badge>
                </p>
                <p class="mt-1 flex items-start gap-1.5 text-xs leading-5 text-foreground">
                    <component :is="selected.linked_patient ? FileUser : UserRoundPlus" class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <span v-if="selected.linked_patient">Son dossier patient <strong class="font-mono">{{ selected.linked_patient.patient_number }}</strong> est repris tel quel.</span>
                    <span v-else>Le nouveau dossier est prérempli depuis sa fiche : vérifiez-le et complétez ce qui manque ci-dessous.</span>
                </p>
            </div>
            <Button size="sm" variant="white-outline" @click="change">Changer de partenaire</Button>
        </div>
    </div>
</template>
