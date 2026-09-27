<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProfessionalEmailsWorkspace from '@/Components/ProfessionalEmails/ProfessionalEmailsWorkspace.vue';

/**
 * ADR-190 — les adresses email professionnelles de tous les sites, sur le
 * portail : chaque geste part vers l'hébergeur, puis vers l'API du site.
 */
defineOptions({ layout: AppLayout });

defineProps({
    sites: { type: Array, default: () => [] },
    hosting: { type: Object, required: true },
});

const urls = {
    mailbox: (row) => `/super-admin/professional-emails/${row.site_code}/${row.uuid}`,
    direct: (siteCode) => `/super-admin/professional-emails/${siteCode}/direct`,
    check: '/super-admin/professional-emails/check',
    prepare: '/super-admin/professional-emails/prepare',
};
</script>

<template>
    <Head title="Emails professionnels" />
    <ProfessionalEmailsWorkspace :sites="sites" :hosting="hosting" :urls="urls" scope="portal" />
</template>
