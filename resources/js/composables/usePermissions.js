import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const page = usePage();

    const permissions = computed(() => page.props.permissions ?? []);

    const can = (permission) => permissions.value.includes(permission);

    return { permissions, can };
}
