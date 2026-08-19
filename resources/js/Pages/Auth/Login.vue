<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
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

const monogram = computed(() => site.value.brand
    .split(' ')
    .filter(Boolean)
    .map((word) => word[0])
    .join('')
    .toUpperCase());

const otherSites = ['Mampikony', 'Ambondromamy', 'Boriziny'].filter(
    (name) => name.toUpperCase() !== site.value.name?.toUpperCase(),
);
</script>

<template>
    <Head title="Connexion" />

    <div class="relative flex min-h-screen">
        <!-- Form panel -->
        <div class="relative flex w-full flex-shrink-0 flex-col bg-white dark:bg-gray-950 lg:w-[44%]">
            <div class="m-auto w-full max-w-[380px] p-6 sm:p-11">
                <div class="mb-10 flex items-center gap-3">
                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-md bg-primary-600 font-heading text-xs font-bold text-white">
                        {{ monogram }}
                    </span>
                    <div class="min-w-0 leading-tight">
                        <div class="truncate font-heading text-sm font-bold text-slate-700 dark:text-white">{{ site.brand }}</div>
                        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Espace professionnel</div>
                    </div>
                </div>

                <div class="mb-8">
                    <h1 class="font-heading text-2xl font-bold leading-tight text-slate-700 dark:text-white">
                        Connexion
                    </h1>
                    <span
                        v-if="site.name"
                        class="mt-2 inline-flex items-center rounded border border-gray-200 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:border-gray-800 dark:text-slate-400"
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
                        <FormLabel for="password" class="mb-2 block">Mot de passe</FormLabel>
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

            <div class="w-full px-6 pb-8 text-xs text-slate-400 sm:px-11">
                <Copyright :brand="site.brand" />
            </div>
        </div>

        <!-- Identity panel — deliberately the same dark surface as the app's
             own sidebar (bg-gray-950), not a generic gradient, and always
             dark regardless of the light/dark toggle, mirroring how the
             sidebar itself behaves by default. -->
        <div class="relative hidden flex-1 flex-col justify-between bg-gray-950 p-11 lg:flex">
            <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-500">
                <span class="h-1.5 w-1.5 flex-none rounded-full bg-primary-500" />
                Système interne
            </div>

            <div>
                <div
                    v-if="site.name"
                    class="font-heading text-6xl font-bold uppercase leading-[0.95] tracking-tight text-white xl:text-7xl"
                >
                    {{ site.name }}
                </div>
                <div class="mt-6 h-px w-16 bg-primary-500" />
                <p class="mt-6 max-w-xs text-sm leading-6 text-slate-400">
                    {{ site.brand }} — plateforme de gestion clinique.
                </p>
            </div>

            <div v-if="otherSites.length" class="flex flex-wrap gap-x-6 gap-y-2">
                <span
                    v-for="name in otherSites"
                    :key="name"
                    class="text-xs font-bold uppercase tracking-wide text-slate-600"
                >
                    {{ name }}
                </span>
            </div>
        </div>
    </div>
</template>
