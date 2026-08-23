<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import { useToastStore } from '@/stores/toast';

const toast = useToastStore();

const toneClasses = {
    success: 'border-gray-200 border-s-4 border-s-green-500 dark:border-gray-800 dark:border-s-green-500',
    warning: 'border-gray-200 border-s-4 border-s-amber-500 dark:border-gray-800 dark:border-s-amber-500',
    error: 'border-gray-200 border-s-4 border-s-red-500 dark:border-gray-800 dark:border-s-red-500',
    info: 'border-gray-200 border-s-4 border-s-primary-500 dark:border-gray-800 dark:border-s-primary-500',
};
const iconNames = { success: 'check-circle', warning: 'alert-circle', error: 'cross-circle', info: 'info' };
const iconClasses = {
    success: 'text-green-600',
    warning: 'text-amber-500',
    error: 'text-red-600',
    info: 'text-primary-600',
};

let stopSuccess;
let stopError;

onMounted(() => {
    // Every submit/update action already flashes its confirmation through
    // this one shared prop (see HandleInertiaRequests) — hooking the toast
    // here covers all of them without touching each form individually.
    stopSuccess = router.on('success', (event) => {
        const flash = event.detail.page.props.flash;
        const message = flash?.status;
        if (!message) return;

        const type = ['warning', 'danger', 'info'].includes(flash?.status_type) ? flash.status_type : 'success';
        toast[type === 'danger' ? 'error' : type](message);
    });

    // Fires only when the response carries validation errors, i.e. after a
    // failed form submission. Surface the actual reason (fields already show
    // their own inline error too) instead of a vague generic sentence.
    stopError = router.on('error', (event) => {
        const messages = [...new Set(Object.values(event.detail.errors ?? {}).filter(Boolean))];

        if (messages.length === 0) {
            toast.error('Veuillez corriger les erreurs du formulaire.');
            return;
        }

        const extra = messages.length > 1 ? ` (+${messages.length - 1} autre${messages.length > 2 ? 's' : ''})` : '';
        toast.error(`${messages[0]}${extra}`);
    });
});

onBeforeUnmount(() => {
    stopSuccess?.();
    stopError?.();
});
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-[1400] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:end-4 sm:items-end">
        <TransitionGroup
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-for="item in toast.toasts"
                :key="item.id"
                :class="['pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white p-4 shadow-lg dark:bg-gray-950', toneClasses[item.type]]"
                role="status"
            >
                <Icon :class="['mt-0.5 shrink-0 text-lg', iconClasses[item.type]]" :name="iconNames[item.type]" />
                <p class="min-w-0 flex-1 text-sm font-semibold leading-5 text-slate-700 dark:text-white">{{ item.message }}</p>
                <button
                    type="button"
                    class="shrink-0 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                    aria-label="Fermer"
                    @click="toast.dismiss(item.id)"
                >
                    <Icon class="text-base" name="cross" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
