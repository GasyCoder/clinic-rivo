<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, FileText, Pencil, RotateCcw, Save, X } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import FormError from '@/Components/UI/FormError.vue';

/**
 * Corriger une constante relevée par les Soins (ADR-093).
 *
 * Replié par défaut : corriger est l'exception, pas le geste courant. Le
 * médecin lit les constantes ; il n'ouvre ce formulaire que lorsqu'une
 * valeur est manifestement fausse — 32 °C au lieu de 36,2.
 *
 * L'écrasement est réel et assumé (arbitrage du propriétaire), mais il n'est
 * jamais silencieux : l'encart d'avertissement le dit avant la saisie, et
 * `CareRecord` est `Auditable` — l'ancienne valeur, son auteur et sa date
 * restent lisibles à l'audit.
 *
 * Aucun acte, aucun consommable, aucune allergie ici : le serveur les refuse
 * nommément, et les proposer dans l'interface laisserait croire qu'un
 * médecin peut créer depuis cet écran une prestation à facturer ou une
 * sortie de stock Pharmacie.
 */
const props = defineProps({
    careRecord: { type: Object, required: true },
    orientationUuid: { type: String, required: true },
});

const open = ref(false);

const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
    .map((value) => ({ value, label: value }));

// Trois états distincts, jamais deux : « non renseigné » et « non » ne
// veulent pas dire la même chose (ADR-032).
const TRISTATE = [
    { value: '', label: 'Non renseigné' },
    { value: 'true', label: 'Oui' },
    { value: 'false', label: 'Non' },
];

const toText = (value) => (value === null || value === undefined ? '' : String(value));
const toTristate = (value) => (value === null || value === undefined ? '' : String(Boolean(value)));

const initial = () => ({
    blood_group: toText(props.careRecord.blood_group),
    blood_pressure_systolic: toText(props.careRecord.blood_pressure_systolic),
    blood_pressure_diastolic: toText(props.careRecord.blood_pressure_diastolic),
    heart_rate: toText(props.careRecord.heart_rate),
    spo2: toText(props.careRecord.spo2),
    temperature_celsius: toText(props.careRecord.temperature_celsius),
    known_diabetes: toTristate(props.careRecord.known_diabetes),
    diabetes_note: toText(props.careRecord.diabetes_note),
    height_cm: toText(props.careRecord.height_cm),
    weight_kg: toText(props.careRecord.weight_kg),
    smoker: toTristate(props.careRecord.smoker),
    alcohol: toTristate(props.careRecord.alcohol),
});

const form = useForm(initial());

// Une correction enregistrée renvoie la fiche fraîche : le formulaire se
// réaligne sur elle plutôt que de garder la saisie précédente.
watch(() => props.careRecord, () => form.defaults(initial()).reset(), { deep: true });

/**
 * L'IMC est calculé par le serveur et jamais envoyé ; cet aperçu sert
 * seulement à montrer au médecin ce que sa saisie produira.
 */
const previewBmi = computed(() => {
    const height = Number.parseFloat(form.height_cm);
    const weight = Number.parseFloat(form.weight_kg);

    if (!Number.isFinite(height) || !Number.isFinite(weight) || height <= 0 || weight <= 0) {
        return null;
    }

    return (weight / ((height / 100) ** 2)).toFixed(2);
});

const dirty = computed(() => form.isDirty);

const submit = () => {
    form
        .transform((data) => Object.fromEntries(
            Object.entries(data).map(([key, value]) => {
                if (value === '') {
                    return [key, null];
                }
                if (['known_diabetes', 'smoker', 'alcohol'].includes(key)) {
                    return [key, value === 'true'];
                }
                return [key, value];
            }),
        ))
        .put(`/medicine/orientations/${props.orientationUuid}/constantes`, {
            preserveScroll: true,
            onSuccess: () => { open.value = false; },
        });
};

