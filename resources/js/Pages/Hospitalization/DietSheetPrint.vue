<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DietSheetBody from '@/Components/Hospitalization/DietSheetBody.vue';
import { ArrowLeft, Printer } from 'lucide-vue-next';

defineOptions({ layout: AppLayout });

/** ADR-113 — la fiche de régime d'un séjour, sur papier. */
defineProps({
    stay: { type: Object, required: true },
});

const printSheet = () => window.print();
</script>

<template>
    <Head :title="`Fiche de régime — ${stay.patient.name}`" />

    <div class="ds-page mx-auto w-full max-w-[52rem] space-y-3">
        <div class="ds-actions flex flex-wrap items-center justify-between gap-3">
            <Button :as="Link" :href="`/hospitalisation/${stay.uuid}`" size="sm" variant="white-outline">
                <ArrowLeft class="h-4 w-4" />Retour au séjour
            </Button>
            <Button type="button" size="sm" @click="printSheet">
                <Printer class="h-4 w-4" />Imprimer
            </Button>
        </div>

        <DietSheetBody :stay="stay" />
    </div>
</template>
