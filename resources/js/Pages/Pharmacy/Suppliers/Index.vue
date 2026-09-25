<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import FolderCard from '@/Components/UI/FolderCard.vue';
import { lucideIcon } from '@/lib/icons';
import { FolderPlus, Search } from 'lucide-vue-next';
import PageHeader from '@/Components/UI/PageHeader.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';

defineOptions({ layout: AppLayout });

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const search = ref('');
const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return props.suppliers.filter((supplier) => !needle || [supplier.name, supplier.code, supplier.contact_name]
        .filter(Boolean)
        .some((value) => value.toLocaleLowerCase().includes(needle)));
});

const creating = ref(false);
const form = useForm({ code: '', name: '', contact_name: '', phone: '', email: '', address: '' });
const submit = () => form.post(pharmacyUrl('/pharmacy/setup/suppliers'), {
    preserveScroll: true,
    onSuccess: () => { form.reset(); creating.value = false; },
});

const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-white';
</script>

<template>
    <Head title="Fournisseurs" />

    <div class="w-full space-y-5">
        <PageHeader eyebrow="Pharmacie"
            title="Fournisseurs"
            description="Chaque fournisseur a son dossier : ses catalogues, ses commandes, ses factures et ses prix. Ouvrez un dossier pour y entrer."
            icon="building"
            tone="amber"
        >
            <template #actions>
                <label class="relative block w-full sm:w-72">
                    <span class="sr-only">Rechercher un fournisseur</span>
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-slate-400 h-4 w-4" />
                    <input v-model="search" type="search" class="h-10 w-full rounded-lg border border-gray-200 bg-white ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Nom du fournisseur…">
                </label>
                <Button v-if="can.create" size="rg" @click="creating = !creating">
                    <component :is="lucideIcon(creating ? 'cross' : 'folder-plus')" class="h-4 w-4" /><span class="ms-2">{{ creating ? 'Fermer' : 'Ajouter un fournisseur' }}</span>
                </Button>
            </template>
        </PageHeader>

        <form v-if="creating" class="space-y-4 rounded-xl border border-primary-200 bg-white p-5 shadow-sm dark:border-primary-900 dark:bg-gray-950" @submit.prevent="submit">
            <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Nouveau fournisseur</h2>
            <ValidationErrorSummary :errors="form.errors" />
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block"><span :class="labelClass">Nom <span class="text-red-500">*</span></span><input v-model="form.name" name="name" :class="inputClass" required></label>
                <label class="block"><span :class="labelClass">Code court <span class="text-red-500">*</span></span><input v-model="form.code" name="code" :class="[inputClass, 'uppercase']" placeholder="Ex. PHARMADIS" required></label>
                <label class="block"><span :class="labelClass">Personne à contacter</span><input v-model="form.contact_name" name="contact_name" :class="inputClass"></label>
                <label class="block"><span :class="labelClass">Téléphone</span><input v-model="form.phone" name="phone" type="tel" :class="inputClass"></label>
                <label class="block"><span :class="labelClass">E-mail</span><input v-model="form.email" name="email" type="email" :class="inputClass"></label>
                <label class="block"><span :class="labelClass">Adresse</span><input v-model="form.address" name="address" :class="inputClass"></label>
            </div>
            <div class="flex justify-end gap-2">
                <Button type="button" size="rg" variant="white-outline" @click="creating = false">Annuler</Button>
                <Button type="submit" size="rg" :disabled="form.processing"><FolderPlus class="h-4 w-4" /><span class="ms-2">Créer le dossier</span></Button>
            </div>
        </form>

        <section class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-900 dark:bg-gray-1000/40">
            <div v-if="visible.length" class="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                <FolderCard v-for="supplier in visible"
                    :key="supplier.uuid"
                    :href="pharmacyUrl(`/pharmacy/suppliers/${supplier.uuid}`)"
                    :title="supplier.name"
                    :subtitle="supplier.contact_name || supplier.phone || supplier.code"
                    :meta="supplier.catalogs_count ? `${supplier.catalogs_count} catalogue${supplier.catalogs_count > 1 ? 's' : ''}` : 'Aucun catalogue'"
                />
            </div>
            <EmptyState v-else
                icon="folder"
                :title="suppliers.length ? 'Aucun fournisseur trouvé' : 'Aucun fournisseur'"
                :description="suppliers.length ? 'Modifiez la recherche.' : 'Les dossiers fournisseurs apparaîtront ici.'"
            />
        </section>
    </div>
</template>
