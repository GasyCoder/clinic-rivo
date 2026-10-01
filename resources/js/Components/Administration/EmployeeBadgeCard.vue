<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Camera, IdCard, Printer } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import EmployeeBadge from '@/Components/Administration/EmployeeBadge.vue';
import { badgeCardOf, badgeFormatName } from '@/utilities/employeeBadge';
import { hrContext, hrUrl } from '@/utilities/hrUrl';
import { settingsUrl } from '@/utilities/settingsSections';

/**
 * ADR-209 — le badge sur la fiche d'un employé : tel qu'il s'imprime, lu dans le
 * dossier. Ce qui manque au badge (la photo) se dit ici, là où on peut le régler.
 */
const props = defineProps({
    employeeUuid: { type: String, required: true },
    /** `{ person, design }`, tel que le serveur le sert. */
    badge: { type: Object, required: true },
    canPrint: { type: Boolean, default: false },
});

const person = computed(() => props.badge.person ?? {});
const landscape = computed(() => props.badge.design?.orientation === 'LANDSCAPE');
const formatName = computed(() => badgeFormatName(props.badge.design?.card_size, badgeCardOf(props.badge.design ?? {})));
const printUrl = computed(() => hrUrl(`/administration/employees/${props.employeeUuid}/badge`));
/** L'apparence se règle sur le portail : le RH d'un site le lit, le Super Admin y va. */
const onPortal = computed(() => Boolean(hrContext()));
const settingsHref = computed(() => settingsUrl('badges', hrContext()?.site?.code ?? ''));
</script>

<template>
    <section class="rounded-xl border border-border bg-card p-5 text-card-foreground shadow-sm" aria-labelledby="employee-badge-title">
        <div class="flex items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <IdCard class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="employee-badge-title" class="text-lg font-semibold leading-tight text-foreground">Badge</h2>
                <p class="mt-0.5 text-sm text-muted-foreground">{{ formatName }}, {{ landscape ? 'en paysage' : 'en portrait' }}, lue dans le dossier.</p>
            </div>
            <Button v-if="canPrint" :as="Link" :href="printUrl" size="sm"><Printer class="h-4 w-4" aria-hidden="true" />Imprimer</Button>
        </div>

        <div :class="['mx-auto mt-4 w-full overflow-hidden shadow-[0_12px_30px_-14px_rgb(15_23_42/0.45)]', landscape ? 'max-w-[20rem]' : 'max-w-[15rem]', badge.design?.corners === 'SQUARE' ? 'rounded-none' : 'rounded-xl']">
            <EmployeeBadge :person="person" :design="badge.design" />
        </div>

        <p v-if="! person.photo_url && badge.design?.show_photo !== false" class="mt-4 flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-[0.8rem] text-muted-foreground">
            <Camera class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Pas de photo : le badge affiche les initiales. La photo se dépose depuis la fiche (« Modifier »).
        </p>
        <p class="mt-3 text-[0.8rem] leading-5 text-muted-foreground">
            Couleurs, textes, polices, disposition et papier se règlent pour le site dans
            <Link v-if="onPortal" :href="settingsHref" class="font-medium text-primary hover:underline">Ressources humaines › Badge du personnel</Link>
            <span v-else class="font-medium text-foreground">Ressources humaines › Badge du personnel</span>
            (Super Admin). Le même modèle vaut pour tout le personnel.
        </p>
    </section>
</template>
