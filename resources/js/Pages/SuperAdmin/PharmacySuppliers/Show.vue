<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FolderCard from '@/Components/UI/FolderCard.vue';
import { Archive, Check, Folder, Mail, MapPin, Pencil, Phone, RotateCcw, TriangleAlert, User } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
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
    { label: 'Personne à contacter', value: props.supplier?.contact_name, icon: User },
    { label: 'Téléphone', value: props.supplier?.phone, icon: Phone, href: props.supplier?.phone ? `tel:${props.supplier.phone}` : null },
    { label: 'E-mail', value: props.supplier?.email, icon: Mail, href: props.supplier?.email ? `mailto:${props.supplier.email}` : null },
    { label: 'Adresse', value: props.supplier?.address, icon: MapPin },
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

const inputClass = 'h-11 w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/25 ';
const labelClass = 'mb-1.5 block text-sm font-medium text-foreground';
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
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" />
            <p>{{ error }}</p>
        </section>

        <template v-else-if="supplier">
            <section class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <Folder :class="cn('h-11 w-11 shrink-0', supplier.archived ? 'text-muted-foreground' : 'fill-amber-200 text-amber-500 dark:fill-amber-500/20')" />
                        <div class="min-w-0">
                            <h1 class="flex flex-wrap items-center gap-2 font-heading text-2xl font-bold text-foreground">
                                {{ supplier.name }}
                                <Badge v-if="supplier.archived" tone="neutral">Archivé</Badge>
                            </h1>
                            <p class="font-mono text-xs text-muted-foreground">{{ supplier.code }} <span class="font-sans">· {{ targetSite.name }}</span></p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="!supplier.archived">
                            <Button v-if="can.update_supplier && !editing" size="rg" variant="white-outline" type="button" @click="startEdit"><Pencil class="h-4 w-4" />Modifier</Button>
                            <Button v-if="can.archive_supplier" size="rg" variant="white-outline" type="button" class="text-red-600" @click="archiving = true"><Archive class="h-4 w-4" />Archiver</Button>
                        </template>
                        <Button v-else-if="can.restore_supplier" size="rg" type="button" @click="restore"><RotateCcw class="h-4 w-4" />Restaurer</Button>
                    </div>
                </div>

                <form v-if="editing" class="mt-5 space-y-4 border-t border-border pt-5" @submit.prevent="saveEdit">
                    <ValidationErrorSummary :errors="editForm.errors" />
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="block"><span :class="labelClass">Code</span><input :value="supplier.code" :class="[inputClass, 'bg-muted text-muted-foreground ']" disabled><span class="mt-1 block text-xs text-muted-foreground">Le code ne change pas : il identifie le fournisseur dans les imports.</span></label>
                        <label class="block"><span :class="labelClass">Nom <span class="text-red-500">*</span></span><input v-model="editForm.name" :class="inputClass" required></label>
                        <label class="block"><span :class="labelClass">Personne à contacter</span><input v-model="editForm.contact_name" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">Téléphone</span><input v-model="editForm.phone" type="tel" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">E-mail</span><input v-model="editForm.email" type="email" :class="inputClass"></label>
                        <label class="block"><span :class="labelClass">Adresse</span><input v-model="editForm.address" :class="inputClass"></label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" size="rg" variant="white-outline" @click="editing = false">Annuler</Button>
                        <Button type="submit" size="rg" :disabled="editForm.processing || !editForm.isDirty"><Check class="h-4 w-4" />Enregistrer</Button>
                    </div>
                </form>

                <dl v-else class="mt-5 grid gap-3 border-t border-border pt-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="detail in details" :key="detail.label" class="flex items-start gap-2.5">
                        <component :is="detail.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">{{ detail.label }}</dt>
                            <dd class="mt-0.5 break-words text-sm font-medium text-foreground">
                                <a v-if="detail.href" :href="detail.href" class="text-primary hover:underline">{{ detail.value }}</a>
                                <span v-else>{{ detail.value || '—' }}</span>
                            </dd>
                        </div>
                    </div>
                </dl>
            </section>

            <section v-if="supplier.archived" class="flex items-start gap-3 rounded-xl border border-border bg-muted px-4 py-3 text-sm text-foreground">
                <Archive class="mt-0.5 h-4.5 w-4.5" />
                <p>Ce fournisseur est archivé<span v-if="supplier.delete_reason"> — motif : « {{ supplier.delete_reason }} »</span>. Il n’est plus proposé pour les commandes ni les entrées de stock ; son dossier reste consultable.</p>
            </section>

            <section>
                <h2 class="mb-2 px-1 text-sm font-semibold text-muted-foreground">Contenu du dossier</h2>
                <div class="rounded-xl border border-border bg-muted/60 p-3 /40">
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
                    <p v-else class="px-3 py-8 text-center text-sm text-muted-foreground">Votre compte ne permet d’ouvrir aucun élément de ce dossier.</p>
                </div>
                <p class="mt-2 px-1 text-xs text-muted-foreground">Les commandes, réceptions et factures se gèrent à la pharmacie de {{ targetSite.name }} ; elles sont ici en consultation.</p>
            </section>
        </template>

        <div v-if="archiving" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="archiving = false">
            <form class="w-full max-w-lg space-y-4 rounded-2xl bg-card p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="archive-supplier-title" @submit.prevent="confirmArchive">
                <h2 id="archive-supplier-title" class="font-heading text-lg font-bold text-foreground">Archiver {{ supplier.name }}</h2>
                <p class="text-sm text-muted-foreground">Il ne sera plus proposé pour les commandes ni les entrées de stock. Ses catalogues, prix, lots et factures sont conservés, et il pourra être restauré. Impossible tant qu’une commande est en brouillon ou attend une réception.</p>
                <label class="block">
                    <span :class="labelClass">Motif <span class="text-red-500">*</span></span>
                    <textarea v-model="archiveForm.reason" rows="3" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/25" placeholder="Ex. : fournisseur n’exerce plus" required></textarea>
                </label>
                <p v-if="archiveForm.errors.reason || archiveForm.errors.site" class="text-sm text-red-600">{{ archiveForm.errors.reason || archiveForm.errors.site }}</p>
                <div class="flex justify-end gap-2">
                    <Button type="button" size="rg" variant="white-outline" @click="archiving = false">Retour</Button>
                    <Button type="submit" size="rg" :disabled="archiveForm.processing || archiveForm.reason.trim().length < 3" class="bg-red-600 hover:bg-red-700"><Archive class="h-4 w-4" />Archiver</Button>
                </div>
            </form>
        </div>
    </div>
</template>
