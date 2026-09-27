<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    Eye,
    Inbox,
    Info,
    KeyRound,
    LoaderCircle,
    LockKeyhole,
    Mail,
    Search,
    ShieldCheck,
    UserRound,
    Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import NoticesButton from '@/Components/Shadcn/NoticesButton.vue';
import PasswordInput from '@/Components/Shadcn/PasswordInput.vue';
import { cn } from '@/lib/cn';
import { avatarTone, filterBoxes, initialsOf, WEBMAIL_BASE } from '@/utilities/webmail';

/**
 * ADR-195 — ouvrir une boîte, sur un site. Sa propre boîte (`webmail.view`), ou,
 * avec `webmail.open_any`, celle d'un autre employé du site. Le portail n'affiche
 * jamais cette page : sa boîte, réglée dans son .env, s'ouvre directement.
 *
 * ADR-200 — sa propre boîte s'ouvre d'elle-même avec le mot de passe saisi à la
 * connexion à RIVO — ou, après une reconnexion par « Se souvenir de moi », avec celui
 * gardé sur l'appareil. Cette page ne la montre que si ce mot de passe n'est pas connu
 * (session ouverte avant cette règle, boîte fermée) ou n'est pas celui de la
 * boîte : il se tape alors une fois. Quand il est connu (`ownReady`), revenir à sa
 * boîte depuis celle d'un collègue se fait sans rien taper.
 *
 * Pleine largeur : à gauche les boîtes, en grille ; à droite, fixe, la boîte
 * choisie — son mot de passe, ou « rien à taper ». Choisir une boîte place le
 * curseur dans le mot de passe ; les flèches passent d'une boîte à l'autre.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    own: { type: Object, default: null },
    others: { type: Array, default: () => [] },
    canOpenAny: { type: Boolean, default: false },
    selected: { type: String, default: null },
    openBox: { type: Object, default: null },
    /** Le mot de passe de sa boîte est connu pour cette session : elle s'ouvre sans rien taper. */
    ownReady: { type: Boolean, default: false },
});

const boxes = computed(() => [...(props.own ? [props.own] : []), ...props.others]);
const query = ref('');

const shown = computed(() => filterBoxes(props.others, query.value));
const showOwn = computed(() => Boolean(props.own) && !query.value);
/** L'ordre dans lequel les flèches parcourent les boîtes affichées. */
const navigable = computed(() => [...(showOwn.value ? [props.own] : []), ...shown.value]);

const form = useForm({ mailbox: props.selected ?? boxes.value[0]?.uuid ?? null, password: '' });
const chosen = computed(() => boxes.value.find((box) => box.uuid === form.mailbox) ?? null);
/** La boîte choisie s'ouvre sans mot de passe : c'est la sienne, et il est connu. */
const needsPassword = computed(() => !(chosen.value?.own && props.ownReady));

/**
 * Un refus venu d'ailleurs — la boîte redemande son mot de passe après une
 * redirection (mot de passe de RIVO différent, mot de passe changé) : le serveur
 * l'envoie dans les erreurs de la page, pas dans celles du formulaire. Affiché
 * jusqu'à ce qu'on change de boîte ou qu'on renvoie le formulaire.
 */
const serverRefusal = ref(usePage().props.errors?.password ?? null);
const passwordError = computed(() => form.errors.password ?? serverRefusal.value);

watch(chosen, (value, previous) => {
    form.password = '';
    form.clearErrors('password');
    if (previous !== undefined) serverRefusal.value = null;
}, { immediate: true });

const focusPassword = () => nextTick(() => document.getElementById(needsPassword.value ? 'webmail-password' : 'webmail-open')?.focus());
const choose = (box) => {
    form.mailbox = box.uuid;
    focusPassword();
};

