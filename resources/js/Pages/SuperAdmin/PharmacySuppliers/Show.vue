<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import FolderCard from '@/Components/UI/FolderCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    targetSite: { type: Object, required: true },
    supplier: { type: Object, default: null },
    counts: { type: Object, default: () => ({}) },
    error: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
});

const baseUrl = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/${props.supplier?.uuid}`);
const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}${props.supplier?.archived ? '&status=ARCHIVED' : ''}`);
const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;

// Same sub-folders as the clinic's supplier folder (ADR-098), each shown only
// with the permission of the page it opens.
const folders = computed(() => [
    { show: props.can.view_catalogs, href: `${baseUrl.value}/catalogs`, title: 'Catalogues', meta: plural(props.counts.catalogs ?? 0, 'fichier'), tone: 'amber' },
    { show: props.can.view_orders, href: `${baseUrl.value}/orders`, title: 'Commandes', meta: plural(props.counts.orders ?? 0, 'commande'), count: props.counts.open_orders, tone: 'violet' },
    { show: props.can.view_invoices, href: `${baseUrl.value}/invoices`, title: 'Factures', meta: plural(props.counts.invoices ?? 0, 'facture'), tone: 'slate' },
    { show: props.can.view_offers, href: `${baseUrl.value}/products`, title: 'Produits et prix', meta: plural(props.counts.offers ?? 0, 'produit'), tone: 'emerald' },
].filter((folder) => folder.show));

const details = computed(() => [
    { label: 'Personne à contacter', value: props.supplier?.contact_name, icon: 'user' },
    { label: 'Téléphone', value: props.supplier?.phone, icon: 'call', href: props.supplier?.phone ? `tel:${props.supplier.phone}` : null },
    { label: 'E-mail', value: props.supplier?.email, icon: 'mail', href: props.supplier?.email ? `mailto:${props.supplier.email}` : null },
    { label: 'Adresse', value: props.supplier?.address, icon: 'map-pin' },
]);

const editing = ref(false);
const editForm = useForm({ name: '', contact_name: '', phone: '', email: '', address: '' });
const startEdit = () => {
    editForm.defaults({
        name: props.supplier.name ?? '',
        contact_name: props.supplier.contact_name ?? '',
        phone: props.supplier.phone ?? '',
        email: props.supplier.email ?? '',
        address: props.supplier.address ?? '',
    });
    editForm.reset();
    editForm.clearErrors();
    editing.value = true;
};
const saveEdit = () => editForm.put(baseUrl.value, { preserveScroll: true, onSuccess: () => { editing.value = false; } });

const archiving = ref(false);
const archiveForm = useForm({ reason: '' });
const confirmArchive = () => archiveForm.delete(baseUrl.value, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = false; archiveForm.reset(); },
});
const restore = () => {
    if (!confirm(`Restaurer ${props.supplier.name} ? Il sera de nouveau proposé pour les commandes et les entrées de stock.`)) return;
    router.post(`${baseUrl.value}/restore`, {}, { preserveScroll: true });
};

const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-white';
</script>

