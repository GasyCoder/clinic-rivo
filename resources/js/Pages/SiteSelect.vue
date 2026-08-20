<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Icon from '@/Components/UI/Icon.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import Copyright from '@/Components/UI/Copyright.vue';
import { useSitePreferenceStore } from '@/stores/sitePreference';

defineOptions({
    layout: GuestLayout,
});

const props = defineProps({
    clinics: {
        type: Array,
        required: true,
    },
    adminUrl: {
        type: String,
        required: true,
    },
});

const page = usePage();
const sitePreference = useSitePreferenceStore();

const rememberChoice = ref(false);

// localStorage doesn't exist during SSR, so the server always renders with
// no preferred site. Gating on `isMounted` (false until the client mounts)
// keeps the client's first render identical to the server's, avoiding a
// hydration mismatch — the preferred-site block only appears in a normal
// reactive update right after hydration completes, not during it.
const isMounted = ref(false);

onMounted(() => {
    isMounted.value = true;
});

const preferredClinic = computed(() => {
    if (!isMounted.value) {
        return null;
    }

    return props.clinics.find((clinic) => clinic.code === sitePreference.preferredSiteCode);
});

const selectSite = (clinic) => {
    if (rememberChoice.value) {
        sitePreference.remember(clinic.code);
    }
};
</script>

<template>
    <Head title="Choisir votre site" />

    <div class="relative flex min-h-screen items-center justify-center px-5 py-10">
        <div class="w-full max-w-[480px]">
            <div class="mb-8 flex flex-col items-center text-center">
                <div
                    class="mb-5 inline-flex h-14 w-14 flex-none items-center justify-center rounded-2xl bg-primary-50 text-primary-600 dark:bg-primary-950"
                >
                    <em class="ni ni-plus-medi-fill text-2xl leading-none" />
                </div>

                <h1 class="font-heading text-xl font-bold leading-tighter -tracking-snug text-slate-700 dark:text-white">
                    {{ page.props.site.brand }}
                </h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">
                    Choisissez votre site.
                </p>
            </div>

            <div v-if="preferredClinic" class="mb-6">
                <a
                    :href="`${preferredClinic.url}/login`"
                    class="group flex items-center gap-4 rounded-md border-2 border-primary-500 bg-primary-50 p-5 transition-all duration-300 hover:border-primary-600 dark:border-primary-600 dark:bg-primary-950"
                >
                    <span
                        class="inline-flex h-11 w-11 flex-none items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-900 dark:text-primary-400"
                    >
                        <Icon name="building" class="text-lg leading-none" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block text-xs font-bold uppercase tracking-relaxed text-primary-500">Votre site habituel</span>
                        <span class="block truncate font-heading text-sm font-bold uppercase tracking-wide text-slate-700 dark:text-white">
                            {{ preferredClinic.name }}
                        </span>
                    </span>

                    <Icon
                        name="chevron-right"
                        class="flex-none text-lg leading-none text-primary-500 rtl:-scale-x-100"
                    />
                </a>

                <button
                    type="button"
                    class="mt-2 text-xs text-slate-400 underline-offset-2 transition-colors hover:text-primary-600 hover:underline"
                    @click="sitePreference.forget"
                >
                    Ce n'est pas votre site ? Oublier ce choix
                </button>
            </div>

            <div v-if="preferredClinic" class="mb-4 flex items-center gap-3">
                <span class="h-px flex-1 bg-gray-200 dark:bg-gray-900" />
                <span class="text-xxs font-bold uppercase tracking-relaxed text-slate-300 dark:text-slate-700">Ou choisir un autre site</span>
                <span class="h-px flex-1 bg-gray-200 dark:bg-gray-900" />
            </div>

            <ul class="space-y-3">
                <li v-for="clinic in clinics" :key="clinic.code">
                    <a
                        :href="`${clinic.url}/login`"
                        class="group flex items-center gap-4 rounded-md border border-gray-200 bg-white p-5 transition-all duration-300 hover:border-primary-500 hover:shadow-sm dark:border-gray-900 dark:bg-gray-950 dark:hover:border-primary-600"
                        @click="selectSite(clinic)"
                    >
                        <span
                            class="inline-flex h-11 w-11 flex-none items-center justify-center rounded-full bg-gray-100 text-slate-500 transition-colors duration-300 group-hover:bg-primary-50 group-hover:text-primary-600 dark:bg-gray-900 dark:text-slate-400 dark:group-hover:bg-primary-950 dark:group-hover:text-primary-500"
                        >
                            <Icon name="building" class="text-lg leading-none" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-xs text-slate-400">{{ page.props.site.brand }}</span>
                            <span class="block truncate font-heading text-sm font-bold uppercase tracking-wide text-slate-700 dark:text-white">
                                {{ clinic.name }}
                            </span>
                        </span>

                        <Icon
                            name="chevron-right"
                            class="flex-none text-lg leading-none text-slate-300 transition-colors duration-300 group-hover:text-primary-500 rtl:-scale-x-100"
                        />
                    </a>
                </li>
            </ul>

            <div class="mt-4 flex justify-center">
                <CheckBox id="remember-site" v-model="rememberChoice" size="sm">
                    Se souvenir de mon choix
                </CheckBox>
            </div>

            <div class="my-8 flex items-center gap-3">
                <span class="h-px flex-1 bg-gray-200 dark:bg-gray-900" />
                <span class="text-xxs font-bold uppercase tracking-relaxed text-slate-300 dark:text-slate-700">Ou</span>
                <span class="h-px flex-1 bg-gray-200 dark:bg-gray-900" />
            </div>

            <a
                :href="`${adminUrl}/login`"
                class="group flex items-center justify-center gap-2 rounded-md px-5 py-3 text-sm font-medium uppercase tracking-wide text-slate-400 transition-colors duration-300 hover:text-primary-600 dark:text-slate-500 dark:hover:text-primary-500"
            >
                <Icon name="shield-check" class="text-base leading-none" />
                Super Administration
            </a>

            <div class="mt-8 text-center text-xs text-slate-400">
                <Copyright :brand="page.props.site.brand" />
            </div>
        </div>
    </div>
</template>
