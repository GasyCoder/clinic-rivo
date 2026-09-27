<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import {
    Camera, CheckCircle2, Crop, Grid3x3, ImagePlus, Loader2, Maximize2, Move, RotateCcw, RotateCw, ScanFace, ShieldCheck, Trash2, TriangleAlert,
    Upload, ZoomIn, ZoomOut,
} from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import NoticesButton from '@/Components/Shadcn/NoticesButton.vue';
import Slider from '@/Components/Shadcn/Slider.vue';
import FormError from '@/Components/UI/FormError.vue';
import EmployeePhoto from '@/Components/Administration/EmployeePhoto.vue';
import { cn } from '@/lib/cn';
import {
    MAX_ZOOM, MIN_ZOOM, clampOffset, cropQuality, displaySize, initialOffset, normalizeRotation, rotatedSize, sourceSquare, zoomAtPoint,
} from '@/utilities/photoCrop';

/*
 * ADR-194 — la photo d'identité 4 × 4 d'un dossier RH.
 *
 * On choisit une image (ou on la dépose), on la cadre dans un carré — on la
 * déplace, on zoome, on la tourne d'un quart de tour — et c'est ce carré, en
 * JPEG de 600 px au plus, qui part au serveur. Le fichier d'origine ne part
 * jamais. Le serveur relit et réencode de toute façon : l'écran n'est jamais
 * la seule garde (EmployeePhotoStore).
 *
 * L'image choisie reste en mémoire le temps de la page : « Recadrer » rouvre
 * le même cadrage sans redemander le fichier.
 *
 * Un seul exemplaire est monté ; `open()`, `acceptFile()`, `recrop()` et
 * `removePhoto()` permettent à d'autres zones de l'écran (le grand cadre de
 * l'étape Identité) de s'en servir sans dupliquer le recadrage.
 */
const props = defineProps({
    modelValue: { type: [File, Object], default: null },
    currentUrl: { type: String, default: null },
    remove: { type: Boolean, default: false },
    name: { type: String, default: '' },
    error: { type: String, default: '' },
    // L'étape Identité possède déjà son grand déclencheur : le champ reste
    // monté pour porter l'input et la fenêtre de recadrage, sans second bouton.
    triggerless: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'update:remove']);

const OUTPUT = 600;
const MIN_FRAME = 220;
const MAX_FRAME = 360;
const PREVIEW = 112;
const MAX_BYTES = 15 * 1024 * 1024;
const ACCEPT = ['image/jpeg', 'image/png', 'image/webp'];

const input = ref(null);
const frame = ref(null);
const stage = ref(null);
const localError = ref('');
const newUrl = ref(null);
const reading = ref(false);
const cropping = ref(false);
const saving = ref(false);
const guides = ref(true);
const dropping = ref(false);

// L'image en cours de cadrage, et celle du dernier cadrage validé (pour « Recadrer »).
const draft = ref({ url: null, width: 0, height: 0 });
const committed = ref(null);
const savedCrop = ref(null);

const rotation = ref(0);
const zoom = ref(1);
const offset = ref({ x: 0, y: 0 });
const frameSize = ref(320);
const dragging = ref(false);

const preview = computed(() => newUrl.value || (props.remove ? null : props.currentUrl));
const hasPhoto = computed(() => Boolean(preview.value));
const canRecrop = computed(() => Boolean(committed.value && props.modelValue));

