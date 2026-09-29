<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Eye, LockKeyhole } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import { formatDateTime } from '@/utilities/date';

/**
 * ADR-216 — un résultat d'analyse adressé à un confrère : il existe, on le voit,
 * mais ses valeurs ne partent du serveur qu'après confirmation. L'ouverture est
 * tracée à l'audit et vaut pour la session ; le serveur est la seule garde.
 */
const props = defineProps({
    /** `{ recipient, addressed_at, open_url }`, servi par le serveur. */
    seal: { type: Object, required: true },
    /** Une ligne dans une liste plutôt qu'un bloc. */
    compact: { type: Boolean, default: false },
    /** Ouvrir la confirmation dès l'arrivée : la page des résultats elle-même. */
    autoOpen: { type: Boolean, default: false },
});

const confirming = ref(false);
const opening = ref(false);

const recipientText = () => props.seal.recipient ?? 'un autre médecin';

const open = () => {
    opening.value = true;
    router.post(props.seal.open_url, {}, {
        preserveScroll: true,
        onFinish: () => { opening.value = false; confirming.value = false; },
    });
};

onMounted(() => { if (props.autoOpen) confirming.value = true; });
</script>

<template>
    <span v-if="compact" class="inline-flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
        <LockKeyhole class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
        Adressé à {{ recipientText() }}
        <Button type="button" size="xs" variant="ghost" class="h-6 px-1.5" @click="confirming = true">
            <Eye class="h-3.5 w-3.5" /> Ouvrir
        </Button>
    </span>
    <div v-else class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
        <p class="flex gap-2">
            <LockKeyhole class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>
                Ces résultats sont adressés à <strong>{{ recipientText() }}</strong><template v-if="seal.addressed_at"> (envoyés le {{ formatDateTime(seal.addressed_at) }})</template>.
                Vous pouvez les ouvrir : votre consultation sera enregistrée.
            </span>
        </p>
        <Button type="button" size="sm" variant="outline" @click="confirming = true">
            <Eye class="h-4 w-4" /> Ouvrir les résultats
        </Button>
    </div>

    <ConfirmModal
        v-model:open="confirming"
        title="Ouvrir un résultat adressé à un confrère ?"
        :description="`Ces résultats d’analyses sont adressés à ${recipientText()}. Les ouvrir quand même ? Votre consultation est enregistrée dans l’audit, à votre nom.`"
        confirm-label="Ouvrir quand même"
        tone="warning"
        :icon="LockKeyhole"
        :processing="opening"
        @confirm="open"
    >
        <p class="text-sm text-muted-foreground">
            Le médecin destinataire les lit sans confirmation<template v-if="seal.addressed_at"> ; ils lui ont été envoyés le {{ formatDateTime(seal.addressed_at) }}</template>.
        </p>
    </ConfirmModal>
</template>
