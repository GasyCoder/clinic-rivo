import { ref } from 'vue';
import { defineStore } from 'pinia';

let nextId = 0;

export const useToastStore = defineStore('toast', () => {
    const toasts = ref([]);

    const dismiss = (id) => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    };

    const push = (type, message, duration) => {
        if (!message) return null;

        const id = ++nextId;
        toasts.value.push({ id, type, message });
        setTimeout(() => dismiss(id), duration);

        return id;
    };

    return {
        toasts,
        success: (message, duration = 5000) => push('success', message, duration),
        warning: (message, duration = 6000) => push('warning', message, duration),
        error: (message, duration = 7000) => push('error', message, duration),
        info: (message, duration = 5000) => push('info', message, duration),
        dismiss,
    };
});
