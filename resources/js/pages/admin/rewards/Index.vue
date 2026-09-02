<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Gift, Pencil, Plus, Trash2 } from '@lucide/vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import { create, destroy, edit } from '@/routes/admin/rewards';

interface Paginated<T> extends Paginator {
    data: T[];
}

/* `index` passes `withRewards: false`: every row's `rewards` array is empty
   here and only the totals counted off the pool arrive. */
const props = defineProps<{
    campaigns: Paginated<App.Data.RewardCampaignData>;
    page_sizes: number[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

function statusTone(status: string): string {
    switch (status) {
        case 'draft':
            return 'border-transparent bg-muted text-faint';
        case 'scheduled':
            return 'border-transparent bg-sky-500/15 text-sky-700 dark:text-sky-400';
        case 'active':
            return 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400';
        case 'paused':
            return 'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400';
        case 'cancelled':
            return 'border-transparent bg-destructive/10 text-destructive';
        default:
            return 'border-transparent bg-muted text-muted-foreground';
    }
}

const dayFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

/**
 * The space is swapped for a `T` before parsing: engines that guess at
 * `Y-m-d H:i:s` treat it as UTC, sliding an evening campaign onto the next day.
 */
function readableDay(value: string | null): string | null {
    if (value === null) {
        return null;
    }

    const parsed = new Date(value.replace(' ', 'T'));

    return Number.isNaN(parsed.getTime()) ? null : dayFormatter.format(parsed);
}

function readableRun(campaign: App.Data.RewardCampaignData): string {
    const from = readableDay(campaign.starts_at);
    const to = readableDay(campaign.ends_at);

    if (from === null && to === null) {
        return 'No dates set';
    }

    if (from === null) {
        return `Until ${to}`;
    }

    if (to === null) {
        return `From ${from}`;
    }

    return `${from} to ${to}`;
}

/**
 * `is_running` is the model's three-part answer and `status` only the first
 * part, so the two disagree once the calendar passes a campaign nobody closed.
 */
function dormantReason(campaign: App.Data.RewardCampaignData): string | null {
    if (campaign.status !== 'active' || campaign.is_running) {
        return null;
    }

    const now = new Date();

    if (
        campaign.starts_at !== null &&
        now < new Date(campaign.starts_at.replace(' ', 'T'))
    ) {
        return 'Not started yet';
    }

    return 'Past its end date';
}

/* The row's `campaign.turns_given === 0` guard mirrors
   `RewardCampaignPolicy::delete`, which refuses a campaign that has been
   shuffled - the pool alone is inventory, and inventory is disposable. */
async function removeCampaign(campaign: App.Data.RewardCampaignData) {
    if (!(await confirmDelete())) {
        return;
    }

    router.delete(destroy(campaign.id).url, { preserveScroll: true });
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

<template>
    <Head title="Reward campaigns" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Reward campaigns</h1>
                <p class="text-sm text-muted-foreground">
                    What the showroom is giving away, and how much of it is
                    left. Only one campaign runs at a time.
                </p>
            </div>

            <Button v-if="props.can.create" as-child data-test="new-campaign">
                <Link :href="create().url">
                    <Plus />
                    New campaign
                </Link>
            </Button>
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                v-if="props.campaigns.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="campaigns-empty"
            >
                <Gift class="mx-auto size-7 text-faint" aria-hidden="true" />
                <p class="mt-3 text-sm text-muted-foreground">
                    No campaign has been set up yet.
                </p>
                <Button
                    v-if="props.can.create"
                    as-child
                    variant="outline"
                    size="sm"
                    class="mt-3.5"
                >
                    <Link :href="create().url">
                        <Plus />
                        Set up the first one
                    </Link>
                </Button>
            </div>

            <template v-else>
                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[940px] grid-cols-[minmax(0,1.5fr)_120px_minmax(0,180px)_minmax(0,1.3fr)_80px_88px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>Campaign</span>
                        <span>Status</span>
                        <span>Runs</span>
                        <span>Drawer</span>
                        <span class="text-right">Turns</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-border">
                        <div
                            v-for="campaign in props.campaigns.data"
                            :key="campaign.id"
                            class="grid min-w-[940px] grid-cols-[minmax(0,1.5fr)_120px_minmax(0,180px)_minmax(0,1.3fr)_80px_88px] items-center gap-4 px-5 py-3.5"
                            :data-test="`campaign-${campaign.id}`"
                        >
                            <div class="min-w-0">
                                <p
                                    class="truncate text-xs font-bold"
                                    :title="campaign.name"
                                >
                                    {{ campaign.name }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-xs text-faint"
                                    :class="
                                        campaign.description ? '' : 'italic'
                                    "
                                >
                                    {{
                                        campaign.description ??
                                        'No description.'
                                    }}
                                </p>
                            </div>

                            <div class="min-w-0">
                                <span
                                    class="w-fit rounded border px-1.5 py-0.5 text-[0.6875rem]"
                                    :class="statusTone(campaign.status)"
                                    :data-test="`status-${campaign.id}`"
                                >
                                    {{ campaign.status_label }}
                                </span>

                                <p
                                    v-if="dormantReason(campaign)"
                                    class="mt-1 text-[0.6875rem] text-amber-700 dark:text-amber-400"
                                    :data-test="`dormant-${campaign.id}`"
                                >
                                    {{ dormantReason(campaign) }}
                                </p>
                            </div>

                            <p
                                class="truncate text-xs text-muted-foreground tabular-nums"
                                :data-test="`runs-${campaign.id}`"
                            >
                                {{ readableRun(campaign) }}
                            </p>

                            <!-- A draft has no pool yet, so all four read zero:
                                 quantities are a promise until publication. -->
                            <div
                                class="min-w-0"
                                :data-test="`drawer-${campaign.id}`"
                            >
                                <p class="text-xs font-bold tabular-nums">
                                    {{ campaign.loaded }} loaded
                                </p>
                                <p
                                    class="mt-0.5 truncate text-xs text-faint tabular-nums"
                                >
                                    {{ campaign.available }} available
                                    <span aria-hidden="true">&bull;</span>
                                    {{ campaign.claimed }} claimed
                                    <span aria-hidden="true">&bull;</span>
                                    {{ campaign.void }} void
                                </p>
                            </div>

                            <p
                                class="text-right text-xs tabular-nums"
                                :title="`${campaign.turns_given} turns have been handed out, at most ${campaign.max_shuffles_per_customer} per customer`"
                                :data-test="`turns-${campaign.id}`"
                            >
                                {{ campaign.turns_given }}
                            </p>

                            <div class="flex items-center justify-end gap-1">
                                <!-- Never gated on `can.update`: reading a
                                     campaign needs only `rewards.view`, and the
                                     form locks its own fields. -->
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`${props.can.update ? 'Edit' : 'View'} ${campaign.name}`"
                                    :data-test="`edit-${campaign.id}`"
                                >
                                    <Link :href="edit(campaign.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <Button
                                    v-if="
                                        props.can.delete &&
                                        campaign.turns_given === 0
                                    "
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${campaign.name}`"
                                    :data-test="`delete-${campaign.id}`"
                                    @click="removeCampaign(campaign)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.campaigns"
                    label="campaigns"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.campaigns.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>
</template>
