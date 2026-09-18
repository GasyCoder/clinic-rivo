<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { Archive, BookMarked, Pencil, Plus, RotateCcw, Search } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

/**
 * Les protocoles thérapeutiques de la clinique (ADR-111).
 *
 * C'est d'eux que viennent les diagnostics et ordonnances proposés en
 * consultation. Le système les applique ; il n'en invente aucun.
 */
const props = defineProps({
    protocols: { type: Array, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    can_manage: { type: Boolean, default: false },
});

const query = ref(props.filters.q);

const visit = (params) => router.get('/medicine/protocoles', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

// La carte est le filtre ; le compte vient du serveur.
const cards = computed(() => [
    { key: 'active', label: 'Actifs', value: props.summary.active ?? 0 },
    { key: 'inactive', label: 'Suspendus', value: props.summary.inactive ?? 0 },
    { key: 'archived', label: 'Archivés', value: props.summary.archived ?? 0 },
]);

// L'archivage demande un motif : un protocole qui a servi reste lisible, et
// l'audit doit dire pourquoi il a cessé de l'être.
const archiving = ref(null);
const archiveForm = useForm({ reason: '' });

const openArchive = (protocol) => {
    archiving.value = protocol;
    archiveForm.reset();
    archiveForm.clearErrors();
};

const submitArchive = () => archiveForm.post(`/medicine/protocoles/${archiving.value.uuid}/archive`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; },
});

