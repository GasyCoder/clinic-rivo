<script setup>
import { Lock } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { onPharmacyPortal, SITE_ONLY_REASON } from '@/utilities/pharmacyUrl';

/**
 * ADR-189 — un geste physique de la Pharmacie (délivrer, servir, entrer en
 * stock, compter l'inventaire, ajuster, réceptionner, imprimer le ticket).
 *
 * Sur le site, le bouton est rendu tel quel (slot). Sur le portail, il est
 * montré verrouillé avec sa raison, plutôt que masqué : un geste masqué se lit
 * « fonction absente » (ADR-158). Le site le refuse de toute façon
 * (`rivo.site-only`) : ce verrou n'est que la parole de l'écran.
 */
defineProps({
    label: { type: String, required: true },
    size: { type: String, default: 'sm' },
    variant: { type: String, default: 'outline' },
    // Dans une ligne de tableau : le cadenas seul, le libellé au survol.
    icon: { type: Boolean, default: false },
});

const onPortal = onPharmacyPortal();
</script>

<template>
    <slot v-if="! onPortal" />
    <span v-else class="inline-flex" :title="`${label} — ${SITE_ONLY_REASON}`">
        <Button type="button" :size="size" :variant="variant" :icon="icon" disabled :aria-label="`${label} — ${SITE_ONLY_REASON}`">
            <Lock class="h-4 w-4" /><template v-if="! icon">{{ label }}</template>
        </Button>
    </span>
</template>
