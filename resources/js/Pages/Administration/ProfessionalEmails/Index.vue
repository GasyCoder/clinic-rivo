<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProfessionalEmailsWorkspace from '@/Components/ProfessionalEmails/ProfessionalEmailsWorkspace.vue';
import { hrUrl } from '@/utilities/hrUrl';

/**
 * ADR-190 — les adresses email professionnelles du site, dans l'espace RH.
 * Chaque geste suit le droit accordé par le Super Admin ; servi aussi au
 * portail par les écrans RH relayés (ADR-187), d'où `hrUrl`.
 */
defineOptions({ layout: AppLayout });

defineProps({
    sites: { type: Array, default: () => [] },
    hosting: { type: Object, required: true },
});

const urls = {
    mailbox: (row) => hrUrl(`/administration/professional-emails/${row.uuid}`),
    direct: () => hrUrl('/administration/professional-emails/direct'),
    check: hrUrl('/administration/professional-emails/check'),
    prepare: hrUrl('/administration/professional-emails/prepare'),
};
</script>

<template>
    <Head title="Emails professionnels" />
    <ProfessionalEmailsWorkspace :sites="sites" :hosting="hosting" :urls="urls" scope="site" />
</template>
