<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import BrandMark from '@/Components/Auth/BrandMark.vue';
import IdentityPanel from '@/Components/Auth/IdentityPanel.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormLabel from '@/Components/UI/FormLabel.vue';
import FormError from '@/Components/UI/FormError.vue';
import InputWrap from '@/Components/UI/InputWrap.vue';
import Input from '@/Components/UI/Input.vue';
import CheckBox from '@/Components/UI/CheckBox.vue';
import Button from '@/Components/UI/Button.vue';
import Copyright from '@/Components/UI/Copyright.vue';
import Icon from '@/Components/UI/Icon.vue';

defineOptions({
    layout: GuestLayout,
});

const showPassword = ref(false);

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
</script>

<template>
    <Head title="Connexion" />

    <div class="relative flex min-h-screen">
        <!-- Form panel -->
        <div class="relative flex w-full flex-shrink-0 flex-col bg-white dark:bg-gray-950 lg:w-[45%]">
            <div class="m-auto w-full max-w-[420px] p-5 2xl:me-[90px]">
                <BrandMark />

                <div class="mb-8">
                    <h1 class="font-heading text-xl font-bold -tracking-snug leading-tighter text-slate-700 dark:text-white">
                        Connexion
                    </h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        Accédez à votre espace avec votre email et votre mot de passe.
                    </p>
                    <span
                        v-if="site.name"
                        class="mt-3 inline-flex items-center rounded border border-gray-200 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:border-gray-800 dark:text-slate-400"
                    >
                        {{ site.name }}
                    </span>
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
                        <FormLabel class="mb-2 flex items-center justify-between">
                            <span>Mot de passe</span>
                            <a
                                href="/forgot-password"
                                tabindex="-1"
                                class="text-xs font-medium text-primary-500 transition-colors duration-300 hover:text-primary-600"
                            >
                                Mot de passe oublié ?
                            </a>
                        </FormLabel>
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
                                autocomplete="current-password"
                                required
                            />
                        </InputWrap>
                        <FormError v-if="form.errors.password">{{ form.errors.password }}</FormError>
                    </FormGroup>

                    <FormGroup class="flex items-center">
                        <CheckBox id="remember" v-model="form.remember" size="sm">
                            Se souvenir de moi
                        </CheckBox>
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
                            {{ form.processing ? 'Connexion…' : 'Connexion' }}
                        </Button>
                    </FormGroup>
                </form>

                <a
                    v-if="site.type === 'clinic' && site.gatewayUrl"
                    :href="site.gatewayUrl"
                    class="mt-6 inline-flex items-center gap-1.5 text-xs font-medium text-slate-400 transition-colors duration-300 hover:text-primary-600 dark:hover:text-primary-500"
                >
                    <Icon name="arrow-left" class="text-sm leading-none rtl:-scale-x-100" />
                    Choisir un autre site
                </a>
            </div>

            <div class="mx-auto w-full max-w-[420px] px-5 pb-10 pt-7 text-center text-xs text-slate-400 2xl:me-[90px]">
                <Copyright :brand="site.brand" />
            </div>
        </div>

        <IdentityPanel />
    </div>
</template>
