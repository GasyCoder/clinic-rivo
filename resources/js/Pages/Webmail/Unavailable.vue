<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { KeyRound, Link2, MailX, UserRoundX } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';

/**
 * ADR-195 — pourquoi ce compte n'a aucune boîte à ouvrir, et quoi faire. La
 * messagerie dépend des permissions : `webmail.view` ouvre sa propre boîte —
 * l'adresse active de la fiche employé reliée au compte —, `webmail.open_any`
 * celle d'un autre employé. Jamais un refus muet.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    reason: { type: String, default: 'unlinked' },
    status: { type: String, default: null },
    permission: { type: String, default: 'webmail.view' },
    portal: { type: Boolean, default: false },
});

const content = computed(() => ({
    permission: {
        icon: KeyRound,
        title: 'Messagerie non accordée à ce compte',
        lines: [
            `La messagerie demande le droit « ${props.permission} » (sa propre boîte), ou « webmail.open_any » (la boîte d’un autre employé).`,
            'Il s’accorde dans « Rôles & permissions » : au socle du rôle, ou en exception pour ce compte.',
        ],
    },
    unlinked: {
        icon: Link2,
        title: 'Votre compte n’est relié à aucune fiche employé',
        lines: [
            'La messagerie ouvre l’adresse professionnelle de la fiche employé reliée à votre compte.',
            'Un administrateur relie le compte dans « Utilisateurs » : modifier le compte, choisir « Personnel clinique », puis la fiche.',
        ],
    },
    no_address: {
        icon: MailX,
        title: 'Votre fiche employé n’a pas d’adresse professionnelle',
        lines: ['Demandez-la aux Ressources humaines : elle se demande depuis votre fiche, puis le Super Admin la crée.'],
    },
    inactive: {
        icon: UserRoundX,
        title: 'Votre adresse professionnelle n’est pas active',
        lines: [
            props.status ? `Elle est actuellement « ${props.status} ».` : 'Elle a été demandée, refusée ou suspendue.',
            'Une adresse demandée s’ouvre dès que le Super Admin l’a créée ; une adresse suspendue se réactive depuis « Emails professionnels ».',
        ],
    },
}[props.reason] ?? {
    icon: MailX,
    title: 'Aucune boîte à ouvrir',
    lines: ['Adressez-vous aux Ressources humaines.'],
}));
</script>

<template>
    <Head title="Messagerie" />

    <div class="mx-auto flex max-w-lg flex-col items-center gap-4 py-16 text-center">
        <span class="grid h-16 w-16 place-items-center rounded-full bg-muted text-muted-foreground"><component :is="content.icon" class="h-7 w-7" aria-hidden="true" /></span>
        <h1 class="text-xl font-bold text-foreground">{{ content.title }}</h1>
        <p v-for="line in content.lines" :key="line" class="text-sm text-muted-foreground">{{ line }}</p>
        <Button :as="Link" href="/" variant="outline">Retour à la vue d’ensemble</Button>
    </div>
</template>
