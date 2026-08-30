<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/composables/useInitials';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

const props = defineProps<{ user: User }>();

const page = usePage();

const { getInitials } = useInitials();

const hasAvatar = computed(
    () => Boolean(props.user.avatar) && props.user.avatar !== '',
);

/**
 * The role they hold, headlined. Null when they hold none, rather than a
 * placeholder - an account waiting to be given a role should look like one.
 */
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

<!--
  The identity block is tinted rather than ruled off, and the only separator
  sits above Sign Out - so the menu reads as "you, then what you can do, then
  the way out".
-->
<template>
    <DropdownMenuLabel
        class="mb-1 flex items-center gap-2.5 rounded-lg bg-brand-50 p-2.5 font-normal dark:bg-brand-950"
    >
        <Avatar class="size-9">
            <AvatarImage
                v-if="hasAvatar"
                :src="user.avatar!"
                :alt="user.name"
            />
            <AvatarFallback
                class="bg-secondary text-xs font-bold text-secondary-foreground"
            >
                {{ getInitials(user.name) }}
            </AvatarFallback>
        </Avatar>

        <div class="grid min-w-0 flex-1 text-left leading-tight">
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
            <Link class="w-full cursor-pointer" :href="edit()" prefetch>
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
