<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HrNav from '../Partials/HrNav.vue';
import HrPageHeader from '../Partials/HrPageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({ columns: Array, referenceValues: Object, limits: Object });
const { can } = usePermissions();
const form = useForm({ file: null });
const fileInput = ref(null);
const isDragging = ref(false);
const requiredColumns = computed(() => props.columns.filter((column) => column.required));
const optionalColumns = computed(() => props.columns.filter((column) => !column.required));
const formattedSize = computed(() => {
    if (!form.file) return '';
    if (form.file.size < 1024 * 1024) return `${Math.max(1, Math.round(form.file.size / 1024))} Ko`;
    return `${(form.file.size / 1024 / 1024).toFixed(1)} Mo`;
});

const setFile = (file) => {
    form.clearErrors('file');
    if (!file) {
        form.file = null;
        return;
    }
    const extension = file.name.split('.').pop()?.toLowerCase();
    if (!['xlsx', 'xls', 'csv'].includes(extension)) {
        form.setError('file', 'Choisissez un fichier Excel (.xlsx, .xls) ou CSV (.csv).');
        form.file = null;
        return;
    }
    if (file.size > props.limits.megabytes * 1024 * 1024) {
        form.setError('file', `Le fichier dépasse la limite de ${props.limits.megabytes} Mo.`);
        form.file = null;
        return;
    }
    form.file = file;
};
const onDrop = (event) => {
    isDragging.value = false;
    setFile(event.dataTransfer?.files?.[0] ?? null);
};
const clearFile = () => {
    form.file = null;
    if (fileInput.value) fileInput.value.value = '';
};
const openFilePicker = () => fileInput.value?.click();
const submit = () => form.post('/administration/employees/import', { forceFormData: true });
</script>

