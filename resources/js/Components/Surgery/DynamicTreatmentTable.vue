<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';

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
const remove = (item) => router.delete(`${props.destroyBaseUrl}/${item.id}`, { preserveScroll: true });
</script>

<template>
    <section class="rounded border border-gray-200 p-4 dark:border-gray-900">
        <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-500">{{ title }}</h3>

        <form v-if="canEdit" class="grid grid-cols-1 gap-3 sm:grid-cols-12" @submit.prevent="submit">
            <select v-model="form.category" class="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:text-white sm:col-span-3" required>
                <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
            </select>
            <Input v-model="form.label" size="lg" class="sm:col-span-5" placeholder="Médicament ou matériel" required />
            <Input v-model="form.quantity" size="lg" class="sm:col-span-2" type="number" min="0.01" step="0.01" placeholder="Quantité" />
            <Input v-model="form.unit" size="lg" class="sm:col-span-1" placeholder="Unité" />
            <Button size="lg" icon type="submit" :disabled="form.processing" aria-label="Ajouter la ligne"><Icon name="plus" /></Button>
            <FormError v-if="form.errors.label" class="sm:col-span-12">{{ form.errors.label }}</FormError>
            <FormError v-if="form.errors.treatment" class="sm:col-span-12">{{ form.errors.treatment }}</FormError>
        </form>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-slate-400 dark:bg-gray-1000">
                    <tr><th class="px-3 py-2 text-start">Type</th><th class="px-3 py-2 text-start">Désignation</th><th class="px-3 py-2 text-start">Quantité</th><th class="w-12 px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                    <tr v-for="item in items" :key="item.id">
                        <td class="px-3 py-2 text-slate-500">{{ categoryLabels[item.category] ?? item.category }}</td>
                        <td class="px-3 py-2 font-medium text-slate-700 dark:text-slate-200">{{ item.label }}</td>
                        <td class="px-3 py-2 text-slate-500">{{ item.quantity ?? '—' }} {{ item.unit ?? '' }}</td>
                        <td class="px-3 py-2">
                            <button v-if="canEdit" type="button" class="flex h-9 w-9 items-center justify-center rounded text-red-500 hover:bg-red-50 dark:hover:bg-red-950" aria-label="Supprimer la ligne" @click="remove(item)"><Icon name="trash" /></button>
                        </td>
                    </tr>
                    <tr v-if="items.length === 0"><td colspan="4" class="px-3 py-5 text-center text-slate-400">Aucune ligne enregistrée.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
