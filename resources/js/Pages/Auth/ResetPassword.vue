<script setup>
import { ref } from 'vue';
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
});

const page = usePage();
const showPassword = ref(false);

const form = useForm({
    token: props.token,
    email: props.email,
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
    <Head title="Réinitialiser le mot de passe" />

    <div class="relative flex min-h-screen">
        <div class="relative flex w-full flex-shrink-0 flex-col bg-white dark:bg-gray-950 lg:w-[45%]">
            <div class="m-auto w-full max-w-[420px] p-5 2xl:me-[90px]">
                <BrandMark />

                <div class="mb-8">
                    <h1 class="font-heading text-xl font-bold -tracking-snug leading-tighter text-slate-700 dark:text-white">
                        Nouveau mot de passe
                    </h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        Choisissez un nouveau mot de passe pour {{ form.email }}.
                    </p>
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
                                required
                            />
                        </InputWrap>
                        <FormError v-if="form.errors.email">{{ form.errors.email }}</FormError>
                    </FormGroup>

                    <FormGroup>
                        <FormLabel for="password" class="mb-2 block">Nouveau mot de passe</FormLabel>
                        <InputWrap>
                            <a
                                href="#password"
                                tabindex="-1"
                                class="absolute end-0 top-0 flex h-11 w-11 items-center justify-center text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-200"
                                @click.prevent="showPassword = !showPassword"
                            >
                                <em v-if="!showPassword" class="ni ni-eye text-base leading-none" />
                                <em v-else class="ni ni-eye-off text-base leading-none" />
                            </a>
                            <Input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                size="lg"
                                autocomplete="new-password"
                                autofocus
                                required
                            />
                        </InputWrap>
                        <p class="mt-1.5 text-xs leading-5 text-slate-400">12 caractères minimum, avec majuscule, minuscule, chiffre et symbole.</p>
                        <FormError v-if="form.errors.password">{{ form.errors.password }}</FormError>
                    </FormGroup>

                    <FormGroup>
                        <FormLabel for="password_confirmation" class="mb-2 block">Confirmer le mot de passe</FormLabel>
                        <InputWrap>
                            <Input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                :type="showPassword ? 'text' : 'password'"
                                size="lg"
                                autocomplete="new-password"
                                required
                            />
                        </InputWrap>
                        <FormError v-if="form.errors.password_confirmation">{{ form.errors.password_confirmation }}</FormError>
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
                            {{ form.processing ? 'Enregistrement…' : 'Réinitialiser le mot de passe' }}
                        </Button>
                    </FormGroup>
                </form>
            </div>

            <div class="mx-auto w-full max-w-[420px] px-5 pb-10 pt-7 text-center text-xs text-slate-400 2xl:me-[90px]">
                <Copyright :brand="page.props.site.brand" />
            </div>
        </div>

        <IdentityPanel />
    </div>
</template>
