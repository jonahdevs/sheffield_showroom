<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';
import { edit as settings } from '@/routes/profile';
import type { User } from '@/types';

defineProps<{ user: User }>();

const page = usePage();

/** The role they hold, or null - an account with no role shows nothing here. */
const roleLabel = computed(() => {
    const roles = page.props.auth.roles ?? [];

    if (roles.length === 0) {
        return null;
    }

    return roles[0]
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
});

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <DropdownMenuLabel
        class="mb-1 rounded-lg bg-brand-50 p-2.5 font-normal dark:bg-brand-950"
    >
        <div class="grid min-w-0 text-left leading-tight">
            <span class="truncate text-xs font-bold">{{ user.name }}</span>
            <span class="truncate text-xs text-muted-foreground">
                {{ user.email }}
            </span>
            <span
                v-if="roleLabel"
                class="truncate text-xs font-semibold text-primary"
                data-test="account-role"
            >
                {{ roleLabel }}
            </span>
        </div>
    </DropdownMenuLabel>

    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="w-full cursor-pointer" :href="settings()" prefetch>
                <Settings class="size-3.5" :stroke-width="1.7" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>

    <DropdownMenuSeparator />

    <DropdownMenuItem :as-child="true" variant="destructive">
        <Link
            class="w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="size-3.5" :stroke-width="1.7" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
