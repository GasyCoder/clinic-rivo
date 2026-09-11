<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({ layout: AppLayout });
defineProps({ document: Object });

const printPage = () => window.print();
</script>

<template>
    <Head :title="document.template_name" />

    <div class="mx-auto max-w-4xl space-y-3">
        <div class="print-actions flex justify-between">
            <Button :as="Link" href="/administration/generated-documents" size="rg" variant="white-outline">
                <Icon name="arrow-left" /><span class="ms-2">Retour</span>
            </Button>
            <Button size="rg" @click="printPage">
                <Icon name="printer" /><span class="ms-2">Imprimer</span>
            </Button>
        </div>

        <article class="print-doc bg-white p-8 text-slate-900">
            <header class="mb-4 flex items-start justify-between gap-4 border-b border-slate-200 pb-3 text-xs text-slate-400 print:hidden">
                <span>{{ document.document_type }} · {{ document.employee.name }}</span>
                <span>{{ document.uuid }}</span>
            </header>
            <div class="canevas-document" v-html="document.rendered_html" />
        </article>
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