const rotated = computed(() => rotatedSize(draft.value.width, draft.value.height, rotation.value));
const box = computed(() => displaySize(rotated.value.width, rotated.value.height, frameSize.value, zoom.value));
const square = computed(() => sourceSquare(offset.value, rotated.value.width, rotated.value.height, frameSize.value, zoom.value));
const quality = computed(() => cropQuality(square.value.side, OUTPUT));
const zoomPercent = computed(() => Math.round(zoom.value * 100));
const QUALITY = {
    excellent: { label: 'Excellente', hint: 'Nette à l’écran comme sur la fiche imprimée.', icon: CheckCircle2, tone: 'text-emerald-700 bg-emerald-50 ring-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/40 dark:ring-emerald-900' },
    good: { label: 'Bonne', hint: 'Suffisante pour la fiche imprimée.', icon: CheckCircle2, tone: 'text-emerald-700 bg-emerald-50 ring-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/40 dark:ring-emerald-900' },
    low: { label: 'Faible', hint: 'Risque d’être floue à l’impression : dézoomez ou choisissez une image plus grande.', icon: TriangleAlert, tone: 'text-amber-800 bg-amber-50 ring-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:ring-amber-900' },
};
const qualityInfo = computed(() => QUALITY[quality.value.level]);
// Les conseils tiennent derrière le bouton « ! » : la scène et l'aperçu gardent la place.
const cropNotices = [
    { key: 'oval', icon: ScanFace, title: 'Visage', text: 'De face, il remplit l’ovale, du menton au sommet du crâne.' },
    { key: 'turn', icon: RotateCw, title: 'Photo couchée', text: 'Tournez-la d’un quart de tour (R, ou Maj + R).' },
    { key: 'zoom', icon: ZoomIn, title: 'Zoom', text: 'Molette ou pincement, là où vous pointez ; double-clic pour recentrer.' },
    { key: 'private', icon: ShieldCheck, title: 'Confidentialité', text: 'Seul le carré part, en JPEG, à l’enregistrement du dossier ; le fichier d’origine reste sur votre poste. Photo conservée en privé.' },
];

/**
 * L'image telle qu'affichée dans un cadre de `size` px : l'élément garde son
 * orientation d'origine, et la rotation se fait autour du centre de la boîte
 * tournée — la même géométrie que le canevas qui produira le carré.
 */
const imageStyle = (size = frameSize.value) => {
    const scale = size / frameSize.value;
    const quarter = normalizeRotation(rotation.value) % 180 !== 0;
    const width = (quarter ? box.value.height : box.value.width) * scale;
    const height = (quarter ? box.value.width : box.value.height) * scale;
    const cx = (offset.value.x + box.value.width / 2) * scale;
    const cy = (offset.value.y + box.value.height / 2) * scale;

    return {
        width: `${width}px`,
        height: `${height}px`,
        transformOrigin: '0 0',
        transform: `translate(${cx}px, ${cy}px) rotate(${rotation.value}deg) translate(${-width / 2}px, ${-height / 2}px)`,
    };
};

const revoke = (url) => { if (url) URL.revokeObjectURL(url); };
const isCommitted = (url) => Boolean(url && committed.value?.url === url);

watch(() => props.modelValue, (file) => {
    revoke(newUrl.value);
    newUrl.value = file instanceof Blob ? URL.createObjectURL(file) : null;
    // Plus de nouvelle photo (retirée, ou formulaire remis à zéro) : plus rien à recadrer.
    if (!(file instanceof Blob) && committed.value) {
        if (committed.value.url !== draft.value.url) revoke(committed.value.url);
        committed.value = null;
        savedCrop.value = null;
    }
}, { immediate: true });

onBeforeUnmount(() => {
    revoke(newUrl.value);
    revoke(draft.value.url);
    if (committed.value && committed.value.url !== draft.value.url) revoke(committed.value.url);
    resizeObserver?.disconnect();
});

const open = () => input.value?.click();

