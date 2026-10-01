<script setup>
import { computed } from 'vue';
import { Baby, BadgeCheck, Footprints, GraduationCap, HeartHandshake, ListPlus, NotebookPen, Plus, Shirt, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import ShadSelect from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import ChipChoice from './ChipChoice.vue';
import EmployeeSectionCard from './EmployeeSectionCard.vue';
import FieldGroup from './FieldGroup.vue';

/**
 * ADR-221 / ADR-225 — famille, qualification, tenue et matériel remis : des
 * informations déclaratives, toutes facultatives, enregistrées toutes seules.
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

const maritalOptions = computed(() => [{ value: '', label: 'Non renseignée' }, ...(props.options.marital_statuses ?? [])]);

const childSexes = [{ value: 'F', label: 'Fille' }, { value: 'G', label: 'Garçon' }];
const addChild = () => form.children.push({ name: '', sex: '', age: '' });
const removeChild = (index) => form.children.splice(index, 1);
const filledChildren = computed(() => form.children.filter((child) => String(child.name).trim() !== '').length);
// Un nombre déclaré avant la liste, sans prénoms : signalé, jamais réécrit avant la liste.
const legacyCount = computed(() => (form.children.length === 0 ? Number(props.employee.children_count ?? 0) : 0));

const sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
const yesNo = [{ value: 'Oui', label: 'Oui' }, { value: 'Non', label: 'Non', tone: 'negative' }];
const handedOver = [
    { key: 'blouse', label: 'Blouse' },
    { key: 'bloc_outfit', label: 'Tenue bloc' },
    { key: 'scrub_cap', label: 'Callot' },
    { key: 'clog', label: 'Sabot' },
];
</script>

<template>
    <EmployeeSectionCard
        :icon="ListPlus"
        title="Famille, qualification et tenue"
        description="Informations déclaratives, sans aucun calcul automatique. Tout est facultatif."
        tone="bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300"
        :state="state"
        :saved-at="savedAt"
        incomplete-hint="Donnez un prénom à chaque enfant"
        :read-only="! canEdit"
        @retry="retry"
    >
        <fieldset :disabled="! canEdit" class="grid gap-4">
            <div class="grid gap-4 lg:grid-cols-5">
                <FieldGroup :icon="HeartHandshake" title="Famille" tone="bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300" class="lg:col-span-3">
                    <FormField as="div" label="Situation matrimoniale" :error="form.errors.marital_status">
                        <ShadSelect id="marital_status" v-model="form.marital_status" :options="maritalOptions" placeholder="Non renseignée" class="w-full sm:max-w-xs" aria-label="Situation matrimoniale" :disabled="! canEdit" />
                    </FormField>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-foreground">Enfants</p>
                            <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold text-muted-foreground" aria-live="polite">
                                <Baby class="h-3.5 w-3.5" aria-hidden="true" />{{ filledChildren }} enfant{{ filledChildren > 1 ? 's' : '' }}
                            </span>
                        </div>

                        <p v-if="legacyCount > 0" class="mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            {{ legacyCount }} enfant{{ legacyCount > 1 ? 's' : '' }} déclaré{{ legacyCount > 1 ? 's' : '' }} avant la liste. Ajoutez-les ci-dessous : le nombre suivra la liste.
                        </p>

                        <ul v-if="form.children.length" class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                            <li v-for="(child, index) in form.children" :key="index" class="bg-background px-3 py-2.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-bold text-primary" aria-hidden="true">{{ index + 1 }}</span>
                                    <Input v-model="child.name" :aria-label="`Prénom de l’enfant ${index + 1}`" placeholder="Prénom" class="h-9 min-w-[9rem] flex-1" />
                                    <ChipChoice v-model="child.sex" :options="childSexes" :label="`Sexe de l’enfant ${index + 1}`" :disabled="! canEdit" size="sm" />
                                    <div class="flex items-center gap-1.5">
                                        <Input v-model="child.age" type="number" min="0" max="60" inputmode="numeric" :aria-label="`Âge de l’enfant ${index + 1}`" placeholder="Âge" class="h-9 w-16 text-center" />
                                        <span class="text-xs text-muted-foreground">ans</span>
                                    </div>
                                    <Button type="button" variant="ghost" size="icon" class="ms-auto h-8 w-8 shrink-0 text-muted-foreground hover:text-destructive" :aria-label="`Retirer l’enfant ${index + 1}`" :disabled="! canEdit" @click="removeChild(index)"><Trash2 class="h-4 w-4" /></Button>
                                </div>
                                <p v-if="form.errors[`children.${index}.name`] || form.errors[`children.${index}.age`]" class="mt-1.5 ps-9 text-xs text-destructive">{{ form.errors[`children.${index}.name`] || form.errors[`children.${index}.age`] }}</p>
                            </li>
                        </ul>
                        <p v-else class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">Aucun enfant déclaré.</p>

                        <Button type="button" variant="outline" size="sm" class="mt-2" :disabled="! canEdit || form.children.length >= 20" @click="addChild"><Plus class="h-4 w-4" />Ajouter un enfant</Button>
                    </div>

                    <FormField v-if="employee.children_details" label="Ancienne note sur les enfants" hint="(saisie avant la liste)" :error="form.errors.children_details">
                        <Textarea id="children_details" v-model="form.children_details" :rows="2" />
                    </FormField>
                </FieldGroup>

                <div class="grid content-start gap-4 lg:col-span-2">
                    <FieldGroup :icon="GraduationCap" title="Qualification" tone="bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300">
                        <FormField label="Diplôme" :error="form.errors.diploma">
                            <Input id="diploma" v-model="form.diploma" placeholder="Ex. Diplôme d’État d’infirmier" />
                        </FormField>
                        <FormField label="Niveau d’études" :error="form.errors.education_level">
                            <Input id="education_level" v-model="form.education_level" placeholder="Ex. Bac +3" />
                        </FormField>
                    </FieldGroup>
                <FieldGroup :icon="NotebookPen" title="Observation RH" tone="bg-muted text-muted-foreground">
                    <Textarea id="observation" v-model="form.observation" :rows="3" aria-label="Observation RH" placeholder="Information utile au suivi administratif" />
                    <p v-if="form.errors.observation" class="text-xs text-destructive">{{ form.errors.observation }}</p>
                </FieldGroup>
                </div>
            </div>

            <FieldGroup :icon="Shirt" title="Tenue et équipement" description="Les tailles à commander et ce qui a été remis. Un second clic sur un choix le retire." tone="bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-[1fr_1fr_12rem]">
                    <FormField as="div" label="Taille T-shirt" :error="form.errors.tshirt_size">
                        <ChipChoice v-model="form.tshirt_size" :options="sizes" label="Taille T-shirt" :disabled="! canEdit" size="sm" />
                    </FormField>
                    <FormField as="div" label="Taille blouse" :error="form.errors.blouse_size">
                        <ChipChoice v-model="form.blouse_size" :options="sizes" label="Taille blouse" :disabled="! canEdit" size="sm" />
                    </FormField>
                    <FormField label="Pointure" :error="form.errors.shoe_size">
                        <IconInput id="shoe_size" v-model="form.shoe_size" :icon="Footprints" inputmode="numeric" placeholder="Ex. 38" />
                    </FormField>
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-foreground">Remis à la personne</p>
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <div v-for="item in handedOver" :key="item.key" class="flex items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2">
                            <span class="text-sm font-medium text-foreground">{{ item.label }}</span>
                            <ChipChoice v-model="form[item.key]" :options="yesNo" :label="`${item.label} remis`" :disabled="! canEdit" size="sm" />
                        </div>
                    </div>
                    <p v-if="['blouse', 'bloc_outfit', 'scrub_cap', 'clog'].some((key) => form.errors[key])" class="mt-1 text-xs text-destructive">{{ form.errors.blouse || form.errors.bloc_outfit || form.errors.scrub_cap || form.errors.clog }}</p>
                </div>

                <FormField label="N° de badge" hint="(vide : le matricule est imprimé)" :error="form.errors.badge" class="sm:max-w-xs">
                    <IconInput id="badge" v-model="form.badge" :icon="BadgeCheck" placeholder="Numéro ou référence" />
                </FormField>
            </FieldGroup>

        </fieldset>
    </EmployeeSectionCard>
</template>
