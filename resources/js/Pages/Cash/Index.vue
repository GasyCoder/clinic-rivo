<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useToastStore } from '@/stores/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    registers: Array,
});

const { can } = usePermissions();
const toast = useToastStore();
const openTarget = ref(null);

const openForm = useForm({
    opening_amount: 0,
    notes: 'Ouverture de caisse en début de journée.',
    cash_register_uuid: '',
});

// A session open by someone else is never mine to resume — only its own
// opener can enter it; anyone else must pick a different, available poste.
const registerStatus = (register) => {
    if (!register.is_open && !register.is_locked) return 'available';

    return register.is_mine ? (register.is_locked ? 'locked' : 'open') : 'blocked';
};

const selectRegister = (register) => {
    const status = registerStatus(register);

    if (status === 'blocked') {
        toast.warning(`${register.name} est déjà utilisée par ${register.opener_name}. Choisissez une autre caisse disponible.`);
        return;
    }

    if (status === 'locked') {
        toast.warning(`${register.name} est verrouillée par la Super Administration. Elle redevient accessible une fois déverrouillée.`);
        return;
    }

    if (status === 'open') {
        router.visit(`/cash/${register.uuid}`);
        return;
    }

    openForm.clearErrors();
    openForm.opening_amount = 0;
    openForm.notes = 'Ouverture de caisse en début de journée.';
    openForm.cash_register_uuid = register.uuid;
    openTarget.value = register;
};

const closeOpenDialog = () => {
    if (!openForm.processing) openTarget.value = null;
};

const confirmOpen = () => openForm.post('/cash/open', {
    preserveScroll: true,
    onSuccess: () => { openTarget.value = null; },
});
</script>