/** Les flèches passent d'une boîte à l'autre, comme dans un groupe de boutons radio. */
const onGridKeydown = (event) => {
    const step = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[event.key];
    if (!step || !navigable.value.length) return;
    event.preventDefault();
    const index = navigable.value.findIndex((box) => box.uuid === form.mailbox);
    const next = navigable.value[(index + step + navigable.value.length) % navigable.value.length];
    form.mailbox = next.uuid;
    nextTick(() => document.getElementById(`webmail-box-${next.uuid}`)?.focus());
};
const tabIndexOf = (box) => (box.uuid === form.mailbox || (!navigable.value.some((candidate) => candidate.uuid === form.mailbox) && box.uuid === navigable.value[0]?.uuid) ? 0 : -1);

const submit = () => {
    serverRefusal.value = null;
    form.post(`${WEBMAIL_BASE}/connexion`, { onFinish: () => form.reset('password') });
};

// À l'arrivée, le curseur va au mot de passe sur grand écran seulement : sur un
// téléphone, il ouvrirait le clavier et ferait défiler la page sous la liste.
onMounted(() => {
    if (chosen.value && window.matchMedia?.('(min-width: 1024px)').matches) focusPassword();
});

// Tout ce qui est bon à savoir tient dans le bouton « ! » de l'en-tête : l'audit d'une
// boîte d'employé d'abord (en ambre), puis les informations. Le badge « Boîte d'un
// employé » reste, lui, visible sur la boîte choisie.
const notices = computed(() => [
    ...(chosen.value && !chosen.value.own ? [{
        key: 'audit',
        icon: ShieldCheck,
        tone: 'warning',
        title: 'Boîte d’un employé',
        text: `Vous ouvrez la boîte de ${chosen.value.owner}. Chaque ouverture et chaque message envoyé depuis cette adresse sont enregistrés dans l’audit à votre nom.`,
    }] : []),
    { key: 'login', icon: KeyRound, tone: 'info', title: 'Votre boîte s’ouvre seule', text: 'D’habitude, votre boîte s’ouvre directement avec le mot de passe saisi à votre connexion à RIVO : vos accès ont reçu le même.' },
    { key: 'password', icon: LockKeyhole, tone: 'info', title: 'Mot de passe', text: 'Gardé chiffré le temps de votre session (et sur cet appareil avec « Se souvenir de moi »), jamais en base ; oublié à votre déconnexion de RIVO.' },
    { key: 'copy', icon: Mail, tone: 'info', title: 'Aucune copie', text: 'Les messages restent chez l’hébergeur : RIVO les lit en direct, sans copie.' },
    { key: 'forgotten', icon: UserRound, tone: 'info', title: 'Mot de passe oublié', text: 'Demandez-en un nouveau aux Ressources humaines.' },
]);

const title = computed(() => (props.canOpenAny ? 'Ouvrir une boîte' : 'Ouvrir ma boîte'));
const intro = computed(() => {
    if (!props.canOpenAny) return 'Votre boîte professionnelle, lue en direct chez l’hébergeur.';
    return props.ownReady
        ? 'Votre boîte s’ouvre sans mot de passe. Celle d’un employé du site demande le sien.'
        : 'Votre boîte, ou celle d’un employé du site. Choisissez-la, puis saisissez son mot de passe.';
});

/**
 * Pourquoi sa boîte demande un mot de passe cette fois — quand le serveur n'a rien
 * dit (un refus s'affiche sous le champ). Sans ce mot, la page se lirait comme un
 * second mot de passe exigé à chaque visite.
 */
const whyAsked = computed(() => {
    if (!chosen.value?.own || props.ownReady || passwordError.value) return null;
    return 'Votre boîte s’ouvre toute seule avec le mot de passe de votre connexion à RIVO. Cette session a été ouverte sans lui (avant la mise à jour de la messagerie, ou boîte fermée) : déconnectez-vous puis reconnectez-vous à RIVO, ou saisissez-le ici une fois.';
});
</script>

