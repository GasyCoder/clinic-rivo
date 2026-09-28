<script setup>
import { Lock } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import { LAB_SITE_ONLY_REASON, onLabPortal } from '@/utilities/labUrl';

/**
 * ADR-215 — un geste clinique du Laboratoire (réceptionner, prélever, saisir,
 * terminer, valider, renvoyer, confier à l'extérieur, conclure).
 *
 * Sur le site, le bouton est rendu tel quel (slot). Sur le portail, il est
 * montré verrouillé avec sa raison, plutôt que masqué : un geste masqué se lit
 * « fonction absente » (ADR-158). Le site le refuse de toute façon
 * (`rivo.site-only:laboratory`) : ce verrou n'est que la parole de l'écran.
 */
defineProps({
    label: { type: String, required: true },
    size: { type: String, default: 'sm' },
    variant: { type: String, default: 'outline' },
    // Dans une ligne serrée : le cadenas seul, le libellé au survol.
    icon: { type: Boolean, default: false },
});

const onPortal = onLabPortal();
</script>

<template>
    <slot v-if="! onPortal" />
    <span v-else class="inline-flex" :title="`${label} — ${LAB_SITE_ONLY_REASON}`">
        <Button type="button" :size="size" :variant="variant" :icon="icon" disabled :aria-label="`${label} — ${LAB_SITE_ONLY_REASON}`">
            <Lock class="h-4 w-4" /><template v-if="! icon">{{ label }}</template>
        </Button>
    </span>
</template>
