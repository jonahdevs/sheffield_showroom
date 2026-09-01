<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';

const page = usePage();
const user = computed(() => page.props.auth.user);

const { getInitials } = useInitials();
</script>

<template>
    <header
        class="sticky top-0 z-5 flex shrink-0 items-center gap-3.5 border-b border-border bg-background px-4.5 py-3 lg:px-7 lg:py-3.5"
    >
        <SidebarTrigger class="-ml-1" />

        <div class="ml-auto flex items-center gap-4">
            <DropdownMenu>
                <DropdownMenuTrigger
                    class="flex cursor-pointer items-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    data-test="sidebar-menu-button"
                    :aria-label="`Account menu for ${user.name}`"
                >
                    <Avatar class="size-9">
                        <AvatarFallback
                            class="bg-secondary text-xs font-bold text-secondary-foreground"
                        >
                            {{ getInitials(user.name) }}
                        </AvatarFallback>
                    </Avatar>
                </DropdownMenuTrigger>

                <DropdownMenuContent class="w-60" align="end" :side-offset="12">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