<template>
    <Head :title="`${title} · Messagerie`" />

    <div class="flex flex-col gap-6">
        <!-- En-tête -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary" aria-hidden="true"><Mail class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Messagerie professionnelle</p>
                    <h1 class="font-heading text-2xl font-bold text-foreground">{{ title }}</h1>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">{{ intro }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                <NoticesButton :notices="notices" />
                <Button v-if="openBox" :as="Link" :href="WEBMAIL_BASE" variant="outline" size="sm">
                    <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Revenir à {{ openBox.owner }}
                </Button>
            </div>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_24rem] xl:grid-cols-[minmax(0,1fr)_27rem]">
            <!-- Les boîtes -->
            <section class="min-w-0 rounded-xl border border-border bg-card shadow-sm" :aria-labelledby="canOpenAny ? 'webmail-boxes-title' : undefined" :aria-label="canOpenAny ? undefined : 'Ma boîte'">
                <div v-if="canOpenAny" class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <h2 id="webmail-boxes-title" class="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <Users class="h-4 w-4 text-muted-foreground" aria-hidden="true" /> Quelle boîte ?
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ others.length }} adresse{{ others.length > 1 ? 's' : '' }} active{{ others.length > 1 ? 's' : '' }}<template v-if="own"> · et la vôtre</template>
                        </p>
                    </div>
                    <div class="w-full sm:w-80">
                        <IconInput v-model="query" :icon="Search" type="search" placeholder="Nom, adresse, fonction…" aria-label="Chercher une boîte" />
                    </div>
                </div>

                <div class="space-y-5 p-4" role="radiogroup" :aria-labelledby="canOpenAny ? 'webmail-boxes-title' : undefined" :aria-label="canOpenAny ? undefined : 'Ma boîte'" @keydown="onGridKeydown">
                    <!-- Sa propre boîte, en tête (seule, sans le droit d'ouvrir celles des autres) -->
                    <div v-if="showOwn">
                        <p class="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground"><UserRound class="h-3.5 w-3.5" aria-hidden="true" /> Ma boîte</p>
                        <div class="grid gap-2 sm:grid-cols-2 2xl:grid-cols-3">
                            <button
                                :id="`webmail-box-${own.uuid}`"
                                type="button"
                                role="radio"
                                :aria-checked="form.mailbox === own.uuid"
                                :tabindex="tabIndexOf(own)"
                                :class="cn('flex w-full items-center gap-3 rounded-lg border p-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', form.mailbox === own.uuid ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40')"
                                @click="choose(own)"
                            >
                                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-bold', avatarTone(own.address))" aria-hidden="true">{{ initialsOf({ name: own.owner, email: own.address }) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex min-w-0 items-center gap-2 text-sm font-semibold text-foreground"><span class="truncate">{{ own.owner }}</span> <Badge tone="primary" class="shrink-0">Ma boîte</Badge></span>
                                    <span class="block truncate text-xs text-muted-foreground">{{ own.address }}</span>
                                    <span v-if="ownReady" class="mt-0.5 flex items-center gap-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-400"><KeyRound class="h-3 w-3" aria-hidden="true" /> Sans mot de passe à taper</span>
                                </span>
                                <Check v-if="form.mailbox === own.uuid" class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <!-- Les autres employés du site -->
                    <div v-if="shown.length">
                        <div class="grid gap-2 sm:grid-cols-2 2xl:grid-cols-3">
                            <button
                                v-for="box in shown"
                                :id="`webmail-box-${box.uuid}`"
                                :key="box.uuid"
                                type="button"
                                role="radio"
                                :aria-checked="form.mailbox === box.uuid"
                                :tabindex="tabIndexOf(box)"
                                :class="cn('flex w-full items-center gap-3 rounded-lg border p-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', form.mailbox === box.uuid ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border hover:border-primary/40 hover:bg-accent/40')"
                                @click="choose(box)"
                            >
                                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-bold', avatarTone(box.address))" aria-hidden="true">{{ initialsOf({ name: box.owner, email: box.address }) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-foreground">{{ box.owner }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">{{ box.address }}</span>
                                    <span v-if="box.job" class="block truncate text-[11px] text-muted-foreground/80">{{ box.job }}</span>
                                </span>
                                <Check v-if="form.mailbox === box.uuid" class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <div v-if="canOpenAny && !shown.length && !showOwn" class="flex flex-col items-center gap-2 px-3 py-12 text-center">
                        <span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground"><Inbox class="h-5 w-5" aria-hidden="true" /></span>
                        <p class="text-sm text-muted-foreground">{{ query ? 'Aucune boîte ne correspond.' : 'Aucune adresse professionnelle active.' }}</p>
                        <button v-if="query" type="button" class="text-sm font-medium text-primary hover:underline" @click="query = ''">Tout afficher</button>
                    </div>
                </div>

                <p v-if="form.errors.mailbox" class="border-t border-border px-4 py-3 text-xs font-medium text-destructive">{{ form.errors.mailbox }}</p>
            </section>

            <!-- La boîte choisie : son mot de passe, ou rien à taper -->
            <aside class="lg:sticky lg:top-4">
                <section v-if="chosen" class="overflow-hidden rounded-xl border border-border bg-card shadow-sm" aria-labelledby="webmail-chosen-title">
                    <div class="flex items-center gap-4 border-b border-border p-5">
                        <span :class="cn('grid h-14 w-14 shrink-0 place-items-center rounded-full text-lg font-bold', avatarTone(chosen.address))" aria-hidden="true">
                            {{ initialsOf({ name: chosen.owner, email: chosen.address }) }}
                        </span>
                        <div class="min-w-0">
                            <h2 id="webmail-chosen-title" class="truncate text-lg font-bold text-foreground">{{ chosen.owner }}</h2>
                            <p class="flex items-center gap-1.5 truncate text-sm text-muted-foreground"><Mail class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /> {{ chosen.address }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <Badge v-if="chosen.own" tone="primary">Ma boîte</Badge>
                                <Badge v-else tone="warning"><Eye class="me-1 h-3 w-3" aria-hidden="true" /> Boîte d’un employé</Badge>
                            </div>
                        </div>
                    </div>

                    <form class="space-y-4 p-5" @submit.prevent="submit">
                        <!-- Sa boîte, mot de passe déjà connu pour la session : rien à taper. -->
                        <div v-if="!needsPassword" class="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50/70 p-3 dark:border-emerald-900 dark:bg-emerald-950/25">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300" aria-hidden="true"><ShieldCheck class="h-4 w-4" /></span>
                            <p class="text-sm text-emerald-900 dark:text-emerald-200">
                                <span class="block font-semibold">Rien à taper</span>
                                <span class="text-xs leading-5 text-emerald-900/80 dark:text-emerald-200/80">Le mot de passe de votre boîte est connu pour cette session : elle s’ouvre directement.</span>
                            </p>
                        </div>

                        <template v-else>
                            <p v-if="whyAsked" class="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />
                                <span>{{ whyAsked }}</span>
                            </p>
                            <FormField :label="chosen.own ? 'Mot de passe de votre boîte' : `Mot de passe de ${chosen.address}`" :error="passwordError" required>
                                <PasswordInput id="webmail-password" v-model="form.password" autocomplete="current-password" :aria-invalid="Boolean(passwordError) || undefined" />
                            </FormField>
                        </template>

                        <Button id="webmail-open" type="submit" size="lg" class="w-full" :disabled="(needsPassword && !form.password) || form.processing">
                            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                            <Inbox v-else-if="!needsPassword" class="h-4 w-4" aria-hidden="true" />
                            <KeyRound v-else class="h-4 w-4" aria-hidden="true" />
                            {{ form.processing ? (needsPassword ? 'Vérification auprès du serveur…' : 'Ouverture…') : (chosen.own ? 'Ouvrir ma boîte' : 'Ouvrir cette boîte') }}
                        </Button>
                    </form>
                </section>

                <section v-else class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border bg-card px-6 py-12 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground"><KeyRound class="h-5 w-5" aria-hidden="true" /></span>
                    <p class="text-sm font-semibold text-foreground">Choisissez une boîte</p>
                    <p class="text-xs text-muted-foreground">Son mot de passe se saisit ici.</p>
                </section>
            </aside>
        </div>
    </div>
</template>
