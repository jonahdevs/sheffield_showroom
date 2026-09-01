<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Building2,
    CheckCheck,
    Gift,
    Hourglass,
    Search,
    TicketCheck,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import StatTile from '@/components/StatTile.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CHART_PALETTE } from '@/composables/useChartTheme';
import { useFilters } from '@/composables/useFilters';
import { rewardIcon } from '@/lib/rewardIcons';
import { dashboard } from '@/routes';
import { index as redeemIndex } from '@/routes/admin/rewards/redeem';
import { index } from '@/routes/admin/rewards/winners';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    rewards: Paginated<App.Data.RewardWinnerRowData>;
    filters: {
        search: string;
        campaign: string;
        type: string;
        status: string;
        range: string;
        from: string;
        to: string;
    };
    date_label: string;
    presets: { value: string; label: string }[];
    /** Null where the window has no length, which is what turns the tiles' caption off. */
    window_days: number | null;
    stats: App.Data.DashboardStatData[];
    /** Only campaigns that have actually handed something out. */
    campaigns: { value: string; label: string }[];
    types: { value: string; label: string }[];
    statuses: { value: string; label: string }[];
    page_sizes: number[];
}>();

/*
  `all` rather than a blank string for the three selects: a shadcn `SelectItem`
  cannot carry an empty value, so `blank` maps it back to the empty string the
  server reads as "no filter".
*/
const { filters, hasFilters, processing, apply, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        campaign:
            props.filters.campaign === '' ? 'all' : props.filters.campaign,
        type: props.filters.type === '' ? 'all' : props.filters.type,
        status: props.filters.status === '' ? 'all' : props.filters.status,
        range: props.filters.range ?? '',
        from: props.filters.range === '' ? (props.filters.from ?? '') : '',
        to: props.filters.range === '' ? (props.filters.to ?? '') : '',
    },
    blank: {
        search: '',
        campaign: 'all',
        type: 'all',
        status: 'all',
        range: '',
        from: '',
        to: '',
    },
    only: ['rewards', 'filters', 'date_label', 'window_days', 'stats'],
});

/** Each spelling of the window clears the other, so a URL never carries both `range` and `from`/`to`. */
function chooseWindow(from: string, to: string): void {
    apply({ range: '', from, to });
}

function choosePreset(range: string): void {
    apply({ range, from: '', to: '' });
}

const windowLabel = computed(
    () =>
        props.presets.find((preset) => preset.value === props.filters.range)
            ?.label ?? props.date_label,
);

const comparison = computed(() => {
    if (props.window_days === null) {
        return '';
    }

    return props.window_days === 1
        ? 'vs the day before'
        : `vs previous ${props.window_days} days`;
});

const TILES: Record<string, { icon: Component; colour: string }> = {
    won: { icon: Gift, colour: CHART_PALETTE[0] },
    redeemed: { icon: CheckCheck, colour: CHART_PALETTE[1] },
    outstanding: { icon: Hourglass, colour: CHART_PALETTE[2] },
};

function tile(key: string) {
    return TILES[key] ?? { icon: Gift, colour: CHART_PALETTE[8] };
}

function statusTone(status: string): string {
    switch (status) {
        case 'redeemed':
            return 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400';
        case 'unredeemed':
            return 'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400';
        case 'expired':
            return 'border-transparent bg-muted text-faint';
        case 'cancelled':
            return 'border-transparent bg-destructive/10 text-destructive';
        default:
            return 'border-transparent bg-muted text-muted-foreground';
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Rewards' },
        ],
    },
});
</script>

