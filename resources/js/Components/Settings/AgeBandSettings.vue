<script setup>
import { computed } from 'vue';
import { Baby, UserRound, Users } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { describeAgeBands } from '@/utilities/patientAge';

/** Les tranches d'âge qui guident le formulaire d'un nouveau patient (ADR-184). */
const props = defineProps({
    form: { type: Object, required: true },
    limits: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});

const agesValid = computed(() => Number(props.form.child_max_age) > Number(props.form.baby_max_age));
const bandsPreview = computed(() => ({ baby_max_age: Number(props.form.baby_max_age), child_max_age: Number(props.form.child_max_age) }));
/** La frise va jusqu'à cinq ans après la fin de l'enfance. */
const ageScale = computed(() => Math.max(20, Number(props.form.child_max_age) + 5));
const segment = (from, to) => `${((to - from) / ageScale.value) * 100}%`;
</script>

<template>
    <SettingsSection id="ages" title="Âges des patients" description="Les tranches qui guident le formulaire d’un nouveau patient. Seuls les nouveaux patients les suivent : aucun dossier existant n’est modifié.">
        <div class="grid gap-6 sm:grid-cols-3">
            <SettingsField label="Bébé jusqu’à" for="reglage-baby-age" description="Date de naissance exacte exigée." :error="form.errors.baby_max_age">
                <div class="relative">
                    <IconInput id="reglage-baby-age" v-model.number="form.baby_max_age" :icon="Baby" type="number" min="0" :max="limits.baby_max_age ?? 5" class="pe-14" :disabled="readonly" />
                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">an(s)</span>
                </div>
            </SettingsField>

            <SettingsField label="Enfant jusqu’à" for="reglage-child-age" description="Sans téléphone, email, profession ni pièce d’identité." :error="form.errors.child_max_age || (! agesValid ? 'Doit dépasser l’âge d’un bébé.' : '')">
                <div class="relative">
                    <IconInput id="reglage-child-age" v-model.number="form.child_max_age" :icon="Users" type="number" :min="Number(form.baby_max_age) + 1" :max="limits.child_max_age ?? 20" class="pe-14" :disabled="readonly" />
                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted-foreground">ans</span>
                </div>
            </SettingsField>

            <SettingsField label="Adulte" for="reglage-adult-age" description="Civilité M. ou Mme, formulaire complet.">
                <IconInput id="reglage-adult-age" :icon="UserRound" :model-value="`À partir de ${Number(form.child_max_age) + 1} ans`" disabled />
            </SettingsField>
        </div>

        <SettingsField label="Aperçu" description="Bébé et enfant reçoivent la civilité « Enfant » ; une civilité contraire à l’âge est refusée.">
            <div v-if="agesValid" class="space-y-2" aria-label="Tranches d’âge">
                <div class="flex h-9 overflow-hidden rounded-md border border-border text-xs font-medium">
                    <span class="flex items-center justify-center gap-1 bg-primary/15 px-1 text-foreground" :style="{ width: segment(0, Number(form.baby_max_age) + 1) }"><Baby class="h-3.5 w-3.5 shrink-0" /><span class="truncate">Bébé</span></span>
                    <span class="flex items-center justify-center gap-1 border-s border-border bg-primary/5 px-1 text-foreground" :style="{ width: segment(Number(form.baby_max_age) + 1, Number(form.child_max_age) + 1) }"><Users class="h-3.5 w-3.5 shrink-0" /><span class="truncate">Enfant</span></span>
                    <span class="flex flex-1 items-center justify-center gap-1 border-s border-border bg-muted px-1 text-muted-foreground"><UserRound class="h-3.5 w-3.5 shrink-0" /><span class="truncate">Adulte</span></span>
                </div>
                <p class="text-sm text-muted-foreground">{{ describeAgeBands(bandsPreview) }}</p>
            </div>
            <p v-else class="text-sm font-medium text-destructive">L’âge de l’enfance doit dépasser celui d’un bébé.</p>
        </SettingsField>
    </SettingsSection>
</template>
