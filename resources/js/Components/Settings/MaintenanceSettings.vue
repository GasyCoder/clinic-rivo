<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import {
    CalendarClock, CircleCheck, Construction, History, Info, KeyRound, Loader2, Play, RotateCcw, Save, ShieldCheck, TriangleAlert, Unlock, Zap,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import DateTimePicker from '@/Components/Shadcn/DateTimePicker.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Label from '@/Components/Shadcn/Label.vue';
import RadioGroup from '@/Components/Shadcn/RadioGroup.vue';
import RadioGroupItem from '@/Components/Shadcn/RadioGroupItem.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import MaintenanceNotice from '@/Components/Maintenance/MaintenanceNotice.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import {
    MAINTENANCE_DURATIONS, MAINTENANCE_STATES, addMinutes, nextMinuteInput, returnLabel, toLocalInput, whenLabel, windowLabel,
} from '@/utilities/maintenance';

/**
 * ADR-193 — la maintenance d'un site : la mettre tout de suite, la programmer, en
 * changer le message ou la fin, la lever. Chaque geste part tout de suite par
 * l'API du site, avec ses propres droits (`app_maintenance.update`) : ce module
 * n'a rien dans le formulaire commun des paramètres.
 *
 * Le serveur décide de tout (état, heures, droits) ; l'écran ne fait que le dire.
 * Aucun formulaire HTML dans ce module : il vit dans celui des paramètres, et un
 * formulaire imbriqué serait invalide.
 */
const props = defineProps({
    maintenance: { type: Object, default: () => ({}) },
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    isPortal: { type: Boolean, default: false },
});

const { can } = usePermissions();
const canManage = computed(() => can('app_maintenance.update'));

const current = computed(() => props.maintenance?.current ?? null);
const history = computed(() => props.maintenance?.history ?? []);
const defaults = computed(() => props.maintenance?.defaults ?? { title: 'Maintenance en cours', message: '' });
const isActive = computed(() => current.value?.state === 'ACTIVE');
const isUpcoming = computed(() => current.value?.state === 'UPCOMING');

/* ------------------------------------------------------------------ */
/* Le formulaire : repart de ce que le site porte                      */
/* ------------------------------------------------------------------ */

const valuesOf = () => ({
    mode: isUpcoming.value ? 'scheduled' : 'now',
    title: current.value?.title ?? defaults.value.title,
    message: current.value ? (current.value.message ?? '') : defaults.value.message,
    starts_at: isUpcoming.value ? toLocalInput(current.value.starts_at) : '',
    ends_at: toLocalInput(current.value?.ends_at),
});

const form = useForm(valuesOf());

const load = () => {
    form.defaults(valuesOf());
    form.reset();
    form.clearErrors();
};

watch(() => [props.siteCode, current.value?.uuid, current.value?.state, current.value?.title, current.value?.message, current.value?.starts_at, current.value?.ends_at].join('|'), load);

const scheduled = computed(() => form.mode === 'scheduled');
const minStart = computed(() => nextMinuteInput());
/** Le début des durées rapides : maintenant, le début déjà en cours, ou le début programmé. */
const durationBase = computed(() => {
    if (scheduled.value) return form.starts_at;
    if (isActive.value) return toLocalInput(current.value.starts_at);

    return toLocalInput(new Date());
});
const setDuration = (minutes) => {
    const base = durationBase.value;
    if (base) form.ends_at = addMinutes(base, minutes);
};

const resetMessage = () => {
    form.title = defaults.value.title;
    form.message = defaults.value.message;
};

/* ------------------------------------------------------------------ */
/* Ce que le bouton fait, et s'il faut le confirmer                    */
/* ------------------------------------------------------------------ */

const primary = computed(() => {
    if (isActive.value && ! scheduled.value) return { label: 'Enregistrer les modifications', icon: Save, confirm: false };
    if (isActive.value && scheduled.value) return { label: 'Reporter la maintenance', icon: CalendarClock, confirm: true };
    if (isUpcoming.value && scheduled.value) return { label: 'Enregistrer les modifications', icon: Save, confirm: false };
    if (scheduled.value) return { label: 'Programmer la maintenance', icon: CalendarClock, confirm: true };

    return { label: 'Mettre en maintenance maintenant', icon: Zap, confirm: true };
});

const canSubmit = computed(() => canManage.value
    && form.title.trim() !== ''
    && (! scheduled.value || form.starts_at !== '')
    && (current.value === null || form.isDirty));

const confirmOpen = ref(false);
const confirmText = computed(() => {
    if (primary.value.icon === Zap) {
        return `${props.siteName} sera fermé dès maintenant. Chaque compte sans le droit « Utiliser le site pendant sa maintenance » verra ce message à sa prochaine action ; une saisie non enregistrée à ce moment peut être perdue.`;
    }
    if (isActive.value) {
        return `Le site rouvre tout de suite, puis se refermera ${whenLabel(form.starts_at)}.`;
    }

    return `Dans les ${props.maintenance?.warning_hours ?? 24} heures qui précèdent, les comptes connectés à ${props.siteName} verront un bandeau d’avertissement ; à l’heure dite, le site se fermera de lui-même.`;
});

const send = () => {
    confirmOpen.value = false;
    form
        .transform((values) => ({
            ...values,
            site_code: props.siteCode,
            starts_at: values.mode === 'scheduled' ? values.starts_at : null,
            ends_at: values.ends_at || null,
        }))
        .put('/super-admin/settings/maintenance', { preserveScroll: true });
};

const submit = () => {
    if (! canSubmit.value) return;
    if (primary.value.confirm) {
        confirmOpen.value = true;

        return;
    }
    send();
};

/* ------------------------------------------------------------------ */
/* Lever ou annuler                                                    */
/* ------------------------------------------------------------------ */

const liftOpen = ref(false);
const liftForm = useForm({ reason: '' });
const openLift = () => {
    liftForm.reset();
    liftForm.clearErrors();
    liftOpen.value = true;
};
const lift = () => liftForm
    .transform((values) => ({ ...values, site_code: props.siteCode }))
    .post('/super-admin/settings/maintenance/lift', {
        preserveScroll: true,
        onSuccess: () => { liftOpen.value = false; },
    });

/** Un refus qui ne porte sur aucun champ (site injoignable, plus rien à lever…). */
const generalError = computed(() => form.errors.maintenance || form.errors.site_code || form.errors.mode || liftForm.errors.maintenance || liftForm.errors.site_code || '');

const historyLine = (item) => {
    if (item.state === 'LIFTED') {
        return `Levée ${whenLabel(item.lifted_at)}${item.lifted_by ? ` par ${item.lifted_by}` : ''}${item.lift_reason ? ` — ${item.lift_reason}` : ''}`;
    }

    return `Terminée ${whenLabel(item.ends_at)}, à l’heure prévue`;
};
</script>

<template>
    <SettingsSection id="maintenance" title="Maintenance" :description="isPortal ? 'Fermer un site pour une intervention, le temps qu’elle dure.' : `Fermer ${siteName} pour une intervention, avec le message que ses comptes liront.`">
        <div v-if="isPortal" class="flex items-start gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
            <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            La maintenance se règle pour un site : tous les comptes du portail sont Super Administrateurs et la traverseraient. Choisissez un site en haut de la page.
        </div>

        <!-- Rien de lisible (base non migrée, ou site pas encore à jour) : dit, jamais présenté comme « ouvert ». -->
        <div v-else-if="maintenance.available !== true" class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" role="alert">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            La maintenance n’est pas encore installée sur {{ siteName }} : le site doit être à jour et ses migrations jouées (php artisan migrate).
        </div>

        <template v-else>
            <!-- 1 · L'état du site, en tête. -->
            <div
                :class="cn('flex flex-wrap items-start gap-4 rounded-xl border p-4 sm:p-5',
                    isActive ? 'border-destructive/40 bg-destructive/5' : isUpcoming ? 'border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/20' : 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20')"
                data-maintenance-state
            >
                <span
                    :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg',
                        isActive ? 'bg-destructive/10 text-destructive' : isUpcoming ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300')"
                    aria-hidden="true"
                >
                    <Construction v-if="isActive" class="h-5 w-5" />
                    <CalendarClock v-else-if="isUpcoming" class="h-5 w-5" />
                    <CircleCheck v-else class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="flex flex-wrap items-center gap-2 text-base font-medium text-foreground">
                        <template v-if="isActive">{{ siteName }} est en maintenance</template>
                        <template v-else-if="isUpcoming">Maintenance programmée</template>
                        <template v-else>{{ siteName }} est ouvert</template>
                        <Badge :variant="current ? MAINTENANCE_STATES[current.state].variant : 'success'">{{ current ? MAINTENANCE_STATES[current.state].label : 'Ouvert' }}</Badge>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="isActive">Depuis {{ whenLabel(current.starts_at) }}. {{ returnLabel(current.ends_at) }}</template>
                        <template v-else-if="isUpcoming">{{ windowLabel(current.starts_at, current.ends_at) }}. Le site se fermera de lui-même.</template>
                        <template v-else>Aucune maintenance en cours ni programmée.</template>
                        <template v-if="current?.created_by"> Mise par {{ current.created_by }}.</template>
                    </p>
                </div>
                <Button v-if="current && canManage" type="button" :variant="isActive ? 'default' : 'outline'" size="sm" @click="openLift">
                    <Unlock class="h-3.5 w-3.5" aria-hidden="true" />{{ isActive ? 'Lever la maintenance' : 'Annuler la maintenance' }}
                </Button>
            </div>

            <p v-if="! canManage" class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Lecture seule : mettre un site en maintenance demande le droit « app_maintenance.update ».
            </p>

            <!-- 2 · Le message, et ce qu'il donnera. -->
            <div class="grid gap-6 cq-4xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                <div class="space-y-6">
                    <SettingsField label="Titre" for="maintenance-title" :error="form.errors.title">
                        <Input id="maintenance-title" v-model="form.title" maxlength="120" :disabled="! canManage" placeholder="Maintenance en cours" @keydown.enter.prevent />
                    </SettingsField>
                    <SettingsField label="Message" for="maintenance-message" :description="`${form.message.length} / 2 000 caractères. Texte simple : les retours à la ligne sont gardés.`" :error="form.errors.message">
                        <Textarea id="maintenance-message" v-model="form.message" rows="5" maxlength="2000" :disabled="! canManage" placeholder="Ce que les comptes du site liront" />
                    </SettingsField>
                    <button v-if="canManage && (form.title !== defaults.title || form.message !== defaults.message)" type="button" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground hover:underline" @click="resetMessage">
                        <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />Reprendre le message proposé
                    </button>

                    <!-- 3 · Quand. -->
                    <SettingsField label="Quand" :description="scheduled ? `Avant le début, les comptes connectés voient un bandeau d’avertissement pendant ${maintenance.warning_hours ?? 24} heures.` : (isActive ? 'La maintenance est déjà en cours : son heure de début ne change pas.' : 'Le site se ferme dès l’enregistrement.')">
                        <RadioGroup v-model="form.mode" :disabled="! canManage" class="grid max-w-md grid-cols-2 gap-3 pt-1" aria-label="Quand la maintenance commence">
                            <Label v-for="option in [{ value: 'now', label: isActive ? 'En cours' : 'Maintenant', icon: Play }, { value: 'scheduled', label: isActive ? 'Reporter' : 'Programmer', icon: CalendarClock }]" :key="option.value" class="cursor-pointer [&:has([data-state=checked])>div]:border-primary [&:has([data-state=checked])>div]:bg-primary/5 [&:has(:focus-visible)>div]:ring-2 [&:has(:focus-visible)>div]:ring-ring/40 [&:has([data-disabled])]:cursor-not-allowed [&:has([data-disabled])]:opacity-60">
                                <RadioGroupItem :value="option.value" class="sr-only" />
                                <div class="flex items-center gap-2 rounded-lg border-2 border-border bg-card px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:border-primary/40">
                                    <component :is="option.icon" class="h-4 w-4 text-primary" aria-hidden="true" />{{ option.label }}
                                </div>
                            </Label>
                        </RadioGroup>
                    </SettingsField>

                    <div class="grid gap-6 cq-2xl:grid-cols-2">
                        <SettingsField v-if="scheduled" label="Début" for="maintenance-starts" :error="form.errors.starts_at">
                            <DateTimePicker id="maintenance-starts" v-model="form.starts_at" :min="minStart" format="long" :disabled="! canManage" />
                        </SettingsField>
                        <SettingsField label="Fin prévue" for="maintenance-ends" description="Le site rouvre de lui-même à cette heure. Vide : il reste fermé jusqu’à ce que vous leviez la maintenance." :error="form.errors.ends_at">
                            <DateTimePicker id="maintenance-ends" v-model="form.ends_at" :min="scheduled ? (form.starts_at || minStart) : minStart" format="long" :disabled="! canManage" />
                            <div v-if="canManage" class="flex flex-wrap items-center gap-1.5 pt-1">
                                <Button v-for="duration in MAINTENANCE_DURATIONS" :key="duration.minutes" type="button" variant="outline" size="sm" :disabled="! durationBase" @click="setDuration(duration.minutes)">+ {{ duration.label }}</Button>
                                <Button v-if="form.ends_at" type="button" variant="ghost" size="sm" @click="form.ends_at = ''">Sans fin prévue</Button>
                            </div>
                        </SettingsField>
                    </div>
                </div>

                <!-- L'aperçu : le composant même de la page de maintenance du site. -->
                <div class="space-y-2">
                    <p class="text-sm font-medium text-foreground">Aperçu</p>
                    <div class="rounded-xl border border-border bg-muted/40 p-4">
                        <div class="rounded-lg border border-border bg-card px-4 py-6 shadow-sm">
                            <MaintenanceNotice :title="form.title || defaults.title" :message="form.message" :ends-at="form.ends_at || null" compact />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">Ce que voit un compte de {{ siteName }} sans le droit de passer.</p>
                </div>
            </div>

            <!-- 4 · Qui passe quand même. -->
            <div class="flex items-start gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                <p>
                    Les comptes qui ont le droit <span class="font-medium text-foreground">« Utiliser le site pendant sa maintenance »</span> (app_maintenance.bypass) continuent d’utiliser le site — pour vérifier l’intervention avant de le rouvrir. Ce droit n’est donné à personne par défaut ; il s’accorde dans
                    <Link href="/super-admin/workspaces/roles" class="inline-flex items-center gap-1 font-medium text-primary hover:underline"><KeyRound class="h-3.5 w-3.5" aria-hidden="true" />Rôles &amp; permissions</Link>.
                    La connexion et l’API du site restent ouvertes : le portail garde la main.
                </p>
            </div>

            <p v-if="generalError" class="flex items-start gap-2 rounded-lg border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ generalError }}
            </p>

            <div v-if="canManage" class="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-5">
                <Button v-if="current && form.isDirty" type="button" variant="outline" :disabled="form.processing" @click="load">Annuler les changements</Button>
                <Button type="button" :variant="primary.icon === Zap ? 'destructive' : 'default'" :disabled="! canSubmit || form.processing" data-maintenance-submit @click="submit">
                    <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                    <component :is="primary.icon" v-else class="h-4 w-4" aria-hidden="true" />{{ primary.label }}
                </Button>
            </div>

            <!-- 5 · Les maintenances passées. -->
            <details v-if="history.length" class="group rounded-lg border border-border">
                <summary class="flex cursor-pointer items-center gap-2 px-4 py-3 text-sm font-medium text-foreground">
                    <History class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Maintenances passées ({{ history.length }})
                </summary>
                <ul class="divide-y divide-border border-t border-border">
                    <li v-for="item in history" :key="item.uuid" class="flex flex-wrap items-start gap-3 px-4 py-3 text-sm">
                        <Badge :variant="MAINTENANCE_STATES[item.state]?.variant ?? 'outline'">{{ MAINTENANCE_STATES[item.state]?.label ?? item.state }}</Badge>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-foreground">{{ item.title }}</p>
                            <p class="text-xs text-muted-foreground">Du {{ whenLabel(item.starts_at) }}<template v-if="item.created_by"> — mise par {{ item.created_by }}</template>. {{ historyLine(item) }}.</p>
                        </div>
                    </li>
                </ul>
            </details>
        </template>
    </SettingsSection>

    <ConfirmModal
        :open="confirmOpen"
        :title="primary.label + ' ?'"
        :description="confirmText"
        :confirm-label="primary.label"
        :tone="primary.icon === Zap ? 'danger' : 'warning'"
        :icon="primary.icon"
        :processing="form.processing"
        @update:open="confirmOpen = $event"
        @confirm="send"
    />

    <ConfirmModal
        :open="liftOpen"
        :title="isActive ? 'Lever la maintenance ?' : 'Annuler la maintenance programmée ?'"
        :description="isActive ? `${siteName} rouvre tout de suite pour tous ses comptes.` : `${siteName} ne sera pas fermé ${current ? whenLabel(current.starts_at) : ''}.`"
        :confirm-label="isActive ? 'Lever la maintenance' : 'Annuler la maintenance'"
        tone="primary"
        :icon="Unlock"
        :processing="liftForm.processing"
        @update:open="liftOpen = $event"
        @confirm="lift"
    >
        <SettingsField label="Motif" for="maintenance-lift-reason" description="Facultatif, gardé dans l’historique." :error="liftForm.errors.reason">
            <Textarea id="maintenance-lift-reason" v-model="liftForm.reason" rows="2" maxlength="500" placeholder="Intervention terminée" />
        </SettingsField>
    </ConfirmModal>
</template>