<template>
    <Head title="Importer des employés" />
    <div class="w-full space-y-5">
        <HrNav />
        <HrPageHeader eyebrow="Import contrôlé · Création uniquement" title="Importer des employés" description="Préparez le fichier avec le modèle officiel, vérifiez les valeurs puis lancez une création atomique : si une ligne est invalide, aucune ligne n’est enregistrée." icon="upload-cloud" tone="sky">
            <template #actions>
                <Button v-if="can('employees.export')" as="a" href="/administration/employees/export" size="rg" variant="white-outline"><Icon name="download" /><span class="ms-2">Exporter l’existant</span></Button>
                <Button :as="Link" href="/administration/employees" size="rg" variant="white-outline"><Icon name="arrow-left" /><span class="ms-2">Retour</span></Button>
            </template>
        </HrPageHeader>

        <ol class="grid overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 md:grid-cols-3" aria-label="Étapes de l’import">
            <li v-for="(step, index) in [{title:'Télécharger le modèle',hint:'Structure officielle .xlsx',icon:'download'},{title:'Compléter et vérifier',hint:'Libellés et formats exacts',icon:'edit'},{title:'Déposer puis importer',hint:'Contrôle atomique du fichier',icon:'upload'}]" :key="step.title" :class="['flex items-center gap-3 px-4 py-4', index > 0 ? 'border-t border-gray-200 dark:border-gray-900 md:border-s md:border-t-0' : '']"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white">{{ index + 1 }}</span><span class="min-w-0"><strong class="block text-sm text-slate-700 dark:text-white">{{ step.title }}</strong><span class="mt-0.5 block text-xs text-slate-400">{{ step.hint }}</span></span></li>
        </ol>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
            <main class="space-y-5">
                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <div class="flex flex-col gap-4 border-b border-gray-200 p-5 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-lg text-sky-600 dark:bg-sky-950 dark:text-sky-300"><Icon name="file-docs" /></span><div><h2 class="text-sm font-bold text-slate-800 dark:text-white">1. Utiliser le modèle officiel</h2><p class="mt-1 text-xs leading-5 text-slate-500">Conservez les en-têtes de la première ligne et saisissez un employé par ligne.</p></div></div>
                        <Button as="a" href="/administration/employees/import-template" size="rg"><Icon name="download" /><span class="ms-2">Télécharger le modèle</span></Button>
                    </div>
                    <div class="p-5">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900 dark:bg-emerald-950/20"><p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Colonnes obligatoires</p><div class="mt-3 flex flex-wrap gap-2"><span v-for="column in requiredColumns" :key="column.name" class="rounded-md border border-emerald-200 bg-white px-2.5 py-1.5 text-xs font-bold text-emerald-700 dark:border-emerald-900 dark:bg-gray-950 dark:text-emerald-300">{{ column.name }} *</span></div></div>
                        <details class="group mt-4 rounded-lg border border-gray-200 dark:border-gray-800">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold text-slate-700 dark:text-white">Voir les {{ columns.length }} colonnes et formats <Icon class="text-slate-400 transition group-open:rotate-180" name="chevron-down" /></summary>
                            <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-800"><table class="w-full min-w-[620px]"><thead class="bg-gray-50/70 dark:bg-gray-1000/40"><tr><th class="px-4 py-2.5 text-start text-[11px] font-bold uppercase text-slate-400">Colonne</th><th class="px-4 py-2.5 text-start text-[11px] font-bold uppercase text-slate-400">Obligatoire</th><th class="px-4 py-2.5 text-start text-[11px] font-bold uppercase text-slate-400">Format accepté</th></tr></thead><tbody><tr v-for="column in columns" :key="column.name" class="border-t border-gray-100 dark:border-gray-900"><td class="px-4 py-2.5 text-sm font-semibold text-slate-700 dark:text-white">{{ column.name }}</td><td class="px-4 py-2.5"><span :class="['rounded-full px-2 py-1 text-[11px] font-bold', column.required ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-gray-100 text-slate-500 dark:bg-gray-900']">{{ column.required ? 'Oui' : 'Non' }}</span></td><td class="px-4 py-2.5 text-xs text-slate-500">{{ column.format }}</td></tr></tbody></table></div>
                        </details>
                    </div>
                </section>

                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
                    <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-800 dark:text-white">2. Déposer le fichier préparé</h2><p class="mt-1 text-xs text-slate-500">Excel ou CSV · {{ limits.rows }} lignes maximum · {{ limits.megabytes }} Mo maximum.</p></div>
                    <form class="p-5" @submit.prevent="submit">
                        <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="setFile($event.target.files?.[0] ?? null)">
                        <div
                            v-if="!form.file"
                            :class="['rounded-xl border-2 border-dashed px-6 py-12 text-center transition', isDragging ? 'border-primary-400 bg-primary-50/70 dark:bg-primary-950/20' : form.errors.file ? 'border-red-300 bg-red-50/50 dark:border-red-900 dark:bg-red-950/20' : 'border-gray-300 bg-gray-50/50 hover:border-primary-300 dark:border-gray-800 dark:bg-gray-1000/20']"
                            @dragenter.prevent="isDragging = true" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="onDrop"
                        >
                            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-white text-2xl text-primary-600 shadow-sm dark:bg-gray-950"><Icon name="upload-cloud" /></span><p class="mt-4 text-sm font-bold text-slate-700 dark:text-white">Glissez le fichier ici</p><p class="mt-1 text-xs text-slate-400">ou sélectionnez-le depuis votre appareil</p><Button type="button" class="mt-4" size="rg" variant="white-outline" @click="openFilePicker">Choisir un fichier</Button>
                        </div>
                        <div v-else class="flex flex-col gap-4 rounded-xl border border-emerald-200 bg-emerald-50/60 p-5 dark:border-emerald-900 dark:bg-emerald-950/20 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-xl text-emerald-600 shadow-sm dark:bg-gray-950"><Icon name="file-check" /></span><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ form.file.name }}</p><p class="mt-1 text-xs text-slate-400">{{ formattedSize }} · prêt à être contrôlé</p></div></div><Button type="button" size="sm" variant="white-outline" @click="clearFile"><Icon name="cross" /><span class="ms-1.5">Retirer</span></Button>
                        </div>
                        <p v-if="form.errors.file" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm leading-6 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">{{ form.errors.file }}</p>
                        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between"><p class="text-xs leading-5 text-slate-400"><Icon class="me-1" name="shield-check" /> Aucun employé existant ne sera modifié ou réactivé.</p><Button size="rg" :disabled="form.processing || !form.file"><Icon name="upload" /><span class="ms-2">{{ form.processing ? 'Contrôle et importation…' : 'Contrôler et importer' }}</span></Button></div>
                    </form>
                </section>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20"><div class="flex gap-3"><Icon class="mt-0.5 shrink-0 text-lg text-amber-600" name="alert-circle" /><div><h2 class="text-sm font-bold text-amber-800 dark:text-amber-300">Import atomique</h2><p class="mt-1 text-xs leading-5 text-amber-700 dark:text-amber-400">Une erreur de format, un matricule déjà utilisé ou un référentiel inconnu annule le fichier entier. Corrigez le fichier puis relancez-le.</p></div></div></section>
                <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/20"><div class="flex gap-3"><Icon class="mt-0.5 shrink-0 text-lg text-emerald-600" name="check-circle" /><div><h2 class="text-sm font-bold text-emerald-800 dark:text-emerald-300">Données automatisées</h2><p class="mt-1 text-xs leading-5 text-emerald-700 dark:text-emerald-400">La civilité est calculée depuis le genre. La fonction choisie synchronise le libellé historique. Si un type de contrat et une date d’entrée sont présents, le contrat initial est créé dans la même transaction.</p></div></div></section>
                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950"><div class="border-b border-gray-200 px-4 py-3 dark:border-gray-900"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Valeurs configurées</h2><p class="mt-1 text-xs text-slate-400">Recopiez exactement ces libellés dans le fichier.</p></div><div class="divide-y divide-gray-100 dark:divide-gray-900"><details v-for="group in [{key:'departments',label:'Départements'},{key:'jobTitles',label:'Fonctions'},{key:'contractTypes',label:'Types de contrat'}]" :key="group.key" class="group"><summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-slate-600 dark:text-slate-300"><span>{{ group.label }} · {{ referenceValues[group.key]?.length ?? 0 }}</span><Icon class="text-slate-400 transition group-open:rotate-180" name="chevron-down" /></summary><div class="flex flex-wrap gap-1.5 px-4 pb-4"><span v-for="item in referenceValues[group.key]" :key="item.uuid" class="rounded-md bg-gray-100 px-2 py-1 text-xs text-slate-600 dark:bg-gray-900 dark:text-slate-300">{{ item.label }}</span><span v-if="!referenceValues[group.key]?.length" class="text-xs text-slate-400">Aucune valeur active.</span></div></details></div><Link v-if="can('hr_settings.view')" href="/administration/settings" class="flex items-center justify-between border-t border-gray-200 px-4 py-3 text-xs font-bold text-primary-600 hover:bg-primary-50 dark:border-gray-900 dark:hover:bg-primary-950/20">Configurer les référentiels <Icon name="arrow-right" /></Link></section>
                <section class="rounded-xl border border-gray-200 bg-white p-4 text-xs leading-5 text-slate-500 shadow-sm dark:border-gray-900 dark:bg-gray-950"><strong class="text-slate-700 dark:text-white">Conseil :</strong> commencez avec quelques lignes, vérifiez le résultat dans l’annuaire puis importez le reste du fichier.</section>
            </aside>
        </div>
    </div>
</template>