const acceptFile = (file) => {
    localError.value = '';
    if (!file) return;
    if (!ACCEPT.includes(file.type)) {
        localError.value = 'Choisissez une image JPEG, PNG ou WebP.';
        return;
    }
    if (file.size > MAX_BYTES) {
        localError.value = 'Cette image est trop lourde : 15 Mo au plus avant recadrage.';
        return;
    }

    reading.value = true;
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
        reading.value = false;
        if (image.naturalWidth < 120 || image.naturalHeight < 120) {
            revoke(url);
            localError.value = 'Cette image est trop petite : 120 × 120 pixels au minimum.';
            return;
        }
        if (!isCommitted(draft.value.url)) revoke(draft.value.url);
        draft.value = { url, width: image.naturalWidth, height: image.naturalHeight };
        rotation.value = 0;
        zoom.value = 1;
        offset.value = initialOffset(image.naturalWidth, image.naturalHeight, frameSize.value);
        cropping.value = true;
    };
    image.onerror = () => {
        reading.value = false;
        revoke(url);
        localError.value = 'Cette image ne peut pas être lue par le navigateur : essayez une photo JPEG ou PNG.';
    };
    image.src = url;
};

/** Rouvre le dernier cadrage validé, sur la même image : rien à redemander. */
const recrop = () => {
    if (!canRecrop.value) {
        open();
        return;
    }
    if (!isCommitted(draft.value.url)) revoke(draft.value.url);
    draft.value = { ...committed.value };
    const crop = savedCrop.value;
    const ratio = frameSize.value / crop.frame;
    rotation.value = crop.rotation;
    zoom.value = crop.zoom;
    offset.value = clampOffset({ x: crop.offset.x * ratio, y: crop.offset.y * ratio }, rotated.value.width, rotated.value.height, frameSize.value, crop.zoom);
    cropping.value = true;
};

const onPick = (event) => {
    acceptFile(event.target.files?.[0]);
    event.target.value = '';
};

// --- La scène s'adapte à la place disponible ------------------------------
let resizeObserver = null;
const fitFrame = (available) => {
    const next = Math.round(Math.max(MIN_FRAME, Math.min(MAX_FRAME, available)));
    if (!next || next === frameSize.value) return;
    const ratio = next / frameSize.value;
    frameSize.value = next;
    offset.value = clampOffset({ x: offset.value.x * ratio, y: offset.value.y * ratio }, rotated.value.width, rotated.value.height, next, zoom.value);
};
watch(cropping, (value) => {
    if (!value) {
        resizeObserver?.disconnect();
        return;
    }
    nextTick(() => {
        if (!stage.value) return;
        fitFrame(stage.value.clientWidth);
        if (typeof ResizeObserver !== 'undefined') {
            resizeObserver ??= new ResizeObserver(([entry]) => fitFrame(entry.contentRect.width));
            resizeObserver.observe(stage.value);
        }
        // Le cadre prend le focus : les flèches déplacent aussitôt.
        frame.value?.focus();
    });
});

// --- Cadrage : glisser, pincer, zoomer, tourner, clavier -------------------
const zoomTo = (value, point = { x: frameSize.value / 2, y: frameSize.value / 2 }) => {
    const next = zoomAtPoint(offset.value, rotated.value.width, rotated.value.height, frameSize.value, zoom.value, Number(value), point);
    zoom.value = next.zoom;
    offset.value = next.offset;
};
const move = (dx, dy) => {
    offset.value = clampOffset({ x: offset.value.x + dx, y: offset.value.y + dy }, rotated.value.width, rotated.value.height, frameSize.value, zoom.value);
};
const rotate = (degrees) => {
    rotation.value = normalizeRotation(rotation.value + degrees);
    zoom.value = 1;
    offset.value = initialOffset(rotated.value.width, rotated.value.height, frameSize.value);
};
const resetCrop = () => {
    zoom.value = 1;
    offset.value = initialOffset(rotated.value.width, rotated.value.height, frameSize.value);
};

