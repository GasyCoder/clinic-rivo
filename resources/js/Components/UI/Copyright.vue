<script setup>
defineProps({
    brand: {
        type: String,
        required: true,
    },
});

const year = new Date().getFullYear();
</script>

<template>
    <span>
        &copy; {{ year }} {{ brand }} - développé par
        <a
            href="https://www.linkedin.com/in/florentbezara/"
            target="_blank"
            rel="noopener noreferrer"
            class="font-medium text-primary-600 transition-colors duration-300 hover:text-primary-700 dark:text-primary-500 dark:hover:text-primary-400"
        >GasyCoder</a>
    </span>
</template>
