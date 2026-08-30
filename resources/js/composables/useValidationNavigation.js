import { nextTick } from 'vue';

const safeFieldId = (key) => `field-${key.replace(/[^a-zA-Z0-9_-]/g, '-')}`;

export function useValidationNavigation(form, activeSection, sectionForError, formElement = null) {
    const hasError = (key) => Boolean(form.errors[key]);
    const errorMessage = (key) => form.errors[key] ?? '';
    const fieldId = (key) => safeFieldId(key);
    const errorId = (key) => `${safeFieldId(key)}-error`;

    const fieldAttrs = (key) => ({
        id: fieldId(key),
        name: key,
        'data-validation-key': key,
        'aria-invalid': hasError(key) ? 'true' : undefined,
        'aria-describedby': hasError(key) ? errorId(key) : undefined,
    });

    const invalidClass = (key) => hasError(key)
        ? '!border-red-400 focus:!border-red-500 focus:!ring-red-100 dark:!border-red-500 dark:focus:!ring-red-950'
        : '';

    const focusError = async (key) => {
        if (!key) return;

        const section = sectionForError?.(key);
        if (section) activeSection.value = section;

        await nextTick();

        requestAnimationFrame(() => {
            const root = formElement?.value ?? document;
            const target = root.querySelector?.(`[data-validation-key="${key}"]`);
            const fallback = root.querySelector?.('[data-validation-summary]');
            const element = target ?? fallback;

            element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            element?.focus?.({ preventScroll: true });
        });
    };

    const focusFirstError = (errors = form.errors) => {
        const keys = Object.keys(errors ?? {});
        const firstField = keys.find((key) => !['assessment', 'anesthesia'].includes(key)) ?? keys[0];
        return focusError(firstField);
    };

    return {
        errorId,
        errorMessage,
        fieldAttrs,
        focusError,
        focusFirstError,
        hasError,
        invalidClass,
    };
}