const pointers = new Map();
let pinch = null;
const framePoint = (x, y) => {
    const rect = frame.value.getBoundingClientRect();
    return { x: x - rect.left, y: y - rect.top };
};
const pinchState = () => {
    const [a, b] = [...pointers.values()];
    return { distance: Math.hypot(a.x - b.x, a.y - b.y) || 1, middle: framePoint((a.x + b.x) / 2, (a.y + b.y) / 2) };
};
const onPointerDown = (event) => {
    event.currentTarget.setPointerCapture?.(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    dragging.value = true;
    if (pointers.size === 2) pinch = { ...pinchState(), zoom: zoom.value };
};
const onPointerMove = (event) => {
    const previous = pointers.get(event.pointerId);
    if (!previous) return;
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (pinch && pointers.size >= 2) {
        const now = pinchState();
        zoomTo(pinch.zoom * (now.distance / pinch.distance), now.middle);
        return;
    }
    move(event.clientX - previous.x, event.clientY - previous.y);
};
const onPointerUp = (event) => {
    pointers.delete(event.pointerId);
    if (pointers.size < 2) pinch = null;
    dragging.value = pointers.size > 0;
};
// Molette de souris comme pavé tactile : un facteur proportionnel au défilement.
const onWheel = (event) => zoomTo(zoom.value * Math.exp(-event.deltaY * 0.0015), framePoint(event.clientX, event.clientY));
const onKey = (event) => {
    const step = event.shiftKey ? 24 : 8;
    const actions = {
        ArrowLeft: () => move(step, 0),
        ArrowRight: () => move(-step, 0),
        ArrowUp: () => move(0, step),
        ArrowDown: () => move(0, -step),
        '+': () => zoomTo(zoom.value + 0.1),
        '=': () => zoomTo(zoom.value + 0.1),
        '-': () => zoomTo(zoom.value - 0.1),
        r: () => rotate(90),
        R: () => rotate(-90),
        0: () => resetCrop(),
    };
    if (actions[event.key]) {
        event.preventDefault();
        actions[event.key]();
    }
};

// Déposer une autre image directement sur la scène.
const onStageDrop = (event) => {
    dropping.value = false;
    acceptFile(event.dataTransfer?.files?.[0]);
};

const cancelCrop = () => {
    cropping.value = false;
    if (!isCommitted(draft.value.url)) revoke(draft.value.url);
    draft.value = committed.value ? { ...committed.value } : { url: null, width: 0, height: 0 };
};

const confirmCrop = async () => {
    saving.value = true;
    localError.value = '';
    const image = new Image();
    image.src = draft.value.url;
    await image.decode().catch(() => {});

    const { width, height } = draft.value;
    const turned = rotated.value;
    const area = square.value;
    const size = Math.max(1, Math.min(OUTPUT, Math.round(area.side)));
    const scale = size / area.side;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, size, size);
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    // Le carré choisi, dans l'image tournée : même géométrie que l'aperçu.
    context.setTransform(scale, 0, 0, scale, -area.x * scale, -area.y * scale);
    context.translate(turned.width / 2, turned.height / 2);
    context.rotate((rotation.value * Math.PI) / 180);
    context.drawImage(image, -width / 2, -height / 2, width, height);

    canvas.toBlob((blob) => {
        saving.value = false;
        if (!blob) {
            localError.value = 'Le recadrage a échoué ; réessayez avec une autre image.';
            return;
        }
        if (committed.value && committed.value.url !== draft.value.url) revoke(committed.value.url);
        committed.value = { ...draft.value };
        savedCrop.value = { rotation: rotation.value, zoom: zoom.value, offset: { ...offset.value }, frame: frameSize.value };
        emit('update:modelValue', new File([blob], 'photo-4x4.jpg', { type: 'image/jpeg' }));
        emit('update:remove', false);
        cropping.value = false;
    }, 'image/jpeg', 0.9);
};

const removePhoto = () => {
    emit('update:modelValue', null);
    emit('update:remove', Boolean(props.currentUrl));
};

defineExpose({ open, acceptFile, recrop, removePhoto, preview, hasPhoto, canRecrop, reading });

const toolClass = 'grid h-8 w-8 shrink-0 place-items-center rounded-lg text-white/75 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40';
</script>

