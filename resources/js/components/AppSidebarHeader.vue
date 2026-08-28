<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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

const hasAvatar = computed(
    () => Boolean(user.value?.avatar) && user.value?.avatar !== '',
);
</script>

<!--
  The topbar: the rail's collapse trigger on the left, the account pushed
  right, over a single 1px rule. No fixed height - the padding is what sets it,
  so the avatar keeps its clearance.

  The crumb trail used to live here and now sits on the page surface below,
  where it has the page's width rather than whatever the account block leaves.
-->
<template>
    <header
        class="sticky top-0 z-5 flex shrink-0 items-center gap-3.5 border-b border-border bg-background px-4.5 py-3 lg:px-7 lg:py-3.5"
    >
        <SidebarTrigger class="-ml-1" />

        <div class="ml-auto flex items-center gap-4">
            <DropdownMenu>
                <DropdownMenuTrigger
                    class="flex cursor-pointer items-center outline-none"
                    data-test="sidebar-menu-button"
                    :aria-label="`Account menu for ${user.name}`"
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
                </DropdownMenuTrigger>

                <DropdownMenuContent class="w-60" align="end" :side-offset="12">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
