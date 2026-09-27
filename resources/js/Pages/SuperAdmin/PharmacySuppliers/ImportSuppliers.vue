<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Check, FileSpreadsheet, TriangleAlert } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

const props = defineProps({
    token: { type: String, required: true },
    targetSite: { type: Object, required: true },
    fileName: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    skippedArchived: { type: Number, default: 0 },
});

const ACTIONS = {
    CREATE: { label: 'À créer', tone: 'success' },
    UPDATE: { label: 'À mettre à jour', tone: 'info' },
    UNCHANGED: { label: 'Inchangé', tone: 'neutral' },
    ERROR: { label: 'À corriger', tone: 'danger' },
};

const tiles = computed(() => [
    { key: 'CREATE', value: props.summary.create ?? 0, text: 'fournisseur(s) à créer' },
    { key: 'UPDATE', value: props.summary.update ?? 0, text: 'à mettre à jour' },
    { key: 'UNCHANGED', value: props.summary.unchanged ?? 0, text: 'déjà à jour' },
    { key: 'ERROR', value: props.summary.error ?? 0, text: 'ligne(s) à corriger' },
]);

const filter = ref((props.summary.error ?? 0) > 0 ? 'ERROR' : 'ALL');
const shown = computed(() => (filter.value === 'ALL' ? props.rows : props.rows.filter((row) => row.action === filter.value)));
const toWrite = computed(() => (props.summary.create ?? 0) + (props.summary.update ?? 0));
const hasErrors = computed(() => (props.summary.error ?? 0) > 0);
const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);

const form = useForm({});
const confirmImport = () => form.post(`/super-admin/pharmacy-suppliers/import/${props.token}`);
const confirmError = computed(() => form.errors.rows ?? form.errors.site ?? form.errors.file ?? null);
</script>

<template>
    <Head title="Vérifier l’import des fournisseurs" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[
            { label: 'Fournisseurs pharmacie', href: listHref },
            { label: targetSite.name, href: listHref },
            { label: 'Vérifier l’import' },
        ]" />

        <div class="flex items-start gap-3">
            <FileSpreadsheet class="text-emerald-500 h-9 w-9" />
            <div class="min-w-0">
                <h1 class="font-heading text-2xl font-bold text-foreground">Vérifier « {{ fileName }} »</h1>
                <p class="mt-1 text-sm text-muted-foreground">Analyse faite par le site {{ targetSite.name }}. <strong>Rien n’est encore enregistré</strong> : contrôlez les lignes, puis confirmez.</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <button
                v-for="tile in tiles"
                :key="tile.key"
                type="button"
                :class="['rounded-xl border bg-card p-4 text-start transition ', filter === tile.key ? 'border-primary ring-2 ring-ring/25' : 'border-border hover:border-primary/40 ']"
                @click="filter = filter === tile.key ? 'ALL' : tile.key"
            >
                <p :class="['text-3xl font-bold', tile.key === 'ERROR' && tile.value ? 'text-red-600' : 'text-foreground']">{{ tile.value }}</p>
                <p class="text-sm text-muted-foreground">{{ tile.text }}</p>
            </button>
        </div>

        <p v-if="skippedArchived" class="text-sm text-muted-foreground">{{ skippedArchived }} ligne(s) marquée(s) « Archivé » ignorée(s) : un fournisseur archivé se restaure depuis son dossier.</p>

        <section v-if="hasErrors" class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5" />
            <p>Corrigez ces lignes dans votre fichier Excel, puis importez-le à nouveau. <strong>L’import est tout ou rien</strong> : tant qu’une ligne est incorrecte, aucun fournisseur n’est enregistré.</p>
        </section>

        <section class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <h2 class="text-sm font-bold text-foreground">{{ filter === 'ALL' ? 'Toutes les lignes' : ACTIONS[filter].label }} · {{ shown.length }}</h2>
                <button v-if="filter !== 'ALL'" type="button" class="text-xs font-bold text-primary" @click="filter = 'ALL'">Tout afficher</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-muted text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 text-start font-semibold">Ligne</th>
                            <th class="px-4 py-2 text-start font-semibold">Fournisseur</th>
                            <th class="px-4 py-2 text-start font-semibold">Résultat</th>
                            <th class="px-4 py-2 text-start font-semibold">Détail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in shown" :key="`${row.line}-${row.code}`" :class="row.action === 'ERROR' ? 'bg-red-50/50 dark:bg-red-950/10' : ''">
                            <td class="px-4 py-3 align-top text-muted-foreground">{{ row.line }}</td>
                            <td class="px-4 py-3 align-top">
                                <p class="font-semibold text-foreground">{{ row.name || row.current_name || '—' }}</p>
                                <p class="font-mono text-xs text-muted-foreground">{{ row.code || 'Code manquant' }}</p>
                            </td>
                            <td class="px-4 py-3 align-top"><Badge :tone="ACTIONS[row.action].tone">{{ ACTIONS[row.action].label }}</Badge></td>
                            <td class="px-4 py-3 align-top text-muted-foreground">
                                <ul v-if="row.action === 'ERROR'" class="space-y-0.5 text-red-700 dark:text-red-300">
                                    <li v-for="error in row.errors" :key="error">{{ error }}</li>
                                </ul>
                                <ul v-else-if="row.action === 'UPDATE'" class="space-y-0.5">
                                    <li v-for="change in row.changes" :key="change.field">
                                        <span class="font-medium">{{ change.label }}</span> :
                                        <span class="text-muted-foreground line-through">{{ change.from || 'vide' }}</span>
                                        → <span class="font-medium text-foreground">{{ change.to }}</span>
                                    </li>
                                </ul>
                                <span v-else-if="row.action === 'CREATE'">{{ [row.contact_name, row.phone, row.email].filter(Boolean).join(' · ') || 'Nouveau dossier fournisseur' }}</span>
                                <span v-else class="text-muted-foreground">Aucune différence</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
            <p v-if="confirmError" class="text-sm text-red-600 sm:me-auto">{{ confirmError }}</p>
            <Button :as="Link" :href="listHref" size="rg" variant="white-outline">Annuler</Button>
            <Button size="rg" type="button" :disabled="hasErrors || toWrite === 0 || form.processing" @click="confirmImport">
                <Check class="h-4 w-4" />
                {{ form.processing ? 'Importation…' : (toWrite === 0 ? 'Rien à importer' : `Confirmer l’import (${toWrite})`) }}
            </Button>
        </div>
    </div>
</template>
