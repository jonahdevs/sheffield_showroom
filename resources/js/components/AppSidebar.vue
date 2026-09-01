<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ClipboardList,
    Gauge,
    Gift,
    KeyRound,
    LayoutGrid,
    Megaphone,
    Package,
    Receipt,
    Shield,
    TicketCheck,
    Trophy,
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
import { index as catalogueIndex } from '@/routes/admin/rewards/catalogue';
import { index as overviewIndex } from '@/routes/admin/rewards/overview';
import { index as redeemIndex } from '@/routes/admin/rewards/redeem';
import { index as winnersIndex } from '@/routes/admin/rewards/winners';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as visitsIndex } from '@/routes/admin/visits';
import type { NavItem } from '@/types';

const { can, canAny } = usePermissions();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
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

const adminNavItems = computed<NavItem[]>(() =>
    can('roles.view')
        ? [
              {
                  title: 'Roles',
                  href: rolesIndex(),
                  icon: Shield,
                  /* Accounts are filed under `/admin/users` but reached from
                     here. A literal path because there is no users index to
                     name - the list of people is a panel on this screen. */
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
  The row labels do not match their route names, and the mismatch is deliberate:
  "Rewards" is the catalogue, "Redeemed" is the searchable record of what has
  been won (`winners`), and "Redeem" is the counter, reachable only by the code
  off a customer's phone. Renaming any of the three to match its route puts two
  rows under one name.
*/
const rewardNavItems = computed<NavItem[]>(() =>
    can('rewards.view')
        ? [
              {
                  title: 'Overview',
                  href: overviewIndex(),
                  icon: Gauge,
              },
              {
                  title: 'Campaigns',
                  href: rewardsIndex(),
                  icon: Megaphone,
                  /* The QR screen is filed under `/admin/shuffles` but reached
                     from here, so this row stays lit while one is open. */
                  activeFor: ['/admin/shuffles'],
              },
              {
                  title: 'Rewards',
                  href: catalogueIndex(),
                  icon: Gift,
              },
              {
                  title: 'Redeemed',
                  href: winnersIndex(),
                  icon: Trophy,
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
                label="Promotions"
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
