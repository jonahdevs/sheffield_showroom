<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    ClipboardList,
    FolderGit2,
    KeyRound,
    LayoutGrid,
    Package,
    Shield,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import { index as customersIndex } from '@/routes/admin/customers';
import { index as permissionsIndex } from '@/routes/admin/permissions';
import { index as productsIndex } from '@/routes/admin/products';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as visitsIndex } from '@/routes/admin/visits';
import type { NavItem } from '@/types';

const { can, canAny } = usePermissions();

/*
  The operational rail. Each row past the dashboard is behind the permission
  its own screen checks, so somebody who cannot open it is not offered a door
  the route would refuse anyway.
*/
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    /* Either half of the visits split opens the list - a salesperson sees
       their own, a manager sees the floor. */
    ...(canAny('visits.view.any', 'visits.view.own')
        ? [
              {
                  title: 'Visits',
                  href: visitsIndex(),
                  icon: ClipboardList,
              },
          ]
        : []),
    ...(can('customers.view.any')
        ? [
              {
                  title: 'Customers',
                  href: customersIndex(),
                  icon: Users,
              },
          ]
        : []),
    ...(can('products.view.any')
        ? [
              {
                  title: 'Products',
                  href: productsIndex(),
                  icon: Package,
              },
          ]
        : []),
]);

/*
  Both screens sit behind `roles.view`, so the whole section drops away for
  somebody who cannot open either. The routes refuse them regardless; this is
  so the sidebar does not advertise a door that is locked.
*/
const adminNavItems = computed<NavItem[]>(() =>
    can('roles.view')
        ? [
              {
                  title: 'Roles',
                  href: rolesIndex(),
                  icon: Shield,
              },
              {
                  title: 'Permissions',
                  href: permissionsIndex(),
                  icon: KeyRound,
              },
          ]
        : [],
);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length > 0"
                :items="adminNavItems"
                label="Administration"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
