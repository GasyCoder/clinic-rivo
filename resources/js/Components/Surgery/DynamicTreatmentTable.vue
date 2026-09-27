<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import { Hash, ListPlus, Pill, Plus, Tag, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    title: String,
    items: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    storeUrl: String,
    destroyBaseUrl: String,
    canEdit: Boolean,
});

const form = useForm({ category: props.categories[0]?.value ?? '', label: '', quantity: '', unit: '' });
const submit = () => form.post(props.storeUrl, {
    preserveScroll: true,
    onSuccess: () => form.reset('label', 'quantity', 'unit'),
});
const categoryLabels = computed(() => Object.fromEntries(props.categories.map((category) => [category.value, category.label])));
const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.value, label: category.label })));
const remove = (item) => router.delete(`${props.destroyBaseUrl}/${item.id}`, { preserveScroll: true });
</script>

<template>
    <section class="rounded-xl border border-border bg-card p-4">
        <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
            <span class="grid h-7 w-7 place-items-center rounded-md bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300" aria-hidden="true"><Pill class="h-3.5 w-3.5" /></span>
            {{ title }}
            <span class="ms-auto text-xs font-normal text-muted-foreground">{{ items.length }} ligne{{ items.length > 1 ? 's' : '' }}</span>
        </h3>

        <form v-if="canEdit" class="grid grid-cols-1 gap-3 sm:grid-cols-12" @submit.prevent="submit">
            <Select v-model="form.category" :options="categoryOptions" class="w-full sm:col-span-3" required />
            <div class="sm:col-span-5"><IconInput v-model="form.label" :icon="Tag" size="lg" placeholder="Médicament ou matériel" aria-label="Désignation" required /></div>
            <div class="sm:col-span-2"><IconInput v-model="form.quantity" :icon="Hash" size="lg" type="number" min="0.01" step="0.01" placeholder="Quantité" aria-label="Quantité" /></div>
            <Input v-model="form.unit" size="lg" class="sm:col-span-1" placeholder="Unité" aria-label="Unité" />
            <Button size="lg" icon type="submit" :disabled="form.processing" aria-label="Ajouter la ligne" title="Ajouter la ligne"><Plus class="h-4 w-4" /></Button>
            <FormError v-if="form.errors.label" class="sm:col-span-12">{{ form.errors.label }}</FormError>
            <FormError v-if="form.errors.treatment" class="sm:col-span-12">{{ form.errors.treatment }}</FormError>
        </form>

        <div class="mt-4 overflow-x-auto rounded-lg border border-border">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-start"><span class="inline-flex items-center gap-1.5"><ListPlus class="h-3.5 w-3.5" aria-hidden="true" />Type</span></th>
                        <th class="px-3 py-2 text-start"><span class="inline-flex items-center gap-1.5"><Tag class="h-3.5 w-3.5" aria-hidden="true" />Désignation</span></th>
                        <th class="px-3 py-2 text-start"><span class="inline-flex items-center gap-1.5"><Hash class="h-3.5 w-3.5" aria-hidden="true" />Quantité</span></th>
                        <th class="w-12 px-3 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="item in items" :key="item.id">
                        <td class="px-3 py-2"><span class="inline-flex rounded-md border border-border bg-muted/40 px-2 py-0.5 text-xs text-foreground">{{ categoryLabels[item.category] ?? item.category }}</span></td>
                        <td class="px-3 py-2 font-medium text-foreground">{{ item.label }}</td>
                        <td class="px-3 py-2 text-muted-foreground tabular-nums">{{ item.quantity ?? '—' }} {{ item.unit ?? '' }}</td>
                        <td class="px-3 py-2">
                            <button v-if="canEdit" type="button" class="flex h-8 w-8 items-center justify-center rounded-md text-destructive hover:bg-destructive/10" :aria-label="`Supprimer ${item.label}`" :title="`Supprimer ${item.label}`" @click="remove(item)"><Trash2 class="h-4 w-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="items.length === 0"><td colspan="4" class="px-3 py-6 text-center text-xs text-muted-foreground"><Pill class="mx-auto mb-1 h-5 w-5 opacity-50" aria-hidden="true" />Aucune ligne enregistrée.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
