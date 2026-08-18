<script setup>
const year = new Date().getFullYear();
</script>

<template>
    <footer
        class="mt-auto border-t border-gray-200
               bg-white px-4 py-4
               dark:border-gray-900 dark:bg-gray-950
               sm:px-6"
    >
        <div
            class="flex flex-col gap-1 text-xs
                   text-slate-400 sm:flex-row
                   sm:items-center sm:justify-between"
        >
            <span>
                © {{ year }} RIVO — Clinique Saint Georges
            </span>

            <span>
                Système de gestion clinique
            </span>
        </div>
    </footer>
</template>
