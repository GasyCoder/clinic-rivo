<script setup>
import { computed } from 'vue';
import { BadgeCheck, GraduationCap, HeartHandshake, ListPlus, Plus, Shirt, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import EmployeeSectionCard from './EmployeeSectionCard.vue';

/**
 * ADR-221 — Famille, qualification et matériel : des informations déclaratives,
 * toutes facultatives, enregistrées toutes seules.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    options: { type: Object, required: true },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: true },
});

const { form, state, savedAt, retry } = useSectionAutosave('more', {
    marital_status: props.employee.marital_status ?? '',
    children: (props.employee.children ?? []).map((child) => ({ name: child.name ?? '', sex: child.sex ?? '', age: child.age ?? '' })),
    children_details: props.employee.children_details ?? '',
    diploma: props.employee.diploma ?? '',
    education_level: props.employee.education_level ?? '',
    badge: props.employee.badge ?? '',
    blouse: props.employee.blouse ?? '',
    tshirt_size: props.employee.tshirt_size ?? '',
    blouse_size: props.employee.blouse_size ?? '',
    bloc_outfit: props.employee.bloc_outfit ?? '',
    shoe_size: props.employee.shoe_size ?? '',
    scrub_cap: props.employee.scrub_cap ?? '',
    clog: props.employee.clog ?? '',
    observation: props.employee.observation ?? '',
}, () => props.url, {
    canEdit: () => props.canEdit,
    // Une ligne qui a un sexe ou un âge mais pas de prénom n'est pas encore enregistrable.
    ready: () => form.children.every((child) => String(child.name).trim() !== '' || (String(child.sex) === '' && String(child.age) === '')),
});

const sexOptions = [{ value: '', label: '—' }, { value: 'F', label: 'Fille' }, { value: 'G', label: 'Garçon' }];
const addChild = () => form.children.push({ name: '', sex: '', age: '' });
const removeChild = (index) => form.children.splice(index, 1);
const filledChildren = computed(() => form.children.filter((child) => String(child.name).trim() !== '').length);
// Ancienne note libre, gardée lisible tant qu'elle n'a pas été reprise en liste.
const legacyCount = computed(() => (form.children.length === 0 ? Number(props.employee.children_count ?? 0) : 0));

const maritalOptions = computed(() => [{ value: '', label: 'Non renseignée' }, ...(props.options.marital_statuses ?? [])]);
</script>

<template>
    <EmployeeSectionCard
        :icon="ListPlus"
        title="Famille et qualification"
        description="Informations déclaratives, sans aucun calcul automatique. Tout est facultatif."
        tone="bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300"
        :state="state"
        :saved-at="savedAt"
        :read-only="! canEdit"
        @retry="retry"
    >
        <fieldset :disabled="! canEdit" class="grid gap-4 lg:grid-cols-3">
            <section class="space-y-4 rounded-xl border border-border p-3.5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><HeartHandshake class="h-4 w-4 text-rose-600" />Famille</h3>
                <FormField as="div" label="Situation matrimoniale" :error="form.errors.marital_status">
                    <ShadSelect id="marital_status" v-model="form.marital_status" :options="maritalOptions" placeholder="Non renseignée" class="w-full" aria-label="Situation matrimoniale" :disabled="! canEdit" />
                </FormField>
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-foreground">Enfants</p>
                    <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-bold text-muted-foreground" aria-live="polite">{{ filledChildren }} enfant{{ filledChildren > 1 ? 's' : '' }}</span>
                </div>
                <p v-if="legacyCount > 0" class="rounded-lg bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    {{ legacyCount }} enfant{{ legacyCount > 1 ? 's' : '' }} déclaré{{ legacyCount > 1 ? 's' : '' }} avant la liste : ajoutez-les ci-dessous, le nombre suivra la liste.
                </p>
                <ul class="space-y-2">
                    <li v-for="(child, index) in form.children" :key="index" class="grid grid-cols-[1fr_5.5rem_4rem_auto] items-start gap-2">
                        <FormField as="div" :error="form.errors[`children.${index}.name`]"><Input v-model="child.name" :aria-label="`Prénom de l’enfant ${index + 1}`" placeholder="Prénom" /></FormField>
                        <ShadSelect v-model="child.sex" :options="sexOptions" :aria-label="`Sexe de l’enfant ${index + 1}`" class="w-full" :disabled="! canEdit" />
                        <FormField as="div" :error="form.errors[`children.${index}.age`]"><Input v-model="child.age" type="number" min="0" max="60" inputmode="numeric" :aria-label="`Âge de l’enfant ${index + 1}`" placeholder="Âge" /></FormField>
                        <Button type="button" variant="ghost" size="icon" :aria-label="`Retirer l’enfant ${index + 1}`" :disabled="! canEdit" @click="removeChild(index)"><Trash2 class="h-4 w-4" /></Button>
                    </li>
                </ul>
                <Button type="button" variant="outline" size="sm" :disabled="! canEdit || form.children.length >= 20" @click="addChild"><Plus class="mr-1 h-4 w-4" />Ajouter un enfant</Button>
                <FormField v-if="props.employee.children_details" label="Ancienne note sur les enfants" :error="form.errors.children_details">
                    <Textarea id="children_details" v-model="form.children_details" :rows="3" />
                </FormField>
            </section>
            <section class="space-y-4 rounded-xl border border-border p-3.5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><GraduationCap class="h-4 w-4 text-amber-600" />Qualification</h3>
                <FormField label="Diplôme" :error="form.errors.diploma">
                    <Input id="diploma" v-model="form.diploma" />
                </FormField>
                <FormField label="Niveau d’études" :error="form.errors.education_level">
                    <Input id="education_level" v-model="form.education_level" />
                </FormField>
                <FormField label="Observation RH" :error="form.errors.observation">
                    <Textarea id="observation" v-model="form.observation" :rows="3" placeholder="Information utile au suivi administratif" />
                </FormField>
            </section>
            <section class="space-y-4 rounded-xl border border-border p-3.5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><Shirt class="h-4 w-4 text-emerald-600" />Matériel remis</h3>
                <FormField label="Badge" :error="form.errors.badge">
                    <IconInput id="badge" v-model="form.badge" :icon="BadgeCheck" placeholder="Numéro ou référence" />
                </FormField>
                <FormField label="Blouse" :error="form.errors.blouse">
                    <IconInput id="blouse" v-model="form.blouse" :icon="Shirt" placeholder="Oui, Non ou référence" />
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Taille T-shirt" :error="form.errors.tshirt_size"><Input id="tshirt_size" v-model="form.tshirt_size" placeholder="M, L…" /></FormField>
                    <FormField label="Taille blouse" :error="form.errors.blouse_size"><Input id="blouse_size" v-model="form.blouse_size" placeholder="M, L…" /></FormField>
                    <FormField label="Pointure" :error="form.errors.shoe_size"><Input id="shoe_size" v-model="form.shoe_size" inputmode="numeric" placeholder="37" /></FormField>
                    <FormField label="Tenue bloc" :error="form.errors.bloc_outfit"><Input id="bloc_outfit" v-model="form.bloc_outfit" placeholder="Oui / taille" /></FormField>
                    <FormField label="Callot" :error="form.errors.scrub_cap"><Input id="scrub_cap" v-model="form.scrub_cap" placeholder="Oui / Non" /></FormField>
                    <FormField label="Sabot" :error="form.errors.clog"><Input id="clog" v-model="form.clog" placeholder="Oui / Non" /></FormField>
                </div>
            </section>
        </fieldset>
    </EmployeeSectionCard>
</template>
