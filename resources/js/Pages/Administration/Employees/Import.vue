<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import {
    ArrowLeft, ArrowRight, ChevronDown, CircleAlert, CircleCheck, Download, FileCheck2, FileSpreadsheet, Pencil, ShieldCheck, Upload, UploadCloud, X,
} from 'lucide-vue-next';
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
const submit = () => form.post(hrUrl('/administration/employees/import'), { forceFormData: true });

const steps = [
    { title: 'Télécharger le modèle', hint: 'Structure officielle .xlsx', icon: Download },
    { title: 'Compléter et vérifier', hint: 'Libellés et formats exacts', icon: Pencil },
    { title: 'Déposer puis importer', hint: 'Contrôle de tout le fichier', icon: Upload },
];
// ADR-188 — départements et fonctions ont leur module ; les types de contrat restent aux paramètres RH.
const referenceGroups = [
    { key: 'departments', label: 'Départements', href: hrUrl('/administration/departments') },
    { key: 'jobTitles', label: 'Fonctions', href: hrUrl('/administration/job-titles') },
    { key: 'contractTypes', label: 'Types de contrat', href: hrUrl('/administration/settings') },
];
</script>

<template>
    <Head title="Importer des employés" />
    <div class="w-full space-y-5">
        <PageHeader eyebrow="Import contrôlé · Création uniquement" title="Importer des employés" description="Préparez le fichier avec le modèle officiel, vérifiez les valeurs puis importez : si une seule ligne est invalide, aucune n’est enregistrée." :icon="UploadCloud" tone="sky">
            <template #actions>
                <Button v-if="can('employees.export')" as="a" :href="hrUrl('/administration/employees/export')" variant="outline"><Download class="h-4 w-4" />Exporter l’existant</Button>
                <Button :as="Link" :href="hrUrl('/administration/employees')" variant="outline"><ArrowLeft class="h-4 w-4" />Retour</Button>
            </template>
        </PageHeader>

        <ol class="grid overflow-hidden rounded-xl border border-border bg-card shadow-sm md:grid-cols-3" aria-label="Étapes de l’import">
            <li v-for="(step, index) in steps" :key="step.title" :class="['flex items-center gap-3 px-4 py-4', index > 0 && 'border-t border-border md:border-s md:border-t-0']">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">{{ index + 1 }}</span>
                <span class="min-w-0">
                    <strong class="flex items-center gap-1.5 text-sm text-foreground"><component :is="step.icon" class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />{{ step.title }}</strong>
                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ step.hint }}</span>
                </span>
            </li>
        </ol>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
            <main class="space-y-5">
                <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-border p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300"><FileSpreadsheet class="h-5 w-5" /></span>
                            <div>
                                <h2 class="text-sm font-bold text-foreground">1. Utiliser le modèle officiel</h2>
                                <p class="mt-1 text-xs leading-5 text-muted-foreground">Gardez les en-têtes de la première ligne et saisissez un employé par ligne.</p>
                            </div>
                        </div>
                        <Button as="a" :href="hrUrl('/administration/employees/import-template')"><Download class="h-4 w-4" />Télécharger le modèle</Button>
                    </div>
                    <div class="p-5">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
                            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Colonnes obligatoires</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span v-for="column in requiredColumns" :key="column.name" class="rounded-md border border-emerald-200 bg-card px-2.5 py-1.5 text-xs font-bold text-emerald-700 dark:border-emerald-900 dark:text-emerald-300">{{ column.name }} *</span>
                            </div>
                        </div>
                        <details class="group mt-4 rounded-lg border border-border">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold text-foreground">
                                Voir les {{ columns.length }} colonnes et leurs formats
                                <ChevronDown class="h-4 w-4 text-muted-foreground transition group-open:rotate-180" />
                            </summary>
                            <div class="overflow-x-auto border-t border-border">
                                <table class="w-full min-w-[620px] text-sm">
                                    <thead class="bg-muted/50 text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <tr><th class="px-4 py-2.5 text-start font-bold">Colonne</th><th class="px-4 py-2.5 text-start font-bold">Obligatoire</th><th class="px-4 py-2.5 text-start font-bold">Format accepté</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="column in columns" :key="column.name" class="border-t border-border/70">
                                            <td class="px-4 py-2.5 font-semibold text-foreground">{{ column.name }}</td>
                                            <td class="px-4 py-2.5"><Badge :variant="column.required ? 'success' : 'secondary'">{{ column.required ? 'Oui' : 'Non' }}</Badge></td>
                                            <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ column.format }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                </section>

                <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div class="border-b border-border px-5 py-4">
                        <h2 class="text-sm font-bold text-foreground">2. Déposer le fichier préparé</h2>
                        <p class="mt-1 text-xs text-muted-foreground">Excel ou CSV · {{ limits.rows }} lignes au plus · {{ limits.megabytes }} Mo au plus.</p>
                    </div>
                    <form class="p-5" @submit.prevent="submit">
                        <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="setFile($event.target.files?.[0] ?? null)">
                        <div
                            v-if="!form.file"
                            :class="['rounded-xl border-2 border-dashed px-6 py-12 text-center transition', isDragging ? 'border-primary bg-primary/5' : form.errors.file ? 'border-destructive/50 bg-destructive/5' : 'border-border bg-muted/30 hover:border-primary/40']"
                            @dragenter.prevent="isDragging = true" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="onDrop"
                        >
                            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-card text-primary shadow-sm"><UploadCloud class="h-6 w-6" /></span>
                            <p class="mt-4 text-sm font-bold text-foreground">Glissez le fichier ici</p>
                            <p class="mt-1 text-xs text-muted-foreground">ou choisissez-le sur cet appareil</p>
                            <Button type="button" class="mt-4" variant="outline" @click="openFilePicker">Choisir un fichier</Button>
                        </div>
                        <div v-else class="flex flex-col gap-4 rounded-xl border border-emerald-200 bg-emerald-50/60 p-5 dark:border-emerald-900 dark:bg-emerald-950/20 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-card text-emerald-600 shadow-sm"><FileCheck2 class="h-5 w-5" /></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-foreground">{{ form.file.name }}</p>
                                    <p class="mt-1 text-xs text-muted-foreground">{{ formattedSize }} · prêt à être contrôlé</p>
                                </div>
                            </div>
                            <Button type="button" size="sm" variant="outline" @click="clearFile"><X class="h-4 w-4" />Retirer</Button>
                        </div>
                        <p v-if="form.errors.file" class="mt-3 whitespace-pre-line rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm leading-6 text-destructive">{{ form.errors.file }}</p>
                        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p class="flex items-center gap-1.5 text-xs leading-5 text-muted-foreground"><ShieldCheck class="h-4 w-4" />Aucun employé existant n’est modifié ni réactivé.</p>
                            <Button type="submit" :disabled="form.processing || !form.file"><Upload class="h-4 w-4" />{{ form.processing ? 'Contrôle et importation…' : 'Contrôler et importer' }}</Button>
                        </div>
                    </form>
                </section>
            </main>

            <aside class="space-y-4 xl:sticky xl:top-4">
                <section class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20">
                    <CircleAlert class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                    <div>
                        <h2 class="text-sm font-bold text-amber-800 dark:text-amber-300">Tout ou rien</h2>
                        <p class="mt-1 text-xs leading-5 text-amber-700 dark:text-amber-400">Une erreur de format, un matricule déjà utilisé ou un libellé inconnu annule tout le fichier. Corrigez-le puis relancez.</p>
                    </div>
                </section>
                <section class="flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
                    <CircleCheck class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                    <div>
                        <h2 class="text-sm font-bold text-emerald-800 dark:text-emerald-300">Ce que RIVO complète</h2>
                        <ul class="mt-1 list-disc space-y-1 ps-4 text-xs leading-5 text-emerald-700 dark:text-emerald-400">
                            <li>Matricule vide : celui de la clinique quand le genre et les dates le permettent, sinon le suivant du modèle.</li>
                            <li>Civilité tirée du genre, genre lu dans la lettre H/F du matricule s’il manque.</li>
                            <li>Type de contrat et date d’entrée présents : le contrat initial est créé en même temps.</li>
                            <li>L’email n’est pas importé : c’est l’adresse professionnelle, créée avec l’accès.</li>
                        </ul>
                    </div>
                </section>
                <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div class="border-b border-border px-4 py-3">
                        <h2 class="text-sm font-bold text-foreground">Valeurs configurées</h2>
                        <p class="mt-1 text-xs text-muted-foreground">Recopiez exactement ces libellés dans le fichier.</p>
                    </div>
                    <div class="divide-y divide-border">
                        <details v-for="group in referenceGroups" :key="group.key" class="group">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-semibold text-foreground">
                                <span>{{ group.label }} · {{ referenceValues[group.key]?.length ?? 0 }}</span>
                                <ChevronDown class="h-4 w-4 text-muted-foreground transition group-open:rotate-180" />
                            </summary>
                            <div class="flex flex-wrap gap-1.5 px-4 pb-3">
                                <span v-for="item in referenceValues[group.key]" :key="item.uuid" class="rounded-md bg-muted px-2 py-1 text-xs text-foreground">{{ item.label }}</span>
                                <span v-if="!referenceValues[group.key]?.length" class="text-xs text-muted-foreground">Aucune valeur active.</span>
                            </div>
                            <Link v-if="can('hr_settings.view')" :href="group.href" class="flex items-center gap-1 px-4 pb-3 text-xs font-semibold text-primary hover:underline">Gérer les {{ group.label.toLowerCase() }}<ArrowRight class="h-3.5 w-3.5" /></Link>
                        </details>
                    </div>
                </section>
                <p class="rounded-xl border border-border bg-card p-4 text-xs leading-5 text-muted-foreground shadow-sm"><strong class="text-foreground">Conseil :</strong> commencez par quelques lignes, vérifiez le résultat dans la liste, puis importez le reste.</p>
            </aside>
        </div>
    </div>
</template>
