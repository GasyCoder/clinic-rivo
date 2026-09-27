<script setup>
import { computed } from 'vue';
import {
    ArrowRight,
    BadgeCheck,
    CircleAlert,
    FileUser,
    GraduationCap,
    IdCard,
    LoaderCircle,
    Lock,
    Search,
    UserRoundPlus,
} from 'lucide-vue-next';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import FormError from '@/Components/UI/FormError.vue';
import { useReceptionLookup } from '@/composables/useReceptionLookup';
import { cn } from '@/lib/cn';
import { formatPatientAge, formatPatientBirthDate, formatPatientInitials, formatPatientName } from '@/utilities/patient';

/**
 * ADR-211 — un membre du personnel ou un stagiaire vient se faire soigner :
 * l'accueil le retrouve dans le dossier RH au lieu de ressaisir son identité.
 * Son dossier patient se crée (ou se met à jour) depuis la fiche RH, qui reste
 * la source : ce qui est faux se corrige dans les RH.
 *
 * Un stagiaire est retrouvé de la même façon, mais son passage reste au tarif
 * Standard (ADR-194). La prise en charge se choisit plus loin, au passage.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
});
const emit = defineEmits(['update:modelValue']);

const { query, results, loading, performed, error, search } = useReceptionLookup('/reception/employees/patient-lookup');

/** Ce qui empêche d'ouvrir un passage depuis cette fiche, s'il y a quelque chose. */
const blockedReason = (employee) => {
    if (employee.linked_patient) return null;
    if (! employee.eligible) return 'Dossier RH inactif';
    if (! employee.can_open_patient_record) return 'Date de naissance absente du dossier RH';

    return null;
};

const choose = (employee) => {
    if (blockedReason(employee)) return;
    emit('update:modelValue', employee);
};
const change = () => emit('update:modelValue', null);

const selected = computed(() => props.modelValue);
const roleLine = (employee) => [employee.job_title || employee.profession, employee.department].filter(Boolean).join(' · ');
const sexLabel = (sex) => ({ F: 'Féminin', M: 'Masculin' }[sex] ?? 'Non renseigné');
</script>

