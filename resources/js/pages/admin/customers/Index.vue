<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import { create, destroy, edit, index } from '@/routes/admin/customers';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    customers: Paginated<App.Data.CustomerRowData>;
    filters: { search: string; type: string };
    types: { value: string; label: string }[];
    page_sizes: number[];
    counts: { all: number; individual: number; company: number };
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const { filters, hasFilters, processing, apply, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        type: props.filters.type === '' ? 'all' : props.filters.type,
    },
    blank: { search: '', type: 'all' },
    only: ['customers', 'filters'],
});

/**
 * The type filter is a tab strip rather than a select: there are only three
 * choices and each one is worth a count.
 */
const tabs = computed(() => [
    { value: 'all', label: 'All', count: props.counts.all },
    ...props.types.map((type) => ({
        value: type.value,
        label: type.label === 'Individual' ? 'Individuals' : 'Companies',
        count:
            type.value === 'individual'
                ? props.counts.individual
                : props.counts.company,
    })),
]);

const deleting = ref<App.Data.CustomerRowData | null>(null);

function confirmDelete() {
    const customer = deleting.value;

    if (customer === null) {
        return;
    }

    router.delete(destroy(customer.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Customers' },
        ],
    },
});
</script>

<!--
  Everyone who has walked into the showroom, individuals and companies in one
  list. They share a table because a salesperson looking somebody up knows the
  name or the number, not which kind of record it was filed under.
-->
<template>
    <Head title="Customers" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Customers</h1>
                <p class="text-sm text-muted-foreground">
                    The people and companies who visit the showroom.
                </p>
            </div>

            <Button v-if="props.can.create" as-child data-test="new-customer">
                <Link :href="create().url">
                    <Plus />
                    New customer
                </Link>
            </Button>
        </div>

        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center gap-1 border-b border-border px-5 pt-3.5"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="-mb-px flex items-center gap-2 border-b-2 px-3 pb-3 text-xs font-bold transition-colors"
                    :class="
                        filters.type === tab.value
                            ? 'border-primary text-primary'
                            : 'border-transparent text-faint hover:text-foreground'
                    "
                    :data-test="`tab-${tab.value}`"
                    @click="apply({ type: tab.value })"
                >
                    {{ tab.label }}
                    <span class="text-faint tabular-nums">{{ tab.count }}</span>
                </button>
            </div>

            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-80">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search name, company, phone or email..."
                        aria-label="Search customers"
                        data-test="customer-search"
                    />

                    <InputGroupAddon>
                        <Search />
                    </InputGroupAddon>
                </InputGroup>

                <Button
                    v-if="hasFilters"
                    variant="link"
                    size="sm"
                    class="px-1 sm:ml-auto"
                    data-test="customer-clear"
                    @click="clear"
                >
                    Clear
                </Button>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload nobody knows
                 what is coming back, and flashing "no customers yet" over rows
                 that are about to land reads as broken. -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.customers.per_page, 10)"
                :columns="5"
            />

            <div
                v-else-if="props.customers.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="customers-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No customer matches that search.'
                            : 'No customers have been recorded yet.'
                    }}
                </p>
                <Button
                    v-if="!hasFilters && props.can.create"
                    as-child
                    variant="quiet"
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
                        class="grid min-w-[1000px] grid-cols-[minmax(0,1.2fr)_130px_minmax(0,1.1fr)_minmax(0,1fr)_100px_84px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        <span>Customer</span>
                        <span>Phone</span>
                        <span>Email</span>
                        <span>Location</span>
                        <span>Added</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="customer in props.customers.data"
                            :key="customer.id"
                            class="grid min-w-[1000px] grid-cols-[minmax(0,1.2fr)_130px_minmax(0,1.1fr)_minmax(0,1fr)_100px_84px] items-center gap-4 px-5 py-3.5"
                            :data-test="`customer-${customer.id}`"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-faint"
                                    aria-hidden="true"
                                >
                                    <Building2
                                        v-if="customer.type === 'company'"
                                        class="size-4"
                                    />
                                    <UserRound v-else class="size-4" />
                                </span>

                                <div class="min-w-0">
                                    <p class="truncate text-xs font-bold">
                                        {{ customer.display_name }}
                                    </p>
                                    <p
                                        v-if="customer.subtitle"
                                        class="mt-0.5 truncate text-xs text-faint"
                                    >
                                        {{ customer.subtitle }}
                                    </p>
                                </div>
                            </div>

                            <span class="truncate text-xs tabular-nums">
                                {{ customer.phone }}
                            </span>

                            <span
                                class="truncate text-xs"
                                :class="customer.email ? '' : 'text-faint'"
                                :title="customer.email ?? undefined"
                            >
                                {{ customer.email ?? '--' }}
                            </span>

                            <span class="truncate text-xs text-faint">
                                {{ customer.location }}
                            </span>

                            <span class="text-xs text-faint tabular-nums">
                                {{ customer.added }}
                            </span>

                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="props.can.update"
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit ${customer.display_name}`"
                                    :data-test="`edit-${customer.id}`"
                                >
                                    <Link :href="edit(customer.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <Button
                                    v-if="props.can.delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Remove ${customer.display_name}`"
                                    :data-test="`delete-${customer.id}`"
                                    @click="deleting = customer"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.customers"
                    label="customers"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.customers.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>

    <Dialog
        :open="deleting !== null"
        @update:open="!$event && (deleting = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    Remove {{ deleting?.display_name }}?
                </DialogTitle>
                <DialogDescription>
                    They come off the customer list. Nothing they are already
                    attached to is lost, and the record can be brought back.
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex flex-col gap-1.5 rounded-lg bg-muted/60 p-3.5 text-xs"
            >
                <span class="font-bold">{{ deleting?.display_name }}</span>
                <span class="text-faint">{{ deleting?.phone }}</span>
                <Badge variant="secondary" class="mt-1 w-fit">
                    {{ deleting?.type_label }}
                </Badge>
            </div>

            <DialogFooter>
                <Button variant="quiet" @click="deleting = null">Cancel</Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-customer"
                    @click="confirmDelete"
                >
                    Remove customer
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
