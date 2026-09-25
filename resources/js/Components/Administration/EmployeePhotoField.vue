<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Camera, Crop, Move, Trash2, ZoomIn, ZoomOut } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormError from '@/Components/UI/FormError.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import {
    MAX_ZOOM, MIN_ZOOM, clampOffset, displaySize, initialOffset, sourceSquare, zoomAround,
} from '@/utilities/photoCrop';

/*
 * ADR-194 — la photo d'identité 4 × 4 d'un dossier RH.
 *
 * On choisit une image (ou on la dépose), on la cadre dans un carré — on la
 * déplace, on zoome — et c'est ce carré, en JPEG de 600 px, qui part au
 * serveur. Le serveur le relit et le réencode de toute façon : l'écran
 * n'est jamais la seule garde (EmployeePhotoStore).
 *
 * Un seul exemplaire est monté ; `open()` et `acceptFile()` permettent à
 * d'autres zones de l'écran (le grand cadre de l'étape Identité) de s'en
 * servir sans dupliquer le recadrage.
 */
const props = defineProps({
    modelValue: { type: [File, Object], default: null },
    currentUrl: { type: String, default: null },
    remove: { type: Boolean, default: false },
    name: { type: String, default: '' },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'update:remove']);

const FRAME = 288;
const OUTPUT = 600;
const ACCEPT = ['image/jpeg', 'image/png', 'image/webp'];

const input = ref(null);
const localError = ref('');
const newUrl = ref(null);
const cropping = ref(false);
const raw = ref({ url: null, width: 0, height: 0 });
const zoom = ref(1);
const offset = ref({ x: 0, y: 0 });
const saving = ref(false);
let drag = null;

const preview = computed(() => newUrl.value || (props.remove ? null : props.currentUrl));
const hasPhoto = computed(() => Boolean(preview.value));
const imageSize = computed(() => displaySize(raw.value.width, raw.value.height, FRAME, zoom.value));

watch(() => props.modelValue, (file) => {
    if (newUrl.value) URL.revokeObjectURL(newUrl.value);
    newUrl.value = file instanceof Blob ? URL.createObjectURL(file) : null;
});
onBeforeUnmount(() => {
    if (newUrl.value) URL.revokeObjectURL(newUrl.value);
    if (raw.value.url) URL.revokeObjectURL(raw.value.url);
});

const open = () => input.value?.click();

const acceptFile = (file) => {
    localError.value = '';
    if (!file) return;
    if (!ACCEPT.includes(file.type)) {
        localError.value = 'Choisissez une image JPEG, PNG ou WebP.';
        return;
    }
    if (file.size > 15 * 1024 * 1024) {
        localError.value = 'Cette image est trop lourde (15 Mo au plus avant recadrage).';
        return;
    }

    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
        if (image.naturalWidth < 120 || image.naturalHeight < 120) {
            URL.revokeObjectURL(url);
            localError.value = 'Cette image est trop petite : 120 × 120 pixels au minimum.';
            return;
        }
        if (raw.value.url) URL.revokeObjectURL(raw.value.url);
        raw.value = { url, width: image.naturalWidth, height: image.naturalHeight };
        zoom.value = 1;
        offset.value = initialOffset(image.naturalWidth, image.naturalHeight, FRAME);
        cropping.value = true;
    };
    image.onerror = () => {
        URL.revokeObjectURL(url);
        localError.value = 'Cette image ne peut pas être lue.';
    };
    image.src = url;
};

const onPick = (event) => {
    acceptFile(event.target.files?.[0]);
    event.target.value = '';
};

// --- Cadrage : glisser, zoomer, clavier --------------------------------------
const setZoom = (value) => {
    const next = zoomAround(offset.value, raw.value.width, raw.value.height, FRAME, zoom.value, Number(value));
    zoom.value = next.zoom;
    offset.value = next.offset;
};
const move = (dx, dy) => {
    offset.value = clampOffset({ x: offset.value.x + dx, y: offset.value.y + dy }, raw.value.width, raw.value.height, FRAME, zoom.value);
};
const startDrag = (event) => {
    drag = { x: event.clientX, y: event.clientY };
    event.currentTarget.setPointerCapture?.(event.pointerId);
};
const onDrag = (event) => {
    if (!drag) return;
    move(event.clientX - drag.x, event.clientY - drag.y);
    drag = { x: event.clientX, y: event.clientY };
};
const endDrag = () => { drag = null; };
const onWheel = (event) => setZoom(zoom.value + (event.deltaY < 0 ? 0.1 : -0.1));
const onKey = (event) => {
    const step = event.shiftKey ? 24 : 8;
    const actions = {
        ArrowLeft: () => move(step, 0),
        ArrowRight: () => move(-step, 0),
        ArrowUp: () => move(0, step),
        ArrowDown: () => move(0, -step),
        '+': () => setZoom(zoom.value + 0.1),
        '=': () => setZoom(zoom.value + 0.1),
        '-': () => setZoom(zoom.value - 0.1),
    };
    if (actions[event.key]) {
        event.preventDefault();
        actions[event.key]();
    }
};

const cancelCrop = () => {
    cropping.value = false;
};

