<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Baby, ClipboardList, FileText, UserPlus, UserRound } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-145, ADR-146, ADR-177 — les nouveau-nés d'une mère, à un seul endroit.
 *
 * Le bébé vit d'abord **dans le dossier de sa mère** : son dossier médical s'ouvre dès que la Maternité
 * l'a consigné, sans attendre qu'il soit patient. Il devient patient **depuis la Maternité** (ADR-177) :
 * la Réception n'a plus de mode « Nouveau-né » — un bébé né ailleurs y est un nouveau patient ordinaire.
 *
 * Le geste de création n'apparaît que si le serveur le propose (`create_patient_url`) : droit de le faire,
 * bébé consigné, pas encore patient. Le serveur rejuge tout — naissance consignée, sexe — ; la fenêtre ne
 * demande que ce que la fiche ne dit pas.
 *
 * Les données viennent de `MaternitySheetSection::forPassage()` : rien n'est recalculé ici.
 */
const props = defineProps({
    /** La projection serveur ; `null` : rien à afficher (aucun dossier Maternité, ou pas le droit de le lire). */
    babies: { type: Object, default: null },
    /** Le dossier Maternité lui-même n'a pas besoin d'un lien vers lui-même. */
    showMaternityLink: { type: Boolean, default: true },
    /**
     * Le serveur relit la fiche **enregistrée**, jamais l'écran : tant que le dossier Maternité a des
     * modifications non enregistrées, la création attend, et l'écran le dit.
     */
    creationBlockedReason: { type: String, default: '' },
});

const list = computed(() => props.babies?.newborns ?? []);
const patients = computed(() => list.value.filter((baby) => baby.patient_number).length);
const canCreateAny = computed(() => list.value.some((baby) => baby.create_patient_url));

const sexTone = (baby) => ({
    Féminin: 'bg-pink-50 text-pink-600 dark:bg-pink-950/40 dark:text-pink-300',
    Masculin: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
}[baby.sex] ?? 'bg-muted text-muted-foreground');

const summary = (baby) => [baby.sex, baby.birth_weight_g ? `${baby.birth_weight_g} g` : null].filter(Boolean).join(' · ');

const SEX_OPTIONS = [
    { value: 'F', label: 'Féminin' },
    { value: 'M', label: 'Masculin' },
];

const target = ref(null);
const saving = ref(false);
const errors = ref({});
const identity = reactive({ last_name: '', first_name: '', sex: '' });

const openCreation = (baby) => {
    target.value = baby;
    errors.value = {};
    identity.last_name = baby.last_name ?? '';
    identity.first_name = baby.first_name ?? '';
    identity.sex = baby.sex_code ?? '';
};

const closeCreation = () => {
    if (saving.value) return;

    target.value = null;
};

const submitCreation = () => {
    if (!target.value?.create_patient_url || saving.value) return;

    saving.value = true;
    errors.value = {};
    router.post(target.value.create_patient_url, {
        last_name: identity.last_name || null,
        first_name: identity.first_name || null,
        // La fiche fait foi : le sexe n'est envoyé que si elle ne le porte pas.
        sex: target.value.sex_code ? null : (identity.sex || null),
    }, {
        preserveScroll: true,
        onSuccess: () => { target.value = null; },
        onError: (received) => { errors.value = received; },
        onFinish: () => { saving.value = false; },
    });
};
</script>

