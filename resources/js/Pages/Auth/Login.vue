<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
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
</script>

<template>
    <Head title="Connexion" />

    <div class="relative flex min-h-screen items-center justify-center px-5 py-10">
        <div class="w-full max-w-[420px] rounded-md border border-gray-200 bg-white p-7 dark:border-gray-900 dark:bg-gray-950 sm:p-9">
            <div class="pb-7 text-center">
                <h1 class="font-heading text-xl font-bold leading-tighter -tracking-snug text-slate-700 dark:text-white">
                    Clinique Saint Georges
                </h1>
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
                            class="absolute end-0 top-0 flex h-11 w-11 items-center justify-center"
                            @click.prevent="showPassword = !showPassword"
                        >
                            <em v-if="!showPassword" class="ni ni-eye text-base leading-none text-slate-400" />
                            <em v-else class="ni ni-eye-off text-base leading-none text-slate-400" />
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

                <FormGroup>
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
                        Connexion
                    </Button>
                </FormGroup>
            </form>
        </div>
    </div>
</template>