<template>
    <div>
        <div v-if="! selected" class="rounded-md border border-border bg-muted/25 p-4 sm:p-5">
            <div class="mb-3">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><IdCard class="h-4 w-4 text-primary" />Rechercher dans le dossier du personnel</h3>
                <p class="mt-1 text-xs text-muted-foreground">Employés et stagiaires, par matricule, nom, prénom, téléphone ou pièce d’identité. Leur identité est reprise du dossier RH : rien à ressaisir.</p>
            </div>
            <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="search">
                <IconInput v-model="query" size="lg" :icon="Search" placeholder="Ex. EMP-0012, Rakoto, 034…" autocomplete="off" aria-label="Rechercher un membre du personnel" />
                <Button size="lg" type="submit" class="justify-center" :disabled="query.trim().length < 2 || loading">
                    <component :is="loading ? LoaderCircle : Search" :class="cn('h-4 w-4', loading && 'animate-spin')" />{{ loading ? 'Recherche…' : 'Rechercher' }}
                </Button>
            </form>
            <FormError v-if="error" class="mt-2">{{ error }}</FormError>

            <div v-if="performed && results.length" class="mt-4 overflow-hidden rounded-md border border-border bg-card">
                <div class="border-b border-border bg-muted/35 px-4 py-2.5 text-xs font-semibold text-muted-foreground">
                    {{ results.length }} personne{{ results.length > 1 ? 's' : '' }} trouvée{{ results.length > 1 ? 's' : '' }}
                </div>
                <button
                    v-for="employee in results"
                    :key="employee.uuid"
                    type="button"
                    :disabled="Boolean(blockedReason(employee))"
                    class="grid w-full gap-3 border-b border-border px-4 py-3.5 text-start transition last:border-0 enabled:hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-60 sm:grid-cols-[44px_minmax(0,1fr)_auto] sm:items-center"
                    @click="choose(employee)"
                >
                    <Avatar rounded size="sm" variant="slate-pale" :text="formatPatientInitials(employee)" />
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-1.5">
                            <span class="truncate text-sm font-bold text-foreground">{{ formatPatientName(employee) }}</span>
                            <Badge v-if="employee.is_intern" variant="warning"><GraduationCap class="h-3 w-3" />Stagiaire</Badge>
                            <Badge v-if="employee.linked_patient" variant="secondary"><FileUser class="h-3 w-3" />Dossier {{ employee.linked_patient.patient_number }}</Badge>
                        </span>
                        <span class="mt-0.5 block text-xs text-muted-foreground">
                            <span class="font-mono font-semibold">{{ employee.employee_number }}</span>
                            <template v-if="roleLine(employee)"> · {{ roleLine(employee) }}</template>
                        </span>
                    </span>
                    <span v-if="blockedReason(employee)" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 dark:text-amber-300"><CircleAlert class="h-4 w-4" />{{ blockedReason(employee) }}</span>
                    <span v-else class="inline-flex items-center text-xs font-bold text-primary">Choisir<ArrowRight class="h-4 w-4" /></span>
                </button>
            </div>

            <div v-else-if="performed && ! loading && ! error" class="mt-4 rounded-md border border-dashed border-border px-5 py-7 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground"><Search class="h-4 w-4" /></span>
                <p class="mt-3 text-sm font-semibold text-foreground">Personne trouvée dans le dossier du personnel</p>
                <p class="mt-1 text-xs text-muted-foreground">Vérifiez la saisie. Une personne absente du dossier RH s’enregistre comme un nouveau patient.</p>
            </div>
        </div>

        <!-- La personne choisie : son identité vient du dossier RH. -->
        <div v-else class="overflow-hidden rounded-md border border-primary/30 bg-primary/5">
            <div class="flex flex-col gap-3 border-b border-primary/20 px-4 py-3.5 sm:flex-row sm:items-center">
                <Avatar rounded size="rg" variant="primary-pale" :text="formatPatientInitials(selected)" />
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="truncate text-base font-bold text-foreground">{{ formatPatientName(selected) }}</span>
                        <Badge v-if="selected.is_intern" variant="warning"><GraduationCap class="h-3 w-3" />Stagiaire</Badge>
                        <Badge v-else variant="secondary"><BadgeCheck class="h-3 w-3" />Personnel</Badge>
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        <span class="font-mono font-semibold">{{ selected.employee_number }}</span>
                        <template v-if="roleLine(selected)"> · {{ roleLine(selected) }}</template>
                    </p>
                </div>
                <Button size="sm" variant="white-outline" @click="change">Changer de personne</Button>
            </div>

            <dl class="grid gap-x-6 gap-y-3 px-4 py-3.5 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Né(e) le</dt>
                    <dd class="mt-0.5 font-semibold text-foreground">{{ formatPatientBirthDate(selected) || 'Non renseignée' }}<span v-if="formatPatientAge(selected)" class="font-normal text-muted-foreground"> · {{ formatPatientAge(selected) }}</span></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Sexe</dt>
                    <dd class="mt-0.5 font-semibold text-foreground">{{ sexLabel(selected.sex) }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Téléphone</dt>
                    <dd class="mt-0.5 font-semibold text-foreground">{{ selected.phone || 'Non renseigné' }}</dd>
                </div>
            </dl>

            <ul class="space-y-2 border-t border-primary/20 px-4 py-3.5 text-xs leading-5">
                <li class="flex items-start gap-2 text-foreground">
                    <component :is="selected.linked_patient ? FileUser : UserRoundPlus" class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <span v-if="selected.linked_patient">Son dossier patient <strong class="font-mono">{{ selected.linked_patient.patient_number }}</strong> est repris et mis à jour depuis le dossier RH.</span>
                    <span v-else>Un dossier patient est ouvert depuis le dossier RH : rien à ressaisir.</span>
                </li>
                <li class="flex items-start gap-2 text-muted-foreground">
                    <Lock class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Une information fausse se corrige dans le dossier RH, pas ici.</span>
                </li>
                <li v-if="selected.is_intern" class="flex items-start gap-2 text-amber-800 dark:text-amber-200">
                    <GraduationCap class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Stagiaire : son passage est au tarif Standard. La prise en charge Personnel ne s’applique pas aux stagiaires.</span>
                </li>
            </ul>
        </div>
    </div>
</template>
