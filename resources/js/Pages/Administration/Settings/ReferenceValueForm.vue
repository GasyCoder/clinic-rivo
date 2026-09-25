<script setup>
import { computed, ref, watch } from 'vue';
import { Check, GraduationCap, LoaderCircle, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';

/*
 * Une valeur d'un référentiel RH, à l'ajout comme à la correction : libellé,
 * code, ordre, et ce que son type porte en plus.
 *
 *   Type de contrat   « contrat de stage » (ADR-194) ;
 *   Type de congé     les règles de décompte (ADR-069).
 */
const props = defineProps({
    form: { type: Object, required: true },
    type: { type: String, required: true },
    mode: { type: String, default: 'create' },
    leaveDayCountMethods: { type: [Array, Object], default: () => [] },
});
const emit = defineEmits(['submit', 'cancel']);

const codeTouched = ref(props.mode === 'edit');
const generateCode = (label) => String(label ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 80);
watch(() => props.form.label, (label) => {
    if (!codeTouched.value) props.form.code = generateCode(label);
});

const methodOptions = computed(() => [...props.leaveDayCountMethods]);
const error = (key) => props.form.errors?.[key];
</script>

<template>
    <form class="space-y-4" @submit.prevent="emit('submit')">
        <div class="grid gap-3 md:grid-cols-[2fr_1fr_110px]">
            <FormField label="Libellé affiché" required :error="error('label')">
                <Input v-model="form.label" required maxlength="255" :placeholder="type === 'INTERNSHIP_FIELD' ? 'Ex. Infirmier' : 'Ex. Ressources humaines'" />
            </FormField>
            <FormField label="Code" required :hint="mode === 'create' && !codeTouched ? '(généré)' : ''" :error="error('code')">
                <Input v-model="form.code" required maxlength="80" class="font-mono text-xs uppercase" placeholder="CODE_AUTOMATIQUE" @input="codeTouched = true" />
            </FormField>
            <FormField label="Ordre" :error="error('position')">
                <Input v-model="form.position" type="number" min="0" max="65535" />
            </FormField>
        </div>

        <!-- ADR-194 — un type de contrat peut être un contrat de stage. -->
        <label v-if="type === 'CONTRACT_TYPE'" class="flex cursor-pointer items-start gap-3 rounded-xl border border-violet-200 bg-violet-50/60 p-4 dark:border-violet-900 dark:bg-violet-950/20">
            <Checkbox v-model="form.metadata.internship" class="mt-0.5" />
            <span>
                <span class="flex items-center gap-1.5 text-sm font-semibold text-foreground"><GraduationCap class="h-4 w-4 text-violet-600" />Contrat de stage</span>
                <span class="mt-0.5 block text-xs leading-5 text-muted-foreground">Le contrat demande alors la filière, l’école et l’encadrant, et le stagiaire apparaît dans « Stages ».</span>
            </span>
        </label>

        <!-- ADR-069 — les règles d'un type de congé. -->
        <fieldset v-if="type === 'LEAVE_TYPE'" class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900 dark:bg-amber-950/20">
            <legend class="px-1 text-xs font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Règles appliquées aux nouvelles demandes</legend>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="flex cursor-pointer items-start gap-3 rounded-lg bg-card p-3 ring-1 ring-border">
                    <Checkbox v-model="form.metadata.consumes_annual_balance" class="mt-0.5" />
                    <span><span class="block text-xs font-semibold text-foreground">Consomme le solde annuel</span><span class="mt-1 block text-[11px] leading-4 text-muted-foreground">Les demandes approuvées réduisent le quota.</span></span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-lg bg-card p-3 ring-1 ring-border">
                    <Checkbox v-model="form.metadata.requires_approval" class="mt-0.5" />
                    <span><span class="block text-xs font-semibold text-foreground">Validation requise</span><span class="mt-1 block text-[11px] leading-4 text-muted-foreground">Sinon la demande est acceptée à sa création.</span></span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-lg bg-card p-3 ring-1 ring-border">
                    <Checkbox v-model="form.metadata.requires_attachment" class="mt-0.5" />
                    <span><span class="block text-xs font-semibold text-foreground">Justificatif obligatoire</span><span class="mt-1 block text-[11px] leading-4 text-muted-foreground">PDF, image ou document Word.</span></span>
                </label>
            </div>
            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <FormField label="Quota annuel (jours)" :error="error('metadata.annual_quota_days')">
                    <Input v-model="form.metadata.annual_quota_days" type="number" min="0.01" max="366" step="0.01" :required="form.metadata.consumes_annual_balance" :disabled="!form.metadata.consumes_annual_balance" />
                </FormField>
                <FormField label="Maximum par demande" :error="error('metadata.max_days_per_request')">
                    <Input v-model="form.metadata.max_days_per_request" type="number" min="0.01" max="366" step="0.01" placeholder="Aucune limite" />
                </FormField>
                <FormField as="div" label="Mode de décompte" :error="error('metadata.day_count_method')">
                    <Select v-model="form.metadata.day_count_method" :options="methodOptions" class="w-full min-w-0" aria-label="Mode de décompte" />
                </FormField>
            </div>
        </fieldset>

        <div class="flex justify-end gap-2">
            <Button v-if="mode === 'edit'" type="button" variant="outline" @click="emit('cancel')"><X class="h-4 w-4" />Annuler</Button>
            <Button type="submit" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" /><Check v-else class="h-4 w-4" />
                {{ mode === 'edit' ? 'Enregistrer' : 'Ajouter' }}
            </Button>
        </div>
    </form>
</template>
