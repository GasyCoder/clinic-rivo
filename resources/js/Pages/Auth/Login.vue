<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Loader2, LogIn, Mail } from 'lucide-vue-next';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import AuthShell from '@/Components/Auth/AuthShell.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import PasswordInput from '@/Components/Shadcn/PasswordInput.vue';

defineOptions({
    layout: GuestLayout,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};

const page = usePage();
const site = computed(() => page.props.site);
const isAdminPortal = computed(() => site.value.type === 'admin');
const accessLabel = computed(() => isAdminPortal.value
    ? 'Super Administration'
    : `Clinique de ${site.value.name}`);
</script>

<template>
    <Head title="Connexion" />

    <AuthShell
        :eyebrow="accessLabel"
        title="Connexion à votre espace"
        description="Utilisez les identifiants de votre compte professionnel."
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

            <FormField label="Mot de passe" :error="form.errors.password">
                <template #action>
                    <a href="/forgot-password" class="shrink-0 text-xs font-semibold text-primary hover:underline hover:underline-offset-4">
                        Mot de passe oublié ?
                    </a>
                </template>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    placeholder="Saisissez votre mot de passe"
                    autocomplete="current-password"
                    :aria-invalid="Boolean(form.errors.password)"
                    required
                />
            </FormField>

            <label class="flex w-fit cursor-pointer items-center gap-2.5 text-sm text-muted-foreground">
                <Checkbox id="remember" v-model="form.remember" />
                Se souvenir de moi
            </label>

            <Button type="submit" size="lg" variant="primary" class="w-full" :disabled="form.processing">
                <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" /><LogIn v-else class="h-4 w-4" />
                {{ form.processing ? 'Connexion…' : 'Se connecter' }}
            </Button>
        </form>

        <template #footer>
            <div class="mt-6 text-center">
                <template v-if="site.type === 'clinic' && site.gatewayUrl">
                    <div class="flex items-center gap-3" aria-hidden="true">
                        <span class="h-px flex-1 bg-border" />
                        <span class="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">ou</span>
                        <span class="h-px flex-1 bg-border" />
                    </div>
                    <a :href="site.gatewayUrl" class="mt-4 inline-flex justify-center text-xs font-semibold text-primary hover:underline hover:underline-offset-4">
                        Choisir un autre établissement
                    </a>
                </template>
                <p :class="['text-xs leading-5 text-muted-foreground', site.type === 'clinic' && site.gatewayUrl ? 'mt-3' : 'mt-1']">
                    Accès réservé au personnel autorisé de la clinique.
                </p>
            </div>
        </template>
    </AuthShell>
</template>
