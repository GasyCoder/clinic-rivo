<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import { Camera, CircleCheck, DoorOpen, Search, ShieldCheck, X } from 'lucide-vue-next';
import { formatDateTime } from '@/utilities/date';
import { formatPatientName } from '@/utilities/patient';

defineOptions({ layout: AppLayout });

/**
 * ADR-116 — le poste de gardiennage : contrôle de sortie à la porte.
 *
 * Le gardien ne décide jamais une sortie — la Caisse l'a déjà prononcée
 * (ADR-090) — il la constate. C'est la « Signature Service Sécurité » du
 * ticket de sortie, remplacée par une action authentifiée plutôt qu'une
 * signature à l'encre, tracée sous `episode.exit_control`.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    toControl: { type: Array, required: true },
    controlled: { type: Array, required: true },
    capabilities: { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');
watch(() => props.filters.q, (value) => { search.value = value ?? ''; });

const submitSearch = () => {
    stopScanner();
    router.get('/guarding', search.value ? { q: search.value } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

/* ------------------------------------------------------------------ *
 * Scanner QR — même mécanisme que le contrôle des tickets Pharmacie à
 * la Caisse (ADR-050), pour la même raison : présenter le papier va plus
 * vite que le taper.
 * ------------------------------------------------------------------ */
const scannerActive = ref(false);
const scannerError = ref('');
const scannerVideo = ref(null);
let qrScanner = null;

const stopScanner = () => {
    scannerActive.value = false;
    qrScanner?.destroy();
    qrScanner = null;
    if (scannerVideo.value) scannerVideo.value.srcObject = null;
};

const startScanner = async () => {
    scannerError.value = '';

    if (!window.isSecureContext) {
        scannerError.value = 'L’accès à la caméra exige une connexion sécurisée HTTPS.';
        return;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        scannerError.value = 'Ce navigateur ne permet pas l’accès à la caméra. Saisissez la référence à la main.';
        return;
    }

    try {
        scannerActive.value = true;
        await nextTick();
        const { default: QrScanner } = await import('qr-scanner');

        if (!scannerActive.value || !scannerVideo.value) return;

        qrScanner = new QrScanner(scannerVideo.value, (result) => {
            const value = result?.data?.trim();
            if (!value) return;

            search.value = value;
            stopScanner();
            submitSearch();
        }, {
            preferredCamera: 'environment',
            maxScansPerSecond: 10,
            returnDetailedScanResult: true,
            onDecodeError: () => {},
        });

        await qrScanner.start();
    } catch (error) {
        stopScanner();
        scannerError.value = ['NotAllowedError', 'SecurityError'].includes(error?.name)
            ? 'Accès à la caméra refusé. Autorisez la caméra pour ce site, puis réessayez.'
            : 'Impossible de démarrer la caméra. Saisissez la référence à la main.';
    }
};

onBeforeUnmount(stopScanner);

/* ------------------------------------------------------------------ *
 * Confirmer la sortie — un constat, jamais une décision : aucun motif
 * n'est exigé, un seul par passage (RecordExitControlAction).
 * ------------------------------------------------------------------ */
const target = ref(null);
const form = useForm({ notes: '' });

const openConfirm = (episode) => {
    target.value = episode;
    form.reset();
    form.clearErrors();
};

const confirmExit = () => {
    form.post(`/guarding/passages/${target.value.uuid}/sortie`, {
        preserveScroll: true,
        onSuccess: () => { target.value = null; },
    });
};

const exitTypeTone = (type) => (type === 'DISCHARGED_DEBT' ? 'warning' : 'success');
</script>

