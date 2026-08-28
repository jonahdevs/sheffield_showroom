<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    NotebookPen,
    Package,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import { create, destroy, edit, index } from '@/routes/admin/visits';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    visits: Paginated<App.Data.VisitRowData>;
    filters: { search: string; purpose: string };
    purposes: { value: string; label: string }[];
    page_sizes: number[];
    total: number;
    /** True for a salesperson, who sees only what they logged. */
    scoped_to_own: boolean;
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const { filters, hasFilters, processing, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        purpose: props.filters.purpose === '' ? 'all' : props.filters.purpose,
    },
    blank: { search: '', purpose: 'all' },
    only: ['visits', 'filters'],
});

const deleting = ref<App.Data.VisitRowData | null>(null);

function confirmDelete() {
    const visit = deleting.value;

    if (visit === null) {
        return;
    }

    router.delete(destroy(visit.id).url, {
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
            { title: 'Visits' },
        ],
    },
});
</script>

<!--
  Every call at the showroom, newest first. A table rather than tiles: a visit
  is read across - who, why, when, how long - and there is no picture to
  recognise it by.
-->
<template>
    <Head title="Visits" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Visits</h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        props.scoped_to_own
                            ? 'The visits you have logged.'
                            : 'Every call at the showroom.'
                    }}
                </p>
            </div>

            <Button v-if="props.can.create" as-child data-test="new-visit">
                <Link :href="create().url">
                    <Plus />
                    New customer visit
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
                        placeholder="Search customer or notes..."
                        aria-label="Search visits"
                        data-test="visit-search"
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
                    data-test="visit-clear"
                    @click="clear"
                >
                    Clear
                </Button>

                <!-- `ml-auto` rather than a spacer, so the purpose sits at the
                     far right on a wide bar and simply wraps under the search
                     on a narrow one. -->
                <Select v-model="filters.purpose">
                    <SelectTrigger
                        class="w-52 sm:ml-auto"
                        aria-label="Filter by nature of visit"
                        data-test="visit-purpose-filter"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent align="end">
                        <SelectItem value="all">All visits</SelectItem>
                        <SelectItem
                            v-for="purpose in props.purposes"
                            :key="purpose.value"
                            :value="purpose.value"
                        >
                            {{ purpose.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload nobody knows
                 what is coming back, and flashing "no visits" over rows about
                 to land reads as broken. -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.visits.per_page, 10)"
                :columns="5"
            />

            <div
                v-else-if="props.visits.data.length === 0"
                class="m-5 rounded-lg bg-muted/60 p-6 text-center"
                data-test="visits-empty"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        hasFilters
                            ? 'No visit matches that search.'
                            : 'No visits have been logged yet.'
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
                        class="grid min-w-[1000px] grid-cols-[minmax(0,1fr)_minmax(0,1fr)_160px_150px_minmax(0,1fr)_84px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        <span>Customer</span>
                        <span>Nature of visit</span>
                        <span>When</span>
                        <span>Source</span>
                        <span>Attended by</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-divider">
                        <div
                            v-for="visit in props.visits.data"
                            :key="visit.id"
                            class="grid min-w-[1000px] grid-cols-[minmax(0,1fr)_minmax(0,1fr)_160px_150px_minmax(0,1fr)_84px] items-center gap-4 px-5 py-3.5"
                            :data-test="`visit-${visit.id}`"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold">
                                    {{ visit.customer_name }}
                                </p>
                                <p
                                    v-if="visit.customer_phone"
                                    class="mt-0.5 truncate text-xs text-faint"
                                >
                                    {{ visit.customer_phone }}
                                </p>
                            </div>

                            <div class="flex min-w-0 flex-col gap-1">
                                <span class="truncate text-xs">
                                    {{ visit.purpose_label }}
                                </span>

                                <span
                                    class="flex items-center gap-2.5 text-xs text-faint"
                                >
                                    <span
                                        v-if="visit.product_count > 0"
                                        class="flex items-center gap-1"
                                        :title="`${visit.product_count} product(s) viewed`"
                                    >
                                        <Package
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{ visit.product_count }}
                                    </span>

                                    <NotebookPen
                                        v-if="visit.has_notes"
                                        class="size-3.5"
                                        aria-label="Has a write-up"
                                    />
                                </span>
                            </div>

                            <div class="min-w-0 text-xs">
                                <p class="truncate tabular-nums">
                                    {{ visit.visited_on }}
                                </p>
                                <p class="mt-0.5 text-faint tabular-nums">
                                    {{ visit.visited_time
                                    }}{{
                                        visit.duration
                                            ? ` - ${visit.duration}`
                                            : ''
                                    }}
                                </p>
                            </div>

                            <span class="min-w-0">
                                <Badge
                                    variant="secondary"
                                    class="max-w-full truncate"
                                >
                                    {{ visit.source_label }}
                                </Badge>
                            </span>

                            <span class="truncate text-xs text-faint">
                                {{ visit.attended_by ?? '--' }}
                            </span>

                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="props.can.update"
                                    as-child
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Edit the visit by ${visit.customer_name}`"
                                    :data-test="`edit-${visit.id}`"
                                >
                                    <Link :href="edit(visit.id).url">
                                        <Pencil />
                                    </Link>
                                </Button>

                                <Button
                                    v-if="props.can.delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Remove the visit by ${visit.customer_name}`"
                                    :data-test="`delete-${visit.id}`"
                                    @click="deleting = visit"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.visits"
                    label="visits"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.visits.per_page"
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
                <DialogTitle>Remove this visit?</DialogTitle>
                <DialogDescription>
                    It comes off the log and out of the counts behind it. The
                    customer and the products it names are untouched, and the
                    record can be brought back.
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex flex-col gap-1.5 rounded-lg bg-muted/60 p-3.5 text-xs"
            >
                <span class="font-bold">{{ deleting?.customer_name }}</span>
                <span class="text-faint">
                    {{ deleting?.purpose_label }} -
                    {{ deleting?.visited_on }} at {{ deleting?.visited_time }}
                </span>
            </div>

            <DialogFooter>
                <Button variant="quiet" @click="deleting = null">Cancel</Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-visit"
                    @click="confirmDelete"
                >
                    Remove visit
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
