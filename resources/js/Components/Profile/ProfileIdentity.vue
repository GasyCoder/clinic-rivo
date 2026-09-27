<script setup>
import { computed } from 'vue';
import { BadgeCheck, Building2, CalendarClock, History, Mail, ShieldCheck, UserRound } from 'lucide-vue-next';

/**
 * L'identité du compte, en lecture (ADR-184). Rien ne s'y modifie : le nom,
 * l'email, le rôle et le profil métier sont gérés par l'administration
 * (ADR-022) — la page le dit plutôt que de laisser chercher un bouton.
 */
const props = defineProps({
    account: { type: Object, required: true },
});

const dateTime = (value) => (value
    ? new Date(value).toLocaleString('fr-FR', { dateStyle: 'long', timeStyle: 'short' })
    : null);

const facts = computed(() => [
    { label: 'Nom', value: props.account.name, icon: UserRound },
    { label: 'Adresse email', value: props.account.email, icon: Mail },
    { label: 'Rôle', value: props.account.role, icon: ShieldCheck },
    { label: 'Profil métier', value: props.account.professional_profile, icon: BadgeCheck, empty: 'Aucun profil métier' },
    { label: 'Établissement', value: props.account.site, icon: Building2 },
    { label: 'Dernière connexion', value: dateTime(props.account.last_login_at), icon: History, empty: 'Jamais enregistrée' },
    { label: 'Compte créé le', value: dateTime(props.account.created_at), icon: CalendarClock },
]);
</script>

<template>
    <div>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div v-for="fact in facts" :key="fact.label" class="flex items-start gap-3 rounded-xl border border-border px-4 py-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <component :is="fact.icon" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <dt class="text-xs font-medium text-muted-foreground">{{ fact.label }}</dt>
                    <dd :class="fact.value ? 'truncate text-sm font-semibold text-foreground' : 'text-sm italic text-muted-foreground'" :title="fact.value || undefined">
                        {{ fact.value || fact.empty || '—' }}
                    </dd>
                </div>
            </div>
        </dl>
        <p class="mt-4 text-xs leading-5 text-muted-foreground">
            Le nom, l’email, le rôle et le profil métier sont gérés par l’administration. Pour une correction, adressez-vous à elle.
        </p>
    </div>
</template>