const cancel = () => {
    form.reset();
    form.clearErrors();
    open.value = false;
};
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-amber-200 bg-amber-50/40 dark:border-amber-900 dark:bg-amber-950/20">
        <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
            <div class="flex min-w-0 items-center gap-2">
                <Pencil class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                <div class="min-w-0">
                    <h2 class="text-xs font-bold text-foreground">Corriger les constantes</h2>
                    <p class="mt-0.5 text-[11px] leading-4 text-muted-foreground">
                        Relevées par les Soins. Les actes réalisés et les observations se corrigent sur la fiche complète.
                    </p>
                </div>
            </div>
            <div v-if="!open" class="flex flex-wrap items-center gap-2">
                <!-- Les actes réalisés, les observations et le reste de la
                     fiche vivent sur leur propre écran. On y renvoie plutôt
                     que d'en recopier une seconde version ici : une fiche
                     dupliquée est une fiche qui finit par diverger. -->
                <Button
                    v-if="careRecord.full_record_url"
                    :as="Link"
                    :href="careRecord.full_record_url"
                    size="sm"
                    variant="white-outline"
                >
                    <FileText class="me-1.5 h-3.5 w-3.5" />Fiche Soins complète
                </Button>
                <Button type="button" size="sm" variant="warning-outline" @click="open = true">
                    <Pencil class="me-1.5 h-3.5 w-3.5" />Corriger les constantes
                </Button>
            </div>
            <Button v-else type="button" size="sm" variant="ghost" @click="cancel">
                <X class="me-1.5 h-3.5 w-3.5" />Annuler
            </Button>
        </div>

        <form v-if="open" class="space-y-4 border-t border-amber-200 bg-card px-3 py-3 dark:border-amber-900" @submit.prevent="submit">
            <p class="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-[11px] leading-4 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                <CircleAlert class="mt-px h-3.5 w-3.5 shrink-0" />
                <span>La valeur corrigée remplace celle des Soins. L’ancienne reste consultable dans le journal d’audit, avec son auteur et sa date.</span>
            </p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="vitals-bp-sys" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Tension systolique <span class="font-normal">(mmHg)</span></label>
                    <Input id="vitals-bp-sys" v-model="form.blood_pressure_systolic" type="number" inputmode="numeric" min="40" max="300" placeholder="170" />
                    <FormError :message="form.errors.blood_pressure_systolic" />
                </div>
                <div>
                    <label for="vitals-bp-dia" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Tension diastolique <span class="font-normal">(mmHg)</span></label>
                    <Input id="vitals-bp-dia" v-model="form.blood_pressure_diastolic" type="number" inputmode="numeric" min="20" max="200" placeholder="120" />
                    <FormError :message="form.errors.blood_pressure_diastolic" />
                </div>
                <div>
                    <label for="vitals-hr" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Fréquence cardiaque <span class="font-normal">(btt/mn)</span></label>
                    <Input id="vitals-hr" v-model="form.heart_rate" type="number" inputmode="numeric" min="20" max="250" placeholder="72" />
                    <FormError :message="form.errors.heart_rate" />
                </div>
                <div>
                    <label for="vitals-spo2" class="mb-1 block text-[11px] font-semibold text-muted-foreground">SpO₂ <span class="font-normal">(%)</span></label>
                    <Input id="vitals-spo2" v-model="form.spo2" type="number" inputmode="numeric" min="0" max="100" placeholder="98" />
                    <FormError :message="form.errors.spo2" />
                </div>
                <div>
                    <label for="vitals-temp" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Température <span class="font-normal">(°C)</span></label>
                    <Input id="vitals-temp" v-model="form.temperature_celsius" type="number" inputmode="decimal" step="0.1" min="25" max="45" placeholder="36.8" />
                    <FormError :message="form.errors.temperature_celsius" />
                </div>
                <div>
                    <label for="vitals-blood-group" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Groupe sanguin</label>
                    <Select id="vitals-blood-group" v-model="form.blood_group" :options="BLOOD_GROUPS" placeholder="Non renseigné" />
                    <FormError :message="form.errors.blood_group" />
                </div>
                <div>
                    <label for="vitals-height" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Taille <span class="font-normal">(cm)</span></label>
                    <Input id="vitals-height" v-model="form.height_cm" type="number" inputmode="decimal" step="0.01" min="20" max="250" placeholder="170" />
                    <FormError :message="form.errors.height_cm" />
                </div>
                <div>
                    <label for="vitals-weight" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Poids <span class="font-normal">(kg)</span></label>
                    <Input id="vitals-weight" v-model="form.weight_kg" type="number" inputmode="decimal" step="0.01" min="0.1" max="500" placeholder="65" />
                    <FormError :message="form.errors.weight_kg" />
                </div>
                <div>
                    <span class="mb-1 block text-[11px] font-semibold text-muted-foreground">IMC</span>
                    <p class="flex h-10 items-center rounded-lg border border-dashed border-border bg-muted/35 px-3 text-sm tabular-nums text-foreground">
                        {{ previewBmi ?? '—' }}
                        <span class="ms-2 text-[10px] font-normal text-muted-foreground">calculé</span>
                    </p>
                </div>
                <div>
                    <label for="vitals-diabetes" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Diabète connu</label>
                    <Select id="vitals-diabetes" v-model="form.known_diabetes" :options="TRISTATE" placeholder="Non renseigné" />
                    <FormError :message="form.errors.known_diabetes" />
                </div>
                <div>
                    <label for="vitals-smoker" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Tabac</label>
                    <Select id="vitals-smoker" v-model="form.smoker" :options="TRISTATE" placeholder="Non renseigné" />
                    <FormError :message="form.errors.smoker" />
                </div>
                <div>
                    <label for="vitals-alcohol" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Alcool</label>
                    <Select id="vitals-alcohol" v-model="form.alcohol" :options="TRISTATE" placeholder="Non renseigné" />
                    <FormError :message="form.errors.alcohol" />
                </div>
            </div>

            <div v-if="form.known_diabetes === 'true'">
                <label for="vitals-diabetes-note" class="mb-1 block text-[11px] font-semibold text-muted-foreground">Précision sur le diabète</label>
                <Input id="vitals-diabetes-note" v-model="form.diabetes_note" type="text" maxlength="1000" placeholder="Type, traitement en cours…" />
                <FormError :message="form.errors.diabetes_note" />
            </div>

            <FormError :message="form.errors.care_record" />

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-3">
                <Button type="button" size="sm" variant="ghost" :disabled="!dirty || form.processing" @click="form.reset()">
                    <RotateCcw class="me-1.5 h-3.5 w-3.5" />Rétablir
                </Button>
                <Button type="submit" size="sm" variant="warning" :disabled="!dirty || form.processing">
                    <Save class="me-1.5 h-3.5 w-3.5" />{{ form.processing ? 'Enregistrement…' : 'Enregistrer la correction' }}
                </Button>
            </div>
        </form>
    </section>
</template>
