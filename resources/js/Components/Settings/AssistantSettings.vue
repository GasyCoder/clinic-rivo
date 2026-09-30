<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Activity, Bot, CircleCheck, CircleSlash, ExternalLink, Gauge, KeyRound, Link2, Loader2, PlugZap, Save, ShieldCheck, Trash2, TriangleAlert, Users,
} from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Input from '@/Components/Shadcn/Input.vue';
import PasswordInput from '@/Components/Shadcn/PasswordInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import SettingsField from '@/Components/Settings/SettingsField.vue';
import SettingsSection from '@/Components/Settings/SettingsSection.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { assistantFormValues, defaultModelLabel as defaultModelLabelFor, formatTokens, keySourceLabel, modelOptionsFor } from '@/utilities/assistant';

/**
 * ADR-222 — les réglages de l'assistant d'aide au logiciel, pour un site (par son
 * API) ou pour le portail : fournisseur, modèle, clé, limites, consignes, et la
 * consommation.
 *
 * La clé n'arrive jamais ici : seulement sa fin masquée. Un champ de clé laissé
 * vide garde la clé enregistrée ; la remplacer s'ouvre d'un bouton, la retirer se
 * confirme. Après l'enregistrement, la clé saisie est effacée de l'écran.
 *
 * Aucun formulaire HTML : ce module vit dans celui des paramètres (un formulaire
 * imbriqué serait invalide). Chaque geste part par ses propres routes et droits.
 */
const props = defineProps({
    /** Les réglages de chaque cible, par code ; null sans le droit `ai_settings.view`. */
    assistant: { type: Object, default: null },
    siteCode: { type: String, required: true },
    siteName: { type: String, default: '' },
    isPortal: { type: Boolean, default: false },
});

const { can } = usePermissions();
const canUpdate = computed(() => can('ai_settings.update'));
const readonly = computed(() => ! canUpdate.value);

const target = computed(() => props.assistant?.[props.siteCode] ?? null);
const data = computed(() => target.value?.data ?? null);
const providers = computed(() => data.value?.providers ?? []);
const effective = computed(() => data.value?.effective ?? {});
const key = computed(() => data.value?.key ?? {});
const usage = computed(() => data.value?.usage ?? { available: false });
const fallbacks = computed(() => data.value?.fallbacks ?? {});
const limits = computed(() => data.value?.limits ?? {});

/* ------------------------------------------------------------------ */
/* Le formulaire                                                       */
/* ------------------------------------------------------------------ */

const form = useForm({ ...assistantFormValues(data.value), api_key: '' });
const replacingKey = ref(false);

const load = () => {
    form.defaults({ ...assistantFormValues(data.value), api_key: '' });
    form.reset();
    form.clearErrors();
    replacingKey.value = false;
    testResult.value = null;
};

watch(() => `${props.siteCode}|${data.value?.updated_at ?? ''}|${key.value?.masked ?? ''}|${key.value?.updated_at ?? ''}`, load);

// GasyCoder AI en tête ; rien de réglé, il est présélectionné (assistantFormValues).
const providerOptions = computed(() => [
    ...(form.provider === '' ? [{ value: '', label: 'Aucun fournisseur' }] : []),
    ...providers.value.map((provider) => ({ value: provider.value, label: provider.label })),
]);
const providerEntry = computed(() => providers.value.find((provider) => provider.value === (form.provider || fallbacks.value.provider)) ?? null);

const CUSTOM = '__custom__';
const customModel = ref(false);
const defaultModelLabel = computed(() => defaultModelLabelFor(providerEntry.value));
/** GasyCoder AI propose ses propres types (GasyCoder AI, Mini, Pro), chacun avec son moteur. */
const namedModels = computed(() => (providerEntry.value?.models ?? []).some((model) => model.engine));
const modelDescription = computed(() => (namedModels.value
    ? 'Les types GasyCoder AI, avec le moteur que chacun appelle ; un autre modèle se saisit à la main.'
    : 'Les modèles proposés viennent du SDK ; un autre se saisit à la main.'));
const modelOptions = computed(() => [
    { value: '', label: defaultModelLabel.value },
    ...modelOptionsFor(providers.value, form.provider || fallbacks.value.provider, form.model),
    { value: CUSTOM, label: 'Autre modèle (saisir son nom)…' },
]);
/** Aucun modèle proposé : le nom se saisit directement. */
const typedModelOnly = computed(() => Boolean(providerEntry.value)
    && ! providerEntry.value.default_model
    && (providerEntry.value.models ?? []).length === 0);
