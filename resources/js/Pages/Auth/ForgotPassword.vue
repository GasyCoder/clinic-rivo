<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import BrandMark from '@/Components/Auth/BrandMark.vue';
import IdentityPanel from '@/Components/Auth/IdentityPanel.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import FormError from '@/Components/UI/FormError.vue';
import InputWrap from '@/Components/UI/InputWrap.vue';
import Input from '@/Components/UI/Input.vue';
import Button from '@/Components/UI/Button.vue';
import Copyright from '@/Components/UI/Copyright.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    layout: GuestLayout,
});

const page = usePage();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password');
};
</script>

<template>
    <Head title="Mot de passe oublié" />

    <div class="relative flex min-h-screen">
        <div class="relative flex w-full flex-shrink-0 flex-col bg-white dark:bg-gray-950 lg:w-[45%]">
            <div class="m-auto w-full max-w-[420px] p-5 2xl:me-[90px]">
                <BrandMark />

                <div class="mb-8">
                    <h1 class="font-heading text-xl font-bold -tracking-snug leading-tighter text-slate-700 dark:text-white">
                        Mot de passe oublié
                    </h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        Indiquez votre adresse email : si elle correspond à un compte, un lien de réinitialisation vous sera envoyé.
                    </p>
                </div>

                <div
                    v-if="page.props.flash.status"
                    class="mb-5 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-400"
                >
                    {{ page.props.flash.status }}
                </div>

                <form @submit.prevent="submit">
                    <FormGroup>
                        <FormLabel for="email" class="mb-2 block">Email</FormLabel>
                        <InputWrap>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                size="lg"
                                autocomplete="username"
                                autofocus
                                required
                            />
                        </InputWrap>
                        <FormError v-if="form.errors.email">{{ form.errors.email }}</FormError>
                    </FormGroup>

                    <FormGroup>
                        <Button
                            type="submit"
                            size="lg"
                            variant="primary"
                            block
                            :disabled="form.processing"
                        >
                            <span
                                v-if="form.processing"
                                class="me-2 inline-block h-4 w-4 flex-none animate-spin rounded-full border-2 border-white/40 border-t-white"
                            />
                            {{ form.processing ? 'Envoi…' : 'Envoyer le lien de réinitialisation' }}
                        </Button>
                    </FormGroup>
                </form>

                <a
                    href="/login"
                    class="mt-6 inline-flex items-center gap-1.5 text-xs font-medium text-slate-400 transition-colors duration-300 hover:text-primary-600 dark:hover:text-primary-500"
                >
                    <Icon name="arrow-left" class="text-sm leading-none rtl:-scale-x-100" />
                    Retour à la connexion
                </a>
            </div>

            <div class="mx-auto w-full max-w-[420px] px-5 pb-10 pt-7 text-center text-xs text-slate-400 2xl:me-[90px]">
                <Copyright :brand="page.props.site.brand" />
            </div>
        </div>

        <IdentityPanel />
    </div>
</template>