const restore = (protocol) => router.post(`/medicine/protocoles/${protocol.uuid}/restore`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Protocoles thérapeutiques" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Card class="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <BookMarked class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h1 class="font-heading text-lg font-bold text-foreground">Protocoles thérapeutiques</h1>
                        <p class="mt-0.5 max-w-2xl text-sm text-muted-foreground">
                            Les signes qui évoquent un diagnostic, et l’ordonnance type qui le traite. La consultation les propose ; le médecin décide toujours.
                        </p>
                    </div>
                </div>

                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                    <form class="w-full sm:w-72" @submit.prevent="visit({ q: query, status: filters.status })">
                        <IconInput v-model="query" :icon="Search" placeholder="Protocole ou diagnostic…" aria-label="Rechercher un protocole" />
                    </form>
                    <Button v-if="can_manage" :as="Link" href="/medicine/protocoles/nouveau">
                        <Plus class="h-4 w-4" aria-hidden="true" />Nouveau protocole
                    </Button>
                </div>
            </div>
        </Card>

        <div class="grid gap-3 sm:grid-cols-3">
            <button
                v-for="card in cards"
                :key="card.key"
                type="button"
                :aria-pressed="filters.status === card.key"
                :class="['rounded-xl border p-4 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    filters.status === card.key ? 'border-primary/40 bg-primary/5' : 'border-border bg-card hover:bg-accent']"
                @click="visit({ q: filters.q, status: card.key })"
            >
                <span class="block text-xs font-semibold text-muted-foreground">{{ card.label }}</span>
                <strong class="mt-1 block font-heading text-2xl tabular-nums text-foreground">{{ card.value }}</strong>
            </button>
        </div>

        <Card class="overflow-hidden">
            <div v-if="protocols.length" class="overflow-x-auto">
                <table class="w-full min-w-[900px] border-collapse text-sm">
                    <caption class="sr-only">Protocoles thérapeutiques</caption>
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="px-4 py-3 text-start">Protocole</th>
                            <th scope="col" class="px-4 py-3 text-start">Signes évocateurs</th>
                            <th scope="col" class="px-4 py-3 text-start">Ordonnance type</th>
                            <th scope="col" class="px-4 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="protocol in protocols" :key="protocol.uuid" class="align-top">
                            <td class="px-4 py-3">
                                <span class="flex flex-wrap items-center gap-2">
                                    <strong class="text-foreground">{{ protocol.name }}</strong>
                                    <Badge v-if="protocol.archived" tone="neutral" class="px-2 py-0.5 text-[10px]">Archivé</Badge>
                                    <Badge v-else-if="!protocol.is_active" tone="warning" class="px-2 py-0.5 text-[10px]">Suspendu</Badge>
                                </span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                    {{ protocol.diagnosis }}<template v-if="protocol.diagnosis_code"> · {{ protocol.diagnosis_code }}</template>
                                </span>
                                <span class="mt-1 block text-xs text-muted-foreground">{{ protocol.population }}</span>
                                <span v-if="protocol.archived && protocol.delete_reason" class="mt-1 block text-xs italic text-muted-foreground">« {{ protocol.delete_reason }} »</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="protocol.indications.length" class="flex flex-wrap gap-1">
                                    <Badge v-for="sign in protocol.indications" :key="sign" tone="neutral" class="px-2 py-0.5 text-[10px] font-medium">{{ sign }}</Badge>
                                </span>
                                <!-- Sans signe, le protocole ne propose jamais le
                                     diagnostic : il ne sert qu'à l'ordonnance. -->
                                <span v-else class="text-xs text-muted-foreground">Aucun — ne propose pas le diagnostic</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">{{ protocol.medicines.join(' · ') }}</td>
                            <td class="px-4 py-3">
                                <div v-if="can_manage" class="flex justify-end gap-1.5">
                                    <template v-if="protocol.archived">
                                        <Button type="button" size="sm" variant="white-outline" @click="restore(protocol)">
                                            <RotateCcw class="h-4 w-4" aria-hidden="true" />Restaurer
                                        </Button>
                                    </template>
                                    <template v-else>
                                        <Button :as="Link" :href="`/medicine/protocoles/${protocol.uuid}/modifier`" size="sm" icon variant="white-outline" title="Modifier" aria-label="Modifier le protocole">
                                            <Pencil class="h-4 w-4" aria-hidden="true" />
                                        </Button>
                                        <Button type="button" size="sm" icon variant="danger-outline" title="Archiver" aria-label="Archiver le protocole" @click="openArchive(protocol)">
                                            <Archive class="h-4 w-4" aria-hidden="true" />
                                        </Button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center justify-center px-6 py-14 text-center">
                <span class="grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><BookMarked class="h-5 w-5" aria-hidden="true" /></span>
                <p class="mt-3 text-sm font-semibold text-foreground">
                    {{ filters.status === 'active' && !filters.q ? 'Aucun protocole encore rédigé' : 'Aucun protocole ne correspond' }}
                </p>
                <p class="mt-1 max-w-md text-xs leading-5 text-muted-foreground">
                    Tant qu’aucun protocole n’existe, la consultation ne propose ni diagnostic ni ordonnance : le système n’invente pas de médecine, il applique celle de la clinique.
                </p>
                <Button v-if="can_manage && filters.status === 'active'" :as="Link" href="/medicine/protocoles/nouveau" class="mt-4">
                    <Plus class="h-4 w-4" aria-hidden="true" />Rédiger le premier protocole
                </Button>
            </div>
        </Card>
    </div>

    <Dialog
        :open="archiving !== null"
        title="Archiver le protocole"
        :description="archiving ? `« ${archiving.name} » cessera d’être proposé. Les diagnostics et ordonnances qu’il a déjà proposés restent tracés.` : ''"
        @update:open="(open) => { if (!open) archiving = null; }"
    >
        <form class="space-y-4" @submit.prevent="submitArchive">
            <FormField label="Motif" required :error="archiveForm.errors.reason">
                <Textarea v-model="archiveForm.reason" rows="3" placeholder="Ex. remplacé par le protocole national 2026" />
            </FormField>
            <div class="flex justify-end gap-2">
                <Button type="button" variant="white-outline" :disabled="archiveForm.processing" @click="archiving = null">Annuler</Button>
                <Button type="submit" variant="destructive" :disabled="archiveForm.processing">
                    <Archive class="h-4 w-4" aria-hidden="true" />Archiver
                </Button>
            </div>
        </form>
    </Dialog>
</template>
