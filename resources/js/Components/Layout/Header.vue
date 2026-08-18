<script setup>
import { useThemeStore } from '@/stores/theme';

const theme = useThemeStore();

const visibility = defineModel('visibility', {
    default: false,
});
</script>

<template>
    <header
        class="nk-header sticky top-0 z-[1021]
               h-16 border-b border-gray-200
               bg-white dark:border-gray-900
               dark:bg-gray-950"
    >
        <div class="flex h-full items-center px-4 sm:px-6">
            <!-- Mobile sidebar -->
            <button
                type="button"
                class="me-3 inline-flex h-9 w-9
                       items-center justify-center rounded-full
                       text-slate-600 hover:bg-gray-100
                       dark:text-slate-300
                       dark:hover:bg-gray-900 xl:hidden"
                @click="visibility = true"
            >
                <em class="icon ni ni-menu text-2xl" />
            </button>

            <!-- Context -->
            <div class="min-w-0">
                <div
                    class="font-heading text-sm font-bold
                           text-slate-700 dark:text-white"
                >
                    Clinique Saint Georges
                </div>

                <div
                    class="hidden text-xs text-slate-400 sm:block"
                >
                    Système de gestion clinique RIVO
                </div>
            </div>

            <div class="ms-auto flex items-center gap-2">
                <!-- Site -->
                <div
                    class="hidden rounded-md border border-gray-200
                           px-3 py-1.5 text-xs text-slate-500
                           dark:border-gray-800
                           dark:text-slate-400 sm:block"
                >
                    Site local
                </div>

                <!-- Dark mode -->
                <button
                    type="button"
                    class="inline-flex h-9 min-w-9 items-center
                           justify-center rounded-full px-2
                           text-slate-600 transition
                           hover:bg-gray-100
                           dark:text-slate-300
                           dark:hover:bg-gray-900"
                    :title="
                        theme.mode === 'dark'
                            ? 'Mode clair'
                            : 'Mode sombre'
                    "
                    @click="theme.updateMode"
                >
                    <span
                        class="text-lg"
                        aria-hidden="true"
                    >
                        {{ theme.mode === 'dark' ? '☀' : '☾' }}
                    </span>
                </button>
            </div>
        </div>
    </header>
</template>
