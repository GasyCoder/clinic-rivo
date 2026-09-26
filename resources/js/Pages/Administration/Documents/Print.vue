<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Archive, ArrowLeft, FileStack, History, Pencil, Printer, RotateCcw } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { usePermissions } from '@/composables/usePermissions';
import { hrUrl } from '@/utilities/hrUrl';

defineOptions({ layout: AppLayout });
const props = defineProps({ document: Object });
const { can } = usePermissions();

const printPage = () => window.print();
const formatDate = (value) => (value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '');

// ADR-199 — le document dans son dossier : modifier (nouvelle version), archiver, restaurer.
const folderHref = hrUrl(`/administration/generated-documents?${new URLSearchParams({ dossier: props.document.folder })}`);
const modifyHref = hrUrl(`/administration/generated-documents/create?from=${props.document.uuid}`);
// Depuis un contrat : un autre canevas, ou la fiche résumé du contrat.
const sourceHref = props.document.source?.kind === 'contract'
    ? hrUrl(`/administration/contracts/${props.document.source.uuid}/print?choisir=1`)
    : props.document.source?.kind === 'leave'
        ? hrUrl(`/administration/leave/${props.document.source.uuid}/print`)
        : null;

const archiving = ref(false);
const archiveForm = useForm({ reason: '' });
const archive = () => archiveForm.delete(hrUrl(`/administration/generated-documents/${props.document.uuid}`), { onSuccess: () => { archiving.value = false; } });
const restoreError = ref('');
const restore = () => router.post(hrUrl(`/administration/generated-documents/${props.document.uuid}/restore`), {}, {
    onError: (errors) => { restoreError.value = Object.values(errors)[0] ?? ''; },
});
</script>

<template>
    <Head :title="document.template_name" />

    <div class="mx-auto max-w-4xl space-y-3">
        <div class="print-actions flex flex-wrap items-center gap-2">
            <Button :as="Link" :href="folderHref" variant="outline"><ArrowLeft class="h-4 w-4" />Retour au dossier</Button>
            <Button v-if="sourceHref" :as="Link" :href="sourceHref" variant="ghost"><FileStack class="h-4 w-4" />{{ document.source.kind === 'contract' ? 'Autre canevas ou fiche résumé' : 'Fiche de demande' }}</Button>
            <div class="ms-auto flex flex-wrap gap-2">
                <template v-if="! document.archived">
                    <Button v-if="can('generated_documents.create') && can('generated_documents.archive')" :as="Link" :href="modifyHref" variant="outline"><Pencil class="h-4 w-4" />Modifier</Button>
                    <Button v-if="can('generated_documents.archive')" variant="danger-outline" @click="archiveForm.reset(); archiving = true"><Archive class="h-4 w-4" />Archiver</Button>
                    <Button @click="printPage"><Printer class="h-4 w-4" />Imprimer</Button>
                </template>
                <Button v-else-if="can('generated_documents.restore') && ! document.replaced_by" variant="outline" @click="restore"><RotateCcw class="h-4 w-4" />Restaurer</Button>
            </div>
        </div>

        <!-- Une version archivée se lit, elle ne s'imprime plus comme document en vigueur. -->
        <div v-if="document.archived" class="print-actions rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100" role="status">
            <p class="font-semibold">Document archivé le {{ formatDate(document.archived_at) }}<template v-if="document.archived_by"> par {{ document.archived_by }}</template>.</p>
            <p class="mt-0.5">Motif : {{ document.archive_reason }}</p>
            <p v-if="document.replaced_by" class="mt-1"><Link :href="hrUrl(`/administration/generated-documents/${document.replaced_by.uuid}/print`)" class="inline-flex items-center gap-1 font-semibold underline"><History class="h-3.5 w-3.5" />Voir la version en vigueur ({{ formatDate(document.replaced_by.created_at) }})</Link></p>
        </div>
        <p v-if="restoreError" class="print-actions rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200" role="alert">{{ restoreError }}</p>
        <p v-if="document.replaces" class="print-actions text-xs text-muted-foreground">
            <History class="me-1 inline h-3.5 w-3.5" />Nouvelle version — remplace celle du <Link :href="hrUrl(`/administration/generated-documents/${document.replaces.uuid}/print`)" class="underline">{{ formatDate(document.replaces.created_at) }}</Link>, archivée.
        </p>

        <article class="print-doc bg-white p-8 text-slate-900">
            <header class="mb-4 flex items-start justify-between gap-4 border-b border-slate-200 pb-3 text-xs text-slate-400 print:hidden">
                <span>{{ document.document_type }} · {{ document.employee.name }}</span>
                <span>{{ document.uuid }}</span>
            </header>
            <div class="canevas-document" v-html="document.rendered_html" />
        </article>

        <Dialog
            v-if="archiving"
            :open="archiving"
            title="Archiver ce document ?"
            description="Il reste consultable dans le dossier (« Archivés ») et peut être restauré ; il n’est jamais effacé."
            :dismissible="false"
            @update:open="(value) => { if (! value) archiving = false; }"
        >
            <FormField label="Motif" required :error="archiveForm.errors.reason">
                <Textarea v-model="archiveForm.reason" rows="3" maxlength="1000" placeholder="Ex. document remplacé, erreur de saisie…" />
            </FormField>
            <template #footer>
                <Button variant="outline" @click="archiving = false">Retour</Button>
                <Button variant="danger" :disabled="archiveForm.processing || archiveForm.reason.trim().length < 3" @click="archive"><Archive class="h-4 w-4" />Archiver</Button>
            </template>
        </Dialog>
    </div>
</template>

<style>
.canevas-document table {
    border-collapse: collapse;
    width: 100%;
    margin: 0.75rem 0;
}
.canevas-document table td,
.canevas-document table th {
    border: 1px solid #cbd5e1;
    padding: 0.375rem 0.5rem;
}
.canevas-document table th {
    background-color: #f8fafc;
    font-weight: 700;
}
.canevas-page-break {
    margin: 1rem 0;
    border-top: 1px dashed #cbd5e1;
}
@media print {
    .print-actions,
    aside,
    nav {
        display: none !important;
    }
    .print-doc {
        padding: 0 !important;
    }
    .canevas-page-break {
        border: 0;
        margin: 0;
        break-after: page;
    }
    .canevas-page-break span {
        display: none;
    }
}
</style>