<template>
    <Card v-if="babies" class="overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-sm font-bold text-foreground">
                    <Baby class="h-4 w-4" aria-hidden="true" />Nouveau-nés
                    <Badge v-if="list.length" variant="outline">{{ patients }} / {{ list.length }} patient{{ patients > 1 ? 's' : '' }}</Badge>
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Chaque bébé a son dossier ici, chez sa mère. Il devient patient depuis la Maternité ; ses soins restent sur le compte de la mère.</p>
            </div>
            <Button v-if="showMaternityLink && babies.maternity_url" :as="Link" :href="babies.maternity_url" variant="outline" size="sm">
                <ClipboardList class="h-4 w-4" aria-hidden="true" />Dossier Maternité
            </Button>
        </header>

        <p v-if="canCreateAny && creationBlockedReason" class="border-b border-amber-200 bg-amber-50 px-5 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-200" role="status">
            {{ creationBlockedReason }}
        </p>

        <ul v-if="list.length" class="divide-y divide-border">
            <li v-for="baby in list" :key="baby.rank" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full', sexTone(baby))" aria-hidden="true">
                    <Baby class="h-5 w-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-foreground">
                        {{ baby.name }}
                        <span v-if="summary(baby)" class="font-normal text-muted-foreground">· {{ summary(baby) }}</span>
                    </p>
                    <p v-if="baby.patient_number" class="font-mono text-xs text-muted-foreground">{{ baby.patient_number }}</p>
                    <p v-else class="text-xs text-muted-foreground">{{ baby.filled ? 'Pas encore patient' : 'Fiche non renseignée' }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="baby.create_patient_url"
                        type="button"
                        size="sm"
                        variant="primary"
                        :disabled="Boolean(creationBlockedReason)"
                        :title="creationBlockedReason || 'Ouvrir le dossier patient de ce bébé, relié à sa mère'"
                        @click="openCreation(baby)"
                    >
                        <UserPlus class="h-4 w-4" aria-hidden="true" />Créer le dossier patient
                    </Button>
                    <Button v-if="baby.medical_record_url" :as="Link" :href="baby.medical_record_url" size="sm" variant="outline">
                        <FileText class="h-4 w-4" aria-hidden="true" />Dossier médical
                    </Button>
                    <Button v-if="baby.patient_url" :as="Link" :href="baby.patient_url" size="sm" variant="ghost">
                        <UserRound class="h-4 w-4" aria-hidden="true" />Dossier patient
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="px-5 py-6 text-center text-sm text-muted-foreground">Aucune fiche de nouveau-né dans ce dossier Maternité.</p>

        <Dialog
            :open="Boolean(target)"
            title="Créer le dossier patient du nouveau-né"
            :description="target ? `${target.name} — numéro dérivé de celui de sa mère, naissance reprise de l’accouchement consigné.` : ''"
            :dismissible="false"
            @update:open="(value) => { if (!value) closeCreation(); }"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><UserPlus class="h-5 w-5" aria-hidden="true" /></span>
            </template>

            <form v-if="target" id="newborn-patient-form" class="space-y-4" @submit.prevent="submitCreation">
                <p v-if="errors.newborn" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/25 dark:text-red-300" role="alert">{{ errors.newborn }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Nom" hint="(facultatif)" :error="errors.last_name">
                        <Input v-model="identity.last_name" maxlength="100" autocomplete="off" placeholder="Nom de la fiche, sinon celui de la mère" />
                    </FormField>
                    <FormField label="Prénom" hint="(facultatif)" :error="errors.first_name">
                        <Input v-model="identity.first_name" maxlength="100" autocomplete="off" placeholder="Le bébé n’est pas toujours prénommé" />
                    </FormField>
                </div>
                <FormField v-if="!target.sex_code" as="div" label="Sexe" required :error="errors.sex">
                    <Select v-model="identity.sex" :options="SEX_OPTIONS" placeholder="Choisir le sexe" aria-label="Sexe du bébé" />
                </FormField>
                <p v-else class="text-xs text-muted-foreground">Sexe repris de la fiche : {{ target.sex }}.</p>
                <p class="text-xs leading-5 text-muted-foreground">Aucun passage n’est ouvert : la Réception en ouvrira un le jour où le bébé reviendra, comme pour tout patient existant.</p>
            </form>

            <template #footer>
                <Button type="button" variant="white-outline" :disabled="saving" @click="closeCreation">Annuler</Button>
                <Button type="submit" form="newborn-patient-form" :disabled="saving || (!target?.sex_code && !identity.sex)">
                    <UserPlus class="h-4 w-4" aria-hidden="true" />{{ saving ? 'Création…' : 'Créer le dossier' }}
                </Button>
            </template>
        </Dialog>
    </Card>
</template>
