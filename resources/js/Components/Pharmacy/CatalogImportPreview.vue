<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Check, CircleAlert, Info, TriangleAlert } from 'lucide-vue-next';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — what an Excel supplier catalog contains, checked before anything
 * is written. Shared by the clinic folder and the central portal; the rows
 * and their errors come from SupplierCatalogImportService::preview().
 */
const props = defineProps({
    catalog: { type: Object, required: true },
    preview: { type: Object, required: true },
    importUrl: { type: String, required: true },
    cancelHref: { type: String, required: true },
});

const onlyErrors = ref(props.preview.invalid_count > 0);
const rows = computed(() => (onlyErrors.value
    ? props.preview.rows.filter((row) => row.errors.length)
    : props.preview.rows));
const readable = computed(() => !props.preview.error && !props.preview.missing_headers.length);
const canConfirm = computed(() => readable.value && props.preview.rows.length > 0 && props.preview.invalid_count === 0);

const processing = ref(false);
const confirmImport = () => router.post(props.importUrl, {}, {
    onStart: () => { processing.value = true; },
    onFinish: () => { processing.value = false; },
});
</script>

<template>
    <div class="space-y-5">
        <section v-if="!readable" class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950/20">
            <h2 class="flex items-center gap-2 font-heading text-base font-bold text-red-800 dark:text-red-200"><CircleAlert class="h-4 w-4" />Ce fichier ne peut pas être lu</h2>
            <p v-if="preview.error" class="mt-2 text-sm text-red-800 dark:text-red-200">{{ preview.error }}</p>
            <p v-if="preview.missing_headers.length" class="mt-2 text-sm text-red-800 dark:text-red-200">
                La première ligne du fichier doit contenir ces colonnes :
                <strong>{{ preview.expected_headers.join(', ') }}</strong>.
                Colonnes manquantes : <strong>{{ preview.missing_headers.join(', ') }}</strong>.
            </p>
            <Button :as="Link" :href="cancelHref" size="rg" variant="white-outline" class="mt-4">Retour aux catalogues</Button>
        </section>

        <template v-else>
            <section class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-border bg-card px-5 py-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Lignes lues</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-foreground">{{ preview.rows.length }}</p>
                </div>
                <div class="rounded-xl border border-border bg-card px-5 py-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Prêtes à être importées</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600">{{ preview.valid_count }}</p>
                </div>
                <div class="rounded-xl border border-border bg-card px-5 py-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">À corriger dans le fichier</p>
                    <p :class="['mt-1 text-2xl font-bold tabular-nums', preview.invalid_count ? 'text-red-600' : 'text-muted-foreground']">{{ preview.invalid_count }}</p>
                </div>
            </section>

            <div v-if="preview.invalid_count" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                <TriangleAlert class="mt-0.5 h-4.5 w-4.5" />
                <p>Corrigez ces lignes dans le fichier Excel, puis importez-le à nouveau. Tant qu’une seule ligne est incorrecte, rien n’est enregistré.</p>
            </div>
            <div v-else-if="catalog.is_imported" class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-100">
                <Info class="mt-0.5 h-4.5 w-4.5" />
                <p>Les {{ catalog.items_count }} lignes déjà lues de ce catalogue seront remplacées par celles-ci. Les prix fournisseurs déjà enregistrés restent dans l’historique.</p>
            </div>

            <section class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div v-if="preview.invalid_count" class="flex gap-1.5 border-b border-border p-4">
                    <button type="button" :class="['rounded-full border px-3.5 py-1.5 text-sm font-semibold', onlyErrors ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground ']" @click="onlyErrors = true">Lignes à corriger</button>
                    <button type="button" :class="['rounded-full border px-3.5 py-1.5 text-sm font-semibold', !onlyErrors ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground ']" @click="onlyErrors = false">Toutes les lignes</button>
                </div>
                <div class="max-h-[60vh] overflow-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="sticky top-0 bg-muted text-xs font-semibold text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3 text-start">Ligne</th>
                                <th class="px-4 py-3 text-start">Référence</th>
                                <th class="px-4 py-3 text-start">Médicament</th>
                                <th class="px-4 py-3 text-start">Présentation</th>
                                <th class="px-4 py-3 text-end">Prix fournisseur</th>
                                <th class="px-5 py-3 text-start">Contrôle</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="row in rows" :key="row.row_number" :class="row.errors.length && 'bg-red-50/60 dark:bg-red-950/10'">
                                <td class="px-5 py-3 tabular-nums text-muted-foreground">{{ row.row_number }}</td>
                                <td class="px-4 py-3 font-mono text-muted-foreground">{{ row.reference || '—' }}</td>
                                <td class="px-4 py-3 font-medium text-foreground">{{ row.medicine_label || '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ row.presentation || '—' }}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{{ row.supplier_price !== null && row.supplier_price !== '' ? formatMoney(row.supplier_price) : '—' }}</td>
                                <td class="px-5 py-3">
                                    <Badge v-if="!row.errors.length" tone="success"><Check class="h-4 w-4" />Correcte</Badge>
                                    <ul v-else class="space-y-1 text-xs text-red-700 dark:text-red-300">
                                        <li v-for="(issue, index) in (row.issues ?? [])" :key="index">
                                            <span class="font-semibold">{{ issue.column }}</span>
                                            <span class="text-muted-foreground"> · lu : {{ issue.value === '' ? 'vide' : `« ${issue.value} »` }}</span>
                                            <span class="block">{{ issue.message }}</span>
                                        </li>
                                        <template v-if="!(row.issues ?? []).length">
                                            <li v-for="error in row.errors" :key="error">{{ error }}</li>
                                        </template>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button :as="Link" :href="cancelHref" size="lg" variant="white-outline">Annuler</Button>
                <Button size="lg" :disabled="!canConfirm || processing" @click="confirmImport">
                    <Check class="h-4 w-4" />{{ processing ? 'Import en cours…' : `Importer ${preview.valid_count} ligne${preview.valid_count > 1 ? 's' : ''}` }}
                </Button>
            </div>
        </template>
    </div>
</template>
