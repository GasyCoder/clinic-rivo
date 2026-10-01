<script setup>
import { computed, ref } from 'vue';
import { Camera, Check, Crop, ImagePlus, Loader2, MapPin, ScanFace, ShieldCheck, Sparkles, Trash2, User } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import NoticesButton from '@/Components/Shadcn/NoticesButton.vue';
import EmployeePhotoField from '@/Components/Administration/EmployeePhotoField.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-221 — les champs de l'étape Identité, les mêmes à la création et dans la
 * fiche : genre, nom, prénoms, naissance, et la photo 4 × 4 dans un grand cadre
 * qui ouvre le recadrage (ADR-194).
 *
 * `form` porte les valeurs et les erreurs ; la photo passe par `v-model:photo`
 * et `v-model:remove-photo`, que la page envoie à sa façon (avec le dossier à
 * la création, seule et tout de suite dans la fiche).
 */
const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    currentPhotoUrl: { type: String, default: null },
    photoError: { type: String, default: '' },
    /** La photo part en ce moment. */
    sending: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: true },
    photoSubtitle: { type: String, default: 'Facultative.' },
});
const photo = defineModel('photo', { default: null });
const removePhoto = defineModel('removePhoto', { default: false });

const photoField = ref(null);
const dropping = ref(false);
const photoNotices = [
    { key: 'face', icon: ScanFace, title: 'Cadrage', text: 'Visage de face, fond clair.' },
    { key: 'crop', icon: Crop, title: 'Avant l’envoi', text: 'Recadrage, zoom et rotation avant l’envoi.' },
    { key: 'format', icon: ShieldCheck, title: 'Fichier', text: 'JPEG, PNG ou WebP · conservée en privé.' },
];

const typedName = computed(() => [props.form.last_name, props.form.first_name].filter(Boolean).join(' '));
const derivedCivility = computed(() => ({ M: 'Monsieur (M.)', F: 'Madame (Mme)' }[props.form.sex] || 'Attribuée après le choix du genre'));
const photoPreview = computed(() => photoField.value?.preview ?? (removePhoto.value ? null : props.currentPhotoUrl));
const invalid = (field) => Boolean(props.form.errors?.[field]);

const onDrop = (event) => {
    dropping.value = false;
    photoField.value?.acceptFile(event.dataTransfer?.files?.[0]);
};
const chooseSex = (sex) => {
    props.form.sex = sex;
    props.form.clearErrors?.('sex');
};
</script>

<template>
    <div class="grid gap-5 lg:grid-cols-[11rem_minmax(0,1fr)]">
        <!-- La photo 4 × 4 : un grand cadre, qui ouvre le recadrage. -->
        <div>
            <div class="flex items-center justify-between gap-2">
                <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Photo 4 × 4</p>
                <NoticesButton :notices="photoNotices" align="start" heading="La photo d’identité" :subtitle="photoSubtitle" />
            </div>
            <EmployeePhotoField
                ref="photoField"
                v-model="photo"
                v-model:remove="removePhoto"
                :current-url="currentPhotoUrl"
                :name="typedName"
                :error="photoError"
                triggerless
            />
            <div
                :class="cn(
                    'group relative mt-2 aspect-square w-full max-w-40 overflow-hidden rounded-xl border-2 bg-card shadow-sm transition-all',
                    photoPreview ? 'border-transparent ring-1 ring-border' : 'border-dashed border-border',
                    dropping && 'scale-[1.02] border-primary bg-primary/10',
                )"
                @dragover.prevent="dropping = canEdit"
                @dragleave.prevent="dropping = false"
                @drop.prevent="canEdit && onDrop($event)"
            >
                <template v-if="photoPreview">
                    <img :src="photoPreview" alt="Photo d’identité" class="h-full w-full object-cover">
                    <div v-if="canEdit" class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-black/55 p-2 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                        <Button v-if="photoField?.canRecrop" type="button" size="xs" variant="white-outline" class="w-full max-w-28" @click="photoField?.recrop()"><Crop class="h-3.5 w-3.5" />Recadrer</Button>
                        <Button type="button" size="xs" variant="white-outline" class="w-full max-w-28" aria-label="Changer la photo d’identité" @click="photoField?.open()"><Camera class="h-3.5 w-3.5" />Changer</Button>
                    </div>
                    <span v-if="sending" class="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 bg-black/60 py-1 text-[10px] font-semibold text-white"><Loader2 class="h-3 w-3 animate-spin" />Envoi…</span>
                </template>
                <button
                    v-else
                    type="button"
                    class="grid h-full w-full place-items-center rounded-[10px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed"
                    aria-label="Choisir ou déposer la photo d’identité"
                    :disabled="! canEdit || photoField?.reading"
                    @click="photoField?.open()"
                >
                    <span class="flex flex-col items-center gap-1.5 px-3 text-center text-muted-foreground">
                        <span class="grid h-10 w-10 place-items-center rounded-full bg-primary/10 text-primary"><Loader2 v-if="photoField?.reading" class="h-5 w-5 animate-spin" /><ImagePlus v-else class="h-5 w-5" /></span>
                        <span class="text-xs font-semibold text-foreground">{{ dropping ? 'Déposez ici' : 'Ajouter la photo' }}</span>
                    </span>
                </button>
            </div>
            <Button v-if="photoPreview && canEdit" type="button" size="xs" variant="ghost" class="mt-1.5 text-muted-foreground hover:text-destructive" @click="photoField?.removePhoto()"><Trash2 class="h-3.5 w-3.5" />Retirer la photo</Button>
        </div>

        <fieldset :disabled="! canEdit" class="space-y-4">
            <FormField as="div" label="Genre" required :error="form.errors?.sex">
                <div id="sex" role="radiogroup" aria-label="Genre" tabindex="-1" class="grid gap-2.5 focus:outline-none sm:grid-cols-2">
                    <button
                        v-for="item in options.sexes"
                        :key="item.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.sex === item.value"
                        :class="cn(
                            'flex items-center gap-2.5 rounded-lg border p-2.5 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60',
                            form.sex === item.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-card hover:border-primary/40 hover:bg-accent/50',
                        )"
                        @click="chooseSex(item.value)"
                    >
                        <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full', form.sex === item.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                            <Check v-if="form.sex === item.value" class="h-4 w-4" :stroke-width="3" />
                            <User v-else class="h-4 w-4" />
                        </span>
                        <span class="text-sm font-semibold text-foreground">{{ item.label }}</span>
                    </button>
                </div>
            </FormField>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField label="Nom" required :error="form.errors?.last_name">
                    <Input id="last_name" v-model="form.last_name" autocomplete="family-name" :aria-invalid="invalid('last_name')" />
                </FormField>
                <FormField label="Prénoms" :error="form.errors?.first_name">
                    <Input id="first_name" v-model="form.first_name" autocomplete="given-name" />
                </FormField>
                <FormField as="div" label="Date de naissance" :error="form.errors?.birth_date">
                    <DatePicker id="birth_date" v-model="form.birth_date" aria-label="Date de naissance" :invalid="invalid('birth_date')" :disabled="! canEdit" />
                </FormField>
                <FormField label="Lieu de naissance" :error="form.errors?.birth_place">
                    <IconInput id="birth_place" v-model="form.birth_place" :icon="MapPin" />
                </FormField>
            </div>
            <p class="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                <Sparkles class="h-3.5 w-3.5 shrink-0 text-emerald-600" /><span>Civilité : <strong class="text-foreground">{{ derivedCivility }}</strong></span>
            </p>
            <slot />
        </fieldset>
    </div>
</template>
