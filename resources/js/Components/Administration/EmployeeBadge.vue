<script setup>
import { computed, ref, watch } from 'vue';
import QRCode from 'qrcode';
import { cn } from '@/lib/cn';
import { initials } from '@/utilities/hr';

/*
 * ADR-194 — le badge professionnel d'un employé, au format carte (CR80,
 * 54 × 85,6 mm) : la même carte à l'écran et à l'impression, à taille réelle.
 *
 * Tout vient du dossier RH : photo 4 × 4, nom, fonction, service, matricule,
 * numéro de badge s'il est renseigné. Le QR ne porte que le matricule — ni
 * date de naissance, ni pièce d'identité, ni rien d'autre du dossier.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    brand: { type: String, default: '' },
    siteName: { type: String, default: '' },
    logoUrl: { type: String, default: null },
    class: { type: String, default: '' },
});
const emit = defineEmits(['ready']);

const qr = ref(null);
const photoFailed = ref(false);
const logoFailed = ref(false);

watch(() => props.employee.employee_number, async (number) => {
    qr.value = number ? await QRCode.toDataURL(String(number), { margin: 0, width: 220, errorCorrectionLevel: 'M' }).catch(() => null) : null;
    emit('ready');
}, { immediate: true });
watch(() => props.employee.photo_url, () => { photoFailed.value = false; });

const lastName = computed(() => (props.employee.last_name || props.employee.name || '').toUpperCase());
const firstName = computed(() => props.employee.last_name ? (props.employee.first_name || '') : '');
const inactive = computed(() => props.employee.archived || !props.employee.active);
</script>

<template>
    <!-- Dimensions en millimètres : c'est la taille réelle, à l'écran comme sur papier. -->
    <article
        :class="cn('employee-badge relative flex shrink-0 flex-col overflow-hidden rounded-[3.2mm] bg-white text-slate-900 shadow-xl ring-1 ring-black/10', props.class)"
        style="width: 54mm; height: 85.6mm;"
        :aria-label="`Badge professionnel de ${employee.name}`"
    >
        <!-- Bandeau de l'établissement -->
        <header class="relative h-[25mm] shrink-0 bg-primary px-[3.5mm] pt-[3mm] text-primary-foreground">
            <span class="absolute -right-[8mm] -top-[10mm] h-[24mm] w-[24mm] rounded-full bg-white/10" aria-hidden="true" />
            <span class="absolute -left-[6mm] top-[12mm] h-[14mm] w-[14mm] rounded-full bg-white/10" aria-hidden="true" />
            <div class="relative flex items-center gap-[2mm]">
                <span class="grid h-[7mm] w-[7mm] shrink-0 place-items-center overflow-hidden rounded-[1.5mm] bg-white text-[2.6mm] font-black text-primary">
                    <img v-if="logoUrl && !logoFailed" :src="logoUrl" alt="" class="h-full w-full object-contain p-[0.6mm]" @error="logoFailed = true">
                    <template v-else>{{ initials(brand || siteName) }}</template>
                </span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-[2.5mm] font-extrabold uppercase tracking-wide">{{ brand || 'Clinique' }}</span>
                    <span class="block truncate text-[2.1mm] opacity-80">{{ siteName }}</span>
                </span>
            </div>
            <p class="relative mt-[2mm] text-[1.9mm] font-bold uppercase tracking-[0.25em] opacity-90">Carte professionnelle</p>
        </header>

        <!-- Photo, à cheval sur le bandeau -->
        <div class="relative z-10 -mt-[9mm] flex justify-center">
            <span class="grid h-[24mm] w-[24mm] place-items-center overflow-hidden rounded-[2mm] border-[0.8mm] border-white bg-slate-100 text-[7mm] font-bold text-slate-500 shadow-md">
                <img v-if="employee.photo_url && !photoFailed" :src="employee.photo_url" :alt="`Photo de ${employee.name}`" class="h-full w-full object-cover" @error="photoFailed = true">
                <template v-else>{{ initials(employee.name) }}</template>
            </span>
        </div>

        <!-- Identité -->
        <div class="mt-[2mm] px-[3mm] text-center">
            <p class="truncate text-[3.6mm] font-black leading-tight tracking-tight">{{ lastName }}</p>
            <p v-if="firstName" class="truncate text-[3mm] font-semibold leading-tight text-slate-700">{{ firstName }}</p>
            <p class="mt-[1.4mm] truncate text-[2.5mm] font-bold leading-tight text-primary">{{ employee.job_title || 'Fonction non renseignée' }}</p>
            <p v-if="employee.department" class="truncate text-[2.2mm] leading-tight text-slate-500">{{ employee.department }}</p>
        </div>

        <!-- Matricule et QR -->
        <footer class="mt-auto flex items-end justify-between gap-[2mm] border-t border-dashed border-slate-200 px-[3.5mm] pb-[3mm] pt-[2mm]">
            <div class="min-w-0 leading-tight">
                <p class="text-[1.8mm] font-bold uppercase tracking-wider text-slate-400">Matricule</p>
                <p class="truncate font-mono text-[3mm] font-bold">{{ employee.employee_number }}</p>
                <template v-if="employee.badge">
                    <p class="mt-[1mm] text-[1.8mm] font-bold uppercase tracking-wider text-slate-400">N° badge</p>
                    <p class="truncate font-mono text-[2.5mm] font-semibold">{{ employee.badge }}</p>
                </template>
            </div>
            <img v-if="qr" :src="qr" alt="QR du matricule" class="h-[15mm] w-[15mm] shrink-0" style="image-rendering: pixelated;">
        </footer>

        <!-- Un dossier qui n'est plus en poste se voit sur la carte elle-même. -->
        <div v-if="inactive" class="pointer-events-none absolute inset-0 grid place-items-center" aria-hidden="true">
            <span class="-rotate-[28deg] rounded-[1mm] border-[0.6mm] border-red-600/70 px-[3mm] py-[1mm] text-[4.5mm] font-black uppercase tracking-widest text-red-600/70">
                {{ employee.archived ? 'Archivé' : 'Inactif' }}
            </span>
        </div>
    </article>
</template>

<style scoped>
/* Les couleurs de la carte s'impriment : sans cela, le bandeau sort blanc. */
.employee-badge,
.employee-badge * {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
</style>
