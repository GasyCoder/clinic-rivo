<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Activity, Users } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivePassageBoard from '@/Components/Clinical/ActivePassageBoard.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

/**
 * ADR-177 — la Médecine voit tous les passages ouverts dont l'accueil est
 * terminé, pas seulement ceux que la désignation lui envoyait. Prendre un
 * patient en charge ouvre sa consultation ; le regarder ne crée rien.
 */
defineProps({
    passages: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    view: { type: String, default: 'waiting' },
    search: { type: String, default: '' },
});

const { can } = usePermissions();
</script>

<template>
    <Head title="Médecine" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-4">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"><Activity class="h-6 w-6" aria-hidden="true" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Médecine</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Patients en attente par ordre d’arrivée, puis ceux que vous avez en consultation, puis ceux déjà terminés.</p>
                </div>
            </div>
            <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline"><Users class="h-4.5 w-4.5" aria-hidden="true" />Dossiers patients</Button>
        </header>

        <ActivePassageBoard module="MEDICINE" base-url="/medicine" :passages="passages" :counts="counts" :view="view" :search="search" />
    </div>
</template>