const confirmCrop = async () => {
    saving.value = true;
    const image = new Image();
    image.src = raw.value.url;
    await image.decode().catch(() => {});

    const square = sourceSquare(offset.value, raw.value.width, raw.value.height, FRAME, zoom.value);
    const size = Math.min(OUTPUT, Math.round(square.side));
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, size, size);
    context.imageSmoothingQuality = 'high';
    context.drawImage(image, square.x, square.y, square.side, square.side, 0, 0, size, size);

    canvas.toBlob((blob) => {
        saving.value = false;
        if (!blob) {
            localError.value = 'Le recadrage a échoué ; réessayez avec une autre image.';
            return;
        }
        emit('update:modelValue', new File([blob], 'photo-4x4.jpg', { type: 'image/jpeg' }));
        emit('update:remove', false);
        cropping.value = false;
    }, 'image/jpeg', 0.9);
};

const removePhoto = () => {
    emit('update:modelValue', null);
    emit('update:remove', Boolean(props.currentUrl));
};

// Ouvrir le cadrage donne le focus au cadre : les flèches déplacent aussitôt.
const frame = ref(null);
watch(cropping, (value) => { if (value) nextTick(() => frame.value?.focus()); });

defineExpose({ open, acceptFile, preview });
</script>

<template>
    <div class="flex items-center gap-3">
        <button
            type="button"
            class="group relative shrink-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="hasPhoto ? 'Changer la photo d’identité' : 'Ajouter une photo d’identité'"
            @click="open"
        >
            <EmployeePhoto :src="preview" :name="name" size="lg" />
            <span class="absolute inset-0 grid place-items-center rounded-lg bg-black/45 text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                <Camera class="h-5 w-5" />
            </span>
        </button>
        <div class="min-w-0">
            <p class="text-xs font-semibold text-foreground">Photo d’identité 4 × 4</p>
            <p class="text-[11px] text-muted-foreground">{{ hasPhoto ? (modelValue ? 'Nouvelle photo, envoyée à l’enregistrement' : 'Photo enregistrée') : 'Facultative' }}</p>
            <div class="mt-1 flex flex-wrap gap-1">
                <Button type="button" size="xs" variant="outline" @click="open">
                    <Camera class="h-3.5 w-3.5" />{{ hasPhoto ? 'Changer' : 'Ajouter' }}
                </Button>
                <Button v-if="hasPhoto" type="button" size="xs" variant="ghost" class="text-muted-foreground hover:text-destructive" @click="removePhoto">
                    <Trash2 class="h-3.5 w-3.5" />Retirer
                </Button>
            </div>
        </div>
        <input ref="input" type="file" class="sr-only" :accept="ACCEPT.join(',')" tabindex="-1" aria-hidden="true" @change="onPick">
    </div>
    <FormError v-if="localError || error">{{ localError || error }}</FormError>

    <Dialog
        :open="cropping"
        title="Cadrer la photo d’identité"
        description="Déplacez la photo et zoomez pour que le visage remplisse le cadre, comme sur une photo 4 × 4."
        :dismissible="false"
        @update:open="(value) => { if (!value) cancelCrop(); }"
    >
        <div class="flex flex-col items-center gap-4">
            <div
                ref="frame"
                class="relative cursor-grab touch-none overflow-hidden rounded-lg bg-muted ring-1 ring-border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
                :style="{ width: `${FRAME}px`, height: `${FRAME}px` }"
                tabindex="0"
                role="application"
                aria-label="Cadre de la photo : glissez ou utilisez les flèches pour déplacer, + et − pour zoomer"
                @pointerdown="startDrag"
                @pointermove="onDrag"
                @pointerup="endDrag"
                @pointercancel="endDrag"
                @wheel.prevent="onWheel"
                @keydown="onKey"
            >
                <img
                    v-if="raw.url"
                    :src="raw.url"
                    alt=""
                    draggable="false"
                    class="pointer-events-none absolute left-0 top-0 max-w-none select-none"
                    :style="{ width: `${imageSize.width}px`, height: `${imageSize.height}px`, transform: `translate(${offset.x}px, ${offset.y}px)` }"
                >
                <!-- Repère du visage : un ovale et les tiers, comme un gabarit de photo d'identité. -->
                <div class="pointer-events-none absolute inset-0">
                    <div class="absolute left-1/2 top-[14%] h-[62%] w-[52%] -translate-x-1/2 rounded-[50%] border-2 border-dashed border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.25)]" />
                </div>
            </div>
            <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><Move class="h-3.5 w-3.5" />Glissez la photo · molette ou curseur pour zoomer</p>
            <label class="flex w-full max-w-[18rem] items-center gap-3">
                <ZoomOut class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="sr-only">Zoom</span>
                <input
                    type="range"
                    class="h-1.5 w-full cursor-pointer accent-primary"
                    :min="MIN_ZOOM"
                    :max="MAX_ZOOM"
                    step="0.01"
                    :value="zoom"
                    @input="setZoom($event.target.value)"
                >
                <ZoomIn class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            </label>
        </div>
        <template #footer>
            <Button type="button" variant="outline" @click="cancelCrop">Annuler</Button>
            <Button type="button" :disabled="saving" @click="confirmCrop"><Crop class="h-4 w-4" />Valider le cadrage</Button>
        </template>
    </Dialog>
</template>
