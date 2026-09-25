<script setup>
import { computed } from 'vue';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { UserRound } from 'lucide-vue-next';
import Icon from '@/Components/UI/Icon.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import HeaderSearch from '@/Components/Layout/HeaderSearch.vue';
import HeaderAttention from '@/Components/Layout/HeaderAttention.vue';
import BrandLockup from '@/Components/Layout/BrandLockup.vue';
import ThemeModeSwitcher from '@/Components/Layout/ThemeModeSwitcher.vue';

const page = usePage();

const visibility = defineModel('visibility');

const user = computed(() => page.props.auth.user);

const initials = computed(() => {
    if (!user.value) {
        return '';
    }

    return user.value.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
});

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="nk-header fixed start-0 w-full h-16 top-0 z-[1021] transition-all duration-300 min-w-[320px]">
        <div class="h-16 border-b bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-900 px-1.5 sm:px-5">
            <div class="container max-w-none">
                <div class="relative flex items-center -mx-1">
                    <div class="px-1 me-4 -ms-1.5 xl:hidden">
                        <button
                            type="button"
                            class="sidebar-toggle *:pointer-events-none inline-flex items-center isolate relative h-9 w-9 px-1.5 before:content-[''] before:absolute before:-z-[1] before:h-5 before:w-5 hover:before:h-10 hover:before:w-10 before:rounded-full before:opacity-0 hover:before:opacity-100 before:transition-all before:duration-300 before:-translate-x-1/2 before:-translate-y-1/2 before:top-1/2 before:left-1/2 before:bg-gray-200 dark:before:bg-gray-900"
                            @click="visibility = true"
                        >
                            <Icon name="menu" class="text-2xl text-slate-600 dark:text-slate-300" />
                        </button>
                    </div>

                    <!-- La marque n'apparaît qu'ici en mobile : sur grand
                         écran le bandeau latéral la porte déjà, et la répéter
                         volait la place à la recherche. -->
                    <div class="flex min-w-0 px-1 py-3 xl:hidden">
                        <BrandLockup />
                    </div>

                    <div class="hidden min-w-0 flex-1 px-1 py-2 xl:flex">
                        <HeaderSearch />
                    </div>

                    <div class="px-1 py-3.5 ms-auto flex items-center gap-2 sm:gap-3">
                        <!-- Clair, Système (l'appareil) ou Sombre, à côté de la cloche. Sur un
                             téléphone la barre n'a pas la place : le choix reste dans le menu du compte. -->
                        <ThemeModeSwitcher variant="header" class="hidden sm:inline-flex" />
                        <span class="hidden h-6 w-px shrink-0 bg-border sm:block" aria-hidden="true" />
                        <HeaderAttention />

                        <!-- Un filet entre la cloche et le compte : deux
                             commandes sans rapport, collées, se lisent comme
                             un seul bloc et on clique l'une pour l'autre. -->
                        <span class="hidden h-6 w-px shrink-0 bg-border sm:block" aria-hidden="true" />

                        <ul class="flex item-center">
                            <li class="inline-flex">
                                <Menu as="div" class="dropdown relative">
                                    <MenuButton class="dropdown-toggle *:pointer-events-none peer inline-flex items-center group">
                                        <div class="flex items-center">
                                            <Avatar rounded :text="initials" size="sm" variant="primary" />
                                            <div class="hidden md:block ms-3 text-start">
                                                <div class="text-slate-600 dark:text-white text-xs font-bold flex items-center max-w-[140px] truncate">
                                                    {{ user?.name }}
                                                    <em class="text-sm leading-none ms-1 ni ni-chevron-down" />
                                                </div>
                                                <div v-if="user?.professional_profile" class="text-[10px] text-slate-400 dark:text-slate-500 truncate">{{ user.professional_profile.name }}</div>
                                            </div>
                                        </div>
                                    </MenuButton>

                                    <MenuItems class="dropdown-menu absolute end-0 top-full mt-2.5 max-xs:min-w-[240px] max-xs:max-w-[240px] min-w-[260px] max-w-[260px] border border-t-3 border-gray-200 dark:border-gray-800 border-t-primary-600 dark:border-t-primary-600 bg-white dark:bg-gray-950 rounded shadow z-[1000]">
                                        <div class="px-7 py-4 border-b border-gray-200 dark:border-gray-800">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-bold text-slate-700 dark:text-white truncate">{{ user?.name }}</span>
                                                <span v-if="user?.professional_profile" class="shrink-0 rounded bg-primary-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary-700 dark:bg-primary-950/30 dark:text-primary-300">{{ user.professional_profile.name }}</span>
                                            </div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ user?.email }}</div>
                                        </div>
                                        <ul class="py-3">
                                            <li>
                                                <!-- ADR-184 — « Mon profil » : identité, droits et mot de passe. -->
                                                <MenuItem v-slot="{ close }">
                                                    <Link
                                                        href="/profil"
                                                        class="w-full relative px-7 py-2.5 flex items-center rounded-[inherit] text-sm leading-5 font-medium text-slate-600 dark:text-slate-300 hover:text-primary-600 hover:dark:text-primary-600 transition-all duration-300"
                                                        @click="close"
                                                    >
                                                        <span class="w-7" aria-hidden="true"><UserRound class="h-[18px] w-[18px]" /></span>
                                                        <span>Mon profil</span>
                                                    </Link>
                                                </MenuItem>
                                            </li>
                                            <li class="px-7 py-2.5 sm:hidden">
                                                <!-- Sur un téléphone seulement : ailleurs, le choix est dans la barre, à côté de la cloche. -->
                                                <p class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Apparence</p>
                                                <ThemeModeSwitcher variant="menu" />
                                            </li>
                                            <li class="block border-t border-gray-200 dark:border-gray-800 my-3" />
                                            <li>
                                                <button
                                                    type="button"
                                                    class="w-full relative px-7 py-2.5 flex items-center rounded-[inherit] text-sm leading-5 font-medium text-slate-600 dark:text-slate-300 hover:text-primary-600 hover:dark:text-primary-600 transition-all duration-300"
                                                    @click="logout"
                                                >
                                                    <Icon class="text-lg leading-none w-7" name="signout" />
                                                    <span>Déconnexion</span>
                                                </button>
                                            </li>
                                        </ul>
                                    </MenuItems>
                                </Menu>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