<!-- Read-only by design: handing a reward over is Redeem's job and a different permission. -->
<template>
    <Head title="Rewards" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Rewards</h1>
                <p class="text-sm text-muted-foreground">
                    Everything won so far, and whether it has been collected.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <DateRangePicker
                    :from="props.filters.from"
                    :to="props.filters.to"
                    :label="windowLabel"
                    :presets="props.presets"
                    :active="props.filters.range"
                    data-test="winner-date-range"
                    @update="chooseWindow"
                    @preset="choosePreset"
                />

                <Button as-child variant="outline" data-test="go-redeem">
                    <Link :href="redeemIndex().url">
                        <TicketCheck />
                        Redeem a code
                    </Link>
                </Button>
            </div>
        </div>

        <div
            class="grid gap-4 transition-opacity @2xl/page:grid-cols-3"
            :class="processing ? 'opacity-60' : ''"
            :aria-busy="processing"
        >
            <StatTile
                v-for="stat in props.stats"
                :key="stat.key"
                :stat="stat"
                :icon="tile(stat.key).icon"
                :colour="tile(stat.key).colour"
                :comparison="comparison"
            />
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-72">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search customer or code..."
                        aria-label="Search rewards"
                        data-test="winner-search"
                    />

                    <InputGroupAddon>
                        <Search />
                    </InputGroupAddon>
                </InputGroup>

                <Button
                    v-if="hasFilters"
                    variant="link"
                    size="sm"
                    class="px-1"
                    data-test="winner-clear"
                    @click="clear"
                >
                    Clear
                </Button>

                <Select
                    v-if="props.campaigns.length > 1"
                    v-model="filters.campaign"
                >
                    <SelectTrigger
                        class="w-44 sm:ml-auto"
                        aria-label="Filter by campaign"
                        data-test="winner-campaign-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All campaigns</SelectItem>
                        <SelectItem
                            v-for="campaign in props.campaigns"
                            :key="campaign.value"
                            :value="campaign.value"
                        >
                            {{ campaign.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="filters.type">
                    <SelectTrigger
                        class="w-44"
                        :class="props.campaigns.length > 1 ? '' : 'sm:ml-auto'"
                        aria-label="Filter by reward"
                        data-test="winner-type-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All rewards</SelectItem>
                        <SelectItem
                            v-for="type in props.types"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="filters.status">
                    <SelectTrigger
                        class="w-40"
                        aria-label="Filter by status"
                        data-test="winner-status-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">Any status</SelectItem>
                        <SelectItem
                            v-for="status in props.statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload this must not flash "nothing won". -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.rewards.per_page, 10)"
                :columns="7"
            />

            <div
                v-else-if="props.rewards.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="winners-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No reward matches those filters.'
                            : 'Nobody has won a reward yet.'
                    }}
                </p>
            </div>

            <template v-else>
                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[1180px] grid-cols-[minmax(0,1.15fr)_minmax(0,1.3fr)_minmax(0,0.8fr)_minmax(0,0.9fr)_minmax(0,0.65fr)_minmax(0,0.65fr)_minmax(0,0.8fr)] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>Customer</span>
                        <span>Reward</span>
                        <span>Code</span>
                        <span>Campaign</span>
                        <span>Won</span>
                        <span>Expires</span>
                        <span>Status</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="reward in props.rewards.data"
                            :key="reward.id"
                            class="grid min-w-[1180px] grid-cols-[minmax(0,1.15fr)_minmax(0,1.3fr)_minmax(0,0.8fr)_minmax(0,0.9fr)_minmax(0,0.65fr)_minmax(0,0.65fr)_minmax(0,0.8fr)] items-center gap-4 px-5 py-3.5"
                            :data-test="`reward-${reward.id}`"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-faint"
                                    aria-hidden="true"
                                >
                                    <Building2
                                        v-if="
                                            reward.customer_type === 'company'
                                        "
                                        class="size-4"
                                    />
                                    <UserRound v-else class="size-4" />
                                </span>

                                <div class="min-w-0">
                                    <p class="truncate text-xs font-bold">
                                        {{ reward.customer_name }}
                                    </p>
                                    <p
                                        v-if="
                                            reward.customer_company ||
                                            reward.customer_phone
                                        "
                                        class="mt-0.5 truncate text-xs text-faint"
                                        :class="
                                            reward.customer_company
                                                ? ''
                                                : 'tabular-nums'
                                        "
                                    >
                                        {{
                                            reward.customer_company ??
                                            reward.customer_phone
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex min-w-0 items-center gap-2.5">
                                <component
                                    :is="rewardIcon(reward.type)"
                                    class="size-4 shrink-0 text-faint"
                                    aria-hidden="true"
                                />

                                <div class="min-w-0">
                                    <p class="truncate text-xs">
                                        {{ reward.reward_name }}
                                    </p>
                                    <!-- Suppressed when the name already contains the value ("10% discount"). -->
                                    <p
                                        v-if="
                                            reward.value &&
                                            !reward.reward_name.includes(
                                                reward.value,
                                            )
                                        "
                                        class="mt-0.5 truncate text-xs text-faint tabular-nums"
                                    >
                                        {{ reward.value }}
                                    </p>

                                    <p
                                        v-if="
                                            reward.qualifying_products.length >
                                            0
                                        "
                                        class="mt-0.5 truncate text-xs text-muted-foreground"
                                        :title="`Paired to ${reward.qualifying_products.join(', ')}`"
                                        :data-test="`qualified-${reward.id}`"
                                    >
                                        Because they bought
                                        {{
                                            reward.qualifying_products.join(
                                                ', ',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>

                            <span
                                class="truncate font-mono text-xs tracking-wide"
                                :data-test="`code-${reward.id}`"
                            >
                                {{ reward.code }}
                            </span>

                            <span
                                class="truncate text-xs text-faint"
                                :title="reward.campaign_name"
                            >
                                {{ reward.campaign_name }}
                            </span>

                            <div class="min-w-0">
                                <p class="truncate text-xs tabular-nums">
                                    {{ reward.won_on }}
                                </p>

                                <p
                                    v-if="reward.purchase_reference"
                                    class="mt-0.5 truncate font-mono text-[0.6875rem] text-faint"
                                    :title="
                                        reward.purchased_on
                                            ? `Purchased ${reward.purchased_on}`
                                            : undefined
                                    "
                                    :data-test="`purchase-${reward.id}`"
                                >
                                    {{ reward.purchase_reference }}
                                </p>
                            </div>

                            <span
                                class="truncate text-xs tabular-nums"
                                :class="reward.expires_on ? '' : 'text-faint'"
                            >
                                {{ reward.expires_on ?? 'No expiry' }}
                            </span>

                            <div class="min-w-0">
                                <span
                                    class="w-fit rounded border px-1.5 py-0.5 text-[0.6875rem]"
                                    :class="statusTone(reward.status)"
                                    :data-test="`status-${reward.id}`"
                                >
                                    {{ reward.status_label }}
                                </span>

                                <p
                                    v-if="reward.redeemed_on"
                                    class="mt-1 truncate text-[0.6875rem] text-faint"
                                >
                                    {{ reward.redeemed_on
                                    }}{{
                                        reward.redeemed_by
                                            ? ` by ${reward.redeemed_by}`
                                            : ''
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.rewards"
                    label="rewards"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.rewards.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>
</template>
