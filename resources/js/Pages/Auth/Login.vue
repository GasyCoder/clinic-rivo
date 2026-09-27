<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BriefcaseMedical,
    Check,
    CircleAlert,
    Construction,
    KeyRound,
    Loader2,
    LogIn,
    Mail,
    PencilLine,
    ShieldCheck,
    Sparkles,
    X,
} from 'lucide-vue-next';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import AuthShell from '@/Components/Auth/AuthShell.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PasswordInput from '@/Components/Shadcn/PasswordInput.vue';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { passwordChecks, passwordMeetsRules } from '@/utilities/passwordRules';

/**
 * ADR-202 — la connexion en deux temps : l'adresse d'abord, puis « Continuer ».
 *
 *  - un compte qui attend sa première connexion : « Bonjour Vola, vous êtes
 *    médecin », puis nouveau mot de passe et confirmation — la personne le choisit
 *    elle-même, personne d'autre ne l'a jamais connu ;
 *  - tout autre compte : le mot de passe, comme d'habitude.
 *
 * Le serveur décide de l'étape ; une adresse inconnue reçoit la même que tout le
 * monde. Si la vérification ne répond pas, le mot de passe est demandé : la page ne
 * reste jamais bloquée à l'étape de l'adresse.
 */
defineOptions({
    layout: GuestLayout,
});

const page = usePage();
const site = computed(() => page.props.site);
const isAdminPortal = computed(() => site.value.type === 'admin');
/** ADR-193 — la maintenance en cours du site, s'il y en a une. */
const maintenance = computed(() => (site.value.maintenance?.state === 'ACTIVE' ? site.value.maintenance : null));
const accessLabel = computed(() => (isAdminPortal.value ? 'Super Administration' : `Clinique de ${site.value.name}`));

/** 'email' → 'password' ou 'activate' */
const step = ref('email');
const greeting = ref(null);
const openUntil = ref(null);
const checking = ref(false);
const identifyError = ref('');

const login = useForm({ email: '', password: '', remember: false });
const activation = useForm({ email: '', password: '', password_confirmation: '', remember: false });

const emailInput = ref(null);
const passwordInput = ref(null);
const newPasswordInput = ref(null);

const focus = async (target) => {
    await nextTick();
    target.value?.$el?.querySelector?.('input')?.focus();
};

// Un lien du RH peut porter l'adresse (« …/login?email=vola.rabe@… ») : elle est déjà écrite.
onMounted(() => {
    const email = new URLSearchParams(window.location.search).get('email');
    if (email && email.includes('@')) login.email = email.trim();
});

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const goTo = (next) => {
    step.value = next;
    focus(next === 'activate' ? newPasswordInput : next === 'password' ? passwordInput : emailInput);
};

const identify = async () => {
    const email = login.email.trim();
    identifyError.value = '';
    login.clearErrors();
    if (!email) {
        identifyError.value = 'Saisissez votre adresse email.';
        focus(emailInput);

        return;
    }

    checking.value = true;
    try {
        const response = await fetch('/login/identifier', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ email }),
        });
        const json = await response.json().catch(() => ({}));

        if (response.status === 422) {
            identifyError.value = Object.values(json.errors ?? {}).flat()[0] ?? 'Cette adresse email n’est pas valide.';
            focus(emailInput);

            return;
        }
        if (response.status === 429) {
            identifyError.value = 'Trop d’essais en peu de temps. Patientez une minute, puis recommencez.';

            return;
        }

        if (response.ok && json.mode === 'activate' && json.greeting) {
            greeting.value = json.greeting;
            openUntil.value = json.open_until ?? null;
            activation.email = email;
            goTo('activate');

            return;
        }
    } catch {
        // Le serveur n'a pas répondu à la vérification : le mot de passe reste possible.
    } finally {
        checking.value = false;
    }

    goTo('password');
};

const changeEmail = () => {
    login.reset('password');
    activation.reset('password', 'password_confirmation');
    activation.clearErrors();
    login.clearErrors();
    greeting.value = null;
    goTo('email');
};

const submitLogin = () => {
    login.post('/login', {
        onFinish: () => login.reset('password'),
        onError: () => focus(passwordInput),
    });
};

const checks = computed(() => passwordChecks(activation.password));
const confirmation = computed(() => {
    if (!activation.password_confirmation) return null;

    return activation.password_confirmation === activation.password;
});
const canActivate = computed(() => passwordMeetsRules(activation.password) && confirmation.value === true && !activation.processing);