const pickModel = (value) => {
    if (value === CUSTOM) {
        customModel.value = true;
        form.model = '';

        return;
    }
    customModel.value = false;
    form.model = value;
};
watch(() => form.provider, (next, previous) => {
    if (previous !== undefined && next !== previous) {
        form.model = '';
        customModel.value = false;
    }
});

/** La clé enregistrée appartient à un autre fournisseur : le serveur refusera sans nouvelle clé. */
const keyMismatch = computed(() => key.value?.stored_for && form.provider && key.value.stored_for !== form.provider && form.api_key.trim() === '');

const changed = computed(() => form.isDirty);

const save = () => {
    if (readonly.value || form.processing) return;

    form
        .transform((values) => {
            const payload = { ...values, site_code: props.siteCode };
            // Une clé laissée vide ne part pas : la clé enregistrée est gardée.
            if (! replacingKey.value || String(values.api_key ?? '').trim() === '') delete payload.api_key;

            return payload;
        })
        .put('/super-admin/settings/assistant', {
            preserveScroll: true,
            onSuccess: () => {
                form.api_key = '';
                replacingKey.value = false;
            },
        });
};

/* ------------------------------------------------------------------ */
/* La clé : remplacer, retirer                                         */
/* ------------------------------------------------------------------ */

const startReplace = () => {
    replacingKey.value = true;
    form.api_key = '';
};
const cancelReplace = () => {
    replacingKey.value = false;
    form.api_key = '';
    form.clearErrors('api_key');
};

const removeOpen = ref(false);
const removeForm = useForm({});
const removeKey = () => removeForm
    .transform(() => ({ site_code: props.siteCode }))
    .delete('/super-admin/settings/assistant/key', {
        preserveScroll: true,
        onSuccess: () => { removeOpen.value = false; },
    });

/* ------------------------------------------------------------------ */
/* Tester la connexion                                                  */
/* ------------------------------------------------------------------ */

const testing = ref(false);
const testResult = ref(null);
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const testConnection = async () => {
    const provider = form.provider || fallbacks.value.provider;

    if (! provider) {
        testResult.value = { ok: false, message: 'Choisissez d’abord un fournisseur.' };

        return;
    }

    testing.value = true;
    testResult.value = null;

    try {
        const response = await fetch('/super-admin/settings/assistant/test', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({
                site_code: props.siteCode,
                provider,
                model: form.model || null,
                timeout_seconds: form.timeout_seconds ? Number(form.timeout_seconds) : null,
                ...(replacingKey.value && form.api_key.trim() !== '' ? { api_key: form.api_key.trim() } : {}),
            }),
        });
        const json = await response.json().catch(() => null);
        const firstError = json?.errors ? Object.values(json.errors)[0] : null;

        testResult.value = {
            ok: Boolean(json?.ok),
            message: json?.message || (Array.isArray(firstError) ? firstError[0] : null) || 'Le test n’a pas abouti.',
            latency: json?.latency_ms ?? null,
        };
    } catch {
        testResult.value = { ok: false, message: 'Le portail n’a pas pu joindre le serveur. Vérifiez la connexion.' };
    } finally {
        testing.value = false;
    }
};

/* ------------------------------------------------------------------ */
/* L'état et la consommation                                           */
/* ------------------------------------------------------------------ */

const state = computed(() => {
    if (effective.value.available) return { label: 'Disponible', tone: 'success', icon: CircleCheck, text: 'Les comptes qui ont le droit « ai_assistant.use » voient le bouton de l’assistant.' };
    if (! effective.value.enabled) return { label: 'Désactivé', tone: 'neutral', icon: CircleSlash, text: 'Le bouton de l’assistant n’apparaît à personne.' };

    return { label: 'À configurer', tone: 'warning', icon: TriangleAlert, text: 'Activé, mais il manque le fournisseur, le modèle ou la clé.' };
});

const maxDay = computed(() => Math.max(1, ...(usage.value.days ?? []).map((day) => day.questions)));
const dayLabel = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
const generalError = computed(() => form.errors.assistant || form.errors.site_code || removeForm.errors.api_key || removeForm.errors.assistant || '');
</script>

