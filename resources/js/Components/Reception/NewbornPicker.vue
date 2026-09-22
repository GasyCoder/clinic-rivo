<script setup>
import { computed, ref } from 'vue';
import { Baby, Building2, CircleCheck, LoaderCircle, MapPinned, Search, UserRound } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import FormError from '@/Components/UI/FormError.vue';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/utilities/date';

/**
 * ADR-146 — « accouchement chez nous ou externe ? », posé à l'accueil d'un nouveau-né.
 *
 * Né chez nous : le bébé n'est pas encore patient, il vit dans le dossier de sa mère. La Réception cherche la
 * mère, voit ses bébés, et le choisit — un clic ; il devient alors patient et repart dans le parcours d'arrivée
 * comme n'importe quel patient existant (`select`). Né ailleurs : on ne le retrouvera pas chez nous ; l'écran
 * parent ouvre alors une identité minimale de bébé (`external`), jamais le profil administratif d'un adulte.
 *
 * Rien de clinique n'est affiché : le nom, le rang, le sexe et la naissance suffisent à reconnaître l'enfant.
 * Le serveur juge de tout (naissance jamais devinée, sexe exigé) ; l'écran reflète ses refus.
 */
const props = defineProps({
    /** `requestJson` de l'écran d'arrivée : même CSRF, mêmes erreurs. */
    request: { type: Function, required: true },
});
const emit = defineEmits(['select', 'external', 'internal']);

const origin = ref(null);
const query = ref('');
const searching = ref(false);
const searched = ref(false);
const mothers = ref([]);
const mother = ref(null);
const babies = ref([]);
const loadingBabies = ref(false);
const choosing = ref(null);
const sexByBaby = ref({});
const message = ref('');

const sexOptions = [
    { value: '', label: 'Choisir…' },
    { value: 'F', label: 'Féminin' },
    { value: 'M', label: 'Masculin' },
];

const errorOf = (error) => error.payload?.errors
    ? Object.values(error.payload.errors).flat()[0]
    : error.message;

const search = async () => {
    if (query.value.trim().length < 2) return;
    searching.value = true;
    searched.value = false;
    message.value = '';
    mother.value = null;
    babies.value = [];

    try {
        const result = await props.request(`/reception/patients/search?q=${encodeURIComponent(query.value.trim())}`);
        mothers.value = result.data ?? [];
        searched.value = true;
    } catch (error) {
        message.value = errorOf(error);
    } finally {
        searching.value = false;
    }
};

const openMother = async (patient) => {
    loadingBabies.value = true;
    message.value = '';
    mother.value = patient;
    babies.value = [];

    try {
        const result = await props.request(`/reception/newborns?mother=${patient.uuid}`);
        babies.value = result.data ?? [];
    } catch (error) {
        message.value = errorOf(error);
    } finally {
        loadingBabies.value = false;
    }
};

const backToMothers = () => {
    mother.value = null;
    babies.value = [];
    message.value = '';
};

const needsSex = (baby) => !baby.patient && !baby.sex_code;

const choose = async (baby) => {
    message.value = '';

    // Déjà patient : rien à créer, on le sélectionne.
    if (baby.patient) {
        emit('select', baby.patient);

        return;
    }

    choosing.value = baby.newborn_uuid;

    try {
        const result = await props.request(`/reception/newborns/${baby.record_uuid}/${baby.newborn_uuid}/patient`, {
            method: 'POST',
            body: JSON.stringify(needsSex(baby) ? { sex: sexByBaby.value[baby.newborn_uuid] } : {}),
        });
        emit('select', result.patient);
    } catch (error) {
        message.value = errorOf(error);
    } finally {
        choosing.value = null;
    }
};

const canChoose = (baby) => !baby.blocked && (!needsSex(baby) || Boolean(sexByBaby.value[baby.newborn_uuid]));
const sexLabel = (code) => ({ F: 'Féminin', M: 'Masculin' }[code] ?? null);
const bornOn = (baby) => (baby.born_at ? formatDateTime(baby.born_at) : null);
const noBabyFound = computed(() => mother.value && !loadingBabies.value && !babies.value.length && !message.value);

const chooseInternalOrigin = () => {
    origin.value = 'here';
    emit('internal');
};

const chooseExternalOrigin = () => {
    origin.value = 'external';
    emit('external');
};
</script>

