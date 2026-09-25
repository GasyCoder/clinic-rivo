<script setup>
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { IdCard, KeyRound, ShieldCheck } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';
import ProfileIdentity from '@/Components/Profile/ProfileIdentity.vue';
import ProfilePermissions from '@/Components/Profile/ProfilePermissions.vue';
import ProfileSecurity from '@/Components/Profile/ProfileSecurity.vue';
import { cn } from '@/lib/cn';

/**
 * « Mon profil » (ADR-184) : l'identité du compte, ses droits, et le
 * changement de son mot de passe — rien d'autre ne s'y modifie (ADR-022).
 *
 * La disposition suit le modèle réglé pour le site depuis le portail :
 * - SIDEBAR : une carte et un menu à gauche, la section à droite (DashWind
 *   « user-profile-regular ») ;
 * - BANNER  : un bandeau au nom de l'utilisateur, puis des onglets.
 * Le contenu est le même dans les deux.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    account: { type: Object, required: true },
    /** Pas `permissions` : ce nom est la prop partagée que lit le menu latéral. */
    grantedPermissions: { type: Array, default: () => [] },
});

const SECTIONS = [
    { id: 'identite', label: 'Mon compte', description: 'Qui vous êtes dans RIVO.', icon: IdCard },
    { id: 'droits', label: 'Mes droits', description: 'Ce que votre compte peut faire.', icon: ShieldCheck },
    { id: 'securite', label: 'Sécurité', description: 'Changer votre mot de passe.', icon: KeyRound },
];

const page = usePage();
const template = computed(() => (page.props.site?.profileTemplate === 'BANNER' ? 'BANNER' : 'SIDEBAR'));
const active = ref('identite');
const activeSection = computed(() => SECTIONS.find((section) => section.id === active.value) ?? SECTIONS[0]);

// Un refus du mot de passe ramène sur la section qui l'a produit.
watch(() => page.props.errors, (errors) => {
    if (errors && ['current_password', 'password', 'password_confirmation'].some((key) => errors[key])) active.value = 'securite';
});

const initials = computed(() => props.account.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0])
    .join('')
    .toUpperCase());
</script>

<template>
    <Head title="Mon profil" />

    <div class="w-full space-y-5">
        <header>
            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Mon compte</p>
            <h1 class="mt-0.5 font-heading text-2xl font-bold tracking-tight text-foreground">Mon profil</h1>
        </header>

        <!-- Barre latérale : la carte et le menu à gauche. -->
        <div v-if="template === 'SIDEBAR'" class="grid items-start gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]" data-profile-template="SIDEBAR">
            <Card class="overflow-hidden lg:sticky lg:top-20">
                <div class="flex flex-col items-center border-b border-border px-5 py-6 text-center">
                    <span class="grid h-16 w-16 place-items-center rounded-full bg-primary font-heading text-xl font-bold text-primary-foreground" aria-hidden="true">{{ initials }}</span>
                    <p class="mt-3 font-heading text-base font-bold text-foreground">{{ account.name }}</p>
                    <p class="truncate text-xs text-muted-foreground">{{ account.email }}</p>
                    <div class="mt-3 flex flex-wrap justify-center gap-1.5">
                        <Badge v-if="account.role">{{ account.role }}</Badge>
                        <Badge v-if="account.professional_profile" variant="outline">{{ account.professional_profile }}</Badge>
                    </div>
                </div>
                <nav class="p-2" aria-label="Sections du profil">
                    <button
                        v-for="section in SECTIONS"
                        :key="section.id"
                        type="button"
                        :aria-current="active === section.id ? 'page' : undefined"
                        :class="cn(
                            'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start text-sm font-medium transition-colors',
                            active === section.id ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )"
                        @click="active = section.id"
                    >
                        <component :is="section.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />{{ section.label }}
                    </button>
                </nav>
            </Card>

            <Card class="overflow-hidden">
                <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary" aria-hidden="true"><component :is="activeSection.icon" class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-base font-bold text-foreground">{{ activeSection.label }}</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">{{ activeSection.description }}</p>
                    </div>
                </div>
                <div class="px-5 py-5">
                    <ProfileIdentity v-if="active === 'identite'" :account="account" />
                    <ProfilePermissions v-else-if="active === 'droits'" :permissions="grantedPermissions" />
                    <ProfileSecurity v-else />
                </div>
            </Card>
        </div>

        <!-- Bandeau et onglets. -->
        <div v-else class="space-y-5" data-profile-template="BANNER">
            <Card class="overflow-hidden">
                <div class="h-28 bg-gradient-to-r from-primary to-primary/70" aria-hidden="true" />
                <div class="flex flex-col gap-4 px-5 pb-5 sm:flex-row sm:items-end">
                    <span class="-mt-10 grid h-20 w-20 shrink-0 place-items-center rounded-full border-4 border-card bg-primary font-heading text-2xl font-bold text-primary-foreground shadow" aria-hidden="true">{{ initials }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-heading text-lg font-bold text-foreground">{{ account.name }}</p>
                        <p class="truncate text-sm text-muted-foreground">{{ account.email }} · {{ account.site }}</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge v-if="account.role">{{ account.role }}</Badge>
                        <Badge v-if="account.professional_profile" variant="outline">{{ account.professional_profile }}</Badge>
                    </div>
                </div>
                <nav class="flex gap-1 overflow-x-auto border-t border-border px-3" aria-label="Sections du profil">
                    <button
                        v-for="section in SECTIONS"
                        :key="section.id"
                        type="button"
                        :aria-current="active === section.id ? 'page' : undefined"
                        :class="cn(
                            '-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-3 text-sm font-semibold transition-colors',
                            active === section.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground',
                        )"
                        @click="active = section.id"
                    >
                        <component :is="section.icon" class="h-4 w-4" aria-hidden="true" />{{ section.label }}
                    </button>
                </nav>
            </Card>

            <Card class="px-5 py-5">
                <ProfileIdentity v-if="active === 'identite'" :account="account" />
                <ProfilePermissions v-else-if="active === 'droits'" :permissions="grantedPermissions" />
                <ProfileSecurity v-else />
            </Card>
        </div>
    </div>
</template>
