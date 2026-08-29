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
const isAdminPortal = computed(() => site.value.type === 'admin');
const accessLabel = computed(() => isAdminPortal.value
    ? 'Super Administration'
    : `Clinique de ${site.value.name}`);
</script>

<template>
    <Head title="Connexion" />

    <div class="relative min-h-screen overflow-hidden bg-primary-950">
        <IdentityPanel />

        <main class="relative z-10 flex min-h-screen w-full items-center justify-center px-4 py-8 sm:px-8 lg:justify-end lg:px-12 xl:px-20">
            <section class="w-full max-w-[450px] rounded-lg border border-white/70 bg-white px-6 py-8 shadow-[0_24px_70px_rgba(10,39,78,0.30)] sm:px-9 sm:py-10 dark:border-gray-800 dark:bg-gray-950">
                <BrandMark />

                <div class="mb-7">
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary-700 dark:text-primary-300">
                        {{ accessLabel }}
                    </p>
                    <h1 class="mt-2 font-heading text-2xl font-bold tracking-tight text-slate-800 dark:text-white sm:text-[28px]">
                        Connexion à votre espace
                    </h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Utilisez les identifiants de votre compte professionnel.
                    </p>
                </div>

                <form @submit.prevent="submit">
                    <FormGroup>
                        <FormLabel for="email" class="mb-2 block font-semibold">Adresse email</FormLabel>
                        <InputWrap>
                            <Icon class="pointer-events-none absolute start-4 top-1/2 z-20 -translate-y-1/2 text-lg text-slate-400" name="mail" />
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                icon="start"
                                size="lg"
                                placeholder="votre.email@clinique.mg"
                                autocomplete="username"
                                :aria-invalid="Boolean(form.errors.email)"
                                autofocus
                                required
                            />
                        </InputWrap>
                        <FormError v-if="form.errors.email">{{ form.errors.email }}</FormError>
                    </FormGroup>

                    <FormGroup>
                        <FormLabel class="mb-2 flex items-center justify-between">
                            <span class="font-semibold">Mot de passe</span>
                            <a
                                href="/forgot-password"
                                class="text-xs font-semibold text-primary-600 transition-colors duration-300 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300"
                            >
                                Mot de passe oublié ?
                            </a>
                        </FormLabel>
                        <InputWrap>
                            <Icon class="pointer-events-none absolute start-4 top-1/2 z-20 -translate-y-1/2 text-lg text-slate-400" name="lock" />
                            <button
                                type="button"
                                class="absolute end-0 top-0 z-20 flex h-11 w-11 items-center justify-center rounded-e-md text-slate-400 transition-colors hover:bg-slate-50 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-300 dark:hover:bg-gray-900 dark:hover:text-slate-200"
                                :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                                :aria-pressed="showPassword"
                                @click="showPassword = !showPassword"
                            >
                                <em v-if="!showPassword" class="ni ni-eye text-base leading-none" />
                                <em v-else class="ni ni-eye-off text-base leading-none" />
                            </button>
                            <Input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                icon="start"
                                size="lg"
                                placeholder="Saisissez votre mot de passe"
                                autocomplete="current-password"
                                :aria-invalid="Boolean(form.errors.password)"
                                required
                            />
                        </InputWrap>
                        <FormError v-if="form.errors.password">{{ form.errors.password }}</FormError>
                    </FormGroup>

                    <FormGroup class="flex items-center pt-1">
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
                            {{ form.processing ? 'Connexion…' : 'Se connecter' }}
                        </Button>
                    </FormGroup>
                </form>

                <div class="mt-6 text-center">
                    <template v-if="site.type === 'clinic' && site.gatewayUrl">
                        <div class="flex items-center gap-3" aria-hidden="true">
                            <span class="h-px flex-1 bg-gray-200 dark:bg-gray-800"></span>
                            <span class="text-[11px] font-medium uppercase tracking-wider text-slate-400">ou</span>
                            <span class="h-px flex-1 bg-gray-200 dark:bg-gray-800"></span>
                        </div>

                        <a
                            :href="site.gatewayUrl"
                            class="mt-4 inline-flex justify-center text-xs font-semibold text-primary-700 transition-colors duration-300 hover:text-primary-900 hover:underline hover:underline-offset-4 dark:text-primary-300 dark:hover:text-primary-200"
                        >
                            Choisir un autre établissement
                        </a>
                    </template>

                    <p :class="['text-xs leading-5 text-slate-400', site.type === 'clinic' && site.gatewayUrl ? 'mt-3' : 'mt-1']">
                        Accès réservé au personnel autorisé de la clinique.
                    </p>
                </div>

                <div class="mt-6 text-center text-[11px] text-slate-400">
                    <Copyright :brand="site.brand" />
                </div>
            </section>
        </main>
    </div>
</template>
