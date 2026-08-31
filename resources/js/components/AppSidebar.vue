<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ClipboardList,
    Gift,
    KeyRound,
    LayoutGrid,
    Package,
    Receipt,
    Shield,
    TicketCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
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
import { index as purchasesIndex } from '@/routes/admin/purchases';
import { index as rewardsIndex } from '@/routes/admin/rewards';
import { index as redeemIndex } from '@/routes/admin/rewards/redeem';
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
    /* What was bought, which is also what earns a reward shuffle - the list
       carries that question in a column rather than hiding it a screen away. */
    ...(can('purchases.view.any')
        ? [
              {
                  title: 'Purchases',
                  href: purchasesIndex(),
                  icon: Receipt,
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
                  /* The accounts are filed under `/admin/users` but reached
                     from here, so this row stays lit while one is open. A
                     literal path rather than a route function because there
                     is no users index to name - the list of people is a panel
                     on the Roles screen, not a page of its own. */
                  activeFor: ['/admin/users'],
              },
              {
                  title: 'Permissions',
                  href: permissionsIndex(),
                  icon: KeyRound,
              },
          ]
        : [],
);

/*
  The promotions rail. Its own group rather than a row under Administration,
  because a campaign is operational work - somebody runs it from the floor -
  and it owns two screens that are used at different desks: the campaigns
  themselves, and the counter where a reward is handed over.

  `rewards.view` opens both. Redeeming needs more, and the screen says so
  rather than the rail hiding a door somebody is allowed to look through.
*/
const rewardNavItems = computed<NavItem[]>(() =>
    can('rewards.view')
        ? [
              {
                  title: 'Campaigns',
                  href: rewardsIndex(),
                  icon: Gift,
                  /* The QR screen is filed under `/admin/shuffles`, but it is
                     reached from here, so this row stays lit while one is
                     open. */
                  activeFor: ['/admin/shuffles'],
              },
              {
                  title: 'Redeem',
                  href: redeemIndex(),
                  icon: TicketCheck,
              },
          ]
        : [],
);
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
                v-if="rewardNavItems.length > 0"
                :items="rewardNavItems"
                label="Rewards"
            />
            <NavMain
                v-if="adminNavItems.length > 0"
                :items="adminNavItems"
                label="Administration"
            />
        </SidebarContent>
    </Sidebar>
    <slot />
</template>