<template>
    <Head title="Caisse" />

    <div class="mx-auto w-full max-w-[1500px] space-y-4">
        <header class="flex flex-col gap-4 border-b border-gray-200 pb-4 dark:border-gray-900 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-primary-600 dark:text-primary-300">Réception · Encaissement</p>
                <h1 class="font-heading text-2xl font-bold text-slate-700 dark:text-white">Postes de caisse</h1>
                <p class="mt-1 text-sm text-slate-400">Sélectionnez le poste de travail à utiliser pour la session d’encaissement.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button :as="Link" href="/reception" size="rg" variant="white-outline"><Icon class="text-lg" name="arrow-left" /><span class="ms-2">Accueil réception</span></Button>
                <Button v-if="can('patients.view')" :as="Link" href="/patients" size="rg" variant="white-outline"><Icon class="text-lg" name="users" /><span class="ms-2">Patients</span></Button>
            </div>
        </header>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
            <div class="grid gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900 md:grid-cols-[minmax(0,1fr)_minmax(280px,420px)] md:items-center">
                <div>
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Choisir un poste</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">Chaque poste tient sa propre session, indépendamment des autres postes.</p>
                </div>
                <div class="border-s-2 border-primary-500 ps-3 text-xs leading-5 text-slate-500 dark:text-slate-300">
                    Les tickets Pharmacie restent contrôlés et encaissés ici, dans l’espace Caisse. La Pharmacie n’encaisse jamais directement.
                </div>
            </div>
            <div v-if="registers.length === 0" class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-500 dark:bg-amber-950/30"><Icon class="text-2xl" name="alert-circle" /></span>
                <div>
                    <h3 class="text-sm font-bold text-slate-700 dark:text-white">Aucune caisse créée ou active</h3>
                    <p class="mt-1 max-w-sm text-sm text-slate-400">Demandez à un administrateur d’en créer une ou d’en réactiver une existante avant de pouvoir encaisser.</p>
                </div>
            </div>

            <div v-else class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="register in registers"
                    :key="register.uuid"
                    type="button"
                    :class="['group flex min-h-36 flex-col items-stretch rounded border bg-white text-start transition-all dark:bg-gray-950',
                        registerStatus(register) === 'open' ? 'border-emerald-300 shadow-sm hover:-translate-y-px hover:border-emerald-400 hover:shadow-md dark:border-emerald-900'
                        : registerStatus(register) === 'locked' ? 'cursor-not-allowed border-amber-200 bg-amber-50/40 dark:border-amber-900 dark:bg-amber-950/10'
                        : registerStatus(register) === 'blocked' ? 'cursor-not-allowed border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-gray-1000/40'
                        : 'border-gray-200 hover:-translate-y-px hover:border-primary-300 hover:shadow-md dark:border-gray-800 dark:hover:border-primary-800']"
                    @click="selectRegister(register)"
                >
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Poste de caisse</p>
                            <p class="mt-1 truncate text-base font-bold text-slate-700 dark:text-white">{{ register.name }}</p>
                        </div>
                        <Icon :class="['mt-0.5 shrink-0 text-xl', registerStatus(register) === 'open' ? 'text-emerald-600 dark:text-emerald-300' : registerStatus(register) === 'locked' ? 'text-amber-600 dark:text-amber-300' : registerStatus(register) === 'blocked' ? 'text-slate-300 dark:text-slate-700' : 'text-primary-600 dark:text-primary-300']" :name="registerStatus(register) === 'open' ? 'unlock' : registerStatus(register) === 'locked' || registerStatus(register) === 'blocked' ? 'lock' : 'wallet'" />
                    </div>
                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 dark:border-gray-900">
                        <div>
                            <p :class="['text-xs font-bold', registerStatus(register) === 'open' ? 'text-emerald-700 dark:text-emerald-300' : registerStatus(register) === 'blocked' ? 'text-slate-400' : 'text-slate-600 dark:text-slate-300']">
                                {{ registerStatus(register) === 'open' ? 'Session en cours' : registerStatus(register) === 'locked' ? 'Session suspendue' : registerStatus(register) === 'blocked' ? 'Poste occupé' : 'Prêt à ouvrir' }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-slate-400">{{ registerStatus(register) === 'open' ? `Caissier : ${register.opener_name}` : registerStatus(register) === 'locked' ? 'Verrouillée par la supervision' : registerStatus(register) === 'blocked' ? `Utilisée par ${register.opener_name}` : 'Aucune session active' }}</p>
                        </div>
                        <span :class="['inline-flex items-center gap-1 text-xs font-bold', registerStatus(register) === 'blocked' || registerStatus(register) === 'locked' ? 'text-slate-300 dark:text-slate-700' : 'text-primary-600 group-hover:translate-x-0.5 dark:text-primary-300']">
                            {{ registerStatus(register) === 'open' ? 'Reprendre' : registerStatus(register) === 'locked' || registerStatus(register) === 'blocked' ? 'Indisponible' : 'Ouvrir' }}<Icon v-if="registerStatus(register) !== 'blocked' && registerStatus(register) !== 'locked'" name="arrow-right" />
                        </span>
                    </div>
                </button>
            </div>
        </section>

        <div v-if="openTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/55 p-4" role="presentation" @click.self="closeOpenDialog">
            <section class="w-full max-w-md overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="cash-open-dialog-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div class="min-w-0">
                        <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.14em] text-primary-600 dark:text-primary-300">Ouverture de session</p>
                        <h2 id="cash-open-dialog-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ openTarget.name }}</h2>
                        <p class="mt-1 text-sm text-slate-400">Renseignez le fond réellement remis au caissier.</p>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Fermer" @click="closeOpenDialog"><Icon class="text-xl" name="cross" /></button>
                </header>

                <form class="space-y-4 p-5" @submit.prevent="confirmOpen">
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="open_amount">Fond initial <span class="text-red-500">*</span></FormLabel>
                        <Input id="open_amount" v-model="openForm.opening_amount" type="number" min="0" step="0.01" size="lg" class="font-bold" required autofocus />
                        <FormError v-if="openForm.errors.opening_amount">{{ openForm.errors.opening_amount }}</FormError>
                    </FormGroup>
                    <FormGroup class="!mb-0">
                        <FormLabel class="mb-1.5" for="open_notes">Note d’ouverture</FormLabel>
                        <textarea id="open_notes" v-model="openForm.notes" rows="3" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none transition-all focus:border-primary-500 focus:ring-2 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950"></textarea>
                        <FormError v-if="openForm.errors.notes">{{ openForm.errors.notes }}</FormError>
                    </FormGroup>
                    <FormError v-if="openForm.errors.cash_register_uuid">{{ openForm.errors.cash_register_uuid }}</FormError>
                    <FormError v-if="openForm.errors.cash_session">{{ openForm.errors.cash_session }}</FormError>
                    <div class="flex justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-900">
                        <Button size="rg" variant="white-outline" type="button" :disabled="openForm.processing" @click="closeOpenDialog">Annuler</Button>
                        <Button size="rg" variant="primary" type="submit" :disabled="openForm.processing"><Icon class="text-lg" name="unlock" /><span class="ms-2">{{ openForm.processing ? 'Ouverture…' : 'Ouvrir la session' }}</span></Button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
