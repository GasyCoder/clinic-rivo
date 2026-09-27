<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound, Loader2, Mail } from 'lucide-vue-next';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import AuthShell from '@/Components/Auth/AuthShell.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PasswordInput from '@/Components/Shadcn/PasswordInput.vue';

defineOptions({
    layout: GuestLayout,
});

const props = defineProps({
    email: {
        type: String,
        default: '',
    },
    token: {
        type: String,
        required: true,
    },
    /** Reached from a new account's welcome email rather than "forgot password". */
    welcome: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    welcome: props.welcome,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head :title="welcome ? 'Activer mon compte' : 'Réinitialiser le mot de passe'" />

    <AuthShell
        :eyebrow="welcome ? 'Activation du compte' : 'Mot de passe'"
        :title="welcome ? 'Bienvenue — activez votre compte' : 'Nouveau mot de passe'"
        :description="welcome
            ? `Choisissez votre mot de passe personnel pour ${form.email}. Il protège les données des patients : ne le communiquez à personne.`
            : `Choisissez un nouveau mot de passe pour ${form.email}.`"
    >
        <!-- En colonne flex : FormField est un <label>, en ligne, sur lequel space-y ne s'applique pas. -->
        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField label="Adresse email" :error="form.errors.email">
                <IconInput
                    id="email"
                    v-model="form.email"
                    :icon="Mail"
                    type="email"
                    size="lg"
                    autocomplete="username"
                    :aria-invalid="Boolean(form.errors.email)"
                    required
                />
            </FormField>

            <FormField label="Nouveau mot de passe" :error="form.errors.password">
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    autocomplete="new-password"
                    :aria-invalid="Boolean(form.errors.password)"
                    autofocus
                    required
                />
                <p class="mt-1.5 text-xs leading-5 text-muted-foreground">12 caractères minimum, avec majuscule, minuscule, chiffre et symbole.</p>
            </FormField>

            <FormField label="Confirmer le mot de passe" :error="form.errors.password_confirmation">
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    autocomplete="new-password"
                    required
                />
            </FormField>

            <Button type="submit" size="lg" variant="primary" class="w-full" :disabled="form.processing">
                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" /><KeyRound v-else class="h-4 w-4" />
                {{ form.processing ? 'Enregistrement…' : (welcome ? 'Activer mon compte' : 'Réinitialiser le mot de passe') }}
            </Button>
        </form>
    </AuthShell>
</template>
