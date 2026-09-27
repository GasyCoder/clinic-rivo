<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    KeyRound,
    Link2,
    Mail,
    MailX,
    Settings2,
    UserRoundCheck,
    UserRoundX,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Separator from '@/Components/Shadcn/Separator.vue';
import { usePermissions } from '@/composables/usePermissions';

/**
 * ADR-195 — pourquoi ce compte n'a aucune boîte à ouvrir, et quoi faire. La
 * messagerie dépend des permissions : `webmail.view` ouvre sa propre boîte —
 * l'adresse active de la fiche employé reliée au compte —, `webmail.open_any`
 * celle d'un autre employé. Sur le portail, la boîte est celle réglée dans son
 * .env. Jamais un refus muet.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    reason: { type: String, default: 'unlinked' },
    status: { type: String, default: null },
    permission: { type: String, default: 'webmail.view' },
    portal: { type: Boolean, default: false },
});

const { can } = usePermissions();
const canLinkAccount = computed(() => props.reason === 'unlinked' && can('users.view') && can('users.update'));

const content = computed(() => ({
    permission: {
        icon: KeyRound,
        eyebrow: 'Accès requis',
        title: 'La messagerie n’est pas encore accessible',
        lead: props.portal
            ? `Votre compte doit disposer du droit « ${props.permission} » pour ouvrir la messagerie du portail.`
            : 'Votre compte ne dispose pas encore du droit nécessaire pour ouvrir une boîte professionnelle.',
        resolution: 'Comment obtenir l’accès',
        steps: props.portal
            ? [`Faire attribuer le droit « ${props.permission} » à votre compte.`, 'Revenir ensuite dans la messagerie.']
            : [`Faire attribuer « ${props.permission} » pour votre boîte, ou « webmail.open_any » pour celle d’un employé.`, 'Le droit peut venir de votre rôle ou d’une exception individuelle.', 'Revenir ensuite dans la messagerie.'],
        note: 'Un administrateur habilité peut vérifier vos droits dans « Rôles & permissions ».',
    },
    portal_unconfigured: {
        icon: Settings2,
        eyebrow: 'Configuration du portail',
        title: 'La boîte du portail n’est pas configurée',
        lead: 'Le Super Admin utilise une adresse propre au portail, ouverte automatiquement et séparée des comptes employés.',
        resolution: 'Configuration attendue',
        steps: ['Créer une adresse non nominative chez l’hébergeur, par exemple direction@…', 'Renseigner RIVO_WEBMAIL_PORTAL_ADDRESS et RIVO_WEBMAIL_PORTAL_PASSWORD.', 'Recharger la messagerie après la mise à jour de l’environnement.'],
        note: 'Ces paramètres sont conservés dans l’environnement du portail, jamais dans une fiche employé.',
    },
    unlinked: {
        icon: Link2,
        eyebrow: 'Compte à relier',
        title: 'Reliez votre compte à votre fiche employé',
        lead: 'La messagerie utilise l’adresse professionnelle enregistrée sur votre fiche employé. Votre compte n’est relié à aucune fiche pour le moment.',
        resolution: canLinkAccount.value ? 'Finaliser le rattachement' : 'Ce qu’il faut demander',
        steps: canLinkAccount.value
            ? ['Ouvrir « Utilisateurs » et modifier votre compte.', 'Choisir le type « Personnel clinique ».', 'Sélectionner votre fiche employé, puis enregistrer.']
            : ['Contacter un administrateur de votre site.', 'Lui demander de relier votre compte à votre fiche employé.', 'Revenir dans la messagerie une fois le rattachement enregistré.'],
        note: canLinkAccount.value
            ? 'Votre fiche doit disposer d’une adresse professionnelle active pour que la boîte puisse s’ouvrir.'
            : 'Aucun mot de passe de messagerie ne vous sera demandé avant que ce rattachement soit terminé.',
    },
    no_address: {
        icon: MailX,
        eyebrow: 'Adresse manquante',
        title: 'Ajoutez une adresse professionnelle à votre fiche',
        lead: 'Votre compte est bien relié à une fiche employé, mais cette fiche ne dispose pas encore d’une adresse professionnelle.',
        resolution: 'Prochaine étape',
        steps: ['Demander l’adresse depuis votre fiche employé.', 'Attendre sa création par le Super Admin.', 'Revenir ici lorsqu’elle est indiquée comme active.'],
        note: 'Les Ressources humaines peuvent vous accompagner dans cette demande.',
    },
    inactive: {
        icon: UserRoundX,
        eyebrow: 'Adresse indisponible',
        title: 'Votre adresse professionnelle n’est pas active',
        lead: props.status ? `Son état actuel est « ${props.status} » : elle ne peut pas encore ouvrir une boîte.` : 'Votre adresse a été demandée, refusée ou suspendue et ne peut pas encore ouvrir une boîte.',
        resolution: 'Selon son état',
        steps: ['Une adresse demandée devient disponible après sa création par le Super Admin.', 'Une adresse suspendue doit être réactivée depuis « Emails professionnels ».', 'Revenir ici lorsque son état est « Active ».'],
        note: 'La messagerie s’ouvrira automatiquement dès que l’adresse sera active.',
    },
}[props.reason] ?? {
    icon: MailX,
    eyebrow: 'Messagerie indisponible',
    title: 'Aucune boîte professionnelle à ouvrir',
    lead: 'Votre compte ne permet pas encore d’ouvrir une boîte de messagerie.',
    resolution: 'Prochaine étape',
    steps: ['Contacter les Ressources humaines ou un administrateur de votre site.', 'Revenir dans la messagerie après vérification de votre compte.'],
    note: null,
}));
</script>

<template>
    <Head title="Messagerie indisponible" />

    <main class="w-full" aria-labelledby="webmail-unavailable-title">
        <Card class="w-full overflow-hidden">
            <header class="flex flex-col gap-4 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border bg-muted/60 text-foreground" aria-hidden="true">
                        <Mail class="h-4 w-4" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-heading text-sm font-semibold text-foreground">Messagerie professionnelle</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">Accès à la boîte associée à votre compte RIVO</p>
                    </div>
                </div>
                <Badge variant="secondary" class="w-fit">Configuration requise</Badge>
            </header>

            <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(22rem,420px)] xl:grid-cols-[minmax(0,1fr)_480px]">
                <section class="p-6 sm:p-8 xl:p-10">
                    <div class="flex items-start gap-4">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border border-border bg-muted/60 text-foreground" aria-hidden="true">
                            <component :is="content.icon" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">{{ content.eyebrow }}</p>
                            <h1 id="webmail-unavailable-title" class="mt-1 max-w-3xl font-heading text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                                {{ content.title }}
                            </h1>
                        </div>
                    </div>

                    <p class="mt-6 max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base sm:leading-7">{{ content.lead }}</p>

                    <Separator class="my-6" />

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Button v-if="canLinkAccount" :as="Link" href="/administration/users">
                            <UserRoundCheck class="h-4 w-4" aria-hidden="true" /> Ouvrir les utilisateurs
                            <ArrowRight class="h-4 w-4" aria-hidden="true" />
                        </Button>
                        <Button :as="Link" href="/" variant="outline">
                            <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Retour à la vue d’ensemble
                        </Button>
                    </div>
                </section>

                <aside class="border-t border-border bg-muted/25 p-6 sm:p-8 lg:border-s lg:border-t-0 xl:p-10" aria-labelledby="webmail-resolution-title">
                    <span class="grid h-10 w-10 place-items-center rounded-lg border border-border bg-card text-muted-foreground shadow-sm" aria-hidden="true">
                        <Mail class="h-4 w-4" />
                    </span>
                    <h2 id="webmail-resolution-title" class="mt-5 text-base font-semibold text-foreground">{{ content.resolution }}</h2>

                    <ol class="mt-4 space-y-4">
                        <li v-for="(step, index) in content.steps" :key="step" class="flex gap-3">
                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-border bg-card text-[11px] font-bold text-muted-foreground" aria-hidden="true">{{ index + 1 }}</span>
                            <p class="pt-0.5 text-sm leading-5 text-foreground/80">{{ step }}</p>
                        </li>
                    </ol>

                    <p v-if="content.note" class="mt-6 rounded-lg border border-border bg-card/80 p-3 text-xs leading-5 text-muted-foreground">
                        {{ content.note }}
                    </p>
                </aside>
            </div>
        </Card>
    </main>
</template>
