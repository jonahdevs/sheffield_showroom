<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Dices, Pencil, Plus, Search, Ticket, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
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
import { useFilters } from '@/composables/useFilters';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import { create, destroy, edit, index } from '@/routes/admin/purchases';
import {
    show as showShuffle,
    store as giveShuffle,
} from '@/routes/admin/shuffles';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    purchases: Paginated<App.Data.PurchaseRowData>;
    filters: { search: string; status: string };
    statuses: { value: string; label: string }[];
    page_sizes: number[];
    can: { create: boolean; update: boolean; shuffle: boolean };
}>();

const { filters, hasFilters, processing, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        status: props.filters.status === '' ? 'all' : props.filters.status,
    },
    blank: { search: '', status: 'all' },
    only: ['purchases', 'filters'],
});

/** Null for Completed: a badge every row wears is a badge nobody reads. */
function statusTone(status: App.Enums.PurchaseStatus): string | null {
    switch (status) {
        case 'pending':
            return 'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400';
        case 'cancelled':
            return 'border-transparent bg-destructive/10 text-destructive';
        default:
            return null;
    }
}

const giving = ref<number | null>(null);

/**
 * `ShuffleSessionController::store` answers an ineligible purchase with
 * `back()->withErrors(['shuffle' => ...])` rather than a toast, so the refusal
 * is held against the row that caused it and printed in that row's own cell.
 */
const refused = ref<{ id: number; message: string } | null>(null);

/**
 * `preserveScroll` is for the refusal, not the success: a successful mint
 * redirects away to the QR screen, while a refusal comes back as this page.
 */
function shuffle(purchase: App.Data.PurchaseRowData) {
    giving.value = purchase.id;
    refused.value = null;

    router.post(
        giveShuffle(purchase.id).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                refused.value = {
                    id: purchase.id,
                    message:
                        errors.shuffle ??
                        'This purchase cannot be given a shuffle.',
                };
            },
            onFinish: () => {
                giving.value = null;
            },
        },
    );
}

async function removePurchase(purchase: App.Data.PurchaseRowData) {
    if (!(await confirmDelete())) {
        return;
    }

    router.delete(destroy(purchase.id).url, { preserveScroll: true });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Purchases' },
        ],
    },
});
</script>

