<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ImageOff, Loader2, Trash2, Upload } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import { cn } from '@/lib/cn';

/**
 * Un fichier des paramètres — logo, icône, signature ou image de fond —
 * déposé, relu, puis
 * enregistré (ADR-184).
 *
 * Le fichier choisi s'affiche avant d'être envoyé : on voit ce qui partira. Il
 * n'est enregistré que sur « Enregistrer », et retirer celui du site demande
 * une confirmation. Le serveur revérifie le format et la taille ; l'écran ne
 * fait que le dire avant.
 */
const props = defineProps({
    kind: { type: String, required: true },
    label: { type: String, required: true },
    description: { type: String, default: '' },
    /** `{ present, data_url }`, tel que le site le renvoie. */
    asset: { type: Object, default: () => ({ present: false, data_url: null }) },
    siteCode: { type: String, required: true },
    maxKb: { type: Number, required: true },
    mimes: { type: String, required: true },
    readonly: { type: Boolean, default: false },
    /** `wide` (logo), `square` (icône), `paper` (signature, sur fond blanc) ou `cover` (photo de fond). */
    shape: { type: String, default: 'wide' },
    /** Ce qui s'affiche quand rien n'est déposé — l'image par défaut du déploiement. */
    fallbackUrl: { type: String, default: '' },
    fallbackLabel: { type: String, default: 'Par défaut' },
});

const emit = defineEmits(['saved']);

const page = usePage();
const input = ref(null);
const pending = ref(null);
const pendingUrl = ref(null);
const localError = ref('');
const processing = ref(false);
const confirmingRemoval = ref(false);

const accept = computed(() => props.mimes.split(',').map((ext) => `.${ext}`).join(','));
const formats = computed(() => props.mimes.toUpperCase().replaceAll(',', ', '));
const serverError = computed(() => page.props.errors?.file ?? '');
const shownUrl = computed(() => pendingUrl.value ?? props.asset?.data_url ?? null);
/** Rien de déposé : l'image par défaut, marquée comme telle. */
const showsFallback = computed(() => ! shownUrl.value && ! props.asset?.present && Boolean(props.fallbackUrl));

const clearPending = () => {
    if (pendingUrl.value) URL.revokeObjectURL(pendingUrl.value);
    pending.value = null;
    pendingUrl.value = null;
    if (input.value) input.value.value = '';
};

onBeforeUnmount(clearPending);
watch(() => props.siteCode, () => { clearPending(); localError.value = ''; });

const choose = (event) => {
    const file = event.target.files?.[0];
    localError.value = '';

    if (! file) return;

    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (! props.mimes.split(',').includes(extension)) {
        localError.value = `Format accepté : ${formats.value}.`;
        clearPending();
        return;
    }

    if (file.size > props.maxKb * 1024) {
        localError.value = `Le fichier dépasse ${props.maxKb} Ko.`;
        clearPending();
        return;
    }

    clearPending();
    pending.value = file;
    pendingUrl.value = URL.createObjectURL(file);
};

const save = () => {
    if (! pending.value) return;

    router.post(`/super-admin/settings/assets/${props.kind}`, { site_code: props.siteCode, file: pending.value }, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => { processing.value = true; },
        onFinish: () => { processing.value = false; },
        onSuccess: () => {
            clearPending();
            emit('saved');
        },
    });
};

const remove = () => {
    router.delete(`/super-admin/settings/assets/${props.kind}`, {
        data: { site_code: props.siteCode },
        preserveScroll: true,
        onStart: () => { processing.value = true; },
        onFinish: () => { processing.value = false; confirmingRemoval.value = false; },
        onSuccess: () => emit('saved'),
    });
};
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
        <div
            :class="cn(
                'grid shrink-0 place-items-center overflow-hidden rounded-xl border border-dashed border-border',
                shape === 'square' ? 'h-24 w-24' : shape === 'cover' ? 'relative aspect-video w-full sm:w-64' : 'h-24 w-full sm:w-56',
                shape === 'paper' ? 'bg-white' : 'bg-muted/40',
                pending ? 'border-solid border-primary ring-2 ring-primary/20' : '',
            )"
        >
            <img
                v-if="shownUrl || showsFallback"
                :src="shownUrl || fallbackUrl"
                :alt="`Aperçu : ${label}`"
                :class="cn(
                    shape === 'cover' ? 'h-full w-full object-cover' : 'max-h-full max-w-full object-contain',
                    shape === 'square' ? 'p-2' : shape === 'cover' ? '' : 'p-3',
                )"
            />
            <span v-else class="flex flex-col items-center gap-1 text-xs text-muted-foreground"><ImageOff class="h-5 w-5" />Aucun fichier</span>
            <span v-if="showsFallback" class="absolute bottom-1.5 start-1.5 rounded bg-slate-950/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ fallbackLabel }}</span>
        </div>

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-foreground">{{ label }}</p>
            <p v-if="description" class="mt-0.5 text-xs leading-5 text-muted-foreground">{{ description }}</p>
            <p class="mt-1 text-[11px] text-muted-foreground">{{ formats }} · {{ maxKb }} Ko au plus</p>

            <p v-if="pending" class="mt-2 text-xs font-medium text-primary">Nouveau fichier prêt : {{ pending.name }} — pas encore enregistré.</p>
            <p v-if="localError || serverError" class="mt-2 text-xs font-medium text-destructive" role="alert">{{ localError || serverError }}</p>

            <div v-if="! readonly" class="mt-3 flex flex-wrap items-center gap-2">
                <input ref="input" type="file" class="sr-only" :accept="accept" :aria-label="`Choisir : ${label}`" @change="choose" />
                <template v-if="pending">
                    <Button type="button" size="sm" variant="primary" :disabled="processing" @click="save">
                        <Loader2 v-if="processing" class="h-4 w-4 animate-spin" /><Upload v-else class="h-4 w-4" />Enregistrer
                    </Button>
                    <Button type="button" size="sm" variant="ghost" :disabled="processing" @click="clearPending">Annuler</Button>
                </template>
                <template v-else>
                    <Button type="button" size="sm" variant="outline" :disabled="processing" @click="input?.click()">
                        <Upload class="h-4 w-4" />{{ asset?.present ? 'Remplacer' : 'Choisir un fichier' }}
                    </Button>
                    <Button v-if="asset?.present" type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" :disabled="processing" @click="confirmingRemoval = true">
                        <Trash2 class="h-4 w-4" />Retirer
                    </Button>
                </template>
            </div>
        </div>

        <ConfirmModal
            :open="confirmingRemoval"
            :title="`Retirer : ${label.toLowerCase()} ?`"
            description="Le fichier est supprimé du site. L’application reprend ce qu’elle affichait avant qu’il soit déposé."
            confirm-label="Retirer"
            tone="danger"
            :processing="processing"
            @update:open="confirmingRemoval = $event"
            @confirm="remove"
        />
    </div>
</template>
