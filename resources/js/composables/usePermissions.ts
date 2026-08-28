import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * What the signed-in user may do, as the server computed it.
 *
 * For hiding controls only. Every one of these is checked again on the way in,
 * so a stale list costs a wrong-looking menu and never an unauthorised write.
 */
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
