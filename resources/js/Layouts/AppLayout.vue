<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

import Sidebar from '@/Components/Layout/Sidebar.vue';
import Header from '@/Components/Layout/Header.vue';
import Footer from '@/Components/Layout/Footer.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import PageSkeleton from '@/Components/Layout/PageSkeleton.vue';
import HrPortalBar from '@/Components/Administration/HrPortalBar.vue';
import PharmacyPortalBar from '@/Components/Pharmacy/PharmacyPortalBar.vue';
import { usePageLoading } from '@/composables/usePageLoading';

import { useThemeSync } from '@/composables/useThemeSync';
import { applyAppearance } from '@/utilities/appearance';

defineProps({
    container: {
        type: Boolean,
        default: false,
    },
});

useThemeSync();

// La page reste montée pendant le chargement (cachée) : si la visite est
// annulée, elle réapparaît telle qu'elle était, saisie comprise.
const pageLoading = usePageLoading();

// ADR-187 / ADR-189 — un écran RH ou Pharmacie d'un site, affiché par le
// portail : sa navigation.
const page = usePage();

// ADR-191 — réappliqué quand l'utilisateur change sa taille de texte, ses animations ou
// son contraste dans « Mon profil » : le serveur ne les pose sur <html> qu'au premier rendu.
watch(() => JSON.stringify(page.props.appearance?.effective ?? null), () => applyAppearance(page.props.appearance?.effective));

const sidebarVisibility = ref(false);
const sidebarCompact = ref(false);
</script>

<template>
    <div class="nk-main">
        <ToastContainer />
        <Sidebar v-model:visibility="sidebarVisibility" v-model:compact="sidebarCompact" />

        <div class="nk-wrap xl:ps-72 [&>.nk-header]:xl:start-72 [&>.nk-header]:xl:w-[calc(100%-theme(spacing.72))] peer-[&.is-compact:not(.has-hover)]:xl:ps-[74px] peer-[&.is-compact:not(.has-hover)]:[&>.nk-header]:xl:start-[74px] peer-[&.is-compact:not(.has-hover)]:[&>.nk-header]:xl:w-[calc(100%-74px)] flex flex-col min-h-screen transition-all duration-300">
            <Header v-model:visibility="sidebarVisibility" />

            <div class="nk-content mt-16 px-1.5 sm:px-5 py-6 sm:py-8">
                <div :class="{ container: true, 'max-w-none': !container }">
                    <PageSkeleton v-if="pageLoading.active" :path="pageLoading.path" />
                    <div v-show="! pageLoading.active">
                        <HrPortalBar v-if="page.props.hrContext" />
                        <PharmacyPortalBar v-if="page.props.pharmacyContext" />
                        <slot />
                    </div>
                </div>
            </div>

            <Footer />
        </div>
    </div>
</template>