const submitActivation = () => {
    if (!canActivate.value) return;
    activation.post('/login/premiere-connexion', {
        onFinish: () => activation.reset('password', 'password_confirmation'),
        onError: (errors) => {
            // Le compte n'attend plus sa première connexion (déjà faite, délai passé) : mot de passe habituel.
            if (errors.email) {
                login.setError('email', errors.email);
                greeting.value = null;
                goTo('password');
            }
        },
    });
};

const title = computed(() => (step.value === 'activate' ? `Bonjour, ${greeting.value?.first_name ?? ''}` : 'Connexion à votre espace'));
const description = computed(() => ({
    email: 'Saisissez l’adresse email de votre compte professionnel.',
    password: 'Saisissez votre mot de passe.',
    activate: 'Première connexion : choisissez votre mot de passe. Personne d’autre ne le connaîtra.',
}[step.value]));
</script>

<template>
    <Head :title="step === 'activate' ? 'Première connexion' : 'Connexion'" />

    <AuthShell :eyebrow="step === 'activate' ? 'Première connexion' : accessLabel" :title="title" :description="description">
        <!-- ADR-193 — le site est fermé : seuls les comptes autorisés peuvent entrer. -->
        <p v-if="maintenance" class="mb-5 flex items-start gap-2.5 rounded-lg border border-amber-300 bg-amber-50 px-3.5 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" role="status">
            <Construction class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300" aria-hidden="true" />
            <span><span class="font-semibold">{{ maintenance.title }}</span> — seuls les comptes autorisés peuvent se connecter pendant la maintenance.</span>
        </p>

        <!-- 1. L'adresse -->
        <form v-if="step === 'email'" class="flex flex-col gap-5" novalidate @submit.prevent="identify">
            <FormField label="Adresse email" :error="identifyError || login.errors.email">
                <IconInput
                    id="email"
                    ref="emailInput"
                    v-model="login.email"
                    :icon="Mail"
                    type="email"
                    size="lg"
                    placeholder="votre.email@clinique.mg"
                    autocomplete="username"
                    inputmode="email"
                    :aria-invalid="Boolean(identifyError || login.errors.email)"
                    autofocus
                    required
                />
            </FormField>

            <Button type="submit" size="lg" variant="primary" class="w-full" :disabled="checking">
                <Loader2 v-if="checking" class="h-4 w-4 animate-spin" aria-hidden="true" />
                {{ checking ? 'Vérification…' : 'Continuer' }}
                <ArrowRight v-if="!checking" class="h-4 w-4" aria-hidden="true" />
            </Button>
        </form>

        <!-- 2a. Le mot de passe, comme d'habitude -->
        <form v-else-if="step === 'password'" class="flex flex-col gap-5" @submit.prevent="submitLogin">
            <div class="flex min-w-0 items-center gap-2.5 rounded-lg border border-border bg-muted/40 px-3 py-2">
                <Mail class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate text-sm font-medium text-foreground" :title="login.email">{{ login.email }}</span>
                <button type="button" class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-semibold text-primary hover:underline" @click="changeEmail">
                    <PencilLine class="h-3.5 w-3.5" aria-hidden="true" />Modifier
                </button>
            </div>
            <!-- Pour les gestionnaires de mots de passe : l'identifiant accompagne le mot de passe. -->
            <input type="email" name="email" :value="login.email" autocomplete="username" class="sr-only" tabindex="-1" aria-hidden="true" readonly>

            <p v-if="login.errors.email" class="-mt-2 flex items-start gap-2 text-sm font-medium text-destructive" role="alert">
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ login.errors.email }}
            </p>

            <FormField label="Mot de passe" :error="login.errors.password">
                <template #action>
                    <a href="/forgot-password" class="shrink-0 text-xs font-semibold text-primary hover:underline hover:underline-offset-4">
                        Mot de passe oublié ?
                    </a>
                </template>
                <PasswordInput
                    id="password"
                    ref="passwordInput"
                    v-model="login.password"
                    placeholder="Saisissez votre mot de passe"
                    autocomplete="current-password"
                    :aria-invalid="Boolean(login.errors.password || login.errors.email)"
                    required
                />
            </FormField>

            <label class="flex w-fit cursor-pointer items-center gap-2.5 text-sm text-muted-foreground">
                <Checkbox id="remember" v-model="login.remember" />
                Se souvenir de moi
            </label>

            <Button type="submit" size="lg" variant="primary" class="w-full" :disabled="login.processing">
                <Loader2 v-if="login.processing" class="h-4 w-4 animate-spin" aria-hidden="true" /><LogIn v-else class="h-4 w-4" aria-hidden="true" />
                {{ login.processing ? 'Connexion…' : 'Se connecter' }}
            </Button>
        </form>

        <!-- 2b. Première connexion : la personne choisit son mot de passe -->
        <form v-else class="flex flex-col gap-5" @submit.prevent="submitActivation">
            <div class="rounded-xl border border-primary/20 bg-primary/5 p-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-primary" aria-hidden="true"><Sparkles class="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-foreground">{{ greeting?.name }}</p>
                        <p v-if="greeting?.job" class="mt-0.5 flex items-center gap-1.5 text-sm text-muted-foreground">
                            <BriefcaseMedical class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Vous êtes {{ greeting.job }}<template v-if="greeting.site"> · {{ greeting.site }}</template>
                        </p>
                        <p class="mt-1 flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                            <Mail class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ activation.email }}</span>
                            <button type="button" class="ms-1 shrink-0 font-semibold text-primary hover:underline" @click="changeEmail">Ce n’est pas moi</button>
                        </p>
                    </div>
                </div>
            </div>
            <input type="email" name="email" :value="activation.email" autocomplete="username" class="sr-only" tabindex="-1" aria-hidden="true" readonly>

            <FormField label="Nouveau mot de passe" :error="activation.errors.password">
                <PasswordInput
                    id="new_password"
                    ref="newPasswordInput"
                    v-model="activation.password"
                    placeholder="Choisissez votre mot de passe"
                    autocomplete="new-password"
                    :aria-invalid="Boolean(activation.errors.password)"
                    aria-describedby="password-rules"
                    required
                />
            </FormField>

            <ul id="password-rules" class="-mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2" aria-label="Règles du mot de passe">
                <li
                    v-for="check in checks"
                    :key="check.key"
                    :class="cn('flex items-center gap-1.5 text-xs', check.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground')"
                >
                    <span :class="cn('grid h-4 w-4 shrink-0 place-items-center rounded-full', check.ok ? 'bg-emerald-600 text-white' : 'border border-border')" aria-hidden="true">
                        <Check v-if="check.ok" class="h-3 w-3" />
                    </span>
                    {{ check.label }}<span class="sr-only">{{ check.ok ? ' : fait' : ' : à faire' }}</span>
                </li>
            </ul>

            <FormField label="Confirmer votre mot de passe" :error="activation.errors.password_confirmation">
                <PasswordInput
                    id="new_password_confirmation"
                    v-model="activation.password_confirmation"
                    placeholder="Saisissez-le une seconde fois"
                    autocomplete="new-password"
                    :aria-invalid="confirmation === false"
                    required
                />
                <p v-if="confirmation === false" class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-destructive">
                    <X class="h-3.5 w-3.5" aria-hidden="true" />Les deux mots de passe ne sont pas identiques.
                </p>
                <p v-else-if="confirmation === true" class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                    <Check class="h-3.5 w-3.5" aria-hidden="true" />Identiques.
                </p>
            </FormField>

            <p v-if="greeting?.mailbox" class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs leading-5 text-muted-foreground">
                <KeyRound class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                Ce mot de passe ouvrira aussi votre messagerie {{ greeting.mailbox }}.
            </p>

            <label class="flex w-fit cursor-pointer items-center gap-2.5 text-sm text-muted-foreground">
                <Checkbox id="activation_remember" v-model="activation.remember" />
                Se souvenir de moi
            </label>

            <Button type="submit" size="lg" variant="primary" class="w-full" :disabled="!canActivate">
                <Loader2 v-if="activation.processing" class="h-4 w-4 animate-spin" aria-hidden="true" /><ShieldCheck v-else class="h-4 w-4" aria-hidden="true" />
                {{ activation.processing ? 'Activation…' : 'Activer mon compte' }}
            </Button>

            <p class="text-center text-xs leading-5 text-muted-foreground">
                Ne le communiquez à personne : la clinique ne vous le demandera jamais.
                <template v-if="openUntil"><br>Première connexion possible jusqu’au {{ formatDate(openUntil) }}.</template>
            </p>
        </form>

        <template #footer>
            <div class="mt-6 text-center">
                <template v-if="site.type === 'clinic' && site.gatewayUrl && step === 'email'">
                    <div class="flex items-center gap-3" aria-hidden="true">
                        <span class="h-px flex-1 bg-border" />
                        <span class="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">ou</span>
                        <span class="h-px flex-1 bg-border" />
                    </div>
                    <a :href="site.gatewayUrl" class="mt-4 inline-flex justify-center text-xs font-semibold text-primary hover:underline hover:underline-offset-4">
                        Choisir un autre établissement
                    </a>
                </template>
                <p :class="['text-xs leading-5 text-muted-foreground', site.type === 'clinic' && site.gatewayUrl && step === 'email' ? 'mt-3' : 'mt-1']">
                    Accès réservé au personnel autorisé de la clinique.
                </p>
            </div>
        </template>
    </AuthShell>
</template>
