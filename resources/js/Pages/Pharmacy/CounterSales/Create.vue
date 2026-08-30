<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ExternalCounterSaleWorkspace from '@/Pages/Pharmacy/Partials/ExternalCounterSaleWorkspace.vue';
import PharmacyWorkspaceNav from '@/Pages/Pharmacy/Partials/PharmacyWorkspaceNav.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    navigation: { type: Object, required: true },
    medicines: { type: Array, default: () => [] },
});

const form = useForm({
    customer_name: '',
    customer_phone: '',
    external_prescriber: '',
    print_after_create: false,
    lines: [],
});

const submit = () => {
    let printWindow = null;
    let printStarted = false;

    if (props.navigation.can_print_ticket) {
        printWindow = window.open('', '_blank', 'popup=yes,width=480,height=720');

        if (!printWindow) {
            window.alert('Autorisez les fenêtres surgissantes pour imprimer et transmettre cette vente.');
            return;
        }

        printWindow.document.title = 'Création du ticket Pharmacie';
        printWindow.document.body.textContent = 'Création et préparation de l’impression…';
    }

    form.print_after_create = props.navigation.can_print_ticket;

    form.post('/pharmacy/counter-sales', {
        preserveScroll: true,
        onSuccess: (page) => {
            const printUrl = page.props.flash?.print_ticket_url;

            if (printWindow && printUrl) {
                printStarted = true;
                printWindow.location.replace(printUrl);
                printWindow.focus();
            } else {
                printWindow?.close();
            }

            form.reset();
        },
        onError: () => { printWindow?.close(); },
        onCancel: () => { printWindow?.close(); },
        onFinish: () => {
            form.print_after_create = false;
            if (printWindow && !printStarted) printWindow.close();
        },
    });
};
</script>

<template>
    <Head title="Nouvelle demande comptoir" />

    <div class="w-full space-y-5">
        <PharmacyWorkspaceNav
            :capabilities="navigation"
            :dispense-count="navigation.dispense_count ?? 0"
            link-mode
        />

        <ExternalCounterSaleWorkspace
            :visible="true"
            :form="form"
            :medicines="medicines"
            :can-print-ticket="navigation.can_print_ticket"
            @submit="submit"
        />
    </div>
</template>