<template>
    <!-- Toujours monté : le grand cadre de l'étape Identité ouvre cet input
         même lorsque le petit déclencheur du composant est masqué. -->
    <input ref="input" type="file" class="sr-only" :accept="ACCEPT.join(',')" tabindex="-1" aria-hidden="true" @change="onPick">

    <div v-if="!triggerless" class="flex items-center gap-3">
        <button
            type="button"
            class="group relative shrink-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="canRecrop ? 'Recadrer la photo d’identité' : hasPhoto ? 'Changer la photo d’identité' : 'Ajouter une photo d’identité'"
            @click="canRecrop ? recrop() : open()"
        >
            <EmployeePhoto :src="preview" :name="name" size="lg" />
            <span class="absolute inset-0 grid place-items-center rounded-lg bg-black/45 text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                <Loader2 v-if="reading" class="h-5 w-5 animate-spin" />
                <component :is="canRecrop ? Crop : Camera" v-else class="h-5 w-5" />
            </span>
        </button>
        <div class="min-w-0">
            <p class="text-xs font-semibold text-foreground">Photo d’identité 4 × 4</p>
            <p class="text-[11px] text-muted-foreground">{{ reading ? 'Lecture de l’image…' : hasPhoto ? (modelValue ? 'Nouvelle photo, envoyée à l’enregistrement' : 'Photo enregistrée') : 'Facultative' }}</p>
            <div class="mt-1 flex flex-wrap gap-1">
                <Button v-if="canRecrop" type="button" size="xs" variant="outline" @click="recrop"><Crop class="h-3.5 w-3.5" />Recadrer</Button>
                <Button type="button" size="xs" variant="outline" @click="open">
                    <Camera class="h-3.5 w-3.5" />{{ hasPhoto ? 'Changer' : 'Ajouter' }}
                </Button>
                <Button v-if="hasPhoto" type="button" size="xs" variant="ghost" class="text-muted-foreground hover:text-destructive" @click="removePhoto">
                    <Trash2 class="h-3.5 w-3.5" />Retirer
                </Button>
            </div>
        </div>
    </div>
    <FormError v-if="localError || error">{{ localError || error }}</FormError>

    <Dialog
        :open="cropping"
        size="xl"
        title="Cadrer la photo d’identité"
        description="Déplacez, zoomez ou tournez la photo : seul le carré sera conservé, au format 4 × 4."
        :dismissible="false"
        content-class="max-h-[calc(100dvh-1rem)] overflow-hidden"
        body-class="max-h-[calc(100dvh-11.5rem)] overflow-y-auto p-0"
        @update:open="(value) => { if (!value) cancelCrop(); }"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Crop class="h-5 w-5" /></span>
        </template>

        <div class="grid md:grid-cols-[minmax(0,1fr)_17rem]">
            <!-- La scène : sombre, pour que seule la photo attire l'œil. -->
            <section class="flex min-w-0 flex-col items-center gap-3 bg-slate-950 px-4 py-5 sm:px-6" aria-label="Zone de recadrage">
                <div
                    ref="stage"
                    class="flex w-full max-w-[22.5rem] justify-center"
                    @dragover.prevent="dropping = true"
                    @dragleave.prevent="dropping = false"
                    @drop.prevent="onStageDrop"
                >
                    <div
                        ref="frame"
                        :class="cn(
                            'relative shrink-0 touch-none select-none overflow-hidden rounded-2xl bg-slate-900 shadow-2xl ring-1 ring-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            dragging ? 'cursor-grabbing' : 'cursor-grab',
                        )"
                        :style="{ width: `${frameSize}px`, height: `${frameSize}px` }"
                        tabindex="0"
                        role="application"
                        aria-roledescription="zone de recadrage"
                        aria-label="Cadre de la photo : glissez, ou flèches pour déplacer ; + et − pour zoomer ; R pour tourner ; 0 pour recentrer"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointercancel="onPointerUp"
                        @wheel.prevent="onWheel"
                        @dblclick="resetCrop"
                        @keydown="onKey"
                    >
                        <img
                            v-if="draft.url"
                            :src="draft.url"
                            alt=""
                            draggable="false"
                            class="pointer-events-none absolute left-0 top-0 max-w-none select-none"
                            :style="imageStyle()"
                        >
                        <!-- Le carré entier est conservé ; l'ovale et les tiers aident
                             seulement à placer le visage comme sur une photo d'identité. -->
                        <div v-if="guides" class="pointer-events-none absolute inset-0" aria-hidden="true">
                            <div class="absolute left-1/2 top-[7%] h-[80%] w-[64%] -translate-x-1/2 rounded-[50%] border-2 border-dashed border-white/90 shadow-[0_0_0_9999px_rgba(2,6,23,0.4)]" />
                            <span class="absolute inset-x-0 top-1/3 border-t border-white/20" />
                            <span class="absolute inset-x-0 top-2/3 border-t border-white/20" />
                            <span class="absolute inset-y-0 left-1/3 border-s border-white/20" />
                            <span class="absolute inset-y-0 left-2/3 border-s border-white/20" />
                        </div>
                        <!-- Les coins du carré conservé. -->
                        <span class="pointer-events-none absolute left-2 top-2 h-5 w-5 rounded-tl-md border-l-[3px] border-t-[3px] border-white" aria-hidden="true" />
                        <span class="pointer-events-none absolute right-2 top-2 h-5 w-5 rounded-tr-md border-r-[3px] border-t-[3px] border-white" aria-hidden="true" />
                        <span class="pointer-events-none absolute bottom-2 left-2 h-5 w-5 rounded-bl-md border-b-[3px] border-l-[3px] border-white" aria-hidden="true" />
                        <span class="pointer-events-none absolute bottom-2 right-2 h-5 w-5 rounded-br-md border-b-[3px] border-r-[3px] border-white" aria-hidden="true" />
                        <!-- Déposer une autre image. -->
                        <div v-if="dropping" class="pointer-events-none absolute inset-0 grid place-items-center bg-primary/70 text-center text-sm font-semibold text-white backdrop-blur-sm">
                            <span class="flex flex-col items-center gap-2"><Upload class="h-6 w-6" />Déposer pour remplacer</span>
                        </div>
                    </div>
                </div>

                <!-- Outils -->
                <div class="flex w-full max-w-[22.5rem] items-center gap-0.5 rounded-xl bg-white/5 p-1 ring-1 ring-white/10" role="toolbar" aria-label="Outils de recadrage">
                    <button type="button" :class="toolClass" title="Tourner à gauche (Maj + R)" aria-label="Tourner d’un quart de tour à gauche" @click="rotate(-90)"><RotateCcw class="h-4 w-4" /></button>
                    <button type="button" :class="toolClass" title="Tourner à droite (R)" aria-label="Tourner d’un quart de tour à droite" @click="rotate(90)"><RotateCw class="h-4 w-4" /></button>
                    <span class="mx-1 hidden h-5 w-px bg-white/15 sm:block" aria-hidden="true" />
                    <button type="button" :class="toolClass" title="Dézoomer (−)" aria-label="Dézoomer" :disabled="zoom <= MIN_ZOOM" @click="zoomTo(zoom - 0.2)"><ZoomOut class="h-4 w-4" /></button>
                    <Slider
                        :model-value="zoom"
                        :min="MIN_ZOOM"
                        :max="MAX_ZOOM"
                        :step="0.01"
                        tone="dark"
                        aria-label="Zoom"
                        :aria-value-text="`${zoomPercent} %`"
                        class="mx-1.5 min-w-12 flex-1"
                        @update:model-value="zoomTo"
                    />
                    <button type="button" :class="toolClass" title="Zoomer (+)" aria-label="Zoomer" :disabled="zoom >= MAX_ZOOM" @click="zoomTo(zoom + 0.2)"><ZoomIn class="h-4 w-4" /></button>
                    <span class="hidden w-10 shrink-0 text-center text-[11px] font-semibold tabular-nums text-white/70 sm:inline-block" aria-hidden="true">{{ zoomPercent }} %</span>
                    <span class="mx-1 hidden h-5 w-px bg-white/15 sm:block" aria-hidden="true" />
                    <button
                        type="button"
                        :class="cn(toolClass, guides && 'bg-white/10 text-white')"
                        :aria-pressed="guides"
                        :title="guides ? 'Masquer les repères' : 'Afficher les repères'"
                        aria-label="Repères du visage"
                        @click="guides = !guides"
                    ><Grid3x3 class="h-4 w-4" /></button>
                    <button type="button" :class="toolClass" title="Recentrer (0 ou double-clic)" aria-label="Recentrer" @click="resetCrop"><Maximize2 class="h-4 w-4" /></button>
                </div>
                <p class="flex items-center gap-1.5 text-center text-[11px] text-white/60"><Move class="h-3.5 w-3.5 shrink-0" />Glissez la photo · molette ou pincement pour zoomer · double-clic pour recentrer</p>
            </section>

            <!-- Aperçu, qualité, conseils -->
            <aside class="space-y-4 border-t border-border bg-card p-4 md:border-s md:border-t-0">
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Aperçu</p>
                        <NoticesButton :notices="cropNotices" heading="Bien cadrer la photo" />
                    </div>
                    <div class="mt-2 flex items-end gap-4">
                        <figure class="flex flex-col items-center gap-1.5">
                            <div class="relative overflow-hidden rounded-lg bg-muted shadow-sm ring-1 ring-border" :style="{ width: `${PREVIEW}px`, height: `${PREVIEW}px` }">
                                <img v-if="draft.url" :src="draft.url" alt="Aperçu de la photo recadrée" class="pointer-events-none absolute left-0 top-0 max-w-none select-none" :style="imageStyle(PREVIEW)">
                            </div>
                            <figcaption class="text-[10px] text-muted-foreground">Fiche · 4 × 4</figcaption>
                        </figure>
                        <figure class="flex flex-col items-center gap-1.5">
                            <div class="relative h-11 w-11 overflow-hidden rounded-full bg-muted shadow-sm ring-1 ring-border">
                                <img v-if="draft.url" :src="draft.url" alt="" class="pointer-events-none absolute left-0 top-0 max-w-none select-none" :style="imageStyle(44)">
                            </div>
                            <figcaption class="text-[10px] text-muted-foreground">Listes</figcaption>
                        </figure>
                    </div>
                    <p v-if="name" class="mt-2 truncate text-xs font-semibold text-foreground">{{ name }}</p>
                </div>

                <div :class="cn('rounded-xl p-3 ring-1', qualityInfo.tone)" role="status">
                    <p class="flex items-center gap-1.5 text-xs font-bold">
                        <component :is="qualityInfo.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />Qualité : {{ qualityInfo.label }}
                        <span class="ms-auto font-mono text-[10px] font-semibold opacity-80">{{ quality.pixels }} × {{ quality.pixels }} px</span>
                    </p>
                    <p class="mt-1 text-[11px] leading-4 opacity-90">{{ qualityInfo.hint }}</p>
                </div>

            </aside>
        </div>
        <template #footer>
            <Button type="button" variant="ghost" class="sm:me-auto" @click="open"><ImagePlus class="h-4 w-4" />Choisir une autre</Button>
            <Button type="button" variant="outline" @click="cancelCrop">Annuler</Button>
            <Button type="button" :disabled="saving" @click="confirmCrop">
                <Loader2 v-if="saving" class="h-4 w-4 animate-spin" /><Crop v-else class="h-4 w-4" />{{ saving ? 'Préparation…' : 'Rogner et utiliser' }}
            </Button>
        </template>
    </Dialog>
</template>
