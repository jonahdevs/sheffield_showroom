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

/*
 * `RewardCampaignController::index` passes `withRewards: false`, so every row
 * here carries an empty `rewards` array rather than the drawer's contents -
 * only the totals counted off the pool, which is all a list needs.
 */
const props = defineProps<{
    campaigns: Paginated<App.Data.RewardCampaignData>;
    page_sizes: number[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

/**
 * Every state gets a colour here, unlike the products grid where the ordinary
 * case goes unsaid. There is no ordinary case for a campaign: a reader
 * scanning this list is asking which one is running and which one is still
 * being written, and that question is the whole reason to open the page.
 */
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
 * The column stores a moment and the list shows a day.
 *
 * The clock is dropped on purpose: a campaign that opens at nine in the
 * morning and one that opens at midnight are the same campaign to somebody
 * scanning for which promotion covers March, and two timestamps per row would
 * crowd out the drawer figures that are the point of the table. The form
 * along shows the hour, which is where it matters.
 *
 * The space is swapped for a `T` before parsing: `Y-m-d H:i:s` is not a
 * format every engine reads, and the ones that guess treat it as UTC, which
 * would slide an evening campaign onto the following day.
 */
function readableDay(value: string | null): string | null {
    if (value === null) {
        return null;
    }

    const parsed = new Date(value.replace(' ', 'T'));

    return Number.isNaN(parsed.getTime()) ? null : dayFormatter.format(parsed);
}

/** When the promotion runs, in the shape the dates it was given allow. */
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
 * Why a campaign marked active is not actually handing anything out.
 *
 * `is_running` is the model's three-part answer and the status is only the
 * first part of it, so the two disagree whenever the calendar has moved past
 * a campaign nobody has got round to completing. A row that said "Active" and
 * nothing else would be lying to the person wondering why the showroom's
 * tablet refuses to shuffle.
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

/**
 * Only ever a draft: `RewardCampaignPolicy::delete` refuses a published
 * campaign, because a pool that has been written is history. The row asks the
 * same question the policy would answer, so the button is never offered where
 * pressing it would come back refused.
 */
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

<!--
  Every promotion the showroom has run, newest first.

  A table rather than the tile grid the catalogue uses: nothing here is
  recognised by sight, and the four drawer figures beside each name are the
  reason to look. `loaded = available + claimed + void` always reconciles, so
  a row that does not add up is a reporting fault worth seeing rather than a
  rounding.
-->
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
                    variant="quiet"
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
                    <!-- Semibold rather than bold: the campaign name in every
                         row below is itself text-xs font-bold, so a bold
                         header would read as one more row instead of the
                         label for all of them. -->
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

                            <!-- A draft has no pool yet, so all four read
                                 zero. That is the honest answer rather than a
                                 gap: the quantities on the form are still a
                                 promise until publication writes them. -->
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
                                <!-- Always a link, never hidden: reading a
                                     campaign needs only `rewards.view`, and
                                     the form itself is what locks its fields
                                     for somebody who may not write. The label
                                     says which of the two they are getting. -->
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
                                        !campaign.is_published
                                    "
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete the ${campaign.name} draft`"
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
