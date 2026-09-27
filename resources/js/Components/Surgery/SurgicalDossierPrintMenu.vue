<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import Popover from '@/Components/Shadcn/Popover.vue';
import { Activity, ChevronDown, FileText, FlaskConical, Layers, LogIn, LogOut, Printer, UserCheck } from 'lucide-vue-next';

/**
 * ADR-172 — « Imprimer le dossier » : le Dossier chirurgical de la clinique,
 * généré depuis les données, entier (quatre feuilles) ou une seule feuille.
 *
 * Le menu ne propose que ce que le compte pourra lire : les feuilles du bloc
 * avec `surgery.view`, celles de l'anesthésie avec `anesthesia.view`. Le
 * serveur revérifie de toute façon, feuille par feuille.
 */
const props = defineProps({
    surgicalRequestUuid: { type: String, required: true },
    /** `surgery` ou `anesthesia` : d'où l'on vient, pour que « Retour » y ramène. */
    workspace: { type: String, default: 'surgery' },
    canViewSurgery: { type: Boolean, default: true },
    canViewAnesthesia: { type: Boolean, default: false },
});

const open = ref(false);

const base = computed(() => `/surgery/${props.surgicalRequestUuid}/dossier`);
const href = (sheet) => `${base.value}?${sheet ? `feuille=${sheet}&` : ''}from=${props.workspace}`;

const sheets = computed(() => [
    { key: 'entry', title: 'Entrée du patient au bloc', icon: LogIn, allowed: props.canViewSurgery },
    { key: 'exit', title: 'Sortie du patient au bloc', icon: LogOut, allowed: props.canViewSurgery },
    { key: 'consultation', title: 'Consultation pré-anesthésique', icon: UserCheck, allowed: props.canViewAnesthesia },
    { key: 'paraclinical', title: 'Examen paraclinique', icon: Activity, allowed: props.canViewAnesthesia },
].filter((sheet) => sheet.allowed));
</script>

<template>
    <Popover v-model:open="open" width-class="w-[min(20rem,calc(100vw-2rem))]">
        <template #trigger>
            <Button type="button" variant="outline" size="sm" :aria-expanded="open">
                <Printer class="h-4 w-4" aria-hidden="true" />Imprimer le dossier
                <ChevronDown class="h-3.5 w-3.5 opacity-70" aria-hidden="true" />
            </Button>
        </template>

        <nav class="p-1.5" aria-label="Feuilles du dossier chirurgical">
            <Link
                :href="href(null)"
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-foreground hover:bg-muted focus:bg-muted focus:outline-none"
                @click="open = false"
            >
                <Layers class="h-4 w-4 text-primary" aria-hidden="true" />
                Tout le dossier
                <span class="ms-auto text-xs font-normal text-muted-foreground">{{ sheets.length }} feuille{{ sheets.length > 1 ? 's' : '' }}</span>
            </Link>
            <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Une seule feuille</p>
            <Link
                v-for="sheet in sheets"
                :key="sheet.key"
                :href="href(sheet.key)"
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-foreground hover:bg-muted focus:bg-muted focus:outline-none"
                @click="open = false"
            >
                <component :is="sheet.icon" class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                {{ sheet.title }}
            </Link>
            <p class="flex items-start gap-1.5 px-3 pb-1 pt-2 text-[11px] text-muted-foreground">
                <FileText class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
                Généré depuis les données saisies ; le PDF est celui du navigateur.
            </p>
        </nav>
    </Popover>
</template>