<template>
    <div class="space-y-4">
        <!-- La question de l'accueil : chez nous ou ailleurs. -->
        <div class="grid gap-3 sm:grid-cols-2">
            <button
                type="button"
                :class="cn('flex items-start gap-3 rounded-lg border p-4 text-start transition', origin === 'here' ? 'border-primary bg-primary/5' : 'border-border bg-card hover:border-primary/40')"
                @click="chooseInternalOrigin"
            >
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                <span>
                    <span class="block text-sm font-bold text-foreground">Né à la clinique</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Le bébé est déjà dans le dossier de sa mère : on le retrouve chez elle.</span>
                </span>
            </button>
            <button
                type="button"
                :class="cn('flex items-start gap-3 rounded-lg border p-4 text-start transition', origin === 'external' ? 'border-primary bg-primary/5' : 'border-border bg-card hover:border-primary/40')"
                @click="chooseExternalOrigin"
            >
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"><MapPinned class="h-5 w-5" /></span>
                <span>
                    <span class="block text-sm font-bold text-foreground">Né ailleurs</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Accouchement externe : identité du bébé et contact de son responsable.</span>
                </span>
            </button>
        </div>

        <section v-if="origin === 'here'" class="rounded-md border border-border bg-muted/25 p-4 sm:p-5">
            <!-- 1 · la mère -->
            <template v-if="!mother">
                <h3 class="text-sm font-bold text-foreground">Chercher la mère</h3>
                <p class="mt-1 text-xs text-muted-foreground">Numéro de dossier, nom, prénom ou téléphone de la mère.</p>
                <form class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="search">
                    <IconInput v-model="query" size="lg" :icon="Search" placeholder="Ex. A-26-0012, Rakoto…" autocomplete="off" @update:model-value="searched = false" />
                    <Button size="lg" type="submit" class="justify-center" :disabled="query.trim().length < 2 || searching">
                        <component :is="searching ? LoaderCircle : Search" class="h-4 w-4" />{{ searching ? 'Recherche…' : 'Rechercher' }}
                    </Button>
                </form>

                <ul v-if="mothers.length" class="mt-4 divide-y divide-border overflow-hidden rounded-md border border-border bg-card">
                    <li v-for="patient in mothers" :key="patient.uuid">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start transition hover:bg-primary/5" @click="openMother(patient)">
                            <UserRound class="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold text-foreground">{{ patient.last_name }} {{ patient.first_name }}</span>
                                <span class="font-mono text-xs text-muted-foreground">{{ patient.patient_number }}</span>
                            </span>
                            <span class="text-xs font-bold text-primary">Voir ses bébés</span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="searched && !searching" class="mt-4 rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">Aucun dossier trouvé. Vérifiez la saisie, ou le bébé est peut-être né ailleurs.</p>
            </template>

            <!-- 2 · ses bébés, dans l'arborescence de sa mère -->
            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Bébés de</p>
                        <p class="truncate text-base font-bold text-foreground">{{ mother.last_name }} {{ mother.first_name }} <span class="font-mono text-xs font-normal text-muted-foreground">· {{ mother.patient_number }}</span></p>
                    </div>
                    <Button type="button" size="sm" variant="white-outline" @click="backToMothers">Changer de mère</Button>
                </div>

                <p v-if="loadingBabies" class="mt-4 flex items-center gap-2 text-sm text-muted-foreground"><LoaderCircle class="h-4 w-4 animate-spin" />Chargement…</p>

                <ul v-else-if="babies.length" class="mt-4 divide-y divide-border overflow-hidden rounded-md border border-border bg-card">
                    <li v-for="baby in babies" :key="baby.newborn_uuid" class="flex flex-wrap items-center gap-3 px-4 py-3.5">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><Baby class="h-5 w-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-foreground">
                                {{ baby.name }}
                                <span v-if="sexLabel(baby.sex_code)" class="font-normal text-muted-foreground">· {{ sexLabel(baby.sex_code) }}</span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="bornOn(baby)">Né(e) le {{ bornOn(baby) }}</template>
                                <Badge v-if="baby.patient" variant="success" class="ms-2"><CircleCheck class="me-1 h-3 w-3" />Déjà patient · {{ baby.patient.patient_number }}</Badge>
                                <span v-else-if="baby.blocked" class="text-amber-700 dark:text-amber-300">{{ baby.blocked }}</span>
                            </p>
                        </div>
                        <!-- Le sexe manque à la fiche : la Réception le donne, un dossier patient ne peut pas l'ignorer. -->
                        <FormField v-if="needsSex(baby)" label="Sexe" required class="w-40">
                            <Select v-model="sexByBaby[baby.newborn_uuid]" class="h-9 w-full" :options="sexOptions" />
                        </FormField>
                        <Button type="button" size="sm" :disabled="!canChoose(baby) || choosing === baby.newborn_uuid" @click="choose(baby)">
                            <component :is="choosing === baby.newborn_uuid ? LoaderCircle : CircleCheck" :class="cn('h-4 w-4', choosing === baby.newborn_uuid && 'animate-spin')" />
                            {{ baby.patient ? 'Sélectionner' : 'Sélectionner ce bébé' }}
                        </Button>
                    </li>
                </ul>

                <div v-else-if="noBabyFound" class="mt-4 rounded-md border border-dashed border-border px-4 py-6 text-center">
                    <p class="text-sm font-semibold text-foreground">Aucun nouveau-né consigné pour cette mère</p>
                    <p class="mt-1 text-xs text-muted-foreground">Le bébé est peut-être né ailleurs, ou la Maternité ne l'a pas encore enregistré.</p>
                    <Button type="button" class="mt-3" size="sm" variant="white-outline" @click="chooseExternalOrigin">Né ailleurs — nouveau patient</Button>
                </div>
            </template>

            <FormError v-if="message" class="mt-3">{{ message }}</FormError>
        </section>
    </div>
</template>
