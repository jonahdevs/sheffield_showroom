import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/* For hiding controls only: every one of these is checked again server-side,
   so a stale list costs a wrong-looking menu, never an unauthorised write. */
export function usePermissions() {
    const page = usePage();

    const permissions = computed<string[]>(
        () => page.props.auth?.permissions ?? [],
    );

    function can(permission: App.Enums.Permission): boolean {
        return permissions.value.includes(permission);
    }

    function canAny(...wanted: App.Enums.Permission[]): boolean {
        return wanted.some(can);
    }

    return { permissions, can, canAny };
}
