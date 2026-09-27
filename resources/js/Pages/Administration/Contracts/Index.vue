<script setup>
import { hrUrl } from '@/utilities/hrUrl';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive, ArchiveRestore, CalendarClock, CalendarPlus, CircleCheck, Download, FileText, History, Pencil, Plus, Printer, Search,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import HrPagination from '../Partials/HrPagination.vue';
import HrStatCard from '../Partials/HrStatCard.vue';
import { usePermissions } from '@/composables/usePermissions';
import { endingLabel, formatPeriod, initials } from '@/utilities/hr';

defineOptions({ layout: AppLayout });

/*
 * ADR-066, ADR-071 — la fiche RH du contrat : type, dates, référence. Le
 * document signé se produit par un canevas (ADR-070), pas ici.
 *
 * L'état se lit sur les dates (servi par le serveur) : un contrat terminé ne
 * s'affiche plus « Actif ». Ce qui finit sous 30 jours a sa carte — la même
 * règle que le chiffre de l'accueil RH — et son échéance sur la ligne.
 */
const props = defineProps({ contracts: Object, filters: Object, summary: Object });
const { can } = usePermissions();

const query = ref(props.filters?.q ?? '');
const status = computed(() => props.filters?.status ?? 'current');
const visit = (params) => router.get(hrUrl('/administration/contracts'), params, { preserveState: true, preserveScroll: true, replace: true });
const search = () => visit({ q: query.value || undefined, status: status.value });

const STATS = [
    { key: 'current', label: 'En cours', hint: 'Actifs aujourd’hui', icon: CircleCheck, tone: 'emerald' },
    { key: 'ending', label: 'Fin sous 30 jours', hint: 'À renouveler ou clore', icon: CalendarClock, tone: 'rose' },
    { key: 'future', label: 'À venir', hint: 'Début futur', icon: CalendarPlus, tone: 'sky' },
    { key: 'ended', label: 'Terminés', hint: 'Date de fin passée', icon: History, tone: 'amber' },
    { key: 'archived', label: 'Archivés', hint: 'Restaurables', icon: Archive, tone: 'slate' },
];
const STATE = {
    current: { label: 'En cours', tone: 'success' },
    future: { label: 'À venir', tone: 'info' },
    ended: { label: 'Terminé', tone: 'neutral' },
    archived: { label: 'Archivé', tone: 'neutral' },
};