<template>
    <SettingsSection id="assistant" title="Assistant IA" :description="isPortal ? 'L’assistant du portail : il aide le Super Administrateur à utiliser RIVO.' : `L’assistant de ${siteName} : il aide chaque compte à utiliser le logiciel, sans donnée de patient.`">
        <div v-if="assistant === null" class="flex items-start gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
            <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            Voir les réglages de l’assistant demande le droit « ai_settings.view ».
        </div>

        <div v-else-if="! target || ! target.ok" class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" role="alert">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            {{ target?.message || `Les réglages de l’assistant de ${siteName} ne sont pas lisibles : le site doit être à jour.` }}
        </div>

        <div v-else-if="! data?.installed" class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" role="alert">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            L’assistant n’est pas encore installé sur {{ siteName }} : jouez ses migrations (php artisan migrate).
        </div>

        <template v-else>
            <!-- 1 · L'état effectif, en tête. -->
            <div
                :class="cn('flex flex-wrap items-start gap-4 rounded-xl border p-4 sm:p-5',
                    state.tone === 'success' ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20'
                    : state.tone === 'warning' ? 'border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/20' : 'border-border bg-muted/30')"
                data-assistant-state
            >
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <Bot class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="flex flex-wrap items-center gap-2 font-medium text-foreground">
                        <component :is="state.icon" class="h-4 w-4" aria-hidden="true" />
                        {{ state.label }}
                        <Badge v-if="effective.provider_label" tone="neutral">{{ effective.provider_label }}</Badge>
                        <Badge v-if="effective.model" tone="neutral" :class="effective.model_label ? '' : 'font-mono'">{{ effective.model_label ?? effective.model }}</Badge>
                    </p>
                    <p class="text-sm text-muted-foreground">{{ state.text }}</p>
                    <p v-if="data.updated_at" class="text-xs text-muted-foreground">
                        Réglé le {{ new Date(data.updated_at).toLocaleString('fr-FR') }}<template v-if="data.updated_by"> par {{ data.updated_by }}</template>.
                    </p>
                </div>
            </div>

            <p v-if="readonly" class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Lecture seule : régler l’assistant demande le droit « ai_settings.update ».
            </p>

            <!-- 2 · Fournisseur et modèle. -->
            <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-4">
                <div class="space-y-1">
                    <p class="text-sm font-medium text-foreground">Activer l’assistant</p>
                    <p class="text-[0.8rem] text-muted-foreground">Désactivé, le bouton disparaît pour tous ; la clé et les réglages restent enregistrés.</p>
                </div>
                <Switch id="reglage-assistant-enabled" v-model="form.enabled" :disabled="readonly" aria-label="Activer l’assistant" />
            </div>

            <div class="grid gap-6 cq-2xl:grid-cols-2">
                <SettingsField label="Fournisseur" for="reglage-assistant-provider" :error="form.errors.provider" description="Celui qui répond aux questions, par le SDK Laravel AI.">
                    <Select id="reglage-assistant-provider" v-model="form.provider" :options="providerOptions" class="w-full" :disabled="readonly" />
                    <!-- GasyCoder AI : une API de type ChatGPT — celle de ChatGPT, ou la vôtre (GASYCODER_AI_URL). -->
                    <p v-if="providerEntry?.api_url" class="mt-2 flex min-w-0 items-start gap-1.5 text-[0.8rem] text-muted-foreground" data-assistant-api-url>
                        <Link2 class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="min-w-0">
                            <template v-if="providerEntry.own_api">Votre API : </template>
                            <template v-else>Type ChatGPT — API de ChatGPT : </template>
                            <span class="break-all font-mono">{{ providerEntry.api_url }}</span>
                            <template v-if="! providerEntry.own_api">. La clé est une clé OpenAI (ChatGPT) ; <span class="font-mono">GASYCODER_AI_URL</span> dans le .env la remplace par votre propre API.</template>
                        </span>
                    </p>
                </SettingsField>
                <SettingsField label="Modèle" for="reglage-assistant-model" :error="form.errors.model" :description="modelDescription">
                    <Select
                        v-if="! customModel && ! typedModelOnly"
                        id="reglage-assistant-model"
                        :model-value="form.model"
                        :options="modelOptions"
                        class="w-full"
                        :disabled="readonly || ! providerEntry"
                        @update:model-value="pickModel"
                    />
                    <div v-else class="flex gap-2">
                        <Input id="reglage-assistant-model" v-model="form.model" placeholder="ex. nom-exact-du-modele" class="font-mono" maxlength="150" autocomplete="off" :disabled="readonly" />
                        <Button v-if="! typedModelOnly" type="button" variant="outline" @click="customModel = false">Liste</Button>
                    </div>
                </SettingsField>
            </div>

            <!-- 3 · La clé : jamais affichée, seulement sa fin masquée. -->
            <div class="space-y-3 rounded-lg border border-border p-4" data-assistant-key>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <KeyRound class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <div class="min-w-0 space-y-1">
                            <p class="text-sm font-medium text-foreground">Clé d’API</p>
                            <p class="text-sm text-muted-foreground">
                                {{ keySourceLabel(key) }}<template v-if="key.masked"> · <span class="font-mono">{{ key.masked }}</span></template>
                                <template v-if="key.updated_at && key.source === 'database'"> · changée le {{ new Date(key.updated_at).toLocaleDateString('fr-FR') }}</template>
                            </p>
                            <p class="text-[0.8rem] text-muted-foreground">
                                Gardée chiffrée sur le serveur ; jamais renvoyée à un navigateur. Sans clé enregistrée, la variable
                                <span class="font-mono">{{ providerEntry?.environment_key ?? 'OPENAI_API_KEY' }}</span> du .env sert de secours.
                            </p>
                        </div>
                    </div>
                    <div v-if="! readonly" class="flex flex-wrap gap-2">
                        <Button v-if="! replacingKey" type="button" size="sm" variant="outline" @click="startReplace">
                            <KeyRound class="h-4 w-4" aria-hidden="true" />{{ key.source === 'database' ? 'Remplacer la clé' : 'Saisir une clé' }}
                        </Button>
                        <Button v-if="key.source === 'database' && ! replacingKey" type="button" size="sm" variant="outline" class="text-destructive" @click="removeOpen = true">
                            <Trash2 class="h-4 w-4" aria-hidden="true" />Retirer
                        </Button>
                    </div>
                </div>

                <div v-if="replacingKey" class="space-y-2">
                    <SettingsField label="Nouvelle clé" for="reglage-assistant-key" :error="form.errors.api_key" description="Collez la clé telle que le fournisseur l’a donnée. Laissée vide, la clé actuelle est gardée.">
                        <PasswordInput id="reglage-assistant-key" v-model="form.api_key" size="default" autocomplete="off" spellcheck="false" placeholder="Collez la clé ici" />
                    </SettingsField>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="button" size="sm" variant="ghost" @click="cancelReplace">Ne pas changer la clé</Button>
                        <a v-if="providerEntry?.key_url" :href="providerEntry.key_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-sm text-primary underline-offset-4 hover:underline">
                            Créer une clé chez {{ providerEntry.label }}<ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    </div>
                </div>
                <p v-else-if="form.errors.api_key" class="text-[0.8rem] font-medium text-destructive" role="alert">{{ form.errors.api_key }}</p>

                <p v-if="keyMismatch" class="flex items-start gap-2 text-[0.8rem] text-amber-700 dark:text-amber-300">
                    <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    La clé enregistrée est celle d’un autre fournisseur : saisissez la clé du nouveau, ou retirez-la d’abord.
                </p>
            </div>

            <!-- 4 · Limites et coûts. -->
            <div class="space-y-4">
                <p class="flex items-center gap-2 text-sm font-medium text-foreground"><Gauge class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Limites</p>
                <div class="grid gap-6 cq-2xl:grid-cols-2 cq-4xl:grid-cols-3">
                    <SettingsField label="Longueur d’une réponse (tokens)" for="reglage-assistant-tokens" :error="form.errors.max_output_tokens" :description="`De ${limits.min_output_tokens} à ${limits.max_output_tokens}. Vide : ${fallbacks.max_output_tokens}.`">
                        <Input id="reglage-assistant-tokens" v-model="form.max_output_tokens" type="number" inputmode="numeric" :min="limits.min_output_tokens" :max="limits.max_output_tokens" :placeholder="String(fallbacks.max_output_tokens ?? '')" :disabled="readonly" />
                    </SettingsField>
                    <SettingsField label="Température" for="reglage-assistant-temperature" :error="form.errors.temperature" description="De 0 (précis) à 2. Vide : celle du fournisseur — certains modèles la refusent.">
                        <Input id="reglage-assistant-temperature" v-model="form.temperature" type="number" inputmode="decimal" step="0.1" min="0" max="2" placeholder="Par défaut" :disabled="readonly" />
                    </SettingsField>
                    <SettingsField label="Délai d’attente (secondes)" for="reglage-assistant-timeout" :error="form.errors.timeout_seconds" :description="`De 5 à 120. Vide : ${fallbacks.timeout_seconds} s.`">
                        <Input id="reglage-assistant-timeout" v-model="form.timeout_seconds" type="number" inputmode="numeric" min="5" max="120" :placeholder="String(fallbacks.timeout_seconds ?? '')" :disabled="readonly" />
                    </SettingsField>
                    <SettingsField label="Questions par heure et par compte" for="reglage-assistant-rate" :error="form.errors.rate_limit_per_hour" :description="`Vide : ${fallbacks.rate_limit_per_hour}.`">
                        <Input id="reglage-assistant-rate" v-model="form.rate_limit_per_hour" type="number" inputmode="numeric" min="1" max="500" :placeholder="String(fallbacks.rate_limit_per_hour ?? '')" :disabled="readonly" />
                    </SettingsField>
                    <SettingsField label="Questions par jour et par compte" for="reglage-assistant-daily" :error="form.errors.daily_limit_per_user" description="Vide : pas de limite quotidienne.">
                        <Input id="reglage-assistant-daily" v-model="form.daily_limit_per_user" type="number" inputmode="numeric" min="1" max="1000" placeholder="Sans limite" :disabled="readonly" />
                    </SettingsField>
                    <SettingsField label="Budget mensuel (tokens)" for="reglage-assistant-budget" :error="form.errors.monthly_token_budget" description="Pour tout l’établissement. Atteint, l’assistant se tait jusqu’au mois suivant.">
                        <Input id="reglage-assistant-budget" v-model="form.monthly_token_budget" type="number" inputmode="numeric" min="1000" placeholder="Sans budget" :disabled="readonly" />
                    </SettingsField>
                </div>
            </div>

            <SettingsField
                label="Consignes de l’établissement"
                for="reglage-assistant-instructions"
                :error="form.errors.instructions"
                :description="`Ton, vocabulaire, précisions propres à l’établissement (${limits.instructions_length} caractères au plus). Elles ne lèvent jamais les règles fixes : l’assistant reste une aide au logiciel, sans conseil médical.`"
            >
                <Textarea id="reglage-assistant-instructions" v-model="form.instructions" rows="4" :maxlength="limits.instructions_length" placeholder="ex. Tutoyez les utilisateurs ; appelez la Caisse « le guichet »." :disabled="readonly" />
            </SettingsField>

            <!-- 5 · Tester, enregistrer. -->
            <div v-if="! readonly" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/30 p-4">
                <div class="min-w-0 flex-1 basis-64" aria-live="polite">
                    <p v-if="testResult" :class="cn('flex items-start gap-2 text-sm', testResult.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-destructive')" data-assistant-test-result>
                        <CircleCheck v-if="testResult.ok" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <TriangleAlert v-else class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ testResult.message }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">Le test pose une question de quelques tokens avec les valeurs ci-dessus, même non enregistrées.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" :disabled="testing" @click="testConnection">
                        <Loader2 v-if="testing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                        <PlugZap v-else class="h-4 w-4" aria-hidden="true" />
                        {{ testing ? 'Test en cours…' : 'Tester la connexion' }}
                    </Button>
                    <Button v-if="changed" type="button" variant="outline" :disabled="form.processing" @click="load">Annuler</Button>
                    <Button type="button" :disabled="! changed || form.processing" @click="save">
                        <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                        <Save v-else class="h-4 w-4" aria-hidden="true" />
                        {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
                    </Button>
                </div>
            </div>

            <p v-if="generalError" class="flex items-start gap-2 rounded-lg border border-destructive/40 px-4 py-3 text-sm text-destructive" role="alert">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ generalError }}
            </p>

            <!-- 6 · La consommation. -->
            <div v-if="usage.available" class="space-y-4" data-assistant-usage>
                <p class="flex items-center gap-2 text-sm font-medium text-foreground"><Activity class="h-4 w-4 text-muted-foreground" aria-hidden="true" />Consommation</p>
                <div class="grid gap-3 cq-2xl:grid-cols-3">
                    <div class="rounded-lg border border-border p-4">
                        <p class="text-xs text-muted-foreground">Aujourd’hui</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-foreground">{{ usage.today.questions }}</p>
                        <p class="text-xs text-muted-foreground">question{{ usage.today.questions > 1 ? 's' : '' }} · {{ formatTokens(usage.today.input_tokens + usage.today.output_tokens) }} tokens<template v-if="usage.today.failed"> · {{ usage.today.failed }} échec{{ usage.today.failed > 1 ? 's' : '' }}</template></p>
                    </div>
                    <div class="rounded-lg border border-border p-4">
                        <p class="text-xs text-muted-foreground">{{ usage.month.label }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-foreground">{{ usage.month.questions }}</p>
                        <p class="text-xs text-muted-foreground">{{ formatTokens(usage.month.input_tokens) }} lus · {{ formatTokens(usage.month.output_tokens) }} écrits · {{ usage.month.users }} compte{{ usage.month.users > 1 ? 's' : '' }}</p>
                    </div>
                    <div class="rounded-lg border border-border p-4">
                        <p class="text-xs text-muted-foreground">Budget du mois</p>
                        <template v-if="usage.budget.tokens">
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-foreground">{{ usage.budget.percent }} %</p>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="usage.budget.percent" aria-valuemin="0" aria-valuemax="100">
                                <div :class="cn('h-full rounded-full', usage.budget.percent >= 90 ? 'bg-destructive' : usage.budget.percent >= 70 ? 'bg-amber-500' : 'bg-primary')" :style="{ width: `${usage.budget.percent}%` }" />
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">{{ formatTokens(usage.budget.used) }} / {{ formatTokens(usage.budget.tokens) }} tokens</p>
                        </template>
                        <p v-else class="mt-1 text-sm text-muted-foreground">Aucun budget réglé.</p>
                    </div>
                </div>

                <div class="rounded-lg border border-border p-4">
                    <p class="mb-3 text-xs text-muted-foreground">Questions des 14 derniers jours</p>
                    <div class="flex h-24 items-end gap-1" aria-hidden="true">
                        <div v-for="day in usage.days" :key="day.date" class="flex flex-1 flex-col items-center justify-end" :title="`${dayLabel(day.date)} : ${day.questions} question(s), ${formatTokens(day.tokens)} tokens`">
                            <div class="w-full rounded-t bg-primary/70" :style="{ height: `${Math.max(day.questions ? 6 : 2, Math.round(day.questions / maxDay * 88))}px` }" />
                        </div>
                    </div>
                    <p class="mt-1 flex justify-between text-[0.7rem] text-muted-foreground"><span>{{ dayLabel(usage.days[0]?.date) }}</span><span>Aujourd’hui</span></p>
                </div>

                <div v-if="usage.top_users?.length" class="rounded-lg border border-border p-4">
                    <p class="mb-2 flex items-center gap-2 text-xs text-muted-foreground"><Users class="h-3.5 w-3.5" aria-hidden="true" />Comptes qui l’utilisent le plus ce mois-ci</p>
                    <ul class="divide-y divide-border text-sm">
                        <li v-for="row in usage.top_users" :key="row.name" class="flex items-center justify-between gap-3 py-1.5">
                            <span class="truncate text-foreground">{{ row.name }}</span>
                            <span class="shrink-0 tabular-nums text-muted-foreground">{{ row.questions }} · {{ formatTokens(row.tokens) }} tokens</span>
                        </li>
                    </ul>
                </div>
                <p class="text-[0.8rem] text-muted-foreground">
                    Les tokens sont ceux que le fournisseur a comptés ; RIVO ne calcule aucun montant — le prix se lit dans la console du fournisseur.
                </p>
            </div>
        </template>
    </SettingsSection>

    <ConfirmModal
        :open="removeOpen"
        title="Retirer la clé d’API ?"
        :description="`La clé enregistrée pour ${siteName} est effacée. Sans clé dans le .env du serveur, l’assistant cessera de répondre jusqu’à ce qu’une nouvelle clé soit saisie.`"
        confirm-label="Retirer la clé"
        tone="danger"
        :icon="Trash2"
        :processing="removeForm.processing"
        @update:open="removeOpen = $event"
        @confirm="removeKey"
    />
</template>
