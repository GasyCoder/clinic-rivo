<script setup>
import { Menu, MenuButton, MenuItems } from '@headlessui/vue';
import Icon from '@/Components/UI/Icon.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import { useThemeStore } from '@/stores/theme';

const theme = useThemeStore();

const visibility = defineModel('visibility');
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

                    <div class="px-1 py-3.5 flex xl:hidden">
                        <span class="font-heading text-lg font-bold text-slate-700 dark:text-white">RIVO</span>
                    </div>

                    <div class="px-1 py-2 hidden xl:flex items-center min-w-0">
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-300 truncate">
                            Clinique Saint Georges
                        </p>
                    </div>

                    <div class="px-1 py-3.5 ms-auto">
                        <ul class="flex item-center -mx-1.5 sm:-mx-2.5">
                            <li class="px-1.5 sm:px-2.5 inline-flex">
                                <Menu as="div" class="dropdown relative">
                                    <MenuButton class="dropdown-toggle *:pointer-events-none peer inline-flex items-center group">
                                        <div class="flex items-center">
                                            <Avatar rounded icon="user-alt" size="sm" variant="primary" />
                                            <div class="hidden md:block ms-3 text-start">
                                                <div class="text-slate-600 dark:text-white text-xs font-bold flex items-center">
                                                    Compte
                                                    <em class="text-sm leading-none ms-1 ni ni-chevron-down" />
                                                </div>
                                            </div>
                                        </div>
                                    </MenuButton>

                                    <MenuItems class="dropdown-menu absolute end-0 top-full mt-2.5 max-xs:min-w-[240px] max-xs:max-w-[240px] min-w-[260px] max-w-[260px] border border-t-3 border-gray-200 dark:border-gray-800 border-t-primary-600 dark:border-t-primary-600 bg-white dark:bg-gray-950 rounded shadow z-[1000]">
                                        <ul class="py-3">
                                            <li>
                                                <div class="relative px-7 py-2.5 flex items-center rounded-[inherit] text-sm leading-5 font-medium text-slate-400 cursor-default">
                                                    <Icon class="text-lg leading-none w-7" name="user-alt" />
                                                    <span>Mon profil</span>
                                                    <span class="ms-auto rounded bg-gray-100 px-1.5 py-0.5 text-xxs dark:bg-gray-900">
                                                        Bientôt
                                                    </span>
                                                </div>
                                            </li>
                                            <li class="block border-t border-gray-200 dark:border-gray-800 my-3" />
                                            <li>
                                                <button
                                                    type="button"
                                                    class="w-full relative px-7 py-2.5 flex items-center rounded-[inherit] text-sm leading-5 font-medium text-slate-600 dark:text-slate-400 hover:text-primary-600 hover:dark:text-primary-600 transition-all duration-300"
                                                    @click="theme.updateMode"
                                                >
                                                    <div class="flex dark:hidden items-center">
                                                        <Icon class="text-start text-lg leading-none w-7" name="moon" />
                                                        <span>Mode sombre</span>
                                                    </div>
                                                    <div class="hidden dark:flex items-center">
                                                        <Icon class="text-start text-lg leading-none w-7" name="sun" />
                                                        <span>Mode clair</span>
                                                    </div>
                                                    <div class="ms-auto relative h-6 w-12 rounded-full border-2 border-gray-200 dark:border-primary-600 bg-white dark:bg-primary-600">
                                                        <div class="absolute start-0.5 dark:start-6.5 top-0.5 h-4 w-4 rounded-full bg-gray-200 dark:bg-white transition-all duration-300" />
                                                    </div>
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