// --- Archiver : un motif, dans une fenêtre qui le demande -------------------
const archiving = ref(null);
const archiveForm = useForm({ reason: '' });
const openArchive = (contract) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = contract;
};
const archive = () => {
    if (!archiveForm.reason.trim()) return;
    archiveForm.delete(hrUrl(`/administration/contracts/${archiving.value.uuid}`), {
        preserveScroll: true,
        onSuccess: () => { archiving.value = null; },
    });
};
const restore = (contract) => router.post(hrUrl(`/administration/contracts/${contract.uuid}/restore`), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Contrats" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines"
            title="Contrats du personnel"
            description="Le type, les dates et la référence de chaque contrat. Le document à signer se produit depuis un canevas, dans « Documents »."
            :icon="FileText"
            tone="sky"
        >
            <template #actions>
                <Button v-if="can('contracts.export')" as="a" :href="hrUrl('/administration/contracts/export')" variant="outline"><Download class="h-4 w-4" />Exporter</Button>
                <Button v-if="can('contracts.create')" :as="Link" :href="hrUrl('/administration/contracts/create')"><Plus class="h-4 w-4" />Nouveau contrat</Button>
            </template>
        </PageHeader>

        <!-- La carte est le filtre. -->
        <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <Link
                v-for="item in STATS"
                :key="item.key"
                :href="hrUrl(`/administration/contracts?status=${item.key}`)"
                preserve-scroll
                :aria-current="status === item.key ? 'page' : undefined"
            >
                <HrStatCard :label="item.label" :value="summary[item.key] ?? 0" :hint="item.hint" :icon="item.icon" :tone="item.tone" :active="status === item.key" />
            </Link>
        </section>

        <section class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            <form class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center" @submit.prevent="search">
                <IconInput v-model="query" :icon="Search" type="search" class="min-w-0 flex-1" placeholder="Référence, matricule, nom ou type de contrat…" />
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="outline">Rechercher</Button>
                    <Button v-if="status !== 'all'" type="button" variant="ghost" @click="visit({ q: query || undefined, status: 'all' })">Tous les contrats</Button>
                </div>
            </form>

            <div v-if="contracts.data.length" class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-sm">
                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-start">Employé</th>
                            <th scope="col" class="px-5 py-3 text-start">Contrat</th>
                            <th scope="col" class="px-5 py-3 text-start">Période</th>
                            <th scope="col" class="px-5 py-3 text-start">État</th>
                            <th scope="col" class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="contract in contracts.data" :key="contract.uuid" class="align-top transition hover:bg-muted/30">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{{ initials(contract.employee.name) }}</span>
                                    <div class="min-w-0">
                                        <Link
                                            v-if="can('employees.view')"
                                            :href="hrUrl(`/administration/employees/${contract.employee.uuid}`)"
                                            class="font-semibold text-foreground hover:text-primary hover:underline"
                                        >{{ contract.employee.name }}</Link>
                                        <p v-else class="font-semibold text-foreground">{{ contract.employee.name }}</p>
                                        <p class="font-mono text-xs text-muted-foreground">{{ contract.employee.employee_number }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <Badge variant="outline">{{ contract.contract_type }}</Badge>
                                <p class="mt-1.5 text-xs text-muted-foreground">{{ contract.reference_number || 'Sans référence' }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-foreground">{{ formatPeriod(contract.starts_on, contract.ends_on) }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <Badge :tone="STATE[contract.state]?.tone">{{ STATE[contract.state]?.label }}</Badge>
                                    <Badge v-if="contract.state === 'current' && contract.ends_in_days !== null && contract.ends_in_days <= 30" tone="danger">
                                        {{ endingLabel(contract.ends_in_days) }}
                                    </Badge>
                                </div>
                                <p v-if="contract.delete_reason" class="mt-1.5 max-w-56 text-xs text-muted-foreground">Motif : {{ contract.delete_reason }}</p>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex justify-end gap-1">
                                    <Button v-if="can('contracts.print')" :as="Link" :href="hrUrl(`/administration/contracts/${contract.uuid}/print`)" size="icon" variant="ghost" title="Aperçu et impression" :aria-label="`Imprimer le contrat de ${contract.employee.name}`"><Printer class="h-4 w-4" /></Button>
                                    <Button v-if="!contract.archived && can('contracts.update')" :as="Link" :href="hrUrl(`/administration/contracts/${contract.uuid}/edit`)" size="icon" variant="ghost" title="Modifier" :aria-label="`Modifier le contrat de ${contract.employee.name}`"><Pencil class="h-4 w-4" /></Button>
                                    <Button v-if="!contract.archived && can('contracts.archive')" size="icon" variant="ghost" class="text-muted-foreground hover:text-destructive" title="Archiver" :aria-label="`Archiver le contrat de ${contract.employee.name}`" @click="openArchive(contract)"><Archive class="h-4 w-4" /></Button>
                                    <Button v-if="contract.archived && can('contracts.restore')" size="sm" variant="outline" @click="restore(contract)"><ArchiveRestore class="h-4 w-4" />Restaurer</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else :icon="FileText" title="Aucun contrat pour ce filtre" description="Choisissez un autre état, ou enregistrez un nouveau contrat." />
            <HrPagination :paginator="contracts" />
        </section>

        <Dialog
            v-if="archiving"
            :open="Boolean(archiving)"
            title="Archiver ce contrat"
            description="Le contrat quitte la liste en cours sans être effacé : il reste restaurable, avec son motif."
            :dismissible="false"
            @update:open="(value) => { if (!value) archiving = null; }"
        >
            <div class="space-y-4">
                <div class="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm">
                    <p class="font-semibold text-foreground">{{ archiving.employee.name }} · {{ archiving.contract_type }}</p>
                    <p class="mt-0.5 text-muted-foreground">{{ formatPeriod(archiving.starts_on, archiving.ends_on) }}</p>
                </div>
                <FormField label="Motif de l’archivage" required :error="archiveForm.errors.reason">
                    <Textarea v-model="archiveForm.reason" rows="3" maxlength="1000" placeholder="Ex. saisi en double" />
                </FormField>
            </div>
            <template #footer>
                <Button variant="outline" @click="archiving = null">Retour</Button>
                <Button variant="destructive" :disabled="archiveForm.processing || !archiveForm.reason.trim()" @click="archive"><Archive class="h-4 w-4" />Archiver</Button>
            </template>
        </Dialog>
    </div>
</template>