<template>
    <Head title="Gardiennage — Contrôle de sortie" />

    <div class="mx-auto w-full max-w-5xl space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><ShieldCheck class="h-5 w-5" /></span>
                <div>
                    <h1 class="font-heading text-xl font-bold text-foreground">Gardiennage — Contrôle de sortie</h1>
                    <p class="text-sm text-muted-foreground">Vérifiez que la sortie a été enregistrée par la Caisse, puis constatez le départ.</p>
                </div>
            </div>
            <Button v-if="capabilities.can_view_visitors" :as="Link" href="/reception/visitors" size="sm" variant="white-outline">
                Registre des visiteurs
            </Button>
        </header>

        <Card class="p-4">
            <form class="flex flex-wrap items-center gap-2" @submit.prevent="submitSearch">
                <div class="relative min-w-0 flex-1">
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-muted-foreground" />
                    <Input v-model="search" type="search" class="ps-9" placeholder="N° de dossier, N° de passage, nom du patient" />
                </div>
                <Button type="button" variant="white-outline" :disabled="scannerActive" @click="startScanner">
                    <Camera class="h-4 w-4" />Scanner le QR
                </Button>
                <Button type="submit"><Search class="h-4 w-4" />Rechercher</Button>
            </form>

            <div v-if="scannerActive || scannerError" class="mt-3">
                <div v-if="scannerActive" class="relative mx-auto max-w-md overflow-hidden rounded-lg border border-primary/30 bg-slate-950">
                    <video ref="scannerVideo" class="aspect-video w-full object-cover" playsinline muted />
                    <div class="pointer-events-none absolute inset-0 flex items-center justify-center"><span class="h-32 w-32 rounded-lg border-2 border-white/80 shadow-[0_0_0_999px_rgba(15,23,42,.35)]" /></div>
                    <button type="button" class="absolute end-3 top-3 inline-flex h-8 items-center gap-1.5 rounded bg-card px-2.5 text-xs font-bold text-foreground shadow" @click="stopScanner"><X class="h-3.5 w-3.5" /> Fermer</button>
                </div>
                <p v-if="scannerError" class="mx-auto mt-2 max-w-md rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">{{ scannerError }}</p>
            </div>
        </Card>

        <Card>
            <div class="border-b border-border px-4 py-3">
                <h2 class="font-heading text-sm font-bold text-foreground">À contrôler ({{ toControl.length }})</h2>
                <p class="text-xs text-muted-foreground">Sortie enregistrée par la Caisse, pas encore constatée à la porte.</p>
            </div>
            <div class="divide-y divide-border">
                <div v-for="episode in toControl" :key="episode.uuid" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-foreground">{{ formatPatientName(episode.patient) }} <span class="font-mono text-xs font-normal text-muted-foreground">· {{ episode.patient?.patient_number }}</span></p>
                        <p class="mt-0.5 text-xs text-muted-foreground">Passage {{ episode.episode_number }} · {{ formatDateTime(episode.administrative_exit_at) }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge :variant="exitTypeTone(episode.administrative_status)">{{ episode.administrative_exit_type_label }}</Badge>
                        <Button v-if="capabilities.can_close" type="button" size="sm" @click="openConfirm(episode)">
                            <DoorOpen class="h-4 w-4" />Confirmer la sortie
                        </Button>
                    </div>
                </div>
                <p v-if="toControl.length === 0" class="px-4 py-8 text-center text-sm text-muted-foreground">Aucun passage en attente de contrôle.</p>
            </div>
        </Card>

        <Card>
            <div class="border-b border-border px-4 py-3">
                <h2 class="font-heading text-sm font-bold text-foreground">Contrôlés récemment</h2>
            </div>
            <div class="divide-y divide-border">
                <div v-for="episode in controlled" :key="episode.uuid" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-foreground">{{ formatPatientName(episode.patient) }} <span class="font-mono text-xs font-normal text-muted-foreground">· {{ episode.patient?.patient_number }}</span></p>
                        <p class="mt-0.5 text-xs text-muted-foreground">Passage {{ episode.episode_number }} · sorti le {{ formatDateTime(episode.exit_control.controlled_at) }}<template v-if="episode.exit_control.controlled_by"> par {{ episode.exit_control.controlled_by }}</template></p>
                    </div>
                    <CircleCheck class="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                </div>
                <p v-if="controlled.length === 0" class="px-4 py-8 text-center text-sm text-muted-foreground">Aucune sortie constatée récemment.</p>
            </div>
        </Card>
    </div>

    <Dialog
        v-if="target"
        :open="!!target"
        title="Confirmer la sortie ?"
        description="Cette action constate le départ physique du patient. Elle ne peut être enregistrée qu'une seule fois par passage."
        :dismissible="false"
        @update:open="target = $event ? target : null"
    >
        <template #icon>
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary"><DoorOpen class="h-5 w-5" /></span>
        </template>

        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Patient</dt><dd class="font-semibold text-foreground">{{ formatPatientName(target.patient) }} · {{ target.episode_number }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Sortie</dt><dd class="font-semibold text-foreground">{{ target.administrative_exit_type_label }}</dd></div>
        </dl>
        <label class="mt-3 block text-sm">
            <span class="mb-1.5 block font-medium text-foreground">Observation <span class="font-normal text-muted-foreground">(facultatif)</span></span>
            <Textarea v-model="form.notes" rows="2" />
        </label>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="target = null">Annuler</Button>
            <Button type="button" :disabled="form.processing" @click="confirmExit">
                <DoorOpen class="h-4 w-4" />{{ form.processing ? 'Confirmation…' : 'Confirmer la sortie' }}
            </Button>
        </template>
    </Dialog>
</template>
