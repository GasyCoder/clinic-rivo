<script setup>
defineProps({
    html: { type: String, default: '' },
});
</script>

<template>
    <!-- The server projects only the clinical rich-text allowlist. -->
    <div class="clinical-rich-text whitespace-pre-wrap" v-html="html" />
</template>

<style scoped>
.clinical-rich-text :deep(p) { margin-block: 0.2rem; }
.clinical-rich-text :deep(ul) { list-style: disc; margin-block: 0.35rem; padding-inline-start: 1.5rem; }
.clinical-rich-text :deep(ol) { list-style: decimal; margin-block: 0.35rem; padding-inline-start: 1.5rem; }
.clinical-rich-text :deep(mark) { background: rgb(254 240 138); color: rgb(51 65 85); padding-inline: 0.1rem; }
</style>