<template>
    <Head :title="supplier ? `Fournisseur · ${supplier.name}` : 'Fournisseur'" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: targetSite.name, href: listHref },
            { label: supplier?.name ?? 'Dossier' },
        ]" />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
            <Icon name="alert" class="mt-0.5 text-lg" />
            <p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <Icon name="folder-fill" :class="['text-5xl leading-none', supplier.archived ? 'text-slate-300' : 'text-amber-400']" />
                        <div class="min-w-0">
                            <h1 class="flex flex-wrap items-center gap-2 font-heading text-2xl font-bold text-slate-800 dark:text-white">
                                {{ supplier.name }}
                                <Badge v-if="supplier.archived" tone="neutral">Archivé</Badge>
                            </h1>
                            <p class="font-mono text-xs text-slate-400">{{ supplier.code }} <span class="font-sans">· {{ targetSite.name }}</span></p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="!supplier.archived">
                            <Button v-if="can.update_supplier && !editing" size="rg" variant="white-outline" type="button" @click="startEdit"><Icon name="edit" /><span class="ms-2">Modifier</span></Button>
                            <Button v-if="can.archive_supplier" size="rg" variant="white-outline" type="button" class="text-red-600" @click="archiving = true"><Icon name="archive" /><span class="ms-2">Archiver</span></Button>
                        </template>
                        <Button v-else-if="can.restore_supplier" size="rg" type="button" @click="restore"><Icon name="undo" /><span class="ms-2">Restaurer</span></Button>
                    </div>
                </div>

                <form v-if="editing" class="mt-5 space-y-4 border-t border-gray-100 pt-5 dark:border-gray-900" @submit.prevent="saveEdit">
                    <ValidationErrorSummary :errors="editForm.errors" />
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="block"><span :class="labelClass">Code</span><input :value="supplier.code" :class="[inputClass, 'bg-gray-50 text-slate-500 dark:bg-gray-900']" disabled><span class="mt-1 block text-xs text-slate-400">Le code ne change pas : il identifie le fournisseur dans les imports.</span></label>
                        <label class="block"><span :class="labelClass">Nom <span class="text-red-500">*</span></span><input v-model="editForm.name" :class="inputClass" required></label>
                        <label class="block"><span :class="labelClass">Personne à contacter</span><input v-model="editForm.contact_name" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">Téléphone</span><input v-model="editForm.phone" type="tel" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">E-mail</span><input v-model="editForm.email" type="email" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">Adresse</span><input v-model="editForm.address" :class="inputClass"></label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="editing = false">Annuler</Button>
                        <Button type="submit" size="rg" :disabled="editForm.processing || !editForm.isDirty"><Icon name="check" /><span class="ms-2">Enregistrer</span></Button>
                    </div>
                </form>

                <dl v-else class="mt-5 grid gap-3 border-t border-gray-100 pt-5 dark:border-gray-900 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="detail in details" :key="detail.label" class="flex items-start gap-2.5">
                        <Icon :name="detail.icon" class="mt-0.5 text-lg text-slate-400" />
                        <div class="min-w-0">
                            <dt class="text-xs text-slate-500">{{ detail.label }}</dt>
                            <dd class="mt-0.5 break-words text-sm font-medium text-slate-800 dark:text-white">
                                <a v-if="detail.href" :href="detail.href" class="text-primary-600 hover:underline">{{ detail.value }}</a>
                                <span v-else>{{ detail.value || '—' }}</span>
                            </dd>
                        </div>
                    </div>
                </dl>
            </section>

            <section v-if="supplier.archived" class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-200">
                <Icon name="archive" class="mt-0.5 text-lg" />
                <p>Ce fournisseur est archivé<span v-if="supplier.delete_reason"> — motif : « {{ supplier.delete_reason }} »</span>. Il n’est plus proposé pour les commandes ni les entrées de stock ; son dossier reste consultable.</p>
            </section>

            <section>
                <h2 class="mb-2 px-1 text-sm font-semibold text-slate-500">Contenu du dossier</h2>
                <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-900 dark:bg-gray-1000/40">
                    <div v-if="folders.length" class="grid grid-cols-2 gap-1 sm:grid-cols-4">
                        <FolderCard
                            v-for="folder in folders"
                            :key="folder.href"
                            :href="folder.href"
                            :title="folder.title"
                            :meta="folder.meta"
                            :count="folder.count"
                            :tone="folder.tone"
                        />
                    </div>
                    <p v-else class="px-3 py-8 text-center text-sm text-slate-500">Votre compte ne permet d’ouvrir aucun élément de ce dossier.</p>
                </div>
                <p class="mt-2 px-1 text-xs text-slate-500">Les commandes, réceptions et factures se gèrent à la pharmacie de {{ targetSite.name }} ; elles sont ici en consultation.</p>
            </section>
        </template>

        <div v-if="archiving" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="archiving = false">
            <form class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="archive-supplier-title" @submit.prevent="confirmArchive">
                <h2 id="archive-supplier-title" class="font-heading text-lg font-bold text-slate-800 dark:text-white">Archiver {{ supplier.name }}</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">Il ne sera plus proposé pour les commandes ni les entrées de stock. Ses catalogues, prix, lots et factures sont conservés, et il pourra être restauré. Impossible tant qu’une commande est en brouillon ou attend une réception.</p>
                <label class="block">
                    <span :class="labelClass">Motif <span class="text-red-500">*</span></span>
                    <textarea v-model="archiveForm.reason" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Ex. : fournisseur n’exerce plus" required></textarea>
                </label>
                <p v-if="archiveForm.errors.reason || archiveForm.errors.site" class="text-sm text-red-600">{{ archiveForm.errors.reason || archiveForm.errors.site }}</p>
                <div class="flex justify-end gap-2">
                    <Button type="button" size="rg" variant="white-outline" @click="archiving = false">Retour</Button>
                    <Button type="submit" size="rg" :disabled="archiveForm.processing || archiveForm.reason.trim().length < 3" class="bg-red-600 hover:bg-red-700"><Icon name="archive" /><span class="ms-2">Archiver</span></Button>
                </div>
            </form>
        </div>
    </div>
</template>
