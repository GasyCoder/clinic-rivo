<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Building2, Info, Server, Users } from 'lucide-vue-next';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrFigures from '@/Components/Administration/HrFigures.vue';

defineOptions({ layout: AppLayout });

/**
 * ADR-066 — the same HR figures as the clinic HR space, per site and for all
 * sites together, read-only. HR work itself stays in each clinic.
 */
const props = defineProps({ sites: Array, summary: Object });

const ALL = 'ALL';
const selected = ref(ALL);
const selectedSite = computed(() => props.sites.find((site) => site.site.code === selected.value) ?? null);
const figures = computed(() => (selected.value === ALL ? props.summary : (selectedSite.value?.data?.summary ?? {})));
const departments = computed(() => selectedSite.value?.data?.departments ?? []);
const maxDepartment = computed(() => Math.max(1, ...departments.value.map((department) => department.employees_count)));
</script>

<template>
    <Head title="Ressources humaines" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Super Administration"
            title="Ressources humaines"
            description="Les mêmes chiffres que l’accueil RH de chaque clinique, en lecture seule. Les dossiers du personnel restent sur leur site."
            icon="briefcase"
            tone="primary"
        >
            <template #actions>
                <span class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm text-muted-foreground">
                    <span :class="['h-2 w-2 rounded-full', summary.online_sites === sites.length ? 'bg-emerald-500' : 'bg-amber-500']" />
                    {{ summary.online_sites }} / {{ sites.length }} sites connectés
                </span>
            </template>
        </PageHeader>

        <div class="flex max-w-full gap-2 overflow-x-auto">
            <button
                type="button"
                :class="['inline-flex shrink-0 items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold shadow-sm transition', selected === ALL ? 'border-primary bg-card text-foreground ' : 'border-border bg-card text-muted-foreground hover:text-foreground ']"
                @click="selected = ALL"
            ><Building2 class="h-4 w-4" />Tous les sites</button>
            <button
                v-for="site in sites"
                :key="site.site.code"
                type="button"
                :class="['inline-flex shrink-0 items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold shadow-sm transition', selected === site.site.code ? 'border-primary bg-card text-foreground ' : 'border-border bg-card text-muted-foreground hover:text-foreground ']"
                @click="selected = site.site.code"
            >
                <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-muted-foreground/40' : 'bg-red-500']" />{{ site.site.name }}
            </button>
        </div>

        <section v-if="selectedSite && !selectedSite.ok" class="flex flex-col items-center justify-center rounded-xl border border-border bg-card px-6 py-12 text-center shadow-sm">
            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-muted text-2xl text-muted-foreground"><Server class="h-4 w-4" /></span>
            <h2 class="mt-4 font-heading text-base font-bold text-foreground">RH indisponible pour {{ selectedSite.site.name }}</h2>
            <p class="mt-1 max-w-lg text-sm text-muted-foreground">{{ selectedSite.message }}</p>
        </section>

        <template v-else>
            <section aria-labelledby="portal-hr-figures">
                <h2 id="portal-hr-figures" class="mb-3 font-heading text-base font-bold text-foreground">{{ selectedSite ? selectedSite.site.name : 'Tous les sites' }} — aujourd’hui</h2>
                <HrFigures :summary="figures" />
            </section>

            <section v-if="selectedSite" class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-lg text-sky-600 dark:bg-sky-950/40 dark:text-sky-300"><Users class="h-4 w-4" /></span>
                    <div>
                        <h2 class="font-heading text-base font-bold text-foreground">Effectif par département</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">Employés actifs de {{ selectedSite.site.name }}.</p>
                    </div>
                </div>
                <div v-if="departments.length" class="mt-4 space-y-3">
                    <div v-for="department in departments" :key="department.uuid || department.label">
                        <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                            <span class="truncate text-muted-foreground">{{ department.label }}</span>
                            <strong class="tabular-nums text-foreground">{{ department.employees_count }}</strong>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-primary/100" :style="{ width: `${Math.max(6, Math.round((department.employees_count / maxDepartment) * 100))}%` }" />
                        </div>
                    </div>
                </div>
                <p v-else class="mt-4 text-sm text-muted-foreground">Aucun employé actif à répartir.</p>
            </section>
            <p v-else class="flex items-start gap-2 px-1 text-sm text-muted-foreground"><Info class="mt-0.5 h-4 w-4" />Choisissez un site pour voir sa répartition par département.</p>
        </template>
    </div>
</template>
