<script setup>
import { useForm } from '@inertiajs/vue3';
import { KeyRound, Loader2, MonitorSmartphone } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import PasswordInput from '@/Components/Shadcn/PasswordInput.vue';

/**
 * Changer son propre mot de passe (ADR-184) : l'ancien est exigé, la politique
 * est celle de tout RIVO, et les autres sessions ouvertes au nom du compte
 * sont fermées. Le serveur revérifie tout ; l'écran ne fait que le dire avant.
 */
const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put('/profil/mot-de-passe', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <form class="flex max-w-xl flex-col gap-5" @submit.prevent="submit">
        <FormField label="Mot de passe actuel" :error="form.errors.current_password">
            <PasswordInput
                id="current_password"
                v-model="form.current_password"
                size="default"
                autocomplete="current-password"
                :aria-invalid="Boolean(form.errors.current_password)"
                required
            />
        </FormField>

        <FormField label="Nouveau mot de passe" :error="form.errors.password">
            <PasswordInput
                id="new_password"
                v-model="form.password"
                size="default"
                autocomplete="new-password"
                :aria-invalid="Boolean(form.errors.password)"
                required
            />
            <p class="mt-1.5 text-xs leading-5 text-muted-foreground">12 caractères minimum, avec majuscule, minuscule, chiffre et symbole ; différent de l’actuel.</p>
        </FormField>

        <FormField label="Confirmer le nouveau mot de passe" :error="form.errors.password_confirmation">
            <PasswordInput
                id="new_password_confirmation"
                v-model="form.password_confirmation"
                size="default"
                autocomplete="new-password"
                required
            />
        </FormField>

        <p class="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs leading-5 text-muted-foreground">
            <MonitorSmartphone class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            Vos autres sessions ouvertes (autre poste, téléphone) seront fermées. Celle-ci reste ouverte.
        </p>

        <Button type="submit" variant="primary" class="self-start" :disabled="form.processing">
            <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" /><KeyRound v-else class="h-4 w-4" />
            {{ form.processing ? 'Enregistrement…' : 'Changer le mot de passe' }}
        </Button>
    </form>
</template>
