<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Gift,
    ImageOff,
    Pencil,
    Plus,
    Search,
    SearchX,
    Trash2,
} from '@lucide/vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
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
import { useFilters } from '@/composables/useFilters';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import { create, destroy, edit, index } from '@/routes/admin/rewards/catalogue';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    rewards: Paginated<App.Data.RewardData>;
    filters: { search: string; type: string; state: string };
    types: { value: string; label: string }[];
    page_sizes: number[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const { filters, hasFilters, processing, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        type: props.filters.type === '' ? 'all' : props.filters.type,
        state: props.filters.state === '' ? 'all' : props.filters.state,
    },
    blank: { search: '', type: 'all', state: 'all' },
    only: ['rewards', 'filters'],
});

/**
 * Retired rewards stay on this list on purpose: campaigns already attached to
 * one carry on handing it out, so hiding it would leave somebody hunting for a
 * reward a promotion is visibly giving away.
 */
async function removeReward(reward: App.Data.RewardData) {
    if (!(await confirmDelete())) {
        return;
    }

    router.delete(destroy(reward.id).url, { preserveScroll: true });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Catalogue' },
        ],
    },
});
</script>

<template>
    <Head title="Reward catalogue" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Reward catalogue</h1>
                <p class="text-sm text-muted-foreground">
                    What the showroom can give away, written once and reused. A
                    campaign decides only how many of one and for how long.
                </p>
            </div>

            <Button v-if="props.can.create" as-child data-test="new-reward">
                <Link :href="create().url">
                    <Plus />
                    New reward
                </Link>
            </Button>
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-80">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search rewards..."
                        aria-label="Search rewards"
                        data-test="reward-search"
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
                    data-test="reward-clear"
                    @click="clear"
                >
                    Clear
                </Button>

                <Select v-model="filters.type">
                    <SelectTrigger
                        class="w-52 sm:ml-auto"
                        aria-label="Filter by kind of reward"
                        data-test="reward-type-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All kinds</SelectItem>
                        <SelectItem
                            v-for="type in props.types"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="filters.state">
                    <SelectTrigger
                        class="w-44"
                        aria-label="Filter by whether the reward is still offered"
                        data-test="reward-state-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">Offered and retired</SelectItem>
                        <SelectItem value="active">Still offered</SelectItem>
                        <SelectItem value="retired">Retired</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Ahead of the empty branches on purpose: mid-reload this must not flash "no rewards". -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.rewards.per_page, 10)"
                :columns="7"
            />

            <!-- `flex-none` overrides `Empty`'s own `flex-1`: the Card around it is a
                 flex column with no height of its own, so filling the free space
                 would mean filling nothing. -->
            <Empty
                v-else-if="props.rewards.data.length === 0 && !hasFilters"
                class="flex-none"
                data-test="rewards-empty"
            >
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <Gift />
                    </EmptyMedia>
                    <EmptyTitle>The catalogue is empty</EmptyTitle>
                    <EmptyDescription>
                        Nothing has been written down yet, so a campaign has
                        nothing to hand out. Describe a reward once here and
                        every promotion from now on can offer it.
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button
                        v-if="props.can.create"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="create().url">
                            <Plus />
                            Write the first one
                        </Link>
                    </Button>
                </EmptyContent>
            </Empty>

            <Empty
                v-else-if="props.rewards.data.length === 0"
                class="flex-none"
                data-test="rewards-no-matches"
            >
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <SearchX />
                    </EmptyMedia>
                    <EmptyTitle>No reward matches that</EmptyTitle>
                    <EmptyDescription>
                        The catalogue has rewards in it, but none of them
                        answers what is being asked here. Retired rewards are
                        shown alongside the rest, so a narrowed state filter is
                        the usual culprit.
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="rewards-clear-filters"
                        @click="clear"
                    >
                        Clear the filters
                    </Button>
                </EmptyContent>
            </Empty>

            <template v-else>
                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[1080px] grid-cols-[minmax(0,1.5fr)_130px_110px_minmax(0,1fr)_96px_100px_88px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>Reward</span>
                        <span>Kind</span>
                        <span>Worth</span>
                        <span>Hands over</span>
                        <span class="text-right">Valid for</span>
                        <span>Held by</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="reward in props.rewards.data"
                            :key="reward.id"
                            class="grid min-w-[1080px] grid-cols-[minmax(0,1.5fr)_130px_110px_minmax(0,1fr)_96px_100px_88px] items-center gap-4 px-5 py-3.5"
                            :data-test="`reward-${reward.id}`"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p
                                        class="truncate text-xs font-bold"
                                        :class="
                                            reward.is_active
                                                ? ''
                                                : 'text-muted-foreground'
                                        "
                                        :title="reward.name"
                                    >
                                        {{ reward.name }}
                                    </p>

                                    <span
                                        v-if="!reward.is_active"
                                        class="shrink-0 rounded border border-transparent bg-amber-500/15 px-1.5 py-0.5 text-[0.6875rem] text-amber-700 dark:text-amber-400"
                                        :data-test="`retired-${reward.id}`"
                                    >
                                        Retired
                                    </span>
                                </div>

                                <p
                                    class="mt-0.5 truncate text-xs text-faint"
                                    :class="reward.description ? '' : 'italic'"
                                >
                                    {{
                                        reward.description ?? 'No description.'
                                    }}
                                </p>
                            </div>

                            <span
                                class="truncate text-xs text-muted-foreground"
                                :data-test="`type-${reward.id}`"
                            >
                                {{ reward.type_label }}
                            </span>

                            <span
                                class="truncate text-xs tabular-nums"
                                :class="reward.value_label ? '' : 'text-faint'"
                                :data-test="`worth-${reward.id}`"
                            >
                                {{ reward.value_label ?? '--' }}
                            </span>

                            <div
                                class="flex min-w-0 items-center gap-2"
                                :data-test="`product-${reward.id}`"
                            >
                                <template v-if="reward.product_name">
                                    <span
                                        class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                                        aria-hidden="true"
                                    >
                                        <img
                                            v-if="reward.product_image_url"
                                            :src="reward.product_image_url"
                                            alt=""
                                            loading="lazy"
                                            class="size-full object-contain"
                                        />
                                        <ImageOff
                                            v-else
                                            class="size-3.5 text-faint"
                                        />
                                    </span>
                                    <span
                                        class="truncate text-xs"
                                        :title="reward.product_name"
                                    >
                                        {{ reward.product_name }}
                                    </span>
                                </template>

                                <span v-else class="text-xs text-faint">
                                    --
                                </span>
                            </div>

                            <!-- What a campaign attaching this reward starts from, not a
                                 deadline anybody holds - the attachment carries its own copy. -->
                            <span
                                class="text-right text-xs tabular-nums"
                                :class="
                                    reward.default_validity_days === null
                                        ? 'text-faint'
                                        : ''
                                "
                                :data-test="`validity-${reward.id}`"
                            >
                                {{
                                    reward.default_validity_days === null
                                        ? 'No limit'
                                        : `${reward.default_validity_days} days`
                                }}
                            </span>

                            <span
                                class="truncate text-xs tabular-nums"
                                :class="
                                    reward.campaigns_count === 0
                                        ? 'text-faint'
                                        : 'text-muted-foreground'
                                "
                                :data-test="`campaigns-${reward.id}`"
                            >
                                {{
                                    reward.campaigns_count === 1
                                        ? '1 campaign'
                                        : `${reward.campaigns_count} campaigns`
                                }}
                            </span>

                            <div class="flex items-center justify-end gap-1">
                                <!-- Always a link, never hidden: reading needs only
                                     `rewards.view`, and the form itself disables its fields
                                     for somebody who may not write. -->
                                <Button
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`${props.can.update ? 'Edit' : 'View'} ${reward.name}`"
                                    :data-test="`edit-${reward.id}`"
                                >
                                    <Link :href="edit(reward.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <Button
                                    v-if="props.can.delete && reward.can_delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${reward.name}`"
                                    :data-test="`delete-${reward.id}`"
                                    @click="removeReward(reward)"
                                >
                                    <Trash2 />
                                </Button>

                                <!-- `RewardPolicy::delete` refuses a reward a campaign holds
                                     (`campaign_rewards.reward_id` is `restrictOnDelete`), so the
                                     row says so rather than offering a press that comes back refused. -->
                                <span
                                    v-else-if="props.can.delete"
                                    class="text-[0.6875rem] text-faint"
                                    title="A reward a campaign is handing out cannot be deleted. Retire it instead."
                                    :data-test="`undeletable-${reward.id}`"
                                >
                                    In use
                                </span>
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