<template>
    <Head title="Purchases" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Purchases</h1>
                <p class="text-sm text-muted-foreground">
                    What was bought, and whether it has earned a shuffle.
                </p>
            </div>

            <Button v-if="props.can.create" as-child data-test="new-purchase">
                <Link :href="create().url">
                    <Plus />
                    New purchase
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
                        placeholder="Search reference or customer..."
                        aria-label="Search purchases"
                        data-test="purchase-search"
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
                    data-test="purchase-clear"
                    @click="clear"
                >
                    Clear
                </Button>

                <Select v-model="filters.status">
                    <SelectTrigger
                        class="w-52 sm:ml-auto"
                        aria-label="Filter by status"
                        data-test="purchase-status-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All purchases</SelectItem>
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

            <!-- Ahead of the empty branch on purpose: mid-reload this must not flash "no purchases". -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.purchases.per_page, 10)"
                :columns="6"
            />

            <div
                v-else-if="props.purchases.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="purchases-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No purchase matches that search.'
                            : 'No purchases have been recorded yet.'
                    }}
                </p>
                <Button
                    v-if="!hasFilters && props.can.create"
                    as-child
                    variant="outline"
                    size="sm"
                    class="mt-3.5"
                >
                    <Link :href="create().url">
                        <Plus />
                        Add the first one
                    </Link>
                </Button>
            </div>

            <template v-else>
                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[1000px] grid-cols-[minmax(0,1.2fr)_minmax(0,0.9fr)_minmax(0,0.6fr)_minmax(0,0.55fr)_minmax(0,0.8fr)_minmax(0,1.5fr)_84px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>Customer</span>
                        <span>Reference</span>
                        <span class="text-right">Amount</span>
                        <span>Status</span>
                        <span>Purchased</span>
                        <span>Reward</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="purchase in props.purchases.data"
                            :key="purchase.id"
                            class="grid min-w-[1000px] grid-cols-[minmax(0,1.2fr)_minmax(0,0.9fr)_minmax(0,0.6fr)_minmax(0,0.55fr)_minmax(0,0.8fr)_minmax(0,1.5fr)_84px] items-center gap-4 px-5 py-3"
                            :data-test="`purchase-${purchase.id}`"
                        >
                            <span
                                class="truncate text-xs font-bold"
                                :title="purchase.customer_name"
                            >
                                {{ purchase.customer_name }}
                            </span>

                            <div class="min-w-0">
                                <p
                                    class="truncate font-mono text-xs"
                                    :class="
                                        purchase.reference ? '' : 'text-faint'
                                    "
                                    :title="purchase.reference ?? undefined"
                                >
                                    {{ purchase.reference ?? '--' }}
                                </p>

                                <p
                                    v-if="purchase.product_names.length > 0"
                                    class="mt-0.5 truncate text-xs text-faint"
                                    :title="purchase.product_names.join(', ')"
                                    :data-test="`products-${purchase.id}`"
                                >
                                    {{ purchase.product_names.join(', ') }}
                                </p>
                            </div>

                            <span class="text-right text-xs tabular-nums">
                                {{ purchase.amount }}
                            </span>

                            <span>
                                <span
                                    v-if="statusTone(purchase.status)"
                                    class="w-fit shrink-0 rounded border px-1.5 py-0.5 text-[0.6875rem]"
                                    :class="statusTone(purchase.status)"
                                    :data-test="`status-${purchase.id}`"
                                >
                                    {{ purchase.status_label }}
                                </span>
                                <span v-else class="text-xs text-faint">
                                    {{ purchase.status_label }}
                                </span>
                            </span>

                            <span
                                class="truncate text-xs text-faint tabular-nums"
                            >
                                {{ purchase.purchased_on }}
                            </span>

                            <div class="min-w-0">
                                <Button
                                    v-if="purchase.shuffle_id"
                                    as-child
                                    variant="outline"
                                    size="sm"
                                    class="max-w-full"
                                    :data-test="`shuffle-${purchase.id}`"
                                >
                                    <Link
                                        :href="
                                            showShuffle(purchase.shuffle_id).url
                                        "
                                    >
                                        <Ticket />
                                        <span class="truncate">
                                            {{ purchase.shuffle_status }}
                                        </span>
                                    </Link>
                                </Button>

                                <Button
                                    v-else-if="
                                        purchase.refusal === null &&
                                        props.can.shuffle
                                    "
                                    variant="outline"
                                    size="sm"
                                    :disabled="giving === purchase.id"
                                    :data-test="`give-shuffle-${purchase.id}`"
                                    @click="shuffle(purchase)"
                                >
                                    <Dices />
                                    {{
                                        giving === purchase.id
                                            ? 'Giving...'
                                            : 'Give shuffle'
                                    }}
                                </Button>

                                <p
                                    v-else-if="purchase.refusal"
                                    class="text-xs text-muted-foreground"
                                    :data-test="`refusal-${purchase.id}`"
                                >
                                    {{ purchase.refusal }}
                                </p>

                                <!-- `v-if`, not part of the chain above: a row refused just now still shows its button. -->
                                <p
                                    v-if="refused?.id === purchase.id"
                                    class="mt-1.5 text-xs text-destructive"
                                    role="alert"
                                    :data-test="`shuffle-error-${purchase.id}`"
                                >
                                    {{ refused.message }}
                                </p>
                            </div>

                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="props.can.update"
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit purchase ${purchase.reference ?? purchase.id}`"
                                    :data-test="`edit-${purchase.id}`"
                                >
                                    <Link :href="edit(purchase.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <!-- Per row, not per page: a sale that has already earned a
                                     turn cannot be removed whatever the viewer holds - see
                                     `PurchasePolicy::delete`. -->
                                <Button
                                    v-if="purchase.can_delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Remove purchase ${purchase.reference ?? purchase.id}`"
                                    :data-test="`delete-${purchase.id}`"
                                    @click="removePurchase(purchase)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.purchases"
                    label="purchases"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.purchases.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>
</template>
