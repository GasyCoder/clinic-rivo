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

const sites = ['Mampikony', 'Ambondromamy', 'Boriziny'];

const year = new Date().getFullYear();

const page = usePage();
const site = computed(() => page.props.site);
</script>

<template>
    <Head title="Connexion" />

    <div class="relative flex min-h-screen">
        <!-- Form panel -->
        <div class="relative flex w-full flex-shrink-0 flex-col bg-white dark:bg-gray-950 lg:w-[46%]">
            <div class="m-auto w-full max-w-[400px] p-6 sm:p-11">
                <div class="mb-8 flex flex-col items-center text-center lg:items-start lg:text-start">
                    <div
                        class="mb-5 inline-flex h-14 w-14 flex-none items-center justify-center rounded-2xl bg-primary-50 text-primary-600 dark:bg-primary-950"
                    >
                        <em class="ni ni-plus-medi-fill text-2xl leading-none" />
                    </div>

                    <h1 class="font-heading text-xl font-bold leading-tighter -tracking-snug text-slate-700 dark:text-white">
                        {{ site.brand }}
                    </h1>
                    <p v-if="site.name" class="mt-1 text-sm font-bold text-primary-600">
                        {{ site.name }}
                    </p>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        Accès réservé au personnel de la clinique.
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
            </div>

            <div class="w-full px-6 pb-8 text-center text-xs text-slate-400 sm:px-11 lg:text-start">
                © {{ year }} {{ site.brand }}
            </div>
        </div>

        <!-- Brand panel -->
        <div
            class="relative hidden flex-1 flex-col justify-between overflow-hidden bg-gradient-to-br from-primary-600 to-primary-800 p-11 lg:flex"
        >
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.07]"
                style="background-image: radial-gradient(circle, #fff 1px, transparent 1px); background-size: 28px 28px"
            />

            <div class="relative flex flex-none items-center gap-2 text-white/90">
                <em class="ni ni-plus-medi-fill text-2xl leading-none" />
                <span class="font-heading text-sm font-bold tracking-wide">{{ site.brand }}</span>
            </div>

            <div class="relative">
                <h2 class="max-w-[380px] font-heading text-3xl font-bold leading-tighter -tracking-snug text-white">
                    Plateforme de gestion clinique
                </h2>
                <p class="mt-4 max-w-[380px] text-sm leading-6 text-white/70">
                    Réception, médecine, chirurgie, laboratoire et pharmacie réunis dans un même espace, pour chaque site.
                </p>

                <div class="mt-8 flex flex-wrap gap-2">
                    <span
                        v-for="site in sites"
                        :key="site"
                        class="rounded-full border border-white/25 bg-white/10 px-3.5 py-1.5 text-xs font-medium text-white/90 backdrop-blur-sm"
                    >
                        {{ site }}
                    </span>
                </div>
            </div>

            <div class="relative flex-none text-xs text-white/50">
                Système interne — accès réservé au personnel habilité.
            </div>
        </div>
    </div>
</template>
