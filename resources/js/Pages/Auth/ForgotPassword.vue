<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Loader2, Mail, Send } from 'lucide-vue-next';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import AuthShell from '@/Components/Auth/AuthShell.vue';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';

defineOptions({
    layout: GuestLayout,
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password');
};
</script>

<template>
    <Head title="Mot de passe oublié" />

    <AuthShell
        eyebrow="Mot de passe"
        title="Mot de passe oublié"
        description="Indiquez votre adresse email : si elle correspond à un compte, un lien de réinitialisation vous sera envoyé."
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
                    placeholder="votre.email@clinique.mg"
                    autocomplete="username"
                    :aria-invalid="Boolean(form.errors.email)"
                    autofocus
                    required
                />
            </FormField>

            <Button type="submit" size="lg" variant="primary" class="w-full" :disabled="form.processing">
                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" /><Send v-else class="h-4 w-4" />
                {{ form.processing ? 'Envoi…' : 'Envoyer le lien de réinitialisation' }}
            </Button>
        </form>

        <template #footer>
            <a href="/login" class="mt-6 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-primary">
                <ArrowLeft class="h-3.5 w-3.5 rtl:-scale-x-100" aria-hidden="true" />
                Retour à la connexion
            </a>
        </template>
    </AuthShell>
</template>
